<?php
/**
 * SNS 로그인 — 소셜 인증·회원·세션.
 *
 * 왜 서버가 하나: 카카오 REST API 키는 client_secret 이 기본 활성이고 네이버는 secret 이 필수다.
 * 앱 번들에 secret 을 넣으면 IPA 를 뜯는 순간 나오므로, 인가 코드를 토큰으로 바꾸는 일은 전부
 * 여기서 한다. 덕분에 **앱에는 어떤 소셜 키도 들어가지 않는다**(애플만 예외 — 네이티브가 발급한
 * identityToken 을 여기서 검증한다).
 *
 * 흐름: 앱 → /auth/start → 각 사 로그인 → /auth/callback/{provider} → kkokkofarm://auth?ticket=
 *      → 앱이 /auth/exchange 로 티켓을 세션 토큰과 바꾼다. 티켓은 1회용·2분 만료라
 *      리다이렉트 URL 에 세션이 그대로 실려 나가지 않는다.
 *
 * 스키마는 이 파일이 스스로 만든다(bootstrap.php 를 건드리지 않는다).
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** 앱으로 돌아올 커스텀 스킴 — app.json 의 scheme 과 같아야 한다 */
const EGG_APP_RETURN  = 'kkokkofarm://auth';
const EGG_STATE_TTL   = 600;               // 인가 요청 유효 시간(초)
const EGG_TICKET_TTL  = 120;               // 1회용 티켓
const EGG_SESSION_TTL = 7776000;           // 세션 90일
const EGG_APPLE_KEYS  = EGG_VAR . '/apple-keys.json';

/** 우리 서버의 콜백 주소 — 각 사 콘솔에 등록한 값과 한 글자도 달라선 안 된다 */
function egg_redirect_uri(string $provider): string
{
    // 통합 도메인(docs/server-infra.md URL 규약). SNS 로그인은 /api 접두를 쓰지 않는다.
    // 각 사 콘솔은 Redirect URI 를 여러 개 받으므로, vhost 가 붙기 전까지 쓰던 egg-api 주소도
    // 함께 등록해 두면 도메인을 옮겨도 로그인이 끊기지 않는다.
    $base = egg_config()['base_url'] ?? 'https://kkokkofarm.j-curve.co.kr';
    return rtrim($base, '/') . '/auth/callback/' . $provider;
}

/**
 * 제공자별 OAuth 규격.
 * scope 가 빈 곳은 콘솔의 동의항목 설정을 그대로 따른다(카카오는 등록하지 않은 scope 를 보내면
 * 요청 전체가 거부된다).
 */
function egg_oauth_spec(string $provider): ?array
{
    static $spec = [
        'kakao' => [
            'authorize' => 'https://kauth.kakao.com/oauth/authorize',
            'token'     => 'https://kauth.kakao.com/oauth/token',
            'profile'   => 'https://kapi.kakao.com/v2/user/me',
            'scope'     => '',
        ],
        'naver' => [
            'authorize' => 'https://nid.naver.com/oauth2.0/authorize',
            'token'     => 'https://nid.naver.com/oauth2.0/token',
            'profile'   => 'https://openapi.naver.com/v1/nid/me',
            'scope'     => '',
        ],
        'google' => [
            'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token'     => 'https://oauth2.googleapis.com/token',
            'profile'   => 'https://www.googleapis.com/oauth2/v3/userinfo',
            'scope'     => 'openid email profile',
        ],
    ];
    return $spec[$provider] ?? null;
}

/** config.php 의 social.{provider} — 키가 없으면 그 제공자는 꺼진 것으로 본다 */
function egg_social_cfg(string $provider): ?array
{
    $c = egg_config()['social'][$provider] ?? null;
    if (!is_array($c)) return null;
    if (($c['client_id'] ?? '') === '') return null;
    return $c;
}

/** 앱에 보여 줄 제공자 목록 — 키가 실제로 들어간 것만 버튼이 뜬다 */
function egg_social_enabled(): array
{
    $out = [];
    foreach (['kakao', 'naver', 'google'] as $p) {
        if (egg_social_cfg($p)) $out[] = $p;
    }
    // 애플은 client_secret 이 없어도 된다 — iOS 네이티브가 토큰을 만들고 여기선 서명만 본다
    if ((egg_config()['social']['apple']['bundle_id'] ?? '') !== '') $out[] = 'apple';
    return $out;
}

