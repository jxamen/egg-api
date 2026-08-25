<?php
/**
 * 표준 앱 어댑터 v1 — 플랫폼 설계 규칙 §11(중앙 어드민은 앱 DB 에 직접 붙지 않고 이 API 로만 통신).
 * 계약 문서: egg-admin/docs/adapter-api-v1.md
 *
 *   GET  /v1/health
 *   GET  /v1/capabilities
 *   POST /v1/admin/members/search        { query?, limit? }
 *   GET  /v1/admin/members/{id}/summary
 *   GET  /v1/admin/rewards/{id}/summary
 *   POST /v1/admin/enforcements/apply    (Idempotency-Key 필수)
 *   POST /v1/admin/enforcements/revoke   (Idempotency-Key 필수)
 *   GET  /v1/admin/operations/{key}
 *
 * 인증: X-Egg-Key = admin_key (admin/data 와 동일). 응답은 {ok,data|error,meta} 공통 형식.
 * 쓰기는 Idempotency-Key 로 멱등 — 같은 키+같은 본문 재시도는 저장된 결과를 재생한다.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

const ADP_VERSION = '1.0';
const ADP_CAPS = ['members.read', 'members.search', 'rewards.read', 'enforcements.apply', 'enforcements.revoke'];

/** 공통 meta — 요청 ID 는 중앙이 준 것을 그대로 돌려줘 감사 로그와 묶이게 한다 */
function adp_meta(): array
{
    static $rid = null;
    if ($rid === null) {
        $rid = (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
        if (!preg_match('/^[0-9a-fA-F-]{8,64}$/', $rid)) {
            $rid = bin2hex(random_bytes(16));
        }
    }
    return [
        'request_id' => $rid,
        'app_id' => (string)(egg_config()['app'] ?? 'kkokkofarm'),
        'environment' => 'production',
        'api_version' => 'v1',
    ];
}

function adp_ok(array $data): never
{
    egg_json(200, ['ok' => true, 'data' => $data, 'meta' => adp_meta()]);
}

function adp_err(int $http, string $code, string $msg, bool $retryable = false): never
{
    egg_json($http, ['ok' => false, 'error' => ['code' => $code, 'message' => $msg, 'retryable' => $retryable], 'meta' => adp_meta()]);
}

function adp_require_admin_key(): void
{
    $key = (string)(egg_config()['admin_key'] ?? '');
    if ($key === '' && is_file(EGG_VAR . '/admin_key')) {
        $key = trim((string)file_get_contents(EGG_VAR . '/admin_key'));
    }
    if ($key === '') adp_err(503, 'UPSTREAM_UNAVAILABLE', 'admin_key 미설정', true);
    if (!hash_equals($key, (string)($_SERVER['HTTP_X_EGG_KEY'] ?? ''))) {
        adp_err(401, 'AUTH_REQUIRED', '인증 실패');
    }
}

/** 어댑터 전용 테이블 — 앱(Data Plane) 소유. 어드민 DB 와 분리(§3 4번 채택 구조) */
function adp_schema(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS adapter_operations (
        op_key     VARCHAR(80) NOT NULL,
        action     VARCHAR(40) NOT NULL,
        body_sha   CHAR(64) NOT NULL,
        result     TEXT NOT NULL,
        created_at BIGINT NOT NULL,
        PRIMARY KEY (op_key)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS app_enforcements (
        action_id        VARCHAR(64) NOT NULL,
        external_user_id VARCHAR(100) NOT NULL,
        scope            VARCHAR(20) NOT NULL,
        feature          VARCHAR(60) NULL,
        action_type      VARCHAR(30) NOT NULL,
        reason_code      VARCHAR(50) NOT NULL,
        starts_at        BIGINT NOT NULL,
        expires_at       BIGINT NULL,
        status           VARCHAR(16) NOT NULL,
        updated_at       BIGINT NOT NULL,
        PRIMARY KEY (action_id)
    )');
}

/** 활성 제재 조회 — 포인트 적립·출금 경로가 이 함수로 차단 여부를 묻는다(회원·포인트 이식 시 소비처) */
function adp_active_enforcements(PDO $db, string $userId): array
{
    $st = $db->prepare('SELECT action_id, scope, feature, action_type, reason_code, expires_at
        FROM app_enforcements WHERE external_user_id = ? AND status = ?');
    $st->execute([$userId, 'active']);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        if ($r['expires_at'] !== null && (int)$r['expires_at'] < time()) continue; // 만료분 제외
        $rows[] = $r;
    }
    return $rows;
}

function adp_mask_email(?string $e): ?string
{
    if (!$e || !str_contains($e, '@')) return null;
    [$l, $d] = explode('@', $e, 2);
    return substr($l, 0, 2) . str_repeat('*', max(1, strlen($l) - 2)) . '@' . $d;
}

/** 멱등 실행 — 같은 키·같은 본문이면 저장 결과 재생, 같은 키·다른 본문이면 409 */
function adp_idempotent(PDO $db, string $action, array $body, callable $fn): never
{
    $opKey = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    if ($opKey === '' || strlen($opKey) > 80 || !preg_match('/^[A-Za-z0-9_.:-]+$/', $opKey)) {
        adp_err(422, 'VALIDATION_ERROR', 'Idempotency-Key 헤더가 필요합니다(80자 이하 영숫자)');
    }
    $sha = hash('sha256', json_encode($body, JSON_UNESCAPED_UNICODE));
    $st = $db->prepare('SELECT body_sha, result FROM adapter_operations WHERE op_key = ?');
    $st->execute([$opKey]);
    if ($row = $st->fetch()) {
        if (!hash_equals((string)$row['body_sha'], $sha)) {
            adp_err(409, 'IDEMPOTENCY_CONFLICT', '같은 Idempotency-Key 로 다른 내용이 왔습니다');
        }
        adp_ok(json_decode((string)$row['result'], true) + ['replayed' => true]);
    }
    $data = $fn();
    $ins = $db->prepare(egg_sql_insert_ignore() . ' adapter_operations (op_key, action, body_sha, result, created_at) VALUES (?,?,?,?,?)');
    $ins->execute([$opKey, $action, $sha, json_encode($data, JSON_UNESCAPED_UNICODE), time()]);
    adp_ok($data);
}

function adp_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $b = json_decode($raw, true);
    return is_array($b) ? $b : $_POST;
}

/** 라우터 — public/v1.php 가 부른다 */
function adp_route(string $path): never
{
    egg_cors();
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $path = trim($path, '/');

    if ($path === 'health') {
        // health 는 무인증 — 중앙이 앱 상태 대시보드에 쓴다(민감 정보 없음)
        adp_ok(['status' => 'ok', 'time' => time(), 'adapter_version' => ADP_VERSION]);
    }

    adp_require_admin_key();
    $db = egg_db();
    adp_schema($db);

    if ($path === 'capabilities') {
        adp_ok(['capabilities' => ADP_CAPS, 'adapter_version' => ADP_VERSION]);
    }

    if ($path === 'admin/members/search' && $method === 'POST') {
        $b = adp_body();
        $q = trim((string)($b['query'] ?? ''));
        $limit = max(1, min(50, (int)($b['limit'] ?? 20)));
        // PII(이메일 등) 검색은 중앙 Identity 몫(§13) — 앱 어댑터는 내부 ID·공급자 기준만
        $sql = 'SELECT id, provider, email, name, points, created_at, last_login_at FROM member';
        $params = [];
        if ($q !== '') { $sql .= ' WHERE id = ? OR social_id = ?'; $params = [$q, $q]; }
        $sql .= ' ORDER BY created_at DESC LIMIT ' . $limit;
        $st = $db->prepare($sql);
        $st->execute($params);
        $items = [];
        foreach ($st->fetchAll() as $m) {
            $items[] = [
                'external_user_id' => $m['id'],
                'provider' => $m['provider'],
                'email_masked' => adp_mask_email($m['email'] ?? null),
                'name' => $m['name'] !== null ? mb_substr((string)$m['name'], 0, 1) . '**' : null,
                'points' => (int)$m['points'],
                'created_at' => (int)$m['created_at'],
                'last_login_at' => $m['last_login_at'] !== null ? (int)$m['last_login_at'] : null,
            ];
        }
        adp_ok(['items' => $items]);
    }

    if (preg_match('#^admin/members/([A-Za-z0-9_.:-]{1,100})/summary$#', $path, $m) && $method === 'GET') {
        $st = $db->prepare('SELECT id, provider, email, name, points, marketing, created_at, last_login_at FROM member WHERE id = ?');
        $st->execute([$m[1]]);
        $row = $st->fetch();
        if (!$row) adp_err(404, 'MEMBER_NOT_FOUND', '회원이 없습니다');
        adp_ok([
            'external_user_id' => $row['id'],
            'provider' => $row['provider'],
            'email_masked' => adp_mask_email($row['email'] ?? null),
            'points' => (int)$row['points'],
            'marketing' => (bool)$row['marketing'],
            'created_at' => (int)$row['created_at'],
            'last_login_at' => $row['last_login_at'] !== null ? (int)$row['last_login_at'] : null,
            'enforcements' => adp_active_enforcements($db, $row['id']),
        ]);
    }

    if (preg_match('#^admin/rewards/([A-Za-z0-9_.:-]{1,100})/summary$#', $path, $m) && $method === 'GET') {
        $sum = $db->prepare("SELECT
            COALESCE(SUM(CASE WHEN delta > 0 THEN delta ELSE 0 END),0) AS earned,
            COALESCE(SUM(CASE WHEN delta < 0 THEN -delta ELSE 0 END),0) AS used,
            COUNT(*) AS cnt FROM point_ledger WHERE user_id = ?");
        $sum->execute([$m[1]]);
        $tot = $sum->fetch() ?: ['earned' => 0, 'used' => 0, 'cnt' => 0];
        $st = $db->prepare('SELECT delta, balance, kind, title, created_at FROM point_ledger WHERE user_id = ? ORDER BY id DESC LIMIT 20');
        $st->execute([$m[1]]);
        adp_ok([
            'earned_total' => (int)$tot['earned'],
            'used_total' => (int)$tot['used'],
            'entries' => (int)$tot['cnt'],
            'recent' => $st->fetchAll(),
        ]);
    }

    if ($path === 'admin/enforcements/apply' && $method === 'POST') {
        $b = adp_body();
        $actionId = trim((string)($b['action_id'] ?? ''));
        $user = trim((string)($b['external_user_id'] ?? ''));
        $type = (string)($b['action_type'] ?? '');
        $scope = (string)($b['scope'] ?? 'app');
        $types = ['watch', 'manual_review_required', 'earn_restricted', 'withdrawal_locked', 'suspended', 'banned'];
        if ($actionId === '' || $user === '' || !in_array($type, $types, true)) {
            adp_err(422, 'VALIDATION_ERROR', 'action_id·external_user_id·action_type 이 필요합니다');
        }
        adp_idempotent($db, 'enforcements.apply', $b, function () use ($db, $b, $actionId, $user, $type, $scope) {
            $exp = isset($b['expires_at']) && $b['expires_at'] !== null && $b['expires_at'] !== '' ? (int)$b['expires_at'] : null;
            $now = time();
            // 같은 action_id 재적용은 상태 갱신(REPLACE 아닌 upsert — 드라이버 공통으로 delete+insert)
            $db->prepare('DELETE FROM app_enforcements WHERE action_id = ?')->execute([$actionId]);
            $db->prepare('INSERT INTO app_enforcements
                (action_id, external_user_id, scope, feature, action_type, reason_code, starts_at, expires_at, status, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$actionId, $user, $scope, ($b['feature'] ?? null) ?: null, $type,
                    substr((string)($b['reason_code'] ?? ''), 0, 50), $now, $exp, 'active', $now]);
            return ['applied' => true, 'action_id' => $actionId];
        });
    }

    if ($path === 'admin/enforcements/revoke' && $method === 'POST') {
        $b = adp_body();
        $actionId = trim((string)($b['action_id'] ?? ''));
        if ($actionId === '') adp_err(422, 'VALIDATION_ERROR', 'action_id 가 필요합니다');
        adp_idempotent($db, 'enforcements.revoke', $b, function () use ($db, $actionId) {
            $st = $db->prepare('UPDATE app_enforcements SET status = ?, updated_at = ? WHERE action_id = ?');
            $st->execute(['revoked', time(), $actionId]);
            return ['revoked' => true, 'action_id' => $actionId, 'existed' => $st->rowCount() > 0];
        });
    }

    if (preg_match('#^admin/operations/([A-Za-z0-9_.:-]{1,80})$#', $path, $m) && $method === 'GET') {
        $st = $db->prepare('SELECT action, result, created_at FROM adapter_operations WHERE op_key = ?');
        $st->execute([$m[1]]);
        $row = $st->fetch();
        if (!$row) adp_err(404, 'NOT_FOUND', '해당 작업 기록이 없습니다');
        adp_ok(['action' => $row['action'], 'result' => json_decode((string)$row['result'], true), 'created_at' => (int)$row['created_at']]);
    }

    adp_err(404, 'NOT_FOUND', '지원하지 않는 어댑터 경로입니다: ' . $path);
}
