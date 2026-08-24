<?php
/**
 * 앱이 미수령 보상을 받아 간다(수령 처리).
 *   POST /ads/claim   body: user_id=...   헤더: X-Egg-Key
 * 응답의 granted 만큼 앱이 사료 게이지를 올린다.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';
egg_require_app_key();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') egg_json(405, ['ok' => false, 'error' => 'post_only']);

$userId = (string)($_POST['user_id'] ?? '');
if ($userId === '') egg_json(400, ['ok' => false, 'error' => 'user_id_required']);

$db = egg_db();
$db->beginTransaction();
try {
    $st = $db->prepare('SELECT transaction_id FROM ad_reward WHERE user_id = ? AND claimed_at IS NULL');
    $st->execute([$userId]);
    $ids = array_column($st->fetchAll(), 'transaction_id');

    if ($ids) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $up = $db->prepare("UPDATE ad_reward SET claimed_at = ? WHERE transaction_id IN ($marks)");
        $up->execute(array_merge([time()], $ids));
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    egg_json(500, ['ok' => false, 'error' => 'db_error']);
}

egg_json(200, ['ok' => true, 'granted' => count($ids), 'item' => REWARD_ITEM, 'amount' => REWARD_AMOUNT]);
