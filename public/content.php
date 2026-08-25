<?php
/**
 * 운영 콘텐츠 엔드포인트 — /content/* (도메인 통합 경로로는 /api/content/*).
 *
 *   GET  /content/notices?cat=&before=&limit=   공지 목록          [X-Egg-Key]
 *   GET  /content/notice?id=                    공지 상세          [X-Egg-Key]
 *   GET  /content/faq?cat=                      자주 묻는 질문      [X-Egg-Key]
 *   GET  /content/legal?kind=terms|privacy      약관 본문(시행본)   [X-Egg-Key]
 *   POST /content/inquiry                       1:1 문의 접수      [Bearer]
 *   GET  /content/inquiries                     내 문의 내역        [Bearer]
 *
 * 문구를 앱에 하드코딩하지 않는 이유: 오타 하나에도 심사·배포가 따라붙기 때문이다.
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/content.php';
require_once __DIR__ . '/../src/auth.php';

egg_require_app_key();

$act = trim((string)($_GET['act'] ?? ''), '/');

if ($act === 'notices') {
    $cat    = (string)($_GET['cat'] ?? '');
    $before = (int)($_GET['before'] ?? 0);
    $limit  = (int)($_GET['limit'] ?? 20);
    egg_json(200, ['ok' => true, 'items' => egg_notices($cat ?: null, $before ?: null, $limit ?: 20)]);
}

if ($act === 'notice') {
    $id = (int)($_GET['id'] ?? 0);
    $n  = $id > 0 ? egg_notice($id) : null;
    if (!$n) egg_json(404, ['ok' => false, 'error' => 'not_found']);
    egg_json(200, ['ok' => true, 'notice' => $n]);
}

if ($act === 'faq') {
    $cat = (string)($_GET['cat'] ?? '');
    egg_json(200, ['ok' => true, 'items' => egg_faqs($cat ?: null)]);
}

if ($act === 'legal') {
    $kind = (string)($_GET['kind'] ?? '');
    if (!in_array($kind, ['terms', 'privacy'], true)) {
        egg_json(400, ['ok' => false, 'error' => 'kind 는 terms|privacy']);
    }
    $doc = egg_legal($kind);
    if (!$doc) egg_json(404, ['ok' => false, 'error' => 'not_found']);
    egg_json(200, ['ok' => true, 'doc' => $doc]);
}

// ── 아래는 로그인한 회원만 ────────────────────────────────
if ($act === 'inquiry' || $act === 'inquiries') {
    $m = egg_session_member();
    if (!$m) egg_json(401, ['ok' => false, 'error' => 'unauthorized']);

    if ($act === 'inquiries') {
        egg_json(200, ['ok' => true, 'items' => egg_inquiries($m['id'], (int)($_GET['limit'] ?? 30))]);
    }

    $r = egg_inquiry_create(
        $m['id'],
        (string)($_POST['type'] ?? ''),
        (string)($_POST['title'] ?? ''),
        (string)($_POST['body'] ?? ''),
    );
    if (!$r['ok']) {
        // too_soon 은 연타 방지라 429 가 맞다(클라이언트가 잠시 뒤 재시도하면 된다)
        egg_json($r['error'] === 'too_soon' ? 429 : 400, $r);
    }
    egg_json(200, $r);
}

egg_json(404, ['ok' => false, 'error' => 'unknown_action']);
