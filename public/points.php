<?php
/**
 * 포인트 엔드포인트 — /points/* (도메인 통합 경로로는 /api/points/*).
 *
 *   GET /points/balance                    잔액              [Bearer]
 *   GET /points/history?before=&limit=     이용내역(최신순)   [Bearer]
 *
 * **적립·차감 API 는 일부러 두지 않는다.** 앱이 "포인트 올려줘" 를 부를 수 있으면 앱을 뜯어
 * 얼마든 만들 수 있다. 적립은 검증된 경로에서만 서버가 스스로 한다 —
 * 광고는 구글 콜백(SSV) 검증 뒤, 미션은 제휴사 참여 확인 뒤, 교환은 발주 직전 차감.
 *
 * before 는 직전 페이지의 마지막 id 다(offset 을 쓰지 않는 이유는 src/points.php 주석 참고).
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/points.php';
require_once __DIR__ . '/../src/auth.php';

egg_require_app_key();

$m = egg_session_member();
if (!$m) egg_json(401, ['ok' => false, 'error' => 'unauthorized']);

$act = trim((string)($_GET['act'] ?? ''), '/');

if ($act === 'balance') {
    egg_json(200, ['ok' => true, 'balance' => egg_points_balance($m['id'])]);
}

if ($act === 'history') {
    $before = (int)($_GET['before'] ?? 0);
    $limit  = (int)($_GET['limit'] ?? 30);
    $rows   = egg_points_history($m['id'], $before ?: null, $limit ?: 30);
    egg_json(200, [
        'ok'      => true,
        'balance' => egg_points_balance($m['id']),
        'items'   => $rows,
        // 다음 페이지 커서 — 더 없으면 null
        'next'    => count($rows) ? $rows[count($rows) - 1]['id'] : null,
    ]);
}

egg_json(404, ['ok' => false, 'error' => 'unknown_action']);