// ─────────────────────────────────────────────────────────── 스키마

/** 인증 관련 테이블 — bootstrap 을 건드리지 않고 여기서만 만든다 */
function egg_auth_schema(): PDO
{
    static $done = false;
    $db = egg_db();
    if ($done) return $db;

    // MySQL 에서는 스키마를 db_mysql.php 가 한곳에서 만든다(아래 DDL 은 SQLite 전용 문법이다)
    if (function_exists('egg_is_mysql') && egg_is_mysql()) { $done = true; return $db; }

    // 회원 — 식별자는 이메일이 아니라 (provider, social_id) 다.
    // 카카오 이메일은 선택 동의라 아예 안 올 수 있고, 애플은 가린 주소가 온다.
    $db->exec('CREATE TABLE IF NOT EXISTS member (
        id            TEXT PRIMARY KEY,
        provider      TEXT NOT NULL,
        social_id     TEXT NOT NULL,
        email         TEXT,
        name          TEXT,
        avatar_url    TEXT,
        phone         TEXT,
        marketing     INTEGER NOT NULL DEFAULT 0,
        agreed_at     INTEGER,        -- 약관 동의 시각. NULL 이면 아직 가입 미완료(농장 이름 미정)
        created_at    INTEGER NOT NULL,
        last_login_at INTEGER
    )');
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS ux_member_social ON member (provider, social_id)');

    $db->exec('CREATE TABLE IF NOT EXISTS member_session (
        token      TEXT PRIMARY KEY,
        user_id    TEXT NOT NULL,
        issued_at  INTEGER NOT NULL,
        expires_at INTEGER NOT NULL
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS ix_session_user ON member_session (user_id)');

    // 인가 요청 상태(CSRF 방지) — 콜백이 이 값을 확인해야 우리가 시작한 요청으로 인정한다
    $db->exec('CREATE TABLE IF NOT EXISTS oauth_state (
        state      TEXT PRIMARY KEY,
        provider   TEXT NOT NULL,
        created_at INTEGER NOT NULL,
        used_at    INTEGER
    )');

    // 앱으로 돌려보낼 1회용 티켓
    $db->exec('CREATE TABLE IF NOT EXISTS auth_ticket (
        ticket     TEXT PRIMARY KEY,
        user_id    TEXT NOT NULL,
        created_at INTEGER NOT NULL,
        used_at    INTEGER
    )');

    $done = true;
    return $db;
}

// ─────────────────────────────────────────────────────────── HTTP

/** @return array{code:int, body:string} */
function egg_http(string $method, string $url, array $opt = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => $opt['headers'] ?? [],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['form'] ?? []));
    }
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => is_string($body) ? $body : ''];
}

// ─────────────────────────────────────────────────────────── 인가 → 프로필

/** 인가 화면 URL 을 만들고 state 를 저장한다 */
function egg_auth_start_url(string $provider): array
{
    $spec = egg_oauth_spec($provider);
    $cfg  = egg_social_cfg($provider);
    if (!$spec) return ['ok' => false, 'error' => 'unknown_provider'];
    if (!$cfg)  return ['ok' => false, 'error' => 'provider_not_configured'];

    $state = bin2hex(random_bytes(16));
    egg_auth_schema()->prepare('INSERT INTO oauth_state (state, provider, created_at) VALUES (?,?,?)')
        ->execute([$state, $provider, time()]);

    $q = [
        'response_type' => 'code',
        'client_id'     => $cfg['client_id'],
        'redirect_uri'  => egg_redirect_uri($provider),
        'state'         => $state,
    ];
    if (($spec['scope'] ?? '') !== '') $q['scope'] = $spec['scope'];

    return ['ok' => true, 'url' => $spec['authorize'] . '?' . http_build_query($q), 'state' => $state];
}

