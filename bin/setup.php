<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap.php';
foreach (['curl', 'dom', 'mbstring', 'pdo_mysql', 'openssl'] as $extension) {
    if (!extension_loaded($extension)) { throw new RuntimeException('Missing PHP extension: ' . $extension); }
}
if (strlen((string)app_config('app_key')) < 64 || strpos(app_config('app_key'), 'REPLACE') !== false) { throw new RuntimeException('Set a random 64-character app_key first.'); }
$storage = app_config('storage_path');
if (!is_dir($storage . '/sessions') && !mkdir($storage . '/sessions', 0700, true)) { throw new RuntimeException('Cannot create storage directory.'); }
db()->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
db()->exec(file_get_contents(dirname(__DIR__) . '/database/002-membership.sql'));
db()->exec(file_get_contents(dirname(__DIR__) . '/database/003-account.sql'));
db()->exec(file_get_contents(dirname(__DIR__) . '/database/004-research.sql'));
echo "Database schema ready. Storage ready. No web server was started.\n";
