<?php
/**
 * MySQL 연결과 스키마.
 *
 * SQLite 로 시작했지만 회원·포인트가 붙으면서 한계가 보인다 — 행 수가 아니라 **쓰기가 한 번에
 * 하나**라, 앱 트래픽과 어드민 조회가 겹치면 거기서 막힌다(테이블을 월별로 쪼개도 같은 파일·
 * 같은 락이라 안 풀린다). 출시 전이라 옮길 실사용 데이터가 없는 지금이 가장 싼 시점이다.
 *
 * **전환은 config.php 에 'db' 를 넣는 순간 일어난다.** 없으면 egg_db() 가 예전처럼 SQLite 를
 * 연다 — 다른 작업이 도중에 깨지지 않게 하려는 것이다.
 *
 *   'db' => ['host'=>'localhost', 'name'=>'kkokkofarm', 'user'=>'...', 'pass'=>'...'],
 *
 * SQLite DDL 을 문자열 치환으로 바꾸지 않고 여기 따로 적는다. 인덱스에 쓰는 컬럼은 MySQL 에서
 * TEXT 로 두면 길이를 요구해 실패하므로 VARCHAR 로 못박아야 하고, 부분 인덱스(WHERE)는 아예
 * 없다. 자동 변환으로 맞추려다 조용히 어긋나느니 명시하는 편이 낫다.
 */
declare(strict_types=1);

/** utf8mb4 에서 인덱스 길이가 안전한 상한 */
const EGG_VC = 191;