/** 콜백의 state 를 확인하고 소비한다(재사용 차단) */
function egg_auth_take_state(string $state, string $provider): bool
{
    $db = egg_auth_schema();
    $st = $db->prepare('SELECT * FROM oauth_state WHERE state = ?');
    $st->execute([$state]);
    $r = $st->fetch();
    if (!$r || $r['used_at'] !== null) return false;
    if ($r['provider'] !== $provider) return false;
    if (time() - (int)$r['created_at'] > EGG_STATE_TTL) return false;
    $db->prepare('UPDATE oauth_state SET used_at = ? WHERE state = ?')->execute([time(), $state]);
    return true;
}

/** 인가 코드 → 액세스 토큰 */
function egg_auth_exchange_code(string $provider, string $code): array
{
    $spec = egg_oauth_spec($provider);
    $cfg  = egg_social_cfg($provider);
    if (!$spec || !$cfg) return ['ok' => false, 'error' => 'provider_not_configured'];

    $form = [
        'grant_type'   => 'authorization_code',
        'client_id'    => $cfg['client_id'],
        'redirect_uri' => egg_redirect_uri($provider),
        'code'         => $code,
    ];
    if (($cfg['client_secret'] ?? '') !== '') $form['client_secret'] = $cfg['client_secret'];

    $r = egg_http('POST', $spec['token'], ['form' => $form]);
    $j = json_decode($r['body'], true);
    $token = $j['access_token'] ?? '';
    if ($r['code'] !== 200 || $token === '') {
        // 네이버는 200 을 주면서 본문에 error 를 넣는다
        $msg = $j['error_description'] ?? ($j['error'] ?? ('http_' . $r['code']));
        return ['ok' => false, 'error' => 'token_failed', 'detail' => (string)$msg];
    }
    return ['ok' => true, 'access_token' => (string)$token];
}

/** 액세스 토큰 → 프로필(제공자마다 다른 응답을 하나로 맞춘다) */
function egg_auth_profile(string $provider, string $accessToken): array
{
    $spec = egg_oauth_spec($provider);
    if (!$spec) return ['ok' => false, 'error' => 'unknown_provider'];

    $r = egg_http('GET', $spec['profile'], ['headers' => ['Authorization: Bearer ' . $accessToken]]);
    $j = json_decode($r['body'], true);
    if ($r['code'] !== 200 || !is_array($j)) return ['ok' => false, 'error' => 'profile_failed'];

    if ($provider === 'kakao') {
        $acc = $j['kakao_account'] ?? [];
        $pr  = $acc['profile'] ?? [];
        return ['ok' => true, 'profile' => [
            'id'     => (string)($j['id'] ?? ''),
            'email'  => $acc['email'] ?? null,
            'name'   => $pr['nickname'] ?? null,
            'avatar' => $pr['profile_image_url'] ?? null,
        ]];
    }
    if ($provider === 'naver') {
        $p = $j['response'] ?? [];
        return ['ok' => true, 'profile' => [
            'id'     => (string)($p['id'] ?? ''),
            'email'  => $p['email'] ?? null,
            'name'   => $p['nickname'] ?? ($p['name'] ?? null),
            'avatar' => $p['profile_image'] ?? null,
        ]];
    }
    // google (OpenID userinfo)
    return ['ok' => true, 'profile' => [
        'id'     => (string)($j['sub'] ?? ''),
        'email'  => $j['email'] ?? null,
        'name'   => $j['name'] ?? null,
        'avatar' => $j['picture'] ?? null,
    ]];
}

// ─────────────────────────────────────────────────────────── 회원·세션

