<?php
// 표준 어댑터 v1 진입점 — .htaccess 가 ^v1/(.+) 를 여기로 보낸다
declare(strict_types=1);
require_once __DIR__ . '/../src/adapter.php';

adp_route((string)($_GET['__p'] ?? ''));
