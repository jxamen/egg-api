<?php
/**
 * POST /kiap/callback?sid= — KIAP 게이트웨이가 인증 종료 후 form POST 로 부른다(iframe 내부).
 * 검증은 insurance-db 의 4단계를 그대로: ①KIAP 성공코드 ②getResult+바인딩 ③복호화·CI fail-closed ④CAS.
 * 어떤 실패든 사유 코드만 남기고 앱 스킴으로 복귀시킨다 — 평문 PII·키·암호문은 로그에도 응답에도 없다.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/kiap.php';

/** 종료 — iframe 안이므로 top 을 앱 스킴으로 돌린다(부모는 같은 오리진의 page.php) */
function kiap_cb_exit(string $ret, array $q): never
{
    $url = $ret . (str_contains($ret, '?') ? '&' : '?') . http_build_query($q);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><script>(window.top||window).location.replace('
        . json_encode($url) . ');</script>'
        . '<p style="font-family:sans-serif;text-align:center;margin-top:40vh;">앱으로 돌아갑니다…</p>';
    exit;
}

function kiap_cb_fail(PDO $db, array $row, string $reason): never
{
    $db->prepare("UPDATE kiap_session SET status='failed', fail_reason=? WHERE sid=?")
       ->execute([$reason, $row['sid']]);
    egg_log(false, 'kiap:' . $reason, (string)$row['uid'], 'sid=' . $row['sid']);
    kiap_cb_exit((string)$row['ret'], ['ok' => 0, 'reason' => $reason]);
}

$sid = (string)($_GET['sid'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !preg_match('/^[0-9a-f]{32}$/', $sid)) {
    http_response_code(400);
    exit('잘못된 요청입니다.');
}

$db = kiap_db();
$st = $db->prepare('SELECT * FROM kiap_session WHERE sid = ?');
$st->execute([$sid]);
$row = $st->fetch();
if (!$row) { http_response_code(404); exit('세션이 없습니다.'); }
if ($row['status'] !== 'pending') kiap_cb_exit((string)$row['ret'], ['ok' => 0, 'reason' => 'SESSION_USED']);
if ((int)$row['created_at'] < time() - KIAP_SESSION_TTL) kiap_cb_fail($db, $row, 'SESSION_EXPIRED');

// ① KIAP 자체 결과 — '0000' 만 성공
$code = (string)($_POST['code'] ?? '');
if ($code !== '' && $code !== '0000') kiap_cb_fail($db, $row, 'KIAP_' . preg_replace('/[^0-9A-Za-z]/', '', $code));

$authToken   = (string)($_POST['auth_token'] ?? '');
$providerId  = strtoupper((string)($_POST['provider_id'] ?? ''));
$clientTxId  = (string)($_POST['client_tx_id'] ?? '');
$serverTxId  = (string)($_POST['server_tx_id'] ?? '');
$accessToken = (string)($_POST['access_token'] ?? '');   // 동적 토큰 — 콘솔 정적 토큰과 다르다
if ($authToken === '' || $providerId === '' || $clientTxId === '' || $serverTxId === '' || $accessToken === '') {
    kiap_cb_fail($db, $row, 'CALLBACK_FIELDS_MISSING');
}
if (!array_key_exists($providerId, KIAP_PROVIDERS)) kiap_cb_fail($db, $row, 'PROVIDER_NOT_ALLOWED');

// ② getResult + 응답 바인딩 검증(fail-closed)
$r = kiap_get_result($accessToken, $providerId, $clientTxId, $serverTxId);
if (!$r['ok']) kiap_cb_fail($db, $row, $r['error']);
$d = $r['data'];
if (($d['client_tx_id'] ?? '') !== $clientTxId
    || ($d['server_tx_id'] ?? '') !== $serverTxId
    || strtoupper((string)($d['provider_id'] ?? '')) !== $providerId
    || ($d['service_code'] ?? '') !== 'AUTH') {
    kiap_cb_fail($db, $row, 'RESULT_BINDING_MISMATCH');
}

// ③ 복호화 — 이름·휴대폰·CI. 생년월일은 받지도 저장하지도 않는다(과세·보관 리스크 최소화).
$name  = kiap_norm_name(kiap_decrypt($d['name'] ?? '', $authToken));
$phone = kiap_norm_phone(kiap_decrypt($d['phone'] ?? '', $authToken));
$ci    = kiap_decrypt($d['ci'] ?? '', $authToken);
if ($name === '' || !preg_match('/^01[0-9]{8,9}$/', $phone)) kiap_cb_fail($db, $row, 'DECRYPT_FAILED');
if ($ci === '') kiap_cb_fail($db, $row, 'CI_MISSING');   // CI 계약 미포함이면 여기서 전부 멈춘다

// ④ 1인 1계정 — CI 원장 대조(반복 수령 차단의 핵심)
$ciHash = kiap_ci_hash($ci);
$uid = (string)$row['uid'];
$ex = $db->prepare('SELECT uid FROM kiap_ci WHERE ci_hash = ?');
$ex->execute([$ciHash]);
$owner = $ex->fetch();
if ($owner && (string)$owner['uid'] !== $uid) kiap_cb_fail($db, $row, 'DUPLICATE_CI');
$mine = $db->prepare('SELECT ci_hash FROM kiap_ci WHERE uid = ? AND ci_hash != ?');
$mine->execute([$uid, $ciHash]);
if ($mine->fetch()) kiap_cb_fail($db, $row, 'UID_CI_CONFLICT');   // 같은 계정에 다른 사람 인증 시도
if (!$owner) {
    $db->prepare(egg_sql_insert_ignore() . ' kiap_ci (ci_hash, uid, verified_at) VALUES (?,?,?)')
       ->execute([$ciHash, $uid, time()]);
}

// 세션 확정 + 앱이 결과를 회수할 1회용 토큰(CAS — pending 인 동안만 성공)
$token = bin2hex(random_bytes(16));
$up = $db->prepare("UPDATE kiap_session
    SET status='verified', provider=?, name=?, phone=?, ci_hash=?, result_token=?, result_expires=?, verified_at=?
    WHERE sid=? AND status='pending'");
$up->execute([$providerId, $name, $phone, $ciHash, $token, time() + KIAP_RESULT_TTL, time(), $sid]);
if ($up->rowCount() !== 1) kiap_cb_exit((string)$row['ret'], ['ok' => 0, 'reason' => 'RACE_BLOCKED']);

egg_log(true, 'kiap:verified:' . $providerId . ':' . kiap_mask_name($name) . ':' . kiap_mask_phone($phone), $uid, 'sid=' . $sid);
kiap_cb_exit((string)$row['ret'], ['ok' => 1, 'sid' => $sid, 't' => $token]);