/** 소셜 프로필로 회원을 찾거나 만든다 */
function egg_member_upsert(string $provider, array $profile): array
{
    $db = egg_auth_schema();
    $socialId = (string)($profile['id'] ?? '');
    if ($socialId === '') throw new RuntimeException('social_id 없음');

    // 회원은 앱 단위다 — 같은 카카오 계정이라도 앱이 다르면 다른 회원으로 본다
    $app = egg_app();
    $st = $db->prepare('SELECT * FROM member WHERE app = ? AND provider = ? AND social_id = ?');
    $st->execute([$app, $provider, $socialId]);
    $m = $st->fetch();
    $now = time();

    if ($m) {
        // 이름은 덮지 않는다 — 사용자가 정한 농장 이름을 소셜 닉네임으로 되돌리면 안 된다.
        $db->prepare('UPDATE member SET last_login_at = ?,
                        email = COALESCE(NULLIF(email, ""), ?),
                        avatar_url = COALESCE(?, avatar_url)
                      WHERE id = ?')
           ->execute([$now, $profile['email'] ?? null, $profile['avatar'] ?? null, $m['id']]);
    } else {
        $id = 'mem_' . bin2hex(random_bytes(8));
        $db->prepare('INSERT INTO member (id, app, provider, social_id, email, name, avatar_url, created_at, last_login_at)
                      VALUES (?,?,?,?,?,?,?,?,?)')
           ->execute([$id, $app, $provider, $socialId, $profile['email'] ?? null, $profile['name'] ?? null,
                      $profile['avatar'] ?? null, $now, $now]);
    }
    $st->execute([$app, $provider, $socialId]);
    return $st->fetch();
}

function egg_ticket_issue(string $userId): string
{
    $t = bin2hex(random_bytes(24));
    egg_auth_schema()->prepare('INSERT INTO auth_ticket (ticket, user_id, created_at) VALUES (?,?,?)')
        ->execute([$t, $userId, time()]);
    return $t;
}

/** 티켓을 회원 ID 로 바꾼다(1회용) */
function egg_ticket_take(string $ticket): ?string
{
    $db = egg_auth_schema();
    $st = $db->prepare('SELECT * FROM auth_ticket WHERE ticket = ?');
    $st->execute([$ticket]);
    $r = $st->fetch();
    if (!$r || $r['used_at'] !== null) return null;
    if (time() - (int)$r['created_at'] > EGG_TICKET_TTL) return null;
    $db->prepare('UPDATE auth_ticket SET used_at = ? WHERE ticket = ?')->execute([time(), $ticket]);
    return (string)$r['user_id'];
}

function egg_session_issue(string $userId): array
{
    $token = bin2hex(random_bytes(32));
    $now = time();
    $exp = $now + EGG_SESSION_TTL;
    egg_auth_schema()->prepare('INSERT INTO member_session (token, user_id, issued_at, expires_at) VALUES (?,?,?,?)')
        ->execute([$token, $userId, $now, $exp]);
    return ['token' => $token, 'issuedAt' => $now, 'expiresAt' => $exp];
}

/** Authorization: Bearer 로 온 세션을 확인한다 */
function egg_session_member(): ?array
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/Bearer\s+([0-9a-f]{64})/i', (string)$h, $m)) return null;
    $st = egg_auth_schema()->prepare('SELECT m.* FROM member_session s JOIN member m ON m.id = s.user_id
                                     WHERE s.token = ? AND s.expires_at > ?');
    $st->execute([$m[1], time()]);
    $row = $st->fetch();
    return $row ?: null;
}

/** 세션을 끊는다(로그아웃) */
function egg_session_revoke(): void
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/Bearer\s+([0-9a-f]{64})/i', (string)$h, $m)) return;
    egg_auth_schema()->prepare('DELETE FROM member_session WHERE token = ?')->execute([$m[1]]);
}

/** 앱에 내려보낼 회원 표현 — 내부 컬럼을 그대로 내보내지 않는다 */
function egg_member_public(array $m): array
{
    return [
        'id'        => $m['id'],
        'provider'  => $m['provider'],
        'email'     => $m['email'] ?: null,
        'name'      => $m['name'] ?: null,
        'avatarUrl' => $m['avatar_url'] ?: null,
        'phone'     => $m['phone'] ?: null,
        'marketing' => (bool)$m['marketing'],
        // 약관 동의가 없으면 앱이 가입 화면(농장 이름·약관)을 띄운다
        'needsSignup' => $m['agreed_at'] === null,
        'createdAt'   => (int)$m['created_at'],
    ];
}

/** 앱으로 돌아가는 302 — 성공은 티켓, 실패는 사유만 싣는다 */
function egg_return_to_app(array $params): never
{
    header('Location: ' . EGG_APP_RETURN . '?' . http_build_query($params));
    http_response_code(302);
    exit;
}

// ─────────────────────────────────────────────────────────── 애플

/** 애플 공개키(JWKS) — 하루 캐시 */
function egg_apple_keys(bool $force = false): array
{
    $fresh = is_file(EGG_APPLE_KEYS) && (time() - (int)filemtime(EGG_APPLE_KEYS) < 86400);
    if (!$force && $fresh) {
        $j = json_decode((string)file_get_contents(EGG_APPLE_KEYS), true);
        if (is_array($j['keys'] ?? null)) return $j['keys'];
    }
    $r = egg_http('GET', 'https://appleid.apple.com/auth/keys');
    $j = json_decode($r['body'], true);
    if (is_array($j['keys'] ?? null)) {
        @file_put_contents(EGG_APPLE_KEYS, $r['body']);
        return $j['keys'];
    }
    if (is_file(EGG_APPLE_KEYS)) {                     // 내려받기 실패 시 만료된 캐시라도 쓴다
        $j = json_decode((string)file_get_contents(EGG_APPLE_KEYS), true);
        if (is_array($j['keys'] ?? null)) return $j['keys'];
    }
    return [];
}

function egg_b64url(string $s): string
{
    return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

/** DER 길이 바이트 */
function egg_der_len(int $len): string
{
    if ($len < 128) return chr($len);
    $s = '';
    while ($len > 0) { $s = chr($len & 0xff) . $s; $len >>= 8; }
    return chr(0x80 | strlen($s)) . $s;
}

/** JWK(n,e) → PEM 공개키. RSA 공개키 DER 을 직접 만든다(JWT 라이브러리 없이) */
function egg_rsa_pem(string $nB64, string $eB64): ?string
{
    $n = egg_b64url($nB64);
    $e = egg_b64url($eB64);
    if ($n === '' || $e === '') return null;

    $int = static function (string $b): string {
        if ($b === '') return "\x02\x01\x00";
        if (ord($b[0]) > 0x7f) $b = "\x00" . $b;       // 최상위 비트가 서면 부호 바이트를 넣는다
        return "\x02" . egg_der_len(strlen($b)) . $b;
    };
    $wrap = static fn(string $body, string $tag): string => $tag . egg_der_len(strlen($body)) . $body;

    $rsaKey = $wrap($int($n) . $int($e), "\x30");
    $bitStr = $wrap("\x00" . $rsaKey, "\x03");
    $algId  = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";  // rsaEncryption + NULL
    $der    = $wrap($algId . $bitStr, "\x30");

    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/**
 * 애플 identityToken(JWT) 검증 → 프로필.
 * 애플은 client_secret 이 필요 없다 — 네이티브가 만든 토큰의 서명만 공개키로 확인한다.
 */
function egg_apple_verify(string $jwt): array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return ['ok' => false, 'error' => 'bad_token'];
    [$h64, $p64, $s64] = $parts;

    $head = json_decode(egg_b64url($h64), true);
    $body = json_decode(egg_b64url($p64), true);
    $sig  = egg_b64url($s64);
    if (!is_array($head) || !is_array($body) || $sig === '') return ['ok' => false, 'error' => 'bad_token'];

    foreach ([false, true] as $force) {                 // 키를 못 찾으면 한 번 갱신해 재시도
        foreach (egg_apple_keys($force) as $k) {
            if (($k['kid'] ?? '') !== ($head['kid'] ?? '')) continue;
            $pem = egg_rsa_pem((string)($k['n'] ?? ''), (string)($k['e'] ?? ''));
            if (!$pem) continue;
            if (openssl_verify("$h64.$p64", $sig, $pem, OPENSSL_ALGO_SHA256) !== 1) {
                return ['ok' => false, 'error' => 'bad_signature'];
            }
            if (($body['iss'] ?? '') !== 'https://appleid.apple.com') return ['ok' => false, 'error' => 'bad_issuer'];
            $bundle = egg_config()['social']['apple']['bundle_id'] ?? '';
            if ($bundle !== '' && ($body['aud'] ?? '') !== $bundle) return ['ok' => false, 'error' => 'bad_audience'];
            if ((int)($body['exp'] ?? 0) < time()) return ['ok' => false, 'error' => 'expired'];

            return ['ok' => true, 'profile' => [
                'id'     => (string)($body['sub'] ?? ''),
                'email'  => $body['email'] ?? null,
                'name'   => null,        // 애플은 최초 1회만 이름을 주며, 그건 앱이 따로 보낸다
                'avatar' => null,
            ]];
        }
    }
    return ['ok' => false, 'error' => 'key_not_found'];
}
