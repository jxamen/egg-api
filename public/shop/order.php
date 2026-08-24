<?php
/**
 * 기프티콘 발주 — 상점 교환과 지갑의 네이버페이 포인트 전환이 같은 경로를 쓴다.
 * 둘의 차이는 goods_code 뿐이다.
 *
 *   POST /shop/order    user_id, goods_code, phone_no        헤더: X-Egg-Key
 *
 * 호출 전제(규격서 8장): **포인트를 먼저 차감하고** 부를 것.
 * 발주가 실패하면 ok=false 와 refund=true 를 돌려주니 호출한 쪽이 포인트를 되돌린다.
 *
 * 타임아웃이 가장 위험하다 — 응답을 못 받아도 상대 쪽에서는 발행됐을 수 있어
 * 규격서가 "같은 TR_ID 로 쿠폰취소요청을 보내라"고 못박아 두었다. 그대로 따른다.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/giftishow.php';
egg_require_app_key();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    egg_json(405, ['ok' => false, 'error' => 'post_only']);
}

$userId    = trim((string)($_POST['user_id'] ?? ''));
$goodsCode = trim((string)($_POST['goods_code'] ?? ''));
$phoneNo   = preg_replace('/\D/', '', (string)($_POST['phone_no'] ?? ''));

if ($userId === '' || $goodsCode === '') {
    egg_json(400, ['ok' => false, 'error' => 'user_id_and_goods_code_required']);
}
if (strlen($phoneNo) < 10) {
    egg_json(400, ['ok' => false, 'error' => 'phone_no_required']);
}

$db = egg_db();
$st = $db->prepare("SELECT * FROM gs_goods WHERE goods_code = ? AND state_cd = 'SALE'");
$st->execute([$goodsCode]);
$goods = $st->fetch();
if (!$goods) {
    egg_json(409, ['ok' => false, 'refund' => true, 'error' => 'goods_unavailable']);
}

$trId = gs_new_tr_id();
$now  = time();

// 부르기 전에 먼저 남긴다 — 도중에 죽어도 흔적이 남아야 대사할 수 있다
$db->prepare('INSERT INTO gs_order (tr_id, user_id, goods_code, point_price, status, created_at)
              VALUES (?,?,?,?,?,?)')
   ->execute([$trId, $userId, $goodsCode, (int)$goods['sale_price'], 'pending', $now]);

$title = mb_substr((string)$goods['brand_name'] ?: '꼬꼬농장', 0, 10);   // 규격: 10자 이하
$msg   = sprintf('꼬꼬농장에서 보낸 %s 교환권입니다.', (string)$goods['goods_name']);

$r = gs_send($trId, $goodsCode, $phoneNo, $title, $msg);

/* 타임아웃 — 발행됐을 가능성이 높으니 반드시 같은 TR_ID 로 취소를 보낸다 */
if ($r['code'] === 'TIMEOUT') {
    $c = gs_cancel($trId);
    $db->prepare('UPDATE gs_order SET status = ?, err_code = ?, err_msg = ?, canceled_at = ? WHERE tr_id = ?')
       ->execute(['canceled', 'TIMEOUT', '응답 없음 → 취소 요청 [' . $c['code'] . ']', time(), $trId]);
    egg_json(504, ['ok' => false, 'refund' => true, 'trId' => $trId, 'error' => 'send_timeout_canceled']);
}

if (!$r['ok']) {
    $db->prepare('UPDATE gs_order SET status = ?, err_code = ?, err_msg = ? WHERE tr_id = ?')
       ->execute(['failed', $r['code'], $r['message'], $trId]);
    $error = $r['code'] === GS_NO_BIZMONEY ? 'out_of_bizmoney' : 'send_failed';
    egg_json(502, ['ok' => false, 'refund' => true, 'trId' => $trId, 'error' => $error, 'code' => $r['code']]);
}

// 성공 응답은 result 가 두 겹으로 감싸여 온다
$inner   = $r['result']['result'] ?? [];
$orderNo = (string)($inner['orderNo'] ?? '');
$pinNo   = (string)($inner['pinNo'] ?? '');
$imgUrl  = (string)($inner['couponImgUrl'] ?? '');

// 비즈머니는 빠졌는데 핀이 안 온 "발송 실패"는 쿠폰취소가 아니라 발송실패취소(0205)로 되돌린다
if ($pinNo === '' && gs_config()['gubun'] !== 'N') {
    $c = gs_send_fail_cancel($trId);
    $db->prepare('UPDATE gs_order SET status = ?, order_no = ?, err_code = ?, err_msg = ?, canceled_at = ? WHERE tr_id = ?')
       ->execute(['canceled', $orderNo, 'NOPIN', '핀 미수신 → 발송실패취소 [' . $c['code'] . ']', time(), $trId]);
    egg_json(502, ['ok' => false, 'refund' => true, 'trId' => $trId, 'error' => 'pin_not_issued']);
}

$validEnd = ((int)$goods['valid_days'] > 0)
    ? date('Ymd', $now + (int)$goods['valid_days'] * 86400)
    : null;

$db->prepare('UPDATE gs_order SET status = ?, order_no = ?, pin_no = ?, coupon_img = ?, valid_end = ?, sent_at = ? WHERE tr_id = ?')
   ->execute(['sent', $orderNo, $pinNo, $imgUrl, $validEnd, time(), $trId]);

egg_json(200, [
    'ok'        => true,
    'trId'      => $trId,
    'orderNo'   => $orderNo,
    'pinNo'     => $pinNo,
    'couponImg' => $imgUrl ?: null,
    'name'      => $goods['goods_name'],
    'brand'     => $goods['brand_name'],
    'price'     => (int)$goods['sale_price'],
    'validEnd'  => $validEnd,
    // PIN·이미지로 직접 전달할 때 판매 화면에 반드시 함께 띄워야 하는 고지(규격서 문서정보 3항)
    'notice'    => [
        'supplier' => '상품공급자 : 주식회사 케이티알파',
        'issuer'   => '발행사업자 : (주)제이커브인터렉티브',
    ],
]);
