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

$sql  = "SELECT goods_code, goods_name, brand_name, affiliate, sale_price, img_s, img_b, valid_days, type_dtl
         FROM gs_goods WHERE state_cd = 'SALE'";
$args = [];
if ($max > 0) { $sql .= ' AND sale_price <= ?';                       $args[] = $max; }
if ($q !== '') { $sql .= ' AND (goods_name LIKE ? OR brand_name LIKE ?)'; $args[] = "%$q%"; $args[] = "%$q%"; }
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
