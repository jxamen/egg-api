<?php
/**
 * AdMob 보상형 서버 측 확인(SSV) 콜백.
 *   GET /ads/ssv?ad_network=..&ad_unit=..&reward_amount=..&reward_item=..&timestamp=..
 *              &transaction_id=..&user_id=..&custom_data=..&key_id=..&signature=..
 *
 * 규칙
 *  - 서명이 맞을 때만 지급한다(클라이언트 이벤트는 믿지 않는다).
 *  - transaction_id 로 중복 지급을 막는다. 같은 콜백이 다시 와도 200.
 *  - 보상 수량은 콜백 값이 아니라 서버 상수(사료 1개)를 쓴다.
 *  - 하루 한도를 서버에서 센다.
 *  - 판단이 끝난 요청에는 200 을 준다(비 2xx 는 구글이 계속 재시도한다).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../src/verify.php';

$qs = (string)($_SERVER['QUERY_STRING'] ?? '');
parse_str($qs, $q);
$userId = isset($q['user_id']) ? (string)$q['user_id'] : null;
$txId   = isset($q['transaction_id']) ? (string)$q['transaction_id'] : '';

/** 판단 결과를 남기고 응답한다 */
function ssv_done(int $code, bool $ok, string $reason, ?string $userId, string $qs): never
{
    egg_log($ok, $reason, $userId, $qs);
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $reason;
    exit;
}

if ($qs === '' || $txId === '') ssv_done(400, false, 'malformed', $userId, $qs);

$v = egg_verify_ssv($qs);
if (!$v['ok']) ssv_done(403, false, $v['reason'], $userId, $qs);

// 재전송 방지 — timestamp 는 밀리초
$ts = (int)($q['timestamp'] ?? 0);
if ($ts > 0 && abs(time() - intdiv($ts, 1000)) > MAX_AGE_SEC) ssv_done(200, false, 'stale', $userId, $qs);

// 테스트 콜백(애드몹 콘솔에서 URL 저장 시 발사)은 검증만 하고 지급하지 않는다
if ($userId === null || $userId === '' || str_starts_with($userId, 'ssv-test')) {
    ssv_done(200, true, 'test_callback', $userId, $qs);
}

try {
    $db = egg_db();

    // 하루 한도
    $st = $db->prepare('SELECT COUNT(*) c FROM ad_reward WHERE user_id = ? AND created_at >= ?');
    $st->execute([$userId, egg_today_start()]);
    if ((int)$st->fetch()['c'] >= DAILY_LIMIT) ssv_done(200, false, 'daily_limit', $userId, $qs);

    // 지급 — transaction_id 가 기본키라 중복은 여기서 걸린다
    $ins = $db->prepare(egg_sql_insert_ignore() . ' ad_reward
        (transaction_id, user_id, ad_unit, reward_item, reward_amount, custom_data, created_at)
        VALUES (?,?,?,?,?,?,?)');
    $ins->execute([
        $txId,
        $userId,
        (string)($q['ad_unit'] ?? ''),
        REWARD_ITEM,
        REWARD_AMOUNT,
        (string)($q['custom_data'] ?? ''),
        time(),
    ]);
    ssv_done(200, true, $ins->rowCount() > 0 ? 'granted' : 'duplicate', $userId, $qs);
} catch (Throwable $e) {
    // DB 문제면 5xx 로 돌려 구글이 재시도하게 둔다
    egg_log(false, 'db_error:' . $e->getMessage(), $userId, $qs);
    http_response_code(500);
    exit;
}
