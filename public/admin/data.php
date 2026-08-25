<?php
/**
 * 어드민 조회 엔드포인트 — egg-admin(라라벨)이 서버 데이터를 읽어가는 통로.
 *   GET /admin/data?q=dashboard
 *   GET /admin/data?q=orders[&limit=&offset=&status=&find=]
 *   GET /admin/data?q=adlogs[&days=]
 *   GET /admin/data?q=goods
 *   GET /admin/data?q=sync[&max=]        기프티쇼 상품 동기화(ops 와 동일 동작)
 * 헤더: X-Egg-Key = config.php 의 admin_key
 *
 * app_key 는 앱 번들에 실려 유출 가능성이 있어, 어드민 데이터는 별도 admin_key 로 잠근다.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/giftishow.php';

egg_cors();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

// admin_key — config.php 우선, 없으면 /ops?do=set-admin-key 가 저장한 var/admin_key 파일.
// (서버 config.php 는 SSH 없이 못 고치므로 파일 저장 경로를 함께 둔다)
$adminKey = (string)(egg_config()['admin_key'] ?? '');
if ($adminKey === '' && is_file(EGG_VAR . '/admin_key')) {
    $adminKey = trim((string)file_get_contents(EGG_VAR . '/admin_key'));
}
if ($adminKey === '') egg_json(503, ['ok' => false, 'error' => 'admin_key 미설정 — /ops?do=set-admin-key 로 등록하세요']);
if (!hash_equals($adminKey, (string)($_SERVER['HTTP_X_EGG_KEY'] ?? ''))) {
    egg_json(401, ['ok' => false, 'error' => '인증 실패']);
}

$db = egg_db();
$q  = (string)($_GET['q'] ?? 'dashboard');
$now = time();
$dayStart = strtotime('today');

if ($q === 'dashboard') {
    $one = fn(string $sql, array $p = []) => (function () use ($db, $sql, $p) {
        $st = $db->prepare($sql); $st->execute($p); return $st->fetch();
    })();
    $goods = $one("SELECT COUNT(*) c, MAX(synced_at) t FROM gs_goods WHERE state_cd='SALE'");
    $ordToday = $one('SELECT COUNT(*) c, COALESCE(SUM(point_price),0) p FROM gs_order WHERE created_at>=?', [$dayStart]);
    $ordAll = $one("SELECT COUNT(*) c, COALESCE(SUM(point_price),0) p FROM gs_order WHERE status='sent'");
    $adToday = $one('SELECT COUNT(*) c, COUNT(DISTINCT user_id) u FROM ad_reward WHERE created_at>=?', [$dayStart]);
    $ad7 = $one('SELECT COUNT(*) c, COUNT(DISTINCT user_id) u FROM ad_reward WHERE created_at>=?', [$now - 7*86400]);
    $ad30 = $one('SELECT COUNT(*) c, COUNT(DISTINCT user_id) u FROM ad_reward WHERE created_at>=?', [$now - 30*86400]);
    $ssvToday = $one('SELECT SUM(ok) ok, COUNT(*)-SUM(ok) rej FROM ssv_log WHERE at>=?', [$dayStart]);
    $biz = null; $bizErr = null;
    try {
        $b = gs_bizmoney();   // 0301 — 실측 응답은 result.balance (문자열)
        $v = $b['result']['balance'] ?? ($b['raw']['balance'] ?? null);
        $biz = $v === null ? null : (int)$v;
        if ($biz === null) $bizErr = trim(($b['code'] ?? '').' '.($b['message'] ?? '')) ?: '응답에 balance 없음';
    } catch (Throwable $e) { $bizErr = $e->getMessage(); }
    egg_json(200, [
        'ok' => true,
        'goods' => ['count' => (int)$goods['c'], 'syncedAt' => (int)$goods['t'] ?: null],
        'ordersToday' => ['count' => (int)$ordToday['c'], 'points' => (int)$ordToday['p']],
        'ordersSentTotal' => ['count' => (int)$ordAll['c'], 'points' => (int)$ordAll['p']],
        'adToday' => ['count' => (int)$adToday['c'], 'users' => (int)$adToday['u']],
        'ad7d' => ['count' => (int)$ad7['c'], 'users' => (int)$ad7['u']],
        'ad30d' => ['count' => (int)$ad30['c'], 'users' => (int)$ad30['u']],
        'ssvToday' => ['ok' => (int)($ssvToday['ok'] ?? 0), 'rejected' => (int)($ssvToday['rej'] ?? 0)],
        'bizmoney' => $biz,
        'bizmoneyError' => $bizErr,
    ]);
}

if ($q === 'orders') {
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 30)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $where = []; $p = [];
    if (($s = (string)($_GET['status'] ?? '')) !== '' && preg_match('/^[a-z]+$/', $s)) { $where[] = 'o.status=?'; $p[] = $s; }
    if (($f = trim((string)($_GET['find'] ?? ''))) !== '') {
        $where[] = '(o.user_id LIKE ? OR o.tr_id LIKE ? OR g.goods_name LIKE ?)';
        array_push($p, "%$f%", "%$f%", "%$f%");
    }
    $w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $cnt = $db->prepare("SELECT COUNT(*) c FROM gs_order o LEFT JOIN gs_goods g ON g.goods_code=o.goods_code $w");
    $cnt->execute($p);
    $total = (int)$cnt->fetch()['c'];
    $st = $db->prepare("SELECT o.tr_id, o.user_id, o.goods_code, g.goods_name, g.brand_name, o.point_price,
                               o.status, o.err_code, o.err_msg, o.created_at, o.sent_at, o.canceled_at, o.pin_no
                        FROM gs_order o LEFT JOIN gs_goods g ON g.goods_code=o.goods_code
                        $w ORDER BY o.created_at DESC LIMIT $limit OFFSET $offset");
    $st->execute($p);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        // 핀번호는 원장 확인용 끝 4자리만 — 전체 값은 어드민에도 내보내지 않는다
        $r['pin_tail'] = $r['pin_no'] ? substr((string)$r['pin_no'], -4) : null;
        unset($r['pin_no']);
    }
    egg_json(200, ['ok' => true, 'total' => $total, 'rows' => $rows]);
}

if ($q === 'adlogs') {
    $days = min(90, max(1, (int)($_GET['days'] ?? 30)));
    $from = strtotime('today') - ($days - 1) * 86400;
    // SQLite: localtime 로 일 단위 집계(서버 TZ 가 KST 가 아닐 수 있어 명시)
    $st = $db->prepare("SELECT date(created_at, 'unixepoch', 'localtime') d,
                               COUNT(*) c, COUNT(DISTINCT user_id) u,
                               SUM(CASE WHEN claimed_at IS NOT NULL THEN 1 ELSE 0 END) claimed
                        FROM ad_reward WHERE created_at>=? GROUP BY d ORDER BY d DESC");
    $st->execute([$from]);
    $daily = $st->fetchAll();
    $rej = $db->prepare('SELECT at, reason, user_id FROM ssv_log WHERE ok=0 AND at>=? ORDER BY at DESC LIMIT 50');
    $rej->execute([$from]);
    egg_json(200, ['ok' => true, 'daily' => $daily, 'rejects' => $rej->fetchAll()]);
}

if ($q === 'goods') {
    $meta = $db->query("SELECT COUNT(*) c, MAX(synced_at) t FROM gs_goods WHERE state_cd='SALE'")->fetch();
    $byType = $db->query("SELECT COALESCE(type_dtl,'기타') k, COUNT(*) c FROM gs_goods WHERE state_cd='SALE' GROUP BY k ORDER BY c DESC")->fetchAll();
    $byBrand = $db->query("SELECT COALESCE(brand_name,'-') k, COUNT(*) c FROM gs_goods WHERE state_cd='SALE' GROUP BY k ORDER BY c DESC LIMIT 20")->fetchAll();
    $sus = $db->query("SELECT COUNT(*) c FROM gs_goods WHERE state_cd!='SALE'")->fetch();
    egg_json(200, ['ok' => true, 'count' => (int)$meta['c'], 'syncedAt' => (int)$meta['t'] ?: null,
        'suspended' => (int)$sus['c'], 'byType' => $byType, 'byBrand' => $byBrand]);
}

if ($q === 'bizraw') {
    // 비즈머니 원본 응답 — 규격서와 실제 응답 키가 다를 때 확인용(admin_key 게이트)
    try { egg_json(200, ['ok' => true, 'raw' => gs_bizmoney()['raw']]); }
    catch (Throwable $e) { egg_json(500, ['ok' => false, 'error' => $e->getMessage()]); }
}

if ($q === 'sync') {
    @set_time_limit(600);
    $max = max(0, (int)($_GET['max'] ?? 0));
    $r = gs_sync($max);
    egg_json(200, ['ok' => true] + $r);
}

egg_json(400, ['ok' => false, 'error' => '알 수 없는 q']);
