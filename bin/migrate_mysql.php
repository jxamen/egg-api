<?php
/**
 * SQLite → MySQL 데이터 이관.
 *
 *   php83 bin/migrate_mysql.php --dry     무엇이 얼마나 옮겨지는지만 본다
 *   php83 bin/migrate_mysql.php           실제로 옮긴다
 *
 * config.php 에 'db' 를 넣은 뒤 실행한다. 넣는 순간 웹은 이미 MySQL(빈 DB)을 보므로,
 * **설정 추가 → 곧바로 이 스크립트 실행** 순서로 붙여서 하는 게 좋다.
 * 여러 번 돌려도 안전하다(기본키 충돌은 건너뛴다).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/bootstrap.php';
require_once $root . '/src/db_mysql.php';

$dry = in_array('--dry', $argv, true);

$cfg = egg_config()['db'] ?? null;
if (!is_array($cfg) || ($cfg['name'] ?? '') === '') {
    fwrite(STDERR, "config.php 에 'db' 가 없다. MySQL 접속 정보를 먼저 넣을 것.\n");
    exit(1);
}
if (!is_file(EGG_DB)) {
    fwrite(STDERR, "옮길 SQLite 파일이 없다: " . EGG_DB . "\n");
    exit(1);
}

$src = new PDO('sqlite:' . EGG_DB, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$dst = egg_mysql_connect($cfg);      // 연결하면서 스키마도 만든다

/** MySQL 쪽에 실제로 있는 테이블만 옮긴다(KIAP 등 다른 모듈 테이블은 그쪽이 만든 뒤에) */
$dstTables = [];
foreach ($dst->query('SHOW TABLES') as $r) $dstTables[] = array_values($r)[0];

$srcTables = [];
foreach ($src->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") as $r) {
    $srcTables[] = $r['name'];
}

$total = 0;
$skipped = [];
foreach ($srcTables as $t) {
    if (!in_array($t, $dstTables, true)) { $skipped[] = $t; continue; }

    $rows = $src->query("SELECT * FROM `$t`")->fetchAll();
    if (!$rows) { printf("%-16s %6d 행\n", $t, 0); continue; }

    // MySQL 쪽 컬럼만 넣는다(양쪽 컬럼이 어긋나도 멈추지 않게)
    $dstCols = [];
    foreach ($dst->query("SHOW COLUMNS FROM `$t`") as $c) $dstCols[] = $c['Field'];
    $cols = array_values(array_intersect(array_keys($rows[0]), $dstCols));
    if (!$cols) { $skipped[] = "$t(컬럼 불일치)"; continue; }

    $ph  = implode(',', array_fill(0, count($cols), '?'));
    $sql = 'INSERT IGNORE INTO `' . $t . '` (`' . implode('`,`', $cols) . "`) VALUES ($ph)";

    if ($dry) {
        printf("%-16s %6d 행 (dry)\n", $t, count($rows));
        $total += count($rows);
        continue;
    }

    $st = $dst->prepare($sql);
    $dst->beginTransaction();
    $n = 0;
    foreach ($rows as $row) {
        $vals = [];
        foreach ($cols as $c) $vals[] = $row[$c];
        $st->execute($vals);
        $n += $st->rowCount();
    }
    $dst->commit();
    printf("%-16s %6d 행\n", $t, $n);
    $total += $n;
}

echo str_repeat('-', 34), "\n";
printf("합계 %d 행%s\n", $total, $dry ? ' (실제로 쓰지 않았다)' : '');
if ($skipped) {
    echo "건너뜀: ", implode(', ', $skipped), "\n";
    echo "  → MySQL 쪽에 그 테이블이 아직 없다. 해당 모듈이 스키마를 만든 뒤 다시 돌리면 된다.\n";
}

// 옮긴 뒤 잔액 캐시와 원장이 맞는지 본다(포인트가 이미 쌓여 있었다면)
if (!$dry && in_array('point_ledger', $dstTables, true)) {
    require_once $root . '/src/points.php';
    $bad = egg_points_audit();
    echo $bad ? ('⚠ 잔액 불일치 ' . count($bad) . '건 — egg_points_audit() 로 확인할 것' . "\n")
              : "잔액 대사 이상 없음\n";
}
