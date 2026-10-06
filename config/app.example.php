<?php
// Copy to local.php on each machine. Never deploy local.php from your PC.
return [
    'environment' => 'production',
    'app_key' => 'REPLACE_WITH_64_RANDOM_HEX_CHARACTERS',
    'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'aiseoauditor', 'user' => 'audit_app', 'password' => ''],
    'registration_enabled' => true,
    'fetch_timeout' => 8,
    'max_response_bytes' => 2097152,
    'storage_path' => dirname(__DIR__) . '/storage',
];
