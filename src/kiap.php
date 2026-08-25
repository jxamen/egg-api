<?php
/**
 * KICA KIAP(통합간편인증) 클라이언트 — insurance-db 구현의 PHP 이식.
 * 규격: SecuKit One-S 연동규격서 V1.6. 검증 원칙은 fail-closed(불확실하면 전부 거절).
 *
 * 서버 몫은 두 가지뿐이다:
 *   1) 콜백이 준 동적 access_token 으로 getResult 1회 POST
 *   2) 응답 필드(name/phone/birthday/ci) AES/CBC 복호화 — 키=base64(auth_token), IV 고정
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** 인증 세션 유효 시간(초) — 페이지 열림 → 콜백까지 */
const KIAP_SESSION_TTL = 900;
/** 결과 회수 토큰 유효 시간(초) — 콜백 → 앱의 result 교환까지 */
const KIAP_RESULT_TTL = 120;
/** 같은 사용자의 재시작 최소 간격(초) */
const KIAP_INIT_RATE = 5;
/** getResult 타임아웃(ms) — KICA 권장 5000 */
const KIAP_TIMEOUT_MS = 5000;
/** 앱 복귀 스킴 화이트리스트 — 여기 없는 ret 은 거절 */
const KIAP_RET_ALLOW = ['kkokkofarm://'];
/** 노출할 인증 기관 (insurance-db 와 동일) */
const KIAP_PROVIDERS = ['KAKAO' => '카카오', 'NAVER' => '네이버', 'TOSS' => '토스'];

/** 자격증명 — config.php 의 kiap 블록 우선, 없으면 /ops?do=set-kiap 가 쓴 var/kiap.json */
function kiap_config(): array
{
    $c = egg_config()['kiap'] ?? null;
    if (!is_array($c) || ($c['client_id'] ?? '') === '') {
        $f = EGG_VAR . '/kiap.json';
        $c = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    }
    return [
        'host'         => rtrim((string)($c['host'] ?? ''), '/'),
        'client_id'    => (string)($c['client_id'] ?? ''),
        'access_token' => (string)($c['access_token'] ?? ''),
    ];
}

function kiap_enabled(): bool
{
    $c = kiap_config();
    return $c['host'] !== '' && $c['client_id'] !== '' && $c['access_token'] !== '';
}

/** CI 해시 파생키 — 최초 사용 시 생성해 var/ 에 고정(바뀌면 기존 해시가 전부 무효라 절대 재생성 금지) */
function kiap_ci_key(): string
{
    $f = EGG_VAR . '/ci_key';
    if (is_file($f)) return trim((string)file_get_contents($f));
    if (!is_dir(EGG_VAR)) mkdir(EGG_VAR, 0700, true);
    $k = bin2hex(random_bytes(32));
    file_put_contents($f, $k);
    @chmod($f, 0600);
    return $k;
}

function kiap_db(): PDO
{
    static $done = false;
    $db = egg_db();
    if (!$done) {
        // 인증 세션 — 페이지가 만들고 콜백이 채운다. ret 은 세션에 묶는다(콜백 파라미터로 못 바꾸게).
        $db->exec('CREATE TABLE IF NOT EXISTS kiap_session (
            sid            TEXT PRIMARY KEY,
            uid            TEXT NOT NULL,
            ret            TEXT NOT NULL,
            status         TEXT NOT NULL,   -- pending / verified / consumed / failed
            provider       TEXT,
            name           TEXT,
            phone          TEXT,
            ci_hash        TEXT,
            fail_reason    TEXT,
            result_token   TEXT,
            result_expires INTEGER,
            created_at     INTEGER NOT NULL,
            verified_at    INTEGER
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS ix_kiap_uid ON kiap_session (uid, created_at)');
        // CI 원장 — 1 CI = 1 계정(반복 수령 차단). 평문 CI 는 저장하지 않는다.
        $db->exec('CREATE TABLE IF NOT EXISTS kiap_ci (
            ci_hash     TEXT PRIMARY KEY,
            uid         TEXT NOT NULL,
            verified_at INTEGER NOT NULL
        )');
        $done = true;
    }
    return $db;
}

/** getResult — Bearer 는 콜백 form 의 **동적** access_token(콘솔 정적 토큰이면 401). */
function kiap_get_result(string $accessToken, string $providerId, string $clientTxId, string $serverTxId): array
{
    $host = kiap_config()['host'];
    $ch = curl_init($host . '/kiap-service/api/v1/getResult');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'provider_id'  => $providerId,
            'client_tx_id' => $clientTxId,
            'server_tx_id' => $serverTxId,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS     => KIAP_TIMEOUT_MS,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Bearer ' . $accessToken,
        ],
    ]);
    $raw  = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) return ['ok' => false, 'error' => 'KIAP_TIMEOUT'];
    if ($http !== 200)  return ['ok' => false, 'error' => 'KIAP_HTTP_' . $http];
    $j = json_decode((string)$raw, true);
    if (!is_array($j)) return ['ok' => false, 'error' => 'KIAP_BADJSON'];
    if (($j['code'] ?? '') !== '0000') return ['ok' => false, 'error' => 'KIAP_' . ($j['code'] ?? 'NOCODE')];
    return ['ok' => true, 'data' => $j];
}

