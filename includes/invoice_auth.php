<?php
declare(strict_types=1);

/**
 * Existing six-digit PIN authentication for invoice-tab accounts.
 *
 * The outer proxy still protects the whole portal. These account records stay
 * separate from the central administrator credential; current_user() may
 * expose that credential's verified admin identity to existing shared admin
 * checks without replacing the invoice accounts. User records belong in
 * /storage/invoice_users.json (private folder)
 * and must contain hashes, never plaintext PINs. Remember-me credentials use a
 * separate HttpOnly/Secure cookie; only their hashes are stored in
 * /storage/invoice_remember_tokens.json and PIN resets revoke those tokens.
 *
 * First-admin record format used by the one-time setup (or manual recovery):
 * [
 *   {
 *     "id": "random-unique-id",
 *     "username": "admin",
 *     "name": "Portaladmin",
 *     "role": "admin",
 *     "pin_hash": "value returned by password_hash(PIN, password algorithm)",
 *     "auth_version": 1,
 *     "active": true,
 *     "created_at": "ISO-8601 timestamp"
 *   }
 * ]
 */

function portal_invoice_auth_users_file(): string
{
    return __DIR__ . '/../storage/invoice_users.json';
}

function portal_invoice_auth_attempts_file(): string
{
    return __DIR__ . '/../storage/invoice_login_attempts.json';
}

function portal_invoice_auth_remember_tokens_file(): string
{
    return __DIR__ . '/../storage/invoice_remember_tokens.json';
}

function portal_invoice_auth_remember_cookie_name(): string
{
    return 'tadega_invoice_remember';
}

function portal_invoice_auth_remember_ttl(): int
{
    // Browsers cap persistent cookie lifetimes; refresh it while the user is active.
    return 400 * 24 * 60 * 60;
}

function portal_invoice_auth_remember_cookie_path(): string
{
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/';
    if (!is_string($scriptName) || $scriptName === '') {
        return '/';
    }
    $directory = str_replace('\\', '/', dirname($scriptName));
    if ($directory === '.' || $directory === '' || $directory === '/') {
        return '/';
    }
    return '/' . trim($directory, '/') . '/';
}

