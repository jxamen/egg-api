<?php
/**
 * 인증 엔드포인트 — /auth/* 를 이 파일 하나가 받는다(.htaccess 한 줄로 라우팅).
 *
 *   GET  /auth/providers          붙어 있는 제공자 목록            [X-Egg-Key]
 *   GET  /auth/start?provider=    각 사 로그인 화면으로 302        (브라우저가 연다 — 키 없음)
 *   GET  /auth/callback/{p}       각 사가 code 를 들고 돌아오는 곳  (브라우저 — 키 없음)
 *   POST /auth/exchange           ticket → 세션 토큰               [X-Egg-Key]
 *   POST /auth/apple              identityToken → 세션 토큰        [X-Egg-Key]
 *   GET  /auth/me                 세션 확인                        [Bearer]
 *   POST /auth/complete           농장 이름·약관 동의로 가입 완료    [Bearer]
 *   POST /auth/logout             세션 폐기                        [Bearer]
 *
 * start·callback 은 브라우저가 직접 여는 주소라 X-Egg-Key 를 실을 수 없다.
 * 대신 state(1회용·10분)로 우리가 시작한 요청인지 확인한다.
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';

$act = (string)($_GET['act'] ?? '');
$act = trim($act, '/');

// ── 제공자 목록 ────────────────────────────────────────────
if ($act === 'providers') {
    egg_require_app_key();
    egg_json(200, ['ok' => true, 'providers' => egg_social_enabled()]);
}

// ── 로그인 시작 ────────────────────────────────────────────
if ($act === 'start') {
    $provider = (string)($_GET['provider'] ?? '');
    $r = egg_auth_start_url($provider);
    if (!$r['ok']) egg_json(400, ['ok' => false, 'error' => $r['error']]);

    // 앱은 이 주소를 브라우저 창으로 연다. json=1 이면 URL 만 돌려준다(디버깅용).
    if (($_GET['json'] ?? '') !== '') egg_json(200, ['ok' => true, 'url' => $r['url']]);
    header('Location: ' . $r['url']);
    http_response_code(302);
    exit;
}

// ── 각 사에서 돌아오는 콜백 ────────────────────────────────
if (str_starts_with($act, 'callback')) {
    $provider = (string)($_GET['provider'] ?? '');
    if ($provider === '' && preg_match('#^callback/([a-z]+)$#', $act, $m)) $provider = $m[1];

    // 사용자가 동의 화면에서 취소한 경우 — 각 사가 error 를 붙여 되돌린다
    if (($_GET['error'] ?? '') !== '') {
        egg_return_to_app(['error' => 'cancelled']);
    }
    $code  = (string)($_GET['code'] ?? '');
    $state = (string)($_GET['state'] ?? '');
    if ($code === '' || $state === '') egg_return_to_app(['error' => 'bad_request']);
    if (!egg_auth_take_state($state, $provider)) egg_return_to_app(['error' => 'bad_state']);

    $tok = egg_auth_exchange_code($provider, $code);
    if (!$tok['ok']) egg_return_to_app(['error' => 'token_failed']);

    $pr = egg_auth_profile($provider, $tok['access_token']);
    if (!$pr['ok'] || ($pr['profile']['id'] ?? '') === '') egg_return_to_app(['error' => 'profile_failed']);

    try {
        $member = egg_member_upsert($provider, $pr['profile']);
    } catch (Throwable $e) {
        egg_return_to_app(['error' => 'server']);
    }

    // 세션 토큰을 URL 에 실어 보내지 않는다 — 1회용 티켓만 넘기고 앱이 교환한다
    egg_return_to_app(['ticket' => egg_ticket_issue($member['id'])]);
}

// ── 티켓 → 세션 ────────────────────────────────────────────
if ($act === 'exchange') {
    egg_require_app_key();
    $ticket = (string)($_POST['ticket'] ?? '');
    if ($ticket === '') egg_json(400, ['ok' => false, 'error' => 'ticket_required']);

    $userId = egg_ticket_take($ticket);
    if ($userId === null) egg_json(401, ['ok' => false, 'error' => 'ticket_invalid']);

    $st = egg_auth_schema()->prepare('SELECT * FROM member WHERE id = ?');
    $st->execute([$userId]);
    $m = $st->fetch();
    if (!$m) egg_json(401, ['ok' => false, 'error' => 'member_not_found']);

    egg_json(200, ['ok' => true, 'session' => egg_session_issue($userId), 'member' => egg_member_public($m)]);
}

// ── 애플(네이티브) ─────────────────────────────────────────
if ($act === 'apple') {
    egg_require_app_key();
    $jwt = (string)($_POST['identityToken'] ?? '');
    if ($jwt === '') egg_json(400, ['ok' => false, 'error' => 'token_required']);

    $v = egg_apple_verify($jwt);
    if (!$v['ok']) egg_json(401, ['ok' => false, 'error' => $v['error']]);

    // 애플은 이름을 최초 1회만 준다 — 앱이 그때 함께 보내 준 값을 쓴다
    $profile = $v['profile'];
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name !== '') $profile['name'] = $name;

    try {
        $m = egg_member_upsert('apple', $profile);
    } catch (Throwable $e) {
        egg_json(500, ['ok' => false, 'error' => 'server']);
    }
    egg_json(200, ['ok' => true, 'session' => egg_session_issue($m['id']), 'member' => egg_member_public($m)]);
}

// ── 세션 확인 ──────────────────────────────────────────────
if ($act === 'me') {
    egg_require_app_key();
    $m = egg_session_member();
    if (!$m) egg_json(401, ['ok' => false, 'error' => 'unauthorized']);
    egg_json(200, ['ok' => true, 'member' => egg_member_public($m)]);
}

// ── 가입 완료(농장 이름·약관 동의) ─────────────────────────
if ($act === 'complete') {
    egg_require_app_key();
    $m = egg_session_member();
    if (!$m) egg_json(401, ['ok' => false, 'error' => 'unauthorized']);

    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') egg_json(400, ['ok' => false, 'error' => 'name_required']);

    // 필수 약관 — 앱 화면과 같은 세 가지가 모두 있어야 가입이 끝난다
    $need = ['age14', 'tos', 'privacy'];
    foreach ($need as $k) {
        if (($_POST[$k] ?? '') !== '1') egg_json(400, ['ok' => false, 'error' => 'agreements_required']);
    }
    $marketing = (($_POST['marketing'] ?? '') === '1') ? 1 : 0;

    egg_auth_schema()->prepare('UPDATE member SET name = ?, marketing = ?, agreed_at = COALESCE(agreed_at, ?) WHERE id = ?')
        ->execute([$name, $marketing, time(), $m['id']]);

    $st = egg_auth_schema()->prepare('SELECT * FROM member WHERE id = ?');
    $st->execute([$m['id']]);
    egg_json(200, ['ok' => true, 'member' => egg_member_public($st->fetch())]);
}

// ── 로그아웃 ───────────────────────────────────────────────
if ($act === 'logout') {
    egg_require_app_key();
    egg_session_revoke();
    egg_json(200, ['ok' => true]);
}

egg_json(404, ['ok' => false, 'error' => 'unknown_action']);