/**
 * 응답 필드 복호화 — AES/CBC/PKCS5, 키=base64_decode(auth_token), IV 고정 'secureiv12345678'.
 * 실패·빈 값은 '' (원인·암호문·키는 절대 밖으로 내지 않는다).
 */
function kiap_decrypt(?string $encB64, string $authTokenB64): string
{
    if ($encB64 === null || $encB64 === '') return '';
    $key = base64_decode($authTokenB64, true);
    if ($key === false || $key === '') return '';
    $cipher = ['16' => 'aes-128-cbc', '24' => 'aes-192-cbc', '32' => 'aes-256-cbc'][(string)strlen($key)] ?? null;
    if ($cipher === null) return '';
    $plain = openssl_decrypt($encB64, $cipher, $key, 0, 'secureiv12345678');
    return $plain === false ? '' : $plain;
}

/** 이름 정규화 — NFC 는 PHP 기본 미지원이라 공백·제어문자 제거만(비교 양쪽 동일 적용이라 충분) */
function kiap_norm_name(string $s): string
{
    if (class_exists('Normalizer')) $s = Normalizer::normalize($s, Normalizer::FORM_C) ?: $s;
    return preg_replace('/[\s\x{200b}-\x{200d}\x{3000}]+/u', '', $s) ?? $s;
}

/** 휴대폰 정규화 — +82/82 → 0, 숫자만 */
function kiap_norm_phone(string $s): string
{
    $d = preg_replace('/[^0-9+]/', '', $s) ?? '';
    if (str_starts_with($d, '+82')) $d = '0' . substr($d, 3);
    elseif (str_starts_with($d, '82') && strlen($d) >= 10) $d = '0' . substr($d, 2);
    return preg_replace('/[^0-9]/', '', $d) ?? '';
}

/** CI 검색 해시 — insurance-db 와 동일 파생: HMAC-SHA256(SHA-256(key:hmac), CI) → base64url */
function kiap_ci_hash(string $ci): string
{
    $hmacKey = hash('sha256', kiap_ci_key() . ':hmac', true);
    $sig = hash_hmac('sha256', $ci, $hmacKey, true);
    return rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
}

/** 마스킹 — 감사 로그에 평문 PII 금지 */
function kiap_mask_name(string $n): string
{
    $n = trim($n);
    $len = mb_strlen($n);
    if ($len === 0) return '';
    if ($len <= 1) return '*';
    if ($len === 2) return mb_substr($n, 0, 1) . '*';
    return mb_substr($n, 0, 1) . str_repeat('*', $len - 2) . mb_substr($n, -1);
}

function kiap_mask_phone(string $p): string
{
    $d = preg_replace('/[^0-9]/', '', $p) ?? '';
    return strlen($d) < 8 ? '****' : substr($d, 0, 3) . '****' . substr($d, -4);
}

/** ret 스킴 검증 — 화이트리스트 접두만 허용 */
function kiap_ret_ok(string $ret): bool
{
    foreach (KIAP_RET_ALLOW as $p) {
        if (str_starts_with($ret, $p)) return true;
    }
    return false;
}
