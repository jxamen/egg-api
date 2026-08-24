<?php
/**
 * 앱 상점에 뿌릴 기프티콘 목록.
 *   GET /shop/goods?max=6000&limit=40&q=아메리카노      헤더: X-Egg-Key
 *
 * 기프티쇼를 매 요청마다 부르지 않는다 — 규격서 FAQ 가 전체 목록을 받아 두고
 * 검색·전시는 자체 DB 에서 하라고 안내한다. 목록은 bin/gs.php sync 가 채운다.
 *
 * 매입가(discount_price)는 우리 마진이라 응답에 넣지 않는다.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';
egg_require_app_key();

$max   = max(0, (int)($_GET['max'] ?? 0));            // 이 금액 이하만 (0 이면 전체)
$limit = min(100, max(1, (int)($_GET['limit'] ?? 40)));
$q     = trim((string)($_GET['q'] ?? ''));
// kind: ''(기본) 기프티콘 목록 — 네이버페이 포인트 쿠폰은 뺀다(지갑의 전용 전환 화면에서만 다룬다,
//       상점에 섞이면 남의 상품권을 되파는 것처럼 보인다) / 'npay' 네이버페이 포인트 쿠폰만
$kind  = trim((string)($_GET['kind'] ?? ''));

$sql  = "SELECT goods_code, goods_name, brand_name, affiliate, sale_price, img_s, img_b, valid_days, type_dtl
         FROM gs_goods WHERE state_cd = 'SALE'";
$args = [];
$npay = "(goods_name LIKE '%네이버페이%' OR brand_name LIKE '%네이버페이%'
          OR goods_name LIKE '%네이버 페이%' OR brand_name LIKE '%네이버 페이%')";
if ($kind === 'npay') {
    // 같은 권종이 여러 건 온다(예: 5천원권 2건 — 발행 시기가 다른 상품). 전환 화면은 금액을
    // 고르는 곳이라 권종당 하나만 남기고, 매입가(discount_price)가 싼 쪽을 고른다.
    // SQLite 는 MIN() 을 쓴 집계에서 나머지 컬럼이 그 최소 행의 값을 갖는다.
    $sql .= " AND $npay AND goods_code IN (
        SELECT goods_code FROM gs_goods
        WHERE state_cd = 'SALE' AND $npay
        GROUP BY sale_price HAVING MIN(discount_price))";
} else {
    $sql .= " AND NOT $npay";
}
if ($max > 0) { $sql .= ' AND sale_price <= ?';                       $args[] = $max; }
if ($q !== '') { $sql .= ' AND (goods_name LIKE ? OR brand_name LIKE ?)'; $args[] = "%$q%"; $args[] = "%$q%"; }
// 네이버페이는 권종(금액) 순으로 보여야 전환 화면의 금액 선택이 자연스럽다
$sql .= ' ORDER BY sale_price, goods_name LIMIT ' . $limit;

$st = egg_db()->prepare($sql);
$st->execute($args);

$items = array_map(static fn(array $g) => [
    'goodsCode' => $g['goods_code'],
    'name'      => $g['goods_name'],
    'brand'     => $g['brand_name'],
    'affiliate' => $g['affiliate'],       // 교환처 (세븐일레븐/바이더웨이 …)
    'price'     => (int)$g['sale_price'], // 액면가 = 차감할 포인트
    'imgS'      => $g['img_s'],
    'imgB'      => $g['img_b'],
    'validDays' => (int)$g['valid_days'],
    'category'  => $g['type_dtl'],
], $st->fetchAll());

$synced = (int)(egg_db()->query('SELECT MAX(synced_at) t FROM gs_goods')->fetch()['t'] ?? 0);

egg_json(200, [
    'ok'       => true,
    'count'    => count($items),
    'syncedAt' => $synced ?: null,
    'items'    => $items,
]);