function portal_invoice_auth_set_remember_cookie(string $value, int $expires): bool
{
    if (headers_sent()) {
        return false;
    }
    return setcookie(portal_invoice_auth_remember_cookie_name(), $value, [
        'expires' => $expires,
        'path' => portal_invoice_auth_remember_cookie_path(),
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function portal_invoice_auth_clear_remember_cookie(): void
{
    $name = portal_invoice_auth_remember_cookie_name();
    unset($_COOKIE[$name]);
    if (headers_sent()) {
        return;
    }
    setcookie($name, '', [
        'expires' => time() - 3600,
        'path' => portal_invoice_auth_remember_cookie_path(),
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function portal_invoice_auth_read_remember_tokens(): ?array
{
    if (!portal_invoice_auth_storage_directory_is_safe()) {
        return null;
    }
    $path = portal_invoice_auth_remember_tokens_file();
    if (is_link($path)) {
        return null;
    }
    if (!file_exists($path)) {
        return [];
    }
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $content = @file_get_contents($path);
    if (!is_string($content) || trim($content) === '') {
        return null;
    }
    try {
        $tokens = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    return is_array($tokens) ? $tokens : null;
}

function portal_invoice_auth_save_remember_tokens(array $tokens): bool
{
    $path = portal_invoice_auth_remember_tokens_file();
    if (
        !portal_invoice_auth_storage_directory_is_safe() ||
        is_link($path) || is_link($path . '.lock') || is_link($path . '.guard.lock')
    ) {
        return false;
    }
    return save_data($path, $tokens);
}

function portal_invoice_auth_update_remember_tokens(callable $update): bool
{
    $path = portal_invoice_auth_remember_tokens_file();
    $guardPath = $path . '.guard.lock';
    if (
        !portal_invoice_auth_storage_directory_is_safe() ||
        is_link($path) || is_link($path . '.lock') || is_link($guardPath)
    ) {
        return false;
    }
    $oldUmask = umask(0077);
    $lock = @fopen($guardPath, 'c');
    umask($oldUmask);
    if (!is_resource($lock)) {
        return false;
    }
    @chmod($guardPath, 0600);
    if (!@flock($lock, LOCK_EX)) {
        fclose($lock);
        return false;
    }
    try {
        $tokens = portal_invoice_auth_read_remember_tokens();
        if (!is_array($tokens) || $update($tokens) !== true) {
            return false;
        }
        return portal_invoice_auth_save_remember_tokens($tokens);
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function portal_invoice_auth_delete_remember_token(string $selector): bool
{
    if (!preg_match('/\\A[a-f0-9]{32}\\z/', $selector)) {
        return false;
    }
    return portal_invoice_auth_update_remember_tokens(static function (array &$tokens) use ($selector): bool {
        if (!isset($tokens[$selector])) {
            return false;
        }
        unset($tokens[$selector]);
        return true;
    });
}

function portal_invoice_auth_forget_current_device(bool $expireCookie = true): void
{
    $name = portal_invoice_auth_remember_cookie_name();
    $raw = $_COOKIE[$name] ?? '';
    if (is_string($raw) && preg_match('/\\A([a-f0-9]{32})\\.[a-f0-9]{64}\\z/', $raw, $match)) {
        portal_invoice_auth_delete_remember_token($match[1]);
    }
    if ($expireCookie) {
        portal_invoice_auth_clear_remember_cookie();
    } else {
        unset($_COOKIE[$name]);
    }
}

function portal_invoice_auth_issue_remember_token(array $user): bool
{
    $userId = is_string($user['id'] ?? null) ? $user['id'] : '';
    if ($userId === '') {
        return false;
    }
    try {
        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        return false;
    }
    $expires = time() + portal_invoice_auth_remember_ttl();
    $record = [
        'user_id' => $userId,
        'auth_version' => max(1, (int) ($user['auth_version'] ?? 1)),
        'token_hash' => hash('sha256', $validator),
        'expires_at' => $expires,
        'created_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
    ];
    $saved = portal_invoice_auth_update_remember_tokens(static function (array &$tokens) use ($selector, $record): bool {
        $now = time();
        foreach ($tokens as $key => $entry) {
            if (!is_array($entry) || (int) ($entry['expires_at'] ?? 0) <= $now) {
                unset($tokens[$key]);
            }
        }
        if (count($tokens) >= 1000) {
            uasort($tokens, static fn (array $a, array $b): int => (int) ($a['expires_at'] ?? 0) <=> (int) ($b['expires_at'] ?? 0));
            $tokens = array_slice($tokens, -999, null, true);
        }
        $tokens[$selector] = $record;
        return true;
    });
    if (!$saved) {
        return false;
    }
    $cookieValue = $selector . '.' . $validator;
    if (!portal_invoice_auth_set_remember_cookie($cookieValue, $expires)) {
        portal_invoice_auth_delete_remember_token($selector);
        return false;
    }
    $_COOKIE[portal_invoice_auth_remember_cookie_name()] = $cookieValue;
    return true;
}

function portal_invoice_auth_refresh_remember_cookie(array $user, array $sessionUser): void
{
    $name = portal_invoice_auth_remember_cookie_name();
    $raw = $_COOKIE[$name] ?? '';
    if ($raw === '') {
        return;
    }
    if (!is_string($raw) || !preg_match('/\\A([a-f0-9]{32})\\.([a-f0-9]{64})\\z/', $raw, $match)) {
        portal_invoice_auth_forget_current_device();
        return;
    }
    $selector = $match[1];
    $validator = $match[2];
    $tokens = portal_invoice_auth_read_remember_tokens();
    $record = is_array($tokens) && is_array($tokens[$selector] ?? null) ? $tokens[$selector] : null;
    $userId = (string) ($user['id'] ?? '');
    $authVersion = max(1, (int) ($sessionUser['auth_version'] ?? 0));
    $tokenHash = is_array($record) && is_string($record['token_hash'] ?? null) ? $record['token_hash'] : '';
    if (
        !is_array($record) ||
        (string) ($record['user_id'] ?? '') !== $userId ||
        max(1, (int) ($record['auth_version'] ?? 0)) !== $authVersion ||
        (int) ($record['expires_at'] ?? 0) <= time() ||
        $tokenHash === '' ||
        !hash_equals($tokenHash, hash('sha256', $validator))
    ) {
        portal_invoice_auth_forget_current_device();
        return;
    }
    if ((int) $record['expires_at'] > time() + 30 * 24 * 60 * 60) {
        return;
    }

    $newExpires = time() + portal_invoice_auth_remember_ttl();
    $updated = portal_invoice_auth_update_remember_tokens(static function (array &$currentTokens) use ($selector, $tokenHash, $userId, $authVersion, $newExpires): bool {
        $current = $currentTokens[$selector] ?? null;
        if (
            !is_array($current) ||
            (string) ($current['user_id'] ?? '') !== $userId ||
            max(1, (int) ($current['auth_version'] ?? 0)) !== $authVersion ||
            !is_string($current['token_hash'] ?? null) ||
            !hash_equals($current['token_hash'], $tokenHash)
        ) {
            return false;
        }
        $currentTokens[$selector]['expires_at'] = $newExpires;
        return true;
    });
    if ($updated) {
        portal_invoice_auth_set_remember_cookie($raw, $newExpires);
    }
}

function portal_invoice_auth_restore_remembered_session(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    $name = portal_invoice_auth_remember_cookie_name();
    $raw = $_COOKIE[$name] ?? '';
    if ($raw === '') {
        return null;
    }
    if (!is_string($raw) || !preg_match('/\\A([a-f0-9]{32})\\.([a-f0-9]{64})\\z/', $raw, $match)) {
        portal_invoice_auth_forget_current_device();
        return null;
    }
    $selector = $match[1];
    $validator = $match[2];
    $tokens = portal_invoice_auth_read_remember_tokens();
    $record = is_array($tokens) && is_array($tokens[$selector] ?? null) ? $tokens[$selector] : null;
    $tokenHash = is_array($record) && is_string($record['token_hash'] ?? null) ? $record['token_hash'] : '';
    if (
        !is_array($record) ||
        (int) ($record['expires_at'] ?? 0) <= time() ||
        $tokenHash === '' ||
        !hash_equals($tokenHash, hash('sha256', $validator))
    ) {
        portal_invoice_auth_forget_current_device();
        return null;
    }

    $user = null;
    foreach (portal_invoice_auth_read_users() as $candidate) {
        if ((string) ($candidate['id'] ?? '') === (string) ($record['user_id'] ?? '')) {
            $user = $candidate;
            break;
        }
    }
    if (
        !is_array($user) ||
        ($user['active'] ?? false) !== true ||
        !in_array(($user['role'] ?? ''), ['admin', 'staff'], true) ||
        max(1, (int) ($user['auth_version'] ?? 1)) !== max(1, (int) ($record['auth_version'] ?? 0))
    ) {
        portal_invoice_auth_forget_current_device();
        return null;
    }
    if (!session_regenerate_id(true)) {
        return null;
    }
    $_SESSION['portal_invoice_auth'] = [
        'user_id' => (string) ($user['id'] ?? ''),
        'auth_version' => max(1, (int) ($user['auth_version'] ?? 1)),
        'authenticated_at' => time(),
    ];
    portal_invoice_auth_refresh_remember_cookie(portal_invoice_auth_public_user($user), $_SESSION['portal_invoice_auth']);
    return portal_invoice_auth_public_user($user);
}

function portal_invoice_auth_revoke_user_remember_tokens(string $userId): bool
{
    if ($userId === '') {
        return false;
    }
    return portal_invoice_auth_update_remember_tokens(static function (array &$tokens) use ($userId): bool {
        $removed = false;
        foreach ($tokens as $selector => $entry) {
            if (is_array($entry) && (string) ($entry['user_id'] ?? '') === $userId) {
                unset($tokens[$selector]);
                $removed = true;
            }
        }
        return $removed;
    });
}

function portal_invoice_auth_storage_directory_is_safe(): bool
{
    $storageDir = dirname(portal_invoice_auth_users_file());
    $portalRoot = realpath(dirname(__DIR__));
    $realStorageDir = realpath($storageDir);
    if (is_link($storageDir) || $portalRoot === false || $realStorageDir === false || !is_dir($realStorageDir)) {
        return false;
    }
    $prefix = rtrim($portalRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return strpos($realStorageDir, $prefix) === 0;
}

function portal_invoice_auth_username_key(string $username): string
{
    $username = trim($username);
    return function_exists('mb_strtolower')
        ? mb_strtolower($username, 'UTF-8')
        : strtolower($username);
}

function portal_invoice_auth_read_users(): array
{
    $path = portal_invoice_auth_users_file();
    if (is_link($path)) {
        return [];
    }
    return array_values(array_filter(load_data($path, []), 'is_array'));
}

function portal_invoice_auth_setup_state(): string
{
    $path = portal_invoice_auth_users_file();
    if (!portal_invoice_auth_storage_directory_is_safe() || is_link($path)) {
        return 'error';
    }
    if (!file_exists($path)) {
        return 'unconfigured';
    }
    if (!is_file($path) || !is_readable($path)) {
        return 'error';
    }

    $content = @file_get_contents($path);
    if (!is_string($content) || trim($content) === '') {
        return 'error';
    }
    try {
        $users = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return 'error';
    }
    if (!is_array($users)) {
        return 'error';
    }
    return $users === [] ? 'unconfigured' : 'configured';
}

function portal_invoice_auth_save_users(array $users): bool
{
    $path = portal_invoice_auth_users_file();
    if (is_link($path)) {
        return false;
    }
    return save_data($path, array_values($users));
}

function portal_invoice_auth_rate_key(string $username): string
{
    return hash('sha256', portal_invoice_auth_username_key($username));
}

function portal_invoice_auth_rate_state(string $username): array
{
    $attempts = load_data(portal_invoice_auth_attempts_file(), []);
    $key = portal_invoice_auth_rate_key($username);
    $entry = is_array($attempts[$key] ?? null) ? $attempts[$key] : [];
    $now = time();
    $lockedUntil = (int) ($entry['locked_until'] ?? 0);
    if ($lockedUntil > $now) {
        return ['locked' => true, 'remaining' => $lockedUntil - $now];
    }
    return ['locked' => false, 'remaining' => 0];
}

function portal_invoice_auth_record_failure(string $username): void
{
    $path = portal_invoice_auth_attempts_file();
    if (is_link($path)) {
        return;
    }

    $attempts = load_data($path, []);
    $now = time();
    foreach ($attempts as $key => $entry) {
        if (!is_array($entry) || (int) ($entry['last_attempt'] ?? 0) < $now - 86400) {
            unset($attempts[$key]);
        }
    }

    $key = portal_invoice_auth_rate_key($username);
    $entry = is_array($attempts[$key] ?? null) ? $attempts[$key] : [];
    $windowStart = (int) ($entry['window_start'] ?? 0);
    if ($windowStart <= 0 || $windowStart < $now - 900) {
        $entry = ['count' => 0, 'window_start' => $now, 'locked_until' => 0];
    }

    $entry['count'] = (int) ($entry['count'] ?? 0) + 1;
    $entry['last_attempt'] = $now;
    if ($entry['count'] >= 5) {
        $entry['locked_until'] = $now + 900;
    }
    $attempts[$key] = $entry;

    // Avoid an unbounded rate-limit file if usernames are submitted repeatedly.
    if (count($attempts) > 2000) {
        uasort($attempts, static fn(array $a, array $b): int => (int) ($a['last_attempt'] ?? 0) <=> (int) ($b['last_attempt'] ?? 0));
        $attempts = array_slice($attempts, -1500, null, true);
    }
    save_data($path, $attempts);
}

function portal_invoice_auth_clear_failures(string $username): void
{
    $path = portal_invoice_auth_attempts_file();
    if (is_link($path)) {
        return;
    }
    $attempts = load_data($path, []);
    unset($attempts[portal_invoice_auth_rate_key($username)]);
    save_data($path, $attempts);
}

function portal_invoice_auth_login(string $username, string $pin, bool $remember = false): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return ['ok' => false, 'code' => 'session_unavailable'];
    }

    $username = trim($username);
    $rate = portal_invoice_auth_rate_state($username);
    if ($rate['locked']) {
        return ['ok' => false, 'code' => 'locked', 'remaining' => $rate['remaining']];
    }

    if (
        $username === '' || strlen($username) > 120 ||
        preg_match('//u', $username) !== 1 ||
        !preg_match('/\A\d{6}\z/', $pin)
    ) {
        portal_invoice_auth_record_failure($username);
        return ['ok' => false, 'code' => 'invalid_credentials'];
    }

    $user = null;
    $needle = portal_invoice_auth_username_key($username);
    foreach (portal_invoice_auth_read_users() as $candidate) {
        if (
            portal_invoice_auth_username_key((string) ($candidate['username'] ?? '')) === $needle &&
            ($candidate['active'] ?? false) === true &&
            in_array(($candidate['role'] ?? ''), ['admin', 'staff'], true)
        ) {
            $user = $candidate;
            break;
        }
    }

    $hash = is_array($user) && is_string($user['pin_hash'] ?? null) ? $user['pin_hash'] : '';
    if ($user === null || $hash === '' || !password_verify($pin, $hash)) {
        portal_invoice_auth_record_failure($username);
        return ['ok' => false, 'code' => 'invalid_credentials'];
    }

    // Replace any remembered login on this browser only after credentials pass.
    portal_invoice_auth_forget_current_device(false);
    if (!session_regenerate_id(true)) {
        portal_invoice_auth_clear_remember_cookie();
        return ['ok' => false, 'code' => 'session_unavailable'];
    }
    portal_invoice_auth_clear_failures($username);
    $_SESSION['portal_invoice_auth'] = [
        'user_id' => (string) ($user['id'] ?? ''),
        'auth_version' => max(1, (int) ($user['auth_version'] ?? 1)),
        'authenticated_at' => time(),
    ];

    $rememberSaved = false;
    if ($remember) {
        $rememberSaved = portal_invoice_auth_issue_remember_token($user);
        if (!$rememberSaved) {
            portal_invoice_auth_clear_remember_cookie();
        }
    } else {
        portal_invoice_auth_clear_remember_cookie();
    }

    if (function_exists('portal_log')) {
        portal_log('invoice.pin_login.success');
    }

    return [
        'ok' => true,
        'user' => portal_invoice_auth_public_user($user),
        'remember_saved' => $rememberSaved,
    ];
}

function portal_invoice_auth_current_user(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    if (function_exists('portal_central_admin_auth_portal_user')) {
        $centralAdmin = portal_central_admin_auth_portal_user();
        if (is_array($centralAdmin)) {
            return $centralAdmin;
        }
    }
    return portal_invoice_auth_personal_user();
}

/** Resolve a personal invoice identity without treating the shared central PIN as a person. */
function portal_invoice_auth_personal_user(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    $sessionUser = $_SESSION['portal_invoice_auth'] ?? null;
    if (!is_array($sessionUser)) {
        return portal_invoice_auth_restore_remembered_session();
    }

    $userId = $sessionUser['user_id'] ?? '';
    if (!is_string($userId) || $userId === '') {
        unset($_SESSION['portal_invoice_auth']);
        portal_invoice_auth_forget_current_device();
        return null;
    }

    foreach (portal_invoice_auth_read_users() as $user) {
        if ((string) ($user['id'] ?? '') !== $userId) {
            continue;
        }
        if (
            ($user['active'] ?? false) !== true ||
            !in_array(($user['role'] ?? ''), ['admin', 'staff'], true) ||
            max(1, (int) ($user['auth_version'] ?? 1)) !== max(1, (int) ($sessionUser['auth_version'] ?? 0))
        ) {
            unset($_SESSION['portal_invoice_auth']);
            portal_invoice_auth_forget_current_device();
            return null;
        }
        $publicUser = portal_invoice_auth_public_user($user);
        portal_invoice_auth_refresh_remember_cookie($publicUser, $sessionUser);
        return $publicUser;
    }

    unset($_SESSION['portal_invoice_auth']);
    portal_invoice_auth_forget_current_device();
    return null;
}

function portal_invoice_auth_public_user(array $user): array
{
    return [
        'id' => (string) ($user['id'] ?? ''),
        'username' => (string) ($user['username'] ?? ''),
        'name' => (string) ($user['name'] ?? $user['username'] ?? ''),
        'role' => in_array(($user['role'] ?? ''), ['admin', 'staff'], true) ? $user['role'] : 'staff',
        'active' => ($user['active'] ?? false) === true,
    ];
}

function portal_invoice_auth_is_admin(): bool
{
    $user = portal_invoice_auth_current_user();
    return is_array($user) && ($user['role'] ?? '') === 'admin';
}

/** Re-verify the currently authenticated invoice admin's PIN for sensitive settings. */
function portal_invoice_auth_verify_current_admin_pin(string $pin): array
{
    $current = portal_invoice_auth_current_user();
    if (!is_array($current) || ($current['role'] ?? '') !== 'admin') {
        return ['ok' => false, 'code' => 'forbidden'];
    }
    if (($current['auth_source'] ?? '') === 'central_admin') {
        return function_exists('portal_central_admin_auth_verify_current_pin')
            ? portal_central_admin_auth_verify_current_pin($pin)
            : ['ok' => false, 'code' => 'forbidden'];
    }
    $username = is_string($current['username'] ?? null) ? $current['username'] : '';
    if ($username === '') {
        return ['ok' => false, 'code' => 'forbidden'];
    }
    $rate = portal_invoice_auth_rate_state($username);
    if (($rate['locked'] ?? false) === true) {
        return ['ok' => false, 'code' => 'locked'];
    }
    if (!preg_match('/\A\d{6}\z/', $pin)) {
        portal_invoice_auth_record_failure($username);
        return ['ok' => false, 'code' => 'invalid_pin'];
    }

    foreach (portal_invoice_auth_read_users() as $storedUser) {
        if (
            (string) ($storedUser['id'] ?? '') !== (string) ($current['id'] ?? '') ||
            ($storedUser['active'] ?? false) !== true || ($storedUser['role'] ?? '') !== 'admin'
        ) {
            continue;
        }
        $hash = is_string($storedUser['pin_hash'] ?? null) ? $storedUser['pin_hash'] : '';
        if ($hash !== '' && password_verify($pin, $hash)) {
            portal_invoice_auth_clear_failures($username);
            return ['ok' => true, 'code' => 'verified'];
        }
        break;
    }
    portal_invoice_auth_record_failure($username);
    return ['ok' => false, 'code' => 'invalid_pin'];
}

function portal_invoice_auth_logout(): void
{
    portal_invoice_auth_forget_current_device();
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    unset($_SESSION['portal_invoice_auth']);
    session_regenerate_id(true);
    if (function_exists('portal_log')) {
        portal_log('invoice.pin_logout');
    }
}

function portal_invoice_auth_admin_users(): array
{
    if (!portal_invoice_auth_is_admin()) {
        return [];
    }
    return array_map('portal_invoice_auth_public_user', portal_invoice_auth_read_users());
}

function portal_invoice_auth_create_user(string $username, string $name, string $pin, string $role = 'staff'): array
{
    if (!portal_invoice_auth_is_admin()) {
        return ['ok' => false, 'code' => 'forbidden'];
    }

    $username = trim($username);
    $name = trim($name);
    if (
        $username === '' || strlen($username) > 120 || preg_match('//u', $username) !== 1 ||
        !preg_match('/\A[\pL\pN._@+-]{1,120}\z/u', $username)
    ) {
        return ['ok' => false, 'code' => 'invalid_username'];
    }
    if (preg_match('//u', $name) !== 1 || preg_match('/[\x00-\x1F\x7F]/u', $name) || (function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name)) > 120) {
        return ['ok' => false, 'code' => 'invalid_name'];
    }
    if (!preg_match('/\A\d{6}\z/', $pin)) {
        return ['ok' => false, 'code' => 'invalid_pin'];
    }
    if (!in_array($role, ['admin', 'staff'], true)) {
        return ['ok' => false, 'code' => 'invalid_role'];
    }

    $users = portal_invoice_auth_read_users();
    $usernameKey = portal_invoice_auth_username_key($username);
    foreach ($users as $user) {
        if (portal_invoice_auth_username_key((string) ($user['username'] ?? '')) === $usernameKey) {
            return ['ok' => false, 'code' => 'duplicate_username'];
        }
    }

    $algo = defined('PASSWORD_ARGON2ID') && function_exists('password_algos') && in_array('argon2id', password_algos(), true)
        ? PASSWORD_ARGON2ID
        : PASSWORD_DEFAULT;
    $hash = password_hash($pin, $algo);
    if (!is_string($hash) || $hash === '') {
        return ['ok' => false, 'code' => 'hash_failed'];
    }

    try {
        $id = bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        return ['ok' => false, 'code' => 'id_failed'];
    }
    $users[] = [
        'id' => $id,
        'username' => $username,
        'name' => $name !== '' ? $name : $username,
        'role' => $role,
        'pin_hash' => $hash,
        'auth_version' => 1,
        'active' => true,
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    if (!portal_invoice_auth_save_users($users)) {
        return ['ok' => false, 'code' => 'save_failed'];
    }
    if (function_exists('portal_log')) {
        portal_log('invoice.pin_user_created');
    }
    return ['ok' => true, 'user' => portal_invoice_auth_public_user(end($users))];
}

function portal_invoice_auth_create_first_admin(string $username, string $name, string $pin): array
{
    $username = trim($username);
    $name = trim($name);
    if (
        $username === '' || strlen($username) > 120 || preg_match('//u', $username) !== 1 ||
        !preg_match('/\A[\pL\pN._@+-]{1,120}\z/u', $username)
    ) {
        return ['ok' => false, 'code' => 'invalid_username'];
    }
    if (preg_match('//u', $name) !== 1 || preg_match('/[\x00-\x1F\x7F]/u', $name) || (function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name)) > 120) {
        return ['ok' => false, 'code' => 'invalid_name'];
    }
    if (!preg_match('/\A\d{6}\z/', $pin)) {
        return ['ok' => false, 'code' => 'invalid_pin'];
    }

    $usersPath = portal_invoice_auth_users_file();
    $setupLockPath = $usersPath . '.setup.lock';
    if (!portal_invoice_auth_storage_directory_is_safe() || is_link($usersPath) || is_link($setupLockPath)) {
        return ['ok' => false, 'code' => 'storage_error'];
    }
    $oldUmask = umask(0077);
    $lock = @fopen($setupLockPath, 'c');
    umask($oldUmask);
    if (!is_resource($lock)) {
        return ['ok' => false, 'code' => 'storage_error'];
    }
    @chmod($setupLockPath, 0600);
    if (!@flock($lock, LOCK_EX)) {
        fclose($lock);
        return ['ok' => false, 'code' => 'storage_error'];
    }

    try {
        $state = portal_invoice_auth_setup_state();
        if ($state === 'error') {
            return ['ok' => false, 'code' => 'storage_error'];
        }
        if ($state !== 'unconfigured') {
            return ['ok' => false, 'code' => 'setup_closed'];
        }

        $algo = defined('PASSWORD_ARGON2ID') && function_exists('password_algos') && in_array('argon2id', password_algos(), true)
            ? PASSWORD_ARGON2ID
            : PASSWORD_DEFAULT;
        $hash = password_hash($pin, $algo);
        if (!is_string($hash) || $hash === '') {
            return ['ok' => false, 'code' => 'hash_failed'];
        }
        try {
            $id = bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            return ['ok' => false, 'code' => 'id_failed'];
        }

        $firstAdmin = [
            'id' => $id,
            'username' => $username,
            'name' => $name !== '' ? $name : $username,
            'role' => 'admin',
            'pin_hash' => $hash,
            'auth_version' => 1,
            'active' => true,
            'created_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'updated_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ];
        if (!portal_invoice_auth_save_users([$firstAdmin])) {
            return ['ok' => false, 'code' => 'save_failed'];
        }
        if (function_exists('portal_log')) {
            portal_log('invoice.pin_user_created');
        }
        return ['ok' => true, 'user' => portal_invoice_auth_public_user($firstAdmin)];
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function portal_invoice_auth_reset_pin(string $userId, string $pin): array
{
    $currentAdmin = portal_invoice_auth_current_user();
    if (!is_array($currentAdmin) || ($currentAdmin['role'] ?? '') !== 'admin') {
        return ['ok' => false, 'code' => 'forbidden'];
    }
    if ($userId === '' || !preg_match('/\A\d{6}\z/', $pin)) {
        return ['ok' => false, 'code' => 'invalid_input'];
    }

    $users = portal_invoice_auth_read_users();
    $targetIndex = null;
    foreach ($users as $index => $user) {
        if ((string) ($user['id'] ?? '') === $userId) {
            $targetIndex = $index;
            break;
        }
    }
    if ($targetIndex === null || ($users[$targetIndex]['active'] ?? false) !== true) {
        return ['ok' => false, 'code' => 'user_not_found'];
    }
    if ((string) ($users[$targetIndex]['id'] ?? '') === (string) ($currentAdmin['id'] ?? '')) {
        return ['ok' => false, 'code' => 'self_reset_not_allowed'];
    }

    $algo = defined('PASSWORD_ARGON2ID') && function_exists('password_algos') && in_array('argon2id', password_algos(), true)
        ? PASSWORD_ARGON2ID
        : PASSWORD_DEFAULT;
    $hash = password_hash($pin, $algo);
    if (!is_string($hash) || $hash === '') {
        return ['ok' => false, 'code' => 'hash_failed'];
    }

    $users[$targetIndex]['pin_hash'] = $hash;
    $users[$targetIndex]['auth_version'] = max(1, (int) ($users[$targetIndex]['auth_version'] ?? 1)) + 1;
    $users[$targetIndex]['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
    if (!portal_invoice_auth_save_users($users)) {
        return ['ok' => false, 'code' => 'save_failed'];
    }
    portal_invoice_auth_revoke_user_remember_tokens($userId);
    if (function_exists('portal_log')) {
        portal_log('invoice.pin_reset');
    }
    return ['ok' => true];
}
