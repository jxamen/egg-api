<?php
/**
 * 포인트 원장 — 적립·사용을 한 줄씩 남기고, 잔액은 회원 행에 캐시한다.
 *
 * 왜 서버인가: 지금까지 포인트는 앱 로컬 상태(store.tsx 의 `points`)였다. 기프티콘으로 바꾸는
 * 재화를 클라이언트가 정하면 앱을 뜯어 얼마든 만들 수 있고, 기기를 바꾸면 사라진다.
 *
 * 왜 잔액을 따로 두나: 잔액을 매번 SUM(delta) 로 구하면 원장이 쌓일수록 느려진다.
 * 그래서 **원장 append + member.points 갱신을 한 트랜잭션**으로 처리하고, 조회는 회원 행
 * 한 줄만 읽는다(O(1)). 원장의 balance 컬럼은 그 시점 잔액을 남겨 두어 사고가 났을 때
 * 어디서 어긋났는지 되짚을 수 있게 한다.
 *
 * 월별 테이블 분리는 하지 않는다 — SQLite 는 행 수로 느려지지 않고(인덱스가 B-tree),
 * 같은 파일을 쪼개도 쓰기 락은 하나라 이득이 없다. 커지는 것은 감사 로그(ssv_log)이므로
 * 그쪽만 보존기간으로 정리한다(egg_points_prune 참고).
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** 적립 사유 — 화면·어드민에서 이 값으로 묶어 본다 */
const POINT_KINDS = ['ad', 'attend', 'mission', 'invite', 'exchange', 'npay', 'adjust'];

function egg_points_schema(): PDO
{
    static $done = false;
    $db = egg_db();
    if ($done) return $db;

    // MySQL 에서는 스키마를 db_mysql.php 가 한곳에서 만든다(아래 DDL 은 SQLite 전용 문법이다)
    if (function_exists('egg_is_mysql') && egg_is_mysql()) { $done = true; return $db; }

    $db->exec('CREATE TABLE IF NOT EXISTS point_ledger (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id    TEXT NOT NULL,
        delta      INTEGER NOT NULL,   -- +적립 / -사용
        balance    INTEGER NOT NULL,   -- 이 거래 직후 잔액(대사·복구용)
        kind       TEXT NOT NULL,      -- ad / attend / mission / invite / exchange / npay / adjust
        title      TEXT NOT NULL,      -- 이용내역에 그대로 보여 줄 문구
        ref        TEXT,               -- 근거 키(transaction_id · tr_id · mission_id …)
        created_at INTEGER NOT NULL
    )');
    // 이용내역 조회는 "이 회원의 최근부터" 가 전부다 — 이 인덱스 하나로 페이지네이션까지 커버한다
    $db->exec('CREATE INDEX IF NOT EXISTS ix_ledger_user ON point_ledger (user_id, id DESC)');
    // 같은 근거로 두 번 적립되지 않게(광고 콜백 재전송·주문 재시도). ref 가 없으면 제약도 없다
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS ux_ledger_ref ON point_ledger (kind, ref) WHERE ref IS NOT NULL');
    $db->exec('CREATE INDEX IF NOT EXISTS ix_ledger_day ON point_ledger (created_at)');

    // 잔액 캐시 — member 는 auth.php 가 만든다. 이미 있으면 그냥 둔다
    $cols = [];
    foreach ($db->query("PRAGMA table_info(member)") as $c) $cols[] = $c['name'];
    if ($cols && !in_array('points', $cols, true)) {
        $db->exec('ALTER TABLE member ADD COLUMN points INTEGER NOT NULL DEFAULT 0');
    }

    $done = true;
    return $db;
}

/**
 * 포인트를 더하거나 뺀다. 원장 기록과 잔액 갱신이 한 트랜잭션으로 묶인다.
 *
 * @param int    $delta 양수면 적립, 음수면 사용
 * @param string $ref   같은 근거로 두 번 처리되지 않게 하는 키(없으면 null)
 * @return array{ok:bool, balance?:int, error?:string}
 */
