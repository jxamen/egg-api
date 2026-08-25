<?php
/** GET /auth/status — 앱이 KICA 간편인증 사용 가능 여부를 묻는다(자격증명 없이도 200). */
declare(strict_types=1);
require_once __DIR__ . '/../../src/kiap.php';
egg_cors();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
egg_json(200, ['ok' => true, 'enabled' => kiap_enabled(), 'providers' => array_keys(KIAP_PROVIDERS)]);
