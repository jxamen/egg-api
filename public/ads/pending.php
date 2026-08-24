<?php
/**
 * 앱이 "받을 사료가 몇 개 남았는지" 묻는다.
 *   GET /ads/pending?user_id=...      헤더: X-Egg-Key
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';
egg_require_app_key();

$userId = (string)($_GET['user_id'] ?? '');
if ($userId === '') egg_json(400, ['ok' => false, 'error' => 'user_id_required']);

$db = egg_db();
$st = $db->prepare('SELECT COUNT(*) c FROM ad_reward WHERE user_id = ? AND claimed_at IS NULL');
$st->execute([$userId]);
$pending = (int)$st->fetch()['c'];

$st = $db->prepare('SELECT COUNT(*) c FROM ad_reward WHERE user_id = ? AND created_at >= ?');
$st->execute([$userId, egg_today_start()]);
$today = (int)$st->fetch()['c'];

egg_json(200, [
    'ok'        => true,
    'pending'   => $pending,
    'item'      => REWARD_ITEM,
    'todayUsed' => $today,
    'todayLeft' => max(0, DAILY_LIMIT - $today),
]);
