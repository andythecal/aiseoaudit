<?php
declare(strict_types=1);
define('APP_ROOT', __DIR__);
spl_autoload_register(function ($class) {
    if (preg_match('/^Audit\\\\([A-Za-z]+)$/', $class, $m)) { require APP_ROOT . '/src/' . $m[1] . '.php'; }
});
if (!is_file(APP_ROOT . '/config/local.php')) { throw new RuntimeException('Application is not configured. Follow README.md to complete setup.'); }
$config = require APP_ROOT . '/config/local.php';
date_default_timezone_set('UTC');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', $config['storage_path'] . '/php-errors.log');
function app_config(?string $key = null) { global $config; return $key === null ? $config : ($config[$key] ?? null); }
function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $c = app_config('db');
        $pdo = new PDO('mysql:host=' . $c['host'] . ';port=' . ($c['port'] ?? 3306) . ';dbname=' . $c['name'] . ';charset=utf8mb4', $c['user'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}
function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
