<?php
declare(strict_types=1);

/**
 * Independent six-digit PIN authentication for service protocols only.
 *
 * The protocol tab gets its own PHP session cookie, user file, and login-rate
 * limit. It does not use the invoice-tab authentication session or remember-me
 * token. The outer Websupport/proxy password protection remains required.
 * A verified central admin identity can pass the shared admin check without
 * replacing these separate protocol accounts. User management is presented in
 * the invoice admin subtab and accepts either existing admin authorization.
 */

function portal_protocol_auth_users_file(): string
{
    return __DIR__ . '/../storage/protocol_users.json';
}

function portal_protocol_auth_attempts_file(): string
{
    return __DIR__ . '/../storage/protocol_login_attempts.json';
}

function portal_protocol_auth_session_name(): string
{
    return 'TADEGA_PROTOCOL_SESSID';
}

function portal_protocol_auth_storage_directory_is_safe(): bool
{
    $storageDir = dirname(portal_protocol_auth_users_file());
    $storageGuard = $storageDir . '/.htaccess';
    $portalRoot = realpath(dirname(__DIR__));
    $realStorageDir = realpath($storageDir);
    if (
        is_link($storageDir) || is_link($storageGuard) || !is_file($storageGuard) ||
        $portalRoot === false || $realStorageDir === false || !is_dir($realStorageDir)
    ) {
        return false;
    }
    $prefix = rtrim($portalRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return strpos($realStorageDir, $prefix) === 0;
}

function portal_protocol_auth_read_json(string $path): ?array
{
    if (
        !portal_protocol_auth_storage_directory_is_safe() || is_link($path) ||
        app_safe_data_path($path) === null
    ) {
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
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    return is_array($data) ? $data : null;
}

function portal_protocol_auth_read_users(): ?array
{
    $users = portal_protocol_auth_read_json(portal_protocol_auth_users_file());
    if (!is_array($users)) {
        return null;
    }
    return array_values(array_filter($users, 'is_array'));
}

function portal_protocol_auth_username_key(string $username): string
{
    $username = trim($username);
    return function_exists('mb_strtolower')
        ? mb_strtolower($username, 'UTF-8')
        : strtolower($username);
}

function portal_protocol_auth_public_user(array $user): array
{
    $role = in_array(($user['role'] ?? ''), ['admin', 'staff'], true) ? (string) $user['role'] : 'staff';
    return [
        'id' => (string) ($user['id'] ?? ''),
        'username' => (string) ($user['username'] ?? ''),
        'name' => (string) ($user['name'] ?? $user['username'] ?? ''),
        'role' => $role,
        'active' => ($user['active'] ?? false) === true,
    ];
}

/** Update one private JSON file under an exclusive guard lock. */
function portal_protocol_auth_update_json(string $path, callable $update): bool
{
    $guardPath = $path . '.guard.lock';
    if (
        !portal_protocol_auth_storage_directory_is_safe() ||
        app_safe_data_path($path) === null || is_link($path) || is_link($path . '.lock') || is_link($guardPath)
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
        $data = portal_protocol_auth_read_json($path);
        if (!is_array($data) || $update($data) !== true) {
            return false;
        }
        return save_data($path, $data);
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function portal_protocol_auth_rate_key(string $username): string
{
    return hash('sha256', portal_protocol_auth_username_key($username));
}

function portal_protocol_auth_rate_state(string $username): ?array
{
    $attempts = portal_protocol_auth_read_json(portal_protocol_auth_attempts_file());
    if (!is_array($attempts)) {
        return null;
    }
    $key = portal_protocol_auth_rate_key($username);
    $entry = is_array($attempts[$key] ?? null) ? $attempts[$key] : [];
    $lockedUntil = (int) ($entry['locked_until'] ?? 0);
    return $lockedUntil > time()
        ? ['locked' => true, 'remaining' => $lockedUntil - time()]
        : ['locked' => false, 'remaining' => 0];
}

function portal_protocol_auth_record_failure(string $username): void
{
    $path = portal_protocol_auth_attempts_file();
    portal_protocol_auth_update_json($path, static function (array &$attempts) use ($username): bool {
        $now = time();
        foreach ($attempts as $key => $entry) {
            if (!is_array($entry) || (int) ($entry['last_attempt'] ?? 0) < $now - 86400) {
                unset($attempts[$key]);
            }
        }
        $key = portal_protocol_auth_rate_key($username);
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
        if (count($attempts) > 2000) {
            uasort($attempts, static fn(array $left, array $right): int => (int) ($left['last_attempt'] ?? 0) <=> (int) ($right['last_attempt'] ?? 0));
            $attempts = array_slice($attempts, -1500, null, true);
        }
        return true;
    });
}

function portal_protocol_auth_clear_failures(string $username): void
{
    $path = portal_protocol_auth_attempts_file();
    portal_protocol_auth_update_json($path, static function (array &$attempts) use ($username): bool {
        unset($attempts[portal_protocol_auth_rate_key($username)]);
        return true;
    });
}

function portal_protocol_auth_login(string $username, string $pin): array
{
    if (
        session_status() !== PHP_SESSION_ACTIVE ||
        session_name() !== portal_protocol_auth_session_name()
    ) {
        return ['ok' => false, 'code' => 'session_unavailable'];
    }

    $username = trim($username);
    $rate = portal_protocol_auth_rate_state($username);
    if (!is_array($rate)) {
        return ['ok' => false, 'code' => 'storage_error'];
    }
    if ($rate['locked']) {
        return ['ok' => false, 'code' => 'locked', 'remaining' => $rate['remaining']];
    }
    if (
        $username === '' || strlen($username) > 120 || preg_match('//u', $username) !== 1 ||
        !preg_match('/\A\d{6}\z/', $pin)
    ) {
        portal_protocol_auth_record_failure($username);
        return ['ok' => false, 'code' => 'invalid_credentials'];
    }

    $users = portal_protocol_auth_read_users();
    if (!is_array($users)) {
        return ['ok' => false, 'code' => 'storage_error'];
    }
    $needle = portal_protocol_auth_username_key($username);
    $user = null;
    foreach ($users as $candidate) {
        if (
            portal_protocol_auth_username_key((string) ($candidate['username'] ?? '')) === $needle &&
            ($candidate['active'] ?? false) === true
        ) {
            $user = $candidate;
            break;
        }
    }
    $hash = is_array($user) && is_string($user['pin_hash'] ?? null) ? $user['pin_hash'] : '';
    if ($user === null || $hash === '' || !password_verify($pin, $hash)) {
        portal_protocol_auth_record_failure($username);
        return ['ok' => false, 'code' => 'invalid_credentials'];
    }

    if (!session_regenerate_id(true)) {
        return ['ok' => false, 'code' => 'session_unavailable'];
    }
    portal_protocol_auth_clear_failures($username);
    $_SESSION['portal_protocol_auth'] = [
        'user_id' => (string) ($user['id'] ?? ''),
        'auth_version' => max(1, (int) ($user['auth_version'] ?? 1)),
        'authenticated_at' => time(),
    ];
    return ['ok' => true, 'user' => portal_protocol_auth_public_user($user)];
}

function portal_protocol_auth_current_user(): ?array
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
    if (session_name() !== portal_protocol_auth_session_name()) {
        return null;
    }
    $sessionUser = $_SESSION['portal_protocol_auth'] ?? null;
    if (!is_array($sessionUser) || !is_string($sessionUser['user_id'] ?? null) || $sessionUser['user_id'] === '') {
        unset($_SESSION['portal_protocol_auth']);
        return null;
    }
    $users = portal_protocol_auth_read_users();
    if (!is_array($users)) {
        unset($_SESSION['portal_protocol_auth']);
        return null;
    }
    foreach ($users as $user) {
        if ((string) ($user['id'] ?? '') !== $sessionUser['user_id']) {
            continue;
        }
        if (
            ($user['active'] ?? false) !== true ||
            max(1, (int) ($user['auth_version'] ?? 1)) !== max(1, (int) ($sessionUser['auth_version'] ?? 0))
        ) {
            unset($_SESSION['portal_protocol_auth']);
            return null;
        }
        return portal_protocol_auth_public_user($user);
    }
    unset($_SESSION['portal_protocol_auth']);
    return null;
}

function portal_protocol_auth_logout(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || session_name() !== portal_protocol_auth_session_name()) {
        return;
    }
    unset($_SESSION['portal_protocol_auth']);
    session_regenerate_id(true);
}

function portal_protocol_auth_admin_users(): array
{
    if (!function_exists('portal_invoice_auth_is_admin') || !portal_invoice_auth_is_admin()) {
        return [];
    }
    $users = portal_protocol_auth_read_users();
    return is_array($users) ? array_map('portal_protocol_auth_public_user', $users) : [];
}

function portal_protocol_auth_create_user(string $username, string $name, string $pin, string $role = 'staff'): array
{
    if (!function_exists('portal_invoice_auth_is_admin') || !portal_invoice_auth_is_admin()) {
        return ['ok' => false, 'code' => 'forbidden'];
    }
    if (!in_array($role, ['admin', 'staff'], true)) {
        return ['ok' => false, 'code' => 'invalid_role'];
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
    $newUser = [
        'id' => $id,
        'username' => $username,
        'name' => $name !== '' ? $name : $username,
        'pin_hash' => $hash,
        'role' => $role,
        'auth_version' => 1,
        'active' => true,
        'created_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
        'updated_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
    ];
    $path = portal_protocol_auth_users_file();
    $result = ['ok' => false, 'code' => 'storage_error'];
    $saved = portal_protocol_auth_update_json($path, static function (array &$users) use ($newUser, &$result): bool {
        $key = portal_protocol_auth_username_key($newUser['username']);
        foreach ($users as $user) {
            if (is_array($user) && portal_protocol_auth_username_key((string) ($user['username'] ?? '')) === $key) {
                $result = ['ok' => false, 'code' => 'duplicate_username'];
                return false;
            }
        }
        $users[] = $newUser;
        $result = ['ok' => true, 'user' => portal_protocol_auth_public_user($newUser)];
        return true;
    });
    return $saved ? $result : ($result['code'] === 'duplicate_username' ? $result : ['ok' => false, 'code' => 'storage_error']);
}

function portal_protocol_auth_set_role(string $userId, string $role): array
{
    if (!function_exists('portal_invoice_auth_is_admin') || !portal_invoice_auth_is_admin()) {
        return ['ok' => false, 'code' => 'forbidden'];
    }
    if (!preg_match('/\\A[a-f0-9]{32}\\z/i', $userId) || !in_array($role, ['admin', 'staff'], true)) {
        return ['ok' => false, 'code' => 'invalid_input'];
    }

    $result = ['ok' => false, 'code' => 'user_not_found'];
    $saved = portal_protocol_auth_update_json(portal_protocol_auth_users_file(), static function (array &$users) use ($userId, $role, &$result): bool {
        $targetIndex = null;
        foreach ($users as $index => $user) {
            if (is_array($user) && (string) ($user['id'] ?? '') === $userId) {
                $targetIndex = $index;
                break;
            }
        }
        if ($targetIndex === null || !is_array($users[$targetIndex] ?? null)) {
            return false;
        }

        $currentRole = in_array(($users[$targetIndex]['role'] ?? ''), ['admin', 'staff'], true)
            ? (string) $users[$targetIndex]['role']
            : 'staff';
        if ($currentRole === $role) {
            $result = ['ok' => true, 'code' => 'unchanged'];
            return true;
        }
        if ($currentRole === 'admin' && $role === 'staff') {
            $anotherActiveAdmin = false;
            foreach ($users as $index => $user) {
                if (
                    $index !== $targetIndex && is_array($user)
                    && ($user['active'] ?? false) === true
                    && ($user['role'] ?? 'staff') === 'admin'
                ) {
                    $anotherActiveAdmin = true;
                    break;
                }
            }
            if (!$anotherActiveAdmin) {
                $result = ['ok' => false, 'code' => 'last_admin'];
                return false;
            }
        }

        $users[$targetIndex]['role'] = $role;
        $users[$targetIndex]['auth_version'] = max(1, (int) ($users[$targetIndex]['auth_version'] ?? 1)) + 1;
        $users[$targetIndex]['updated_at'] = gmdate('Y-m-d\\TH:i:s\\Z');
        $result = ['ok' => true, 'code' => 'role_updated'];
        return true;
    });
    if ($saved) {
        return $result;
    }
    return in_array($result['code'], ['user_not_found', 'last_admin'], true)
        ? $result
        : ['ok' => false, 'code' => 'storage_error'];
}

function portal_protocol_auth_reset_pin(string $userId, string $pin): array
{
    if (!function_exists('portal_invoice_auth_is_admin') || !portal_invoice_auth_is_admin()) {
        return ['ok' => false, 'code' => 'forbidden'];
    }
    if (!preg_match('/\A[a-f0-9]{32}\z/', $userId) || !preg_match('/\A\d{6}\z/', $pin)) {
        return ['ok' => false, 'code' => 'invalid_input'];
    }
    $algo = defined('PASSWORD_ARGON2ID') && function_exists('password_algos') && in_array('argon2id', password_algos(), true)
        ? PASSWORD_ARGON2ID
        : PASSWORD_DEFAULT;
    $hash = password_hash($pin, $algo);
    if (!is_string($hash) || $hash === '') {
        return ['ok' => false, 'code' => 'hash_failed'];
    }
    $result = ['ok' => false, 'code' => 'user_not_found'];
    $saved = portal_protocol_auth_update_json(portal_protocol_auth_users_file(), static function (array &$users) use ($userId, $hash, &$result): bool {
        foreach ($users as &$user) {
            if (!is_array($user) || (string) ($user['id'] ?? '') !== $userId) {
                continue;
            }
            $user['pin_hash'] = $hash;
            $user['auth_version'] = max(1, (int) ($user['auth_version'] ?? 1)) + 1;
            $user['updated_at'] = gmdate('Y-m-d\\TH:i:s\\Z');
            $result = ['ok' => true];
            unset($user);
            return true;
        }
        unset($user);
        return false;
    });
    if ($saved) {
        return $result;
    }
    return $result['code'] === 'user_not_found' ? $result : ['ok' => false, 'code' => 'storage_error'];
}