function egg_mysql_connect(array $cfg): PDO
{
    $host = $cfg['host'] ?? 'localhost';
    $name = $cfg['name'] ?? '';
    $port = (int)($cfg['port'] ?? 3306);
    $sock = $cfg['socket'] ?? '';

    $dsn = $sock !== ''
        ? "mysql:unix_socket=$sock;dbname=$name;charset=utf8mb4"
        : "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";

    $db = new PDO($dsn, (string)($cfg['user'] ?? ''), (string)($cfg['pass'] ?? ''), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    egg_mysql_schema($db);
    return $db;
}

/**
 * 스키마. CREATE TABLE 안에 인덱스를 함께 적는다 —
 * MySQL 은 `CREATE INDEX IF NOT EXISTS` 를 받지 않아 따로 만들면 재실행 때마다 터진다.
 */
function egg_mysql_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;

    $vc = EGG_VC;

    // ── 광고 보상 원장 — transaction_id 가 기본키라 콜백이 다시 와도 한 번만 쌓인다
    $db->exec("CREATE TABLE IF NOT EXISTS ad_reward (
        transaction_id VARCHAR($vc) NOT NULL PRIMARY KEY,
        user_id        VARCHAR(64)  NOT NULL,
        ad_unit        VARCHAR($vc),
        reward_item    VARCHAR(64),
        reward_amount  INT,
        custom_data    TEXT,
        created_at     BIGINT NOT NULL,
        claimed_at     BIGINT,
        KEY ix_reward_user (user_id, claimed_at),
        KEY ix_reward_day  (user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 기프티쇼 상품 캐시(전체 목록 API 하나뿐이라 받아 두고 여기서 검색·전시한다)
    $db->exec("CREATE TABLE IF NOT EXISTS gs_goods (
        goods_code     VARCHAR(64) NOT NULL PRIMARY KEY,
        goods_name     VARCHAR(255) NOT NULL,
        brand_name     VARCHAR(255),
        brand_code     VARCHAR(64),
        affiliate      VARCHAR(255),
        sale_price     INT,
        discount_price INT,
        img_s          VARCHAR(500),
        img_b          VARCHAR(500),
        valid_days     INT,
        type_dtl       VARCHAR(255),
        category1      INT,
        state_cd       VARCHAR(16),
        synced_at      BIGINT NOT NULL,
        KEY ix_goods_state (state_cd, sale_price)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 교환 원장 — tr_id 가 기본키라 같은 주문으로 두 번 발송되지 않는다
    $db->exec("CREATE TABLE IF NOT EXISTS gs_order (
        tr_id       VARCHAR(32) NOT NULL PRIMARY KEY,
        user_id     VARCHAR(64) NOT NULL,
        goods_code  VARCHAR(64) NOT NULL,
        point_price INT NOT NULL,
        status      VARCHAR(16) NOT NULL,
        order_no    VARCHAR(64),
        pin_no      VARCHAR(64),
        coupon_img  VARCHAR(500),
        valid_end   VARCHAR(16),
        err_code    VARCHAR(32),
        err_msg     VARCHAR(500),
        created_at  BIGINT NOT NULL,
        sent_at     BIGINT,
        canceled_at BIGINT,
        KEY ix_order_user (user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 광고 콜백 감사 로그 — 거절된 것도 남긴다(보존기간을 두고 정리한다)
    $db->exec("CREATE TABLE IF NOT EXISTS ssv_log (
        id      BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        at      BIGINT NOT NULL,
        ok      TINYINT NOT NULL,
        reason  VARCHAR($vc),
        user_id VARCHAR(64),
        qs      TEXT,
        KEY ix_ssv_at (at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 회원 — 식별자는 이메일이 아니라 (provider, social_id) 다
    $db->exec("CREATE TABLE IF NOT EXISTS member (
        id            VARCHAR(64) NOT NULL PRIMARY KEY,
        provider      VARCHAR(16) NOT NULL,
        social_id     VARCHAR($vc) NOT NULL,
        email         VARCHAR($vc),
        name          VARCHAR(100),
        avatar_url    VARCHAR(500),
        phone         VARCHAR(32),
        marketing     TINYINT NOT NULL DEFAULT 0,
        agreed_at     BIGINT,
        points        BIGINT NOT NULL DEFAULT 0,
        created_at    BIGINT NOT NULL,
        last_login_at BIGINT,
        UNIQUE KEY ux_member_social (provider, social_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS member_session (
        token      CHAR(64) NOT NULL PRIMARY KEY,
        user_id    VARCHAR(64) NOT NULL,
        issued_at  BIGINT NOT NULL,
        expires_at BIGINT NOT NULL,
        KEY ix_session_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS oauth_state (
        state      CHAR(32) NOT NULL PRIMARY KEY,
        provider   VARCHAR(16) NOT NULL,
        created_at BIGINT NOT NULL,
        used_at    BIGINT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS auth_ticket (
        ticket     CHAR(48) NOT NULL PRIMARY KEY,
        user_id    VARCHAR(64) NOT NULL,
        created_at BIGINT NOT NULL,
        used_at    BIGINT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 포인트 원장. 잔액은 member.points 에 캐시하고 여기엔 이력을 쌓는다.
    // ref 는 같은 근거로 두 번 적립되지 않게 막는다. SQLite 는 부분 인덱스(WHERE ref IS NOT NULL)
    // 로 처리했지만 MySQL 에는 없다 — NULL 이 여럿 있어도 UNIQUE 가 걸리지 않는 성질을 그대로 쓴다.
    $db->exec("CREATE TABLE IF NOT EXISTS point_ledger (
        id         BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id    VARCHAR(64) NOT NULL,
        delta      INT NOT NULL,
        balance    BIGINT NOT NULL,
        kind       VARCHAR(16) NOT NULL,
        title      VARCHAR($vc) NOT NULL,
        ref        VARCHAR($vc) DEFAULT NULL,
        created_at BIGINT NOT NULL,
        KEY ix_ledger_user (user_id, id),
        KEY ix_ledger_day (created_at),
        UNIQUE KEY ux_ledger_ref (kind, ref)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $done = true;
}

/** 지금 연결이 MySQL 인지 — 드라이버마다 다른 구문을 쓸 때 판별용 */
function egg_is_mysql(): bool
{
    $c = egg_config()['db'] ?? null;
    return is_array($c) && (($c['name'] ?? '') !== '');
}
