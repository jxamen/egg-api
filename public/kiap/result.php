<?php
/**
 * GET /kiap/result?sid=&t= — 앱이 1회용 토큰으로 인증 결과(이름·휴대폰)를 회수한다.
 * 헤더 X-Egg-Key(앱 키) + 세션의 result_token 이중 잠금. 회수하면 consumed.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/kiap.php';
egg_require_app_key();

$sid = (string)($_GET['sid'] ?? '');
$t   = (string)($_GET['t'] ?? '');
if (!preg_match('/^[0-9a-f]{32}$/', $sid) || !preg_match('/^[0-9a-f]{32}$/', $t)) {
    egg_json(400, ['ok' => false, 'error' => '잘못된 요청']);
}
$db = kiap_db();
$st = $db->prepare('SELECT * FROM kiap_session WHERE sid = ?');
$st->execute([$sid]);
$row = $st->fetch();
if (!$row || $row['status'] !== 'verified'
    || !is_string($row['result_token']) || !hash_equals($row['result_token'], $t)
    || (int)$row['result_expires'] < time()) {
    egg_json(404, ['ok' => false, 'error' => '결과가 없거나 만료되었습니다']);
}
$db->prepare("UPDATE kiap_session SET status = 'consumed', result_token = NULL WHERE sid = ?")->execute([$sid]);
egg_json(200, [
    'ok'       => true,
    'name'     => (string)$row['name'],
    'phone'    => (string)$row['phone'],
    'provider' => (string)$row['provider'],
]);
