<?php
/**
 * 기프티쇼 연동 점검·동기화 CLI.
 *
 *   php bin/gs.php bizmoney                비즈머니 잔액 (인증키 확인용 가장 싼 호출)
 *   php bin/gs.php sync [최대건수]          상품 전체를 받아 gs_goods 에 저장
 *   php bin/gs.php list [개수]              저장된 상품 훑어보기
 *   php bin/gs.php detail <goods_code>      상품 상세
 *   php bin/gs.php coupon <tr_id>           쿠폰 상세(발송 결과 확인)
 *   php bin/gs.php cancel <tr_id>           쿠폰 취소
 *
 * 상품 동기화는 규격서 권고대로 매일(또는 주 2~3회) 새벽 2~4시에 돌린다.
 *   0 3 * * * cd /www/jcurve/egg-api && php bin/gs.php sync >> var/gs_sync.log 2>&1
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI 전용\n");
require_once __DIR__ . '/../src/giftishow.php';

$cmd = $argv[1] ?? '';
$arg = $argv[2] ?? '';

function out(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(array $r): never { out(sprintf('실패 [%s] %s', $r['code'], $r['message'])); exit(1); }

switch ($cmd) {
    case 'bizmoney':
        $r = gs_bizmoney();
        if (!$r['ok']) fail($r);
        // 0301 은 balance 가 최상위에 온다 — result 안이 아니다
        out('비즈머니 잔액: ' . number_format((float)($r['raw']['balance'] ?? 0)) . '원');
        break;

    case 'sync':
        $r = gs_sync($arg !== '' ? (int)$arg : 0, static fn (string $m) => out($m));
        if (!$r['ok']) fail($r);
        out(sprintf('동기화 완료: %d건 (판매중지 %d건)', $r['seen'], $r['suspended']));
        break;
    case 'list':
        $n = $arg !== '' ? (int)$arg : 20;
        $rows = egg_db()->query("SELECT goods_code, sale_price, discount_price, brand_name, goods_name
                                 FROM gs_goods WHERE state_cd = 'SALE' ORDER BY sale_price LIMIT $n")->fetchAll();
        foreach ($rows as $g) {
            out(sprintf('%-14s %7s원 (매입 %6s) %-10s %s',
                $g['goods_code'], number_format((float)$g['sale_price']),
                number_format((float)$g['discount_price']), $g['brand_name'], $g['goods_name']));
        }
        out(count($rows) . '건');
        break;

    case 'detail':
        if ($arg === '') exit("goods_code 를 주세요\n");
        $r = gs_goods_detail($arg);
        if (!$r['ok']) fail($r);
        out(json_encode($r['result'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        break;

    case 'coupon':
        if ($arg === '') exit("tr_id 를 주세요\n");
        $r = gs_coupon($arg);
        if (!$r['ok']) fail($r);
        out(json_encode($r['result'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        break;

    case 'cancel':
        if ($arg === '') exit("tr_id 를 주세요\n");
        $r = gs_cancel($arg);
        out(sprintf('[%s] %s', $r['code'], $r['message']));
        break;

    default:
        out('사용법: php bin/gs.php <bizmoney|sync|list|detail|coupon|cancel> [인자]');
        exit(1);
}
