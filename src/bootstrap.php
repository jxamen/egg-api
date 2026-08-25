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

    // config.php 에 'db' 가 있으면 MySQL 을 쓴다. 없으면 예전처럼 SQLite —
    // 전환 도중에 다른 작업이 깨지지 않게 두 경로를 함께 둔다(사정은 src/db_mysql.php).
    $mysql = egg_config()['db'] ?? null;
    if (is_array($mysql) && ($mysql['name'] ?? '') !== '') {
        require_once __DIR__ . '/db_mysql.php';
        return $db = egg_mysql_connect($mysql);
    }

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

    // 기프티쇼 상품 캐시 — 규격서 FAQ 대로 전체 목록을 받아 두고 검색·전시는 여기서 한다
    // (카테고리 검색 API 가 없고, 매 요청마다 호출하면 느려진다)
    $db->exec('CREATE TABLE IF NOT EXISTS gs_goods (
        goods_code     TEXT PRIMARY KEY,
        goods_name     TEXT NOT NULL,
        brand_name     TEXT,
        brand_code     TEXT,
        affiliate      TEXT,
        sale_price     INTEGER,      -- 액면가. 이용자에게 받을 포인트의 기준
        discount_price INTEGER,      -- 우리가 지불하는 금액(등급할인 적용) — 앱에 내보내지 않는다
        img_s          TEXT,
        img_b          TEXT,
        valid_days     INTEGER,      -- limitDay
        type_dtl       TEXT,         -- goodsTypeDtlNm (편의점·카페 …)
        category1      INTEGER,
        state_cd       TEXT,         -- SALE / SUS
        synced_at      INTEGER NOT NULL
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS ix_goods_state ON gs_goods (state_cd, sale_price)');

    // 교환 원장 — tr_id 가 기본키라 같은 주문으로 두 번 발송되지 않는다
    $db->exec('CREATE TABLE IF NOT EXISTS gs_order (
        tr_id       TEXT PRIMARY KEY,
        user_id     TEXT NOT NULL,
        goods_code  TEXT NOT NULL,
        point_price INTEGER NOT NULL,
        status      TEXT NOT NULL,   -- pending / sent / failed / canceled
        order_no    TEXT,
        pin_no      TEXT,
        coupon_img  TEXT,
        valid_end   TEXT,
        err_code    TEXT,
        err_msg     TEXT,
        created_at  INTEGER NOT NULL,
        sent_at     INTEGER,
        canceled_at INTEGER
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS ix_order_user ON gs_order (user_id, created_at)');

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
/** CORS — 앱(네이티브)은 무관하지만 웹 빌드(Expo web)가 다른 오리진에서 부른다.
 *  X-Egg-Key 커스텀 헤더 때문에 브라우저가 preflight(OPTIONS)를 먼저 보낸다. */
function egg_cors(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: X-Egg-Key, Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
}

function egg_json(int $code, array $body): never
{
    http_response_code($code);
    egg_cors();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/** 앱 전용 엔드포인트 보호 — 실제 회원 세션이 붙기 전까지 쓰는 임시 앱 키 */
function egg_require_app_key(): void
{
    // preflight 는 키 없이 온다 — 검사 전에 빈 응답으로 통과시킨다
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        egg_cors();
        http_response_code(204);
        exit;
    }
    $cfg = egg_config();
    $key = $cfg['app_key'] ?? '';
    $got = $_SERVER['HTTP_X_EGG_KEY'] ?? '';
    if ($key === '' || !hash_equals($key, $got)) {
        egg_json(401, ['ok' => false, 'error' => 'unauthorized']);
    }
}

/**
 * 드라이버마다 다른 SQL 조각. SQLite 와 MySQL 은 "있으면 무시/갱신" 구문이 서로 다르고
 * 호환되는 표기가 없다 — 문자열로 바꿔치기하다 조용히 어긋나느니 여기서 한 번에 고른다.
 */
function egg_sql_insert_ignore(): string
{
    return (function_exists('egg_is_mysql') && egg_is_mysql()) ? 'INSERT IGNORE INTO' : 'INSERT OR IGNORE INTO';
}

/** unix 초 → 날짜 문자열(YYYY-MM-DD, KST). 일별 집계에 쓴다 */
function egg_sql_date(string $col): string
{
    return (function_exists('egg_is_mysql') && egg_is_mysql())
        ? "DATE(CONVERT_TZ(FROM_UNIXTIME($col), '+00:00', '+09:00'))"
        : "date($col, 'unixepoch', 'localtime')";
}

/**
 * 어느 앱의 데이터인가.
 *
 * 서비스가 앱 하나로 끝나지 않는다 — 공지·FAQ·약관·회원이 앱마다 따로 관리돼야 한다.
 * 지금은 앱이 하나라 config 기본값을 쓰고, 조회는 app 파라미터로 고를 수 있게 열어 둔다.
 * **앱이 늘면 앱 키마다 앱을 매핑해 이 값을 서버가 정하도록 바꿀 것**(파라미터는 위조된다).
 */
function egg_app(): string
{
    $a = (string)($_GET['app'] ?? ($_POST['app'] ?? ''));
    if ($a !== '' && preg_match('/^[a-z0-9_-]{2,32}$/', $a)) return $a;
    return (string)(egg_config()['app'] ?? 'kkokkofarm');
}

/** KST 기준 오늘 0시 (하루 한도 계산용) */
function egg_today_start(): int
{
    return (new DateTimeImmutable('today', new DateTimeZone('Asia/Seoul')))->getTimestamp();
}
