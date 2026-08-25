<?php
/**
 * 운영 엔드포인트 — 서버에 셸로 들어가지 않고 배포·동기화를 돌린다.
 *   GET /ops?do=status      현재 커밋·상품 수·마지막 동기화
 *   GET /ops?do=deploy      origin/master 로 강제 일치(= deploy.sh 의 코드 동기화 단계)
 *   GET /ops?do=sync[&max=] 기프티쇼 상품 동기화(0101)
 * 헤더: X-Egg-Key (앱 키와 동일)
 *
 * 왜 있나: 배포·상품 동기화를 사람이 SSH 로 들어가 매번 돌려야 했다. 코드가 바뀔 때마다
 * 서버 접속을 요구하는 구조라 실수가 나기 쉽다(실제로 clone 실패 뒤 이어진 명령에
 * 서비스가 내려간 적이 있다). 읽기 전용이 아닌 동작이라 앱 키로 잠근다.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/giftishow.php';
egg_require_app_key();

$root = dirname(__DIR__);
$do   = (string)($_GET['do'] ?? 'status');

/** git 등 외부 명령 — exec 가 막혀 있으면 그대로 알려 준다(조용히 실패하지 않게) */
function sh(string $cmd): array
{
    if (!function_exists('exec')) return ['ok' => false, 'out' => 'exec() 비활성'];
    $out = [];
    $rc = 0;
    @exec($cmd . ' 2>&1', $out, $rc);
    return ['ok' => $rc === 0, 'rc' => $rc, 'out' => implode("\n", $out)];
}

function head(string $root): string
{
    $r = sh('git -C ' . escapeshellarg($root) . ' rev-parse --short HEAD');
    return trim($r['out'] ?? '');
}

function goodsStat(): array
{
    try {
        $row = egg_db()->query("SELECT COUNT(*) c, MAX(synced_at) t FROM gs_goods WHERE state_cd='SALE'")->fetch();
        return ['count' => (int)($row['c'] ?? 0), 'syncedAt' => (int)($row['t'] ?? 0) ?: null];
    } catch (Throwable $e) {
        return ['count' => 0, 'syncedAt' => null, 'error' => $e->getMessage()];
    }
}

if ($do === 'status') {
    egg_json(200, ['ok' => true, 'head' => head($root)] + goodsStat());
}

if ($do === 'deploy') {
    $g = escapeshellarg($root);
    $r = sh("git -C $g fetch --quiet origin master && git -C $g reset --hard origin/master");
    // var/ 는 git 에 없다 — 배포 뒤에도 있어야 SQLite 가 열린다
    @mkdir($root . '/var', 0700);
    egg_json($r['ok'] ? 200 : 500, ['ok' => $r['ok'], 'head' => head($root), 'log' => $r['out']]);
}

if ($do === 'set-kiap') {
    // KIAP 자격증명 등록 — config.php 를 원격에서 못 고쳐 var/kiap.json 에 둔다(0600)
    $host = trim((string)($_GET['host'] ?? 'https://kiap.signgate.com'));
    $cid  = trim((string)($_GET['client_id'] ?? ''));
    $tok  = trim((string)($_GET['access_token'] ?? ''));
    if (!preg_match('#^https://[a-z0-9.-]+$#', $host) || $cid === '' || $tok === '') {
        egg_json(400, ['ok' => false, 'error' => 'host(https)·client_id·access_token 필요']);
    }
    @mkdir($root . '/var', 0700);
    $f = $root . '/var/kiap.json';
    $ok = @file_put_contents($f, json_encode(['host' => $host, 'client_id' => $cid, 'access_token' => $tok])) !== false;
    @chmod($f, 0600);
    egg_json($ok ? 200 : 500, ['ok' => $ok]);
}

if ($do === 'set-admin-key') {
    // 어드민 조회용 키 등록 — config.php 를 원격에서 못 고쳐 var/ 에 둔다(0600, 웹 접근 불가 경로)
    $v = trim((string)($_GET['value'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9]{32,128}$/', $v)) {
        egg_json(400, ['ok' => false, 'error' => 'value 는 영숫자 32~128자']);
    }
    @mkdir($root . '/var', 0700);
    $f = $root . '/var/admin_key';
    $ok = @file_put_contents($f, $v) !== false;
    @chmod($f, 0600);
    egg_json($ok ? 200 : 500, ['ok' => $ok]);
}

if ($do === 'sync') {
    @set_time_limit(600);
    $max = max(0, (int)($_GET['max'] ?? 0));
    $r = gs_sync($max);
    egg_json($r['ok'] ? 200 : 502, $r + goodsStat());
}

egg_json(400, ['ok' => false, 'message' => 'do 는 status|deploy|sync 중 하나']);
