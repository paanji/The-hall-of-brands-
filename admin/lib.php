<?php
if (!defined('ADMIN_LIB_LOADED')) define('ADMIN_LIB_LOADED', true);

require_once __DIR__ . '/../api/lib.php'; // blocks_read/blocks_update/GRID_SIZE/etc.
require_once __DIR__ . '/../.private/admin_config.php';

// --- Secure session setup -------------------------------------------------
// Must happen before session_start() and before any output.
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/admin/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_name('hob_admin');
    session_start();
}

function admin_is_logged_in(): bool {
    if (empty($_SESSION['admin_authenticated'])) return false;
    $idleLimit = ADMIN_SESSION_IDLE_MINUTES * 60;
    if (!empty($_SESSION['admin_last_activity']) && (time() - $_SESSION['admin_last_activity']) > $idleLimit) {
        admin_logout();
        return false;
    }
    $_SESSION['admin_last_activity'] = time();
    return true;
}

function admin_require_login(): void {
    if (!admin_is_logged_in()) {
        header('Location: /admin/login.php');
        exit;
    }
}

function admin_logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

// --- CSRF protection -------------------------------------------------------

function admin_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function admin_csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(admin_csrf_token()) . '">';
}

function admin_verify_csrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('Session expired or invalid request. Go back and try again.');
    }
}

// --- Brute-force lockout ----------------------------------------------------

function admin_login_attempts_read(): array {
    if (!file_exists(ADMIN_ATTEMPTS_PATH)) return ['count' => 0, 'lockedUntil' => 0];
    $data = json_decode(@file_get_contents(ADMIN_ATTEMPTS_PATH), true);
    return is_array($data) ? $data : ['count' => 0, 'lockedUntil' => 0];
}

function admin_login_attempts_write(array $data): void {
    @file_put_contents(ADMIN_ATTEMPTS_PATH, json_encode($data), LOCK_EX);
}

function admin_is_locked_out(): bool {
    $a = admin_login_attempts_read();
    return !empty($a['lockedUntil']) && $a['lockedUntil'] > time();
}

function admin_lockout_seconds_remaining(): int {
    $a = admin_login_attempts_read();
    return max(0, ($a['lockedUntil'] ?? 0) - time());
}

function admin_register_failed_login(): void {
    $a = admin_login_attempts_read();
    $a['count'] = ($a['count'] ?? 0) + 1;
    if ($a['count'] >= ADMIN_LOGIN_MAX_ATTEMPTS) {
        $a['lockedUntil'] = time() + ADMIN_LOGIN_LOCKOUT_MINUTES * 60;
        $a['count'] = 0;
    }
    admin_login_attempts_write($a);
}

function admin_clear_failed_logins(): void {
    admin_login_attempts_write(['count' => 0, 'lockedUntil' => 0]);
}

// --- Shared helpers for the dashboard --------------------------------------

function admin_format_rupees(int $paise): string {
    return '₹' . number_format($paise / 100, 0);
}

function admin_format_amount(int $minor, string $currency): string {
    if ($currency === 'usd') return '$' . number_format($minor / 100, 2);
    return admin_format_rupees($minor);
}

/** Group blocks.json entries by orderId into per-client records. */
function admin_group_orders(): array {
    $data = blocks_read();
    $orders = [];
    foreach ($data as $n => $info) {
        $status = $info['status'] ?? null;
        if ($status !== 'sold' && $status !== 'pending') continue;
        $orderId = $info['orderId'] ?? ('unknown-' . $n);
        if (!isset($orders[$orderId])) {
            $orders[$orderId] = [
                'orderId' => $orderId,
                'status' => $status,
                'brand' => $info['alt'] ?? ($info['brand'] ?? ''),
                'link' => $info['link'] ?? ($info['website'] ?? ''),
                'caption' => $info['caption'] ?? '',
                'email' => $info['email'] ?? '',
                'imgSrc' => $info['imgSrc'] ?? '',
                'source' => $info['source'] ?? ($info['gateway'] ?? ($status === 'pending' ? 'online' : 'offline')),
                'currency' => $info['currency'] ?? 'inr',
                'amountPaidMinor' => $info['amountPaidMinor'] ?? 0,
                'soldAt' => $info['soldAt'] ?? ($info['expiresAt'] ?? null),
                'blocks' => [],
            ];
        }
        $orders[$orderId]['blocks'][] = (int) $n;
    }
    foreach ($orders as &$o) {
        sort($o['blocks']);
    }
    uasort($orders, fn($a, $b) => ($b['soldAt'] ?? 0) <=> ($a['soldAt'] ?? 0));
    return $orders;
}

function admin_page_header(string $title): void {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="robots" content="noindex, nofollow">';
    echo '<title>' . htmlspecialchars($title) . ' — Hall of Brands Admin</title>';
    echo '<link rel="stylesheet" href="/admin/style.css">';
    echo '</head><body>';
}

function admin_page_footer(): void {
    echo '</body></html>';
}
