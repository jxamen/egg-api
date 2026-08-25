<?php
/**
 * 운영 콘텐츠 — 공지·FAQ·약관·1:1 문의.
 *
 * 지금까지 이 문구들은 앱 코드에 배열로 박혀 있었다(support.tsx 의 FAQ, legal.tsx 의 조항).
 * 오타 하나 고치려 해도 앱을 다시 심사받아 배포해야 하는 구조라 서버로 옮긴다.
 * 앱은 받아서 그리기만 하고, 편집은 어드민이 한다.
 *
 * 약관은 버전을 남긴다 — 내용이 바뀌면 재동의를 받아야 하고, 회원이 어느 버전에 동의했는지
 * 나중에 증빙할 수 있어야 한다. 조회는 "오늘 기준 시행 중인 최신본" 하나를 준다.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** 앱 FAQ 탭과 같은 값 — all 은 전체를 뜻하므로 저장하지 않는다 */
const FAQ_CATEGORIES = ['farm', 'point', 'exch'];
/** 공지 분류 — 앱 공지 탭(전체/서비스/이벤트) */
const NOTICE_CATEGORIES = ['svc', 'evt'];

/** 공지 목록 — 고정(pinned) 먼저, 그다음 최신순 */
function egg_notices(?string $cat = null, ?int $before = null, int $limit = 20): array
{
    $limit = max(1, min(50, $limit));
    $db = egg_db();
    $where = ['app = ?', 'published = 1'];
    $args  = [egg_app()];
    if ($cat !== null && $cat !== '' && $cat !== 'all') { $where[] = 'category = ?'; $args[] = $cat; }
    if ($before !== null && $before > 0)                { $where[] = 'id < ?';       $args[] = $before; }

    $sql = 'SELECT id, category, title, pinned, published_at, created_at FROM notice WHERE '
         . implode(' AND ', $where) . ' ORDER BY pinned DESC, id DESC LIMIT ' . $limit;
    $st = $db->prepare($sql);
    $st->execute($args);

    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = [
            'id'     => (int)$r['id'],
            'cat'    => $r['category'],
            'title'  => $r['title'],
            'pinned' => (bool)$r['pinned'],
            'at'     => (int)($r['published_at'] ?: $r['created_at']),
        ];
    }
    return $out;
}

function egg_notice(int $id): ?array
{
    $st = egg_db()->prepare('SELECT * FROM notice WHERE id = ? AND app = ? AND published = 1');
    $st->execute([$id, egg_app()]);
    $r = $st->fetch();
    if (!$r) return null;
    return [
        'id'    => (int)$r['id'],
        'cat'   => $r['category'],
        'title' => $r['title'],
        'body'  => $r['body'],
        'at'    => (int)($r['published_at'] ?: $r['created_at']),
    ];
}

/** FAQ — 카테고리별. 정렬값이 같으면 등록순 */
function egg_faqs(?string $cat = null): array
{
    $db = egg_db();
    if ($cat !== null && $cat !== '' && $cat !== 'all') {
        $st = $db->prepare('SELECT id, category, question, answer FROM faq
                            WHERE app = ? AND published = 1 AND category = ? ORDER BY sort_order, id');
        $st->execute([egg_app(), $cat]);
    } else {
        $st = $db->prepare('SELECT id, category, question, answer FROM faq
                            WHERE app = ? AND published = 1 ORDER BY sort_order, id');
        $st->execute([egg_app()]);
    }
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = ['id' => (int)$r['id'], 'cat' => $r['category'], 'q' => $r['question'], 'a' => $r['answer']];
    }
    return $out;
}

/**
 * 약관 본문 — 오늘 기준 시행 중인 최신본.
 * 아직 시행일이 오지 않은 개정본은 내보내지 않는다(공지로 미리 알리고 시행일에 바뀐다).
 */
function egg_legal(string $kind): ?array
{
    $st = egg_db()->prepare('SELECT * FROM legal_doc
                             WHERE app = ? AND kind = ? AND published = 1 AND effective_at <= ?
                             ORDER BY effective_at DESC, id DESC LIMIT 1');
    $st->execute([egg_app(), $kind, time()]);
    $r = $st->fetch();
    if (!$r) return null;
    return [
        'kind'      => $r['kind'],
        'version'   => $r['version'],
        'effective' => (int)$r['effective_at'],
        'body'      => $r['body'],
    ];
}

/** 1:1 문의 접수 */
function egg_inquiry_create(string $userId, string $type, string $title, string $body): array
{
    $title = trim($title);
    $body  = trim($body);
    if ($title === '') return ['ok' => false, 'error' => 'title_required'];
    if ($body === '')  return ['ok' => false, 'error' => 'body_required'];

    $db = egg_db();
    // 도배 방지 — 같은 회원이 1분 안에 다시 넣으면 막는다
    $st = $db->prepare('SELECT COUNT(*) FROM inquiry WHERE user_id = ? AND created_at > ?');
    $st->execute([$userId, time() - 60]);
    if ((int)$st->fetchColumn() > 0) return ['ok' => false, 'error' => 'too_soon'];

    $db->prepare('INSERT INTO inquiry (app, user_id, type, title, body, status, created_at)
                  VALUES (?,?,?,?,?,?,?)')
       ->execute([egg_app(), $userId, $type !== '' ? $type : null, mb_substr($title, 0, 100), $body, 'open', time()]);

    return ['ok' => true, 'id' => (int)$db->lastInsertId()];
}

/** 내 문의 내역 */
function egg_inquiries(string $userId, int $limit = 30): array
{
    $limit = max(1, min(50, $limit));
    $st = egg_db()->prepare('SELECT id, type, title, body, status, answer, answered_at, created_at
                             FROM inquiry WHERE user_id = ? ORDER BY id DESC LIMIT ' . $limit);
    $st->execute([$userId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = [
            'id'         => (int)$r['id'],
            'type'       => $r['type'],
            'title'      => $r['title'],
            'body'       => $r['body'],
            'status'     => $r['status'],
            'answer'     => $r['answer'],
            'answeredAt' => $r['answered_at'] ? (int)$r['answered_at'] : null,
            'at'         => (int)$r['created_at'],
        ];
    }
    return $out;
}
