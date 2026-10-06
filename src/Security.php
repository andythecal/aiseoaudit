<?php
declare(strict_types=1);
namespace Audit;
final class Security {
    public static function session(): void {
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        if (\app_config('environment') === 'production' && !$secure) { \json_response(['error' => 'HTTPS is required. Open the secure site URL.'], 400); }
        ini_set('session.use_strict_mode', '1');
        session_save_path(\app_config('storage_path') . '/sessions');
        session_name('audit_session');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
        if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
        $visitor = $_COOKIE['audit_visitor'] ?? '';
        $parts = explode('.', $visitor);
        if (count($parts) !== 2 || !preg_match('/^[a-f0-9]{64}$/', $parts[0]) || !hash_equals(hash_hmac('sha256', $parts[0], \app_config('app_key')), $parts[1])) {
            $id = bin2hex(random_bytes(32));
            $visitor = $id . '.' . hash_hmac('sha256', $id, \app_config('app_key'));
            setcookie('audit_visitor', $visitor, ['expires' => time() + 31536000, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        }
        $_SESSION['visitor_hash'] = hash('sha256', $visitor);
    }
    public static function csrf(): void {
        if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) { \json_response(['error' => 'Your session expired. Refresh the page and try again.'], 403); }
    }
    public static function rate(string $purpose, int $limit, ?string $identity = null): void {
        $hour = (int)floor(time() / 3600);
        $bucket = hash_hmac('sha256', $purpose . ':' . ($identity ?? ($_SERVER['REMOTE_ADDR'] ?? 'cli')) . ':' . $hour, \app_config('app_key'));
        $q = \db()->prepare('INSERT INTO rate_limits (bucket, attempts, expires_at) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE attempts = attempts + 1');
        $q->execute([$bucket, gmdate('Y-m-d H:i:s', ($hour + 2) * 3600)]);
        $q = \db()->prepare('SELECT attempts FROM rate_limits WHERE bucket = ?'); $q->execute([$bucket]);
        if ((int)$q->fetchColumn() > $limit) { header('Retry-After: ' . (($hour + 1) * 3600 - time())); \json_response(['error' => 'Too many requests. Please try again after this hour.'], 429); }
        if (random_int(1, 100) === 1) { \db()->exec('DELETE FROM rate_limits WHERE expires_at < UTC_TIMESTAMP()'); }
    }
    public static function user(): ?array {
        if (empty($_SESSION['user_id'])) { return null; }
        $q = \db()->prepare('SELECT id, email, EXISTS(SELECT 1 FROM super_admins a WHERE a.user_id=users.id) AS is_super_admin FROM users WHERE id = ?'); $q->execute([$_SESSION['user_id']]);
        return $q->fetch() ?: null;
    }
}
