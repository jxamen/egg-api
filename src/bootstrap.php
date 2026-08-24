<?php
/**
 * egg-api 공통 부트스트랩 — 설정·SQLite 연결·스키마.
 * 문서 루트(public) 밖에 있어 웹에서 직접 열 수 없다.
 */
declare(strict_types=1);

const EGG_ROOT   = __DIR__ . '/..';
const EGG_VAR    = EGG_ROOT . '/var';
const EGG_DB     = EGG_VAR . '/egg.sqlite';
const EGG_KEYS   = EGG_VAR . '/verifier-keys.json';
const EGG_LOG    = EGG_VAR . '/ssv.log';

/** 광고 1회 보상 — 콜백이 보내는 reward_amount 는 믿지 않고 서버가 정한다 */
const REWARD_ITEM   = '사료';
const REWARD_AMOUNT = 1;
/** 사용자당 하루 최대 지급 횟수(앱 표시값과 같아야 한다) */
const DAILY_LIMIT   = 5;
/** 콜백 유효 시간(초) — 오래된 재전송은 버린다 */
const MAX_AGE_SEC   = 600;

function egg_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = EGG_ROOT . '/config.php';
        $cfg = is_file($file) ? require $file : [];
    }
    return $cfg;
}

function egg_db(): PDO
{
    static $db = null;
    if ($db !== null) return $db;

    if (!is_dir(EGG_VAR)) mkdir(EGG_VAR, 0775, true);
    $db = new PDO('sqlite:' . EGG_DB, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA busy_timeout = 3000');

    // 지급 원장 — transaction_id 가 기본키라 같은 콜백이 여러 번 와도 한 번만 쌓인다
    $db->exec('CREATE TABLE IF NOT EXISTS ad_reward (
        transaction_id TEXT PRIMARY KEY,
        user_id        TEXT NOT NULL,
        ad_unit        TEXT,
        reward_item    TEXT,
        reward_amount  INTEGER,
        custom_data    TEXT,
        created_at     INTEGER NOT NULL,
        claimed_at     INTEGER
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS ix_reward_user ON ad_reward (user_id, claimed_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS ix_reward_day  ON ad_reward (user_id, created_at)');

    // 콜백 감사 로그 — 거절된 것도 남긴다
    $db->exec('CREATE TABLE IF NOT EXISTS ssv_log (
        id      INTEGER PRIMARY KEY AUTOINCREMENT,
        at      INTEGER NOT NULL,
        ok      INTEGER NOT NULL,
        reason  TEXT,
        user_id TEXT,
        qs      TEXT
    )');
    return $db;
}

function egg_log(bool $ok, string $reason, ?string $userId, string $qs): void
{
    try {
        $st = egg_db()->prepare('INSERT INTO ssv_log (at, ok, reason, user_id, qs) VALUES (?,?,?,?,?)');
        $st->execute([time(), $ok ? 1 : 0, $reason, $userId, substr($qs, 0, 2000)]);
    } catch (Throwable $e) {
        // DB 가 죽어도 콜백 응답은 막지 않는다
    }
    @file_put_contents(EGG_LOG, sprintf("%s %s %s %s\n", date('c'), $ok ? 'OK ' : 'REJ', $reason, $qs), FILE_APPEND);
}

/** JSON 응답 후 종료 */
function egg_json(int $code, array $body): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/** 앱 전용 엔드포인트 보호 — 실제 회원 세션이 붙기 전까지 쓰는 임시 앱 키 */
function egg_require_app_key(): void
{
    $cfg = egg_config();
    $key = $cfg['app_key'] ?? '';
    $got = $_SERVER['HTTP_X_EGG_KEY'] ?? '';
    if ($key === '' || !hash_equals($key, $got)) {
        egg_json(401, ['ok' => false, 'error' => 'unauthorized']);
    }
}

/** KST 기준 오늘 0시 (하루 한도 계산용) */
function egg_today_start(): int
{
    return (new DateTimeImmutable('today', new DateTimeZone('Asia/Seoul')))->getTimestamp();
}