function egg_points_add(string $userId, int $delta, string $kind, string $title, ?string $ref = null): array
{
    if ($delta === 0) return ['ok' => false, 'error' => 'zero_delta'];
    if (!in_array($kind, POINT_KINDS, true)) return ['ok' => false, 'error' => 'bad_kind'];

    $db = egg_points_schema();
    try {
        $db->beginTransaction();

        $st = $db->prepare('SELECT points FROM member WHERE id = ?');
        $st->execute([$userId]);
        $row = $st->fetch();
        if (!$row) { $db->rollBack(); return ['ok' => false, 'error' => 'member_not_found']; }

        $balance = (int)$row['points'] + $delta;
        // 잔액이 음수가 되는 차감은 막는다 — 교환은 반드시 차감이 먼저 성공해야 발주로 넘어간다
        if ($balance < 0) { $db->rollBack(); return ['ok' => false, 'error' => 'insufficient', 'balance' => (int)$row['points']]; }

        $db->prepare('INSERT INTO point_ledger (user_id, delta, balance, kind, title, ref, created_at)
                      VALUES (?,?,?,?,?,?,?)')
           ->execute([$userId, $delta, $balance, $kind, $title, $ref, time()]);
        $db->prepare('UPDATE member SET points = ? WHERE id = ?')->execute([$balance, $userId]);

        $db->commit();
        return ['ok' => true, 'balance' => $balance];
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        // UNIQUE(kind, ref) 위반 = 같은 근거로 이미 처리됨. 실패가 아니라 "이미 됨" 으로 답한다.
        // 메시지는 드라이버마다 다르므로(SQLite 'UNIQUE constraint failed' / MySQL 'Duplicate entry')
        // 표준 SQLSTATE 23000(무결성 제약 위반)으로 본다.
        if ((string)$e->getCode() === '23000') {
            return ['ok' => true, 'balance' => egg_points_balance($userId), 'duplicated' => true];
        }
        return ['ok' => false, 'error' => 'db'];
    }
}

function egg_points_balance(string $userId): int
{
    $st = egg_points_schema()->prepare('SELECT points FROM member WHERE id = ?');
    $st->execute([$userId]);
    return (int)($st->fetchColumn() ?: 0);
}

/**
 * 이용내역 — 최신부터. `before` 는 직전 페이지의 마지막 id 를 넘긴다(offset 을 쓰지 않는
 * 이유: 행이 쌓일수록 offset 은 앞을 전부 세느라 느려진다).
 */
function egg_points_history(string $userId, ?int $before = null, int $limit = 30): array
{
    $limit = max(1, min(100, $limit));
    $db = egg_points_schema();
    if ($before !== null && $before > 0) {
        $st = $db->prepare('SELECT * FROM point_ledger WHERE user_id = ? AND id < ? ORDER BY id DESC LIMIT ?');
        $st->execute([$userId, $before, $limit]);
    } else {
        $st = $db->prepare('SELECT * FROM point_ledger WHERE user_id = ? ORDER BY id DESC LIMIT ?');
        $st->execute([$userId, $limit]);
    }
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = [
            'id'      => (int)$r['id'],
            'delta'   => (int)$r['delta'],
            'balance' => (int)$r['balance'],
            'kind'    => $r['kind'],
            'title'   => $r['title'],
            'at'      => (int)$r['created_at'],
        ];
    }
    return $rows;
}

/**
 * 원장과 잔액 캐시가 어긋났는지 확인한다(대사).
 * 원장은 진실이고 member.points 는 사본이라, 사고가 나면 이쪽으로 찾는다.
 */
function egg_points_audit(?string $userId = null): array
{
    $db = egg_points_schema();
    $sql = 'SELECT m.id, m.points AS cached, COALESCE(SUM(l.delta), 0) AS summed
            FROM member m LEFT JOIN point_ledger l ON l.user_id = m.id';
    $args = [];
    if ($userId !== null) { $sql .= ' WHERE m.id = ?'; $args[] = $userId; }
    $sql .= ' GROUP BY m.id HAVING cached != summed';
    $st = $db->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/**
 * 감사 로그 정리 — 원장은 정산 근거라 지우지 않는다. 무한정 쌓여 파일을 키우는 것은
 * 광고 콜백 로그(ssv_log)이므로 그쪽만 보존기간을 둔다.
 * 크론: 하루 1회 `php83 -r "require 'src/points.php'; egg_points_prune();"`
 */
function egg_points_prune(int $keepDays = 90): int
{
    $cut = time() - $keepDays * 86400;
    $st = egg_db()->prepare('DELETE FROM ssv_log WHERE at < ?');
    $st->execute([$cut]);
    return $st->rowCount();
}
