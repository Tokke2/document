<?php
declare(strict_types=1);

/**
 * One central, private-storage credential for cross-portal administration.
 * The existing invoice and protocol account stores remain separate. This
 * credential is never accepted by scoped customer-PWA routes.
 */
function portal_central_admin_auth_session_name(): string
{
    return 'TADEGA_ADMIN_SESSID';
}

function portal_central_admin_auth_config_file(): string
{
    return __DIR__ . '/../storage/central_admin.json';
}

function portal_central_admin_auth_attempts_file(): string
{
    return __DIR__ . '/../storage/central_admin_login_attempts.json';
}

function portal_central_admin_auth_storage_is_safe(): bool
{
    $portalRoot = realpath(dirname(__DIR__));
    if ($portalRoot === false) {
        return false;
    }

    $storagePath = $portalRoot . DIRECTORY_SEPARATOR . 'storage';
    $storageRealPath = realpath($storagePath);
    $guardPath = $storagePath . DIRECTORY_SEPARATOR . '.htaccess';
    return !is_link($storagePath)
        && $storageRealPath === $storagePath
        && is_dir($storageRealPath)
        && !is_link($guardPath)
        && is_file($guardPath)
        && is_readable($guardPath);
}

function portal_central_admin_auth_is_scoped_pwa(): bool
{
    return defined('TADEGA_PWA_APP')
        && is_string(TADEGA_PWA_APP)
        && in_array(TADEGA_PWA_APP, ['protocol', 'invoice', 'skadeblankett'], true);
}

function portal_central_admin_auth_pin_is_valid(string $pin): bool
{
    // A central PIN contains 6–10 digits and no more than two optional symbols.
    // Keep this limited to an ASCII allowlist so accepted characters are explicit and predictable.
    if (strlen($pin) < 6 || strlen($pin) > 12 || preg_match('/\A[0-9!@#$%&*+=_-]{6,12}\z/D', $pin) !== 1) {
        return false;
    }
    $digitCount = preg_match_all('/[0-9]/', $pin);
    if (!is_int($digitCount)) {
        return false;
    }
    $symbolCount = strlen($pin) - $digitCount;
    return $digitCount >= 6 && $digitCount <= 10 && $symbolCount <= 2;
}

/** Permit a pre-policy PIN only to verify an existing hash during one-time migration. */
function portal_central_admin_auth_legacy_pin_is_valid(string $pin): bool
{
    return strlen($pin) <= 512 && preg_match('/\A[^\p{C}]{12,128}\z/uD', $pin) === 1;
}

function portal_central_admin_auth_hash_input(string $pin): string
{
    // Pre-hash to keep long, valid credentials safe on hosts whose default
    // password_hash algorithm has a short input limit (for example bcrypt).
    return hash('sha256', "tadega-central-admin-v1\0" . $pin);
}

function portal_central_admin_auth_hash_pin(string $pin): string|false
{
    if (!portal_central_admin_auth_pin_is_valid($pin)) {
        return false;
    }
    return password_hash(portal_central_admin_auth_hash_input($pin), PASSWORD_DEFAULT);
}

function portal_central_admin_auth_verify_pin_hash(string $pin, string $hash): bool
{
    return (portal_central_admin_auth_pin_is_valid($pin) || portal_central_admin_auth_legacy_pin_is_valid($pin))
        && $hash !== ''
        && password_verify(portal_central_admin_auth_hash_input($pin), $hash);
}

function portal_central_admin_auth_config_is_valid(array $config): bool
{
    $now = time();
    $createdAt = $config['created_at'] ?? null;
    $changedAt = $config['pin_changed_at'] ?? null;
    $credentialId = $config['credential_id'] ?? null;
    $pinHash = $config['pin_hash'] ?? null;
    // Missing policy metadata marks an existing credential for a one-time PIN update.
    $pinPolicyVersion = $config['pin_policy_version'] ?? 1;

    return ($config['schema_version'] ?? null) === 1
        && is_int($createdAt) && $createdAt > 0 && $createdAt <= $now + 300
        && is_int($changedAt) && $changedAt >= $createdAt && $changedAt <= $now + 300
        && is_string($credentialId) && preg_match('/\A[a-f0-9]{32}\z/', $credentialId) === 1
        && is_string($pinHash) && strlen($pinHash) >= 30 && strlen($pinHash) <= 255
        && is_int($pinPolicyVersion) && in_array($pinPolicyVersion, [1, 2], true);
}

function portal_central_admin_auth_setup_state(): string
{
    if (!portal_central_admin_auth_storage_is_safe()) {
        return 'error';
    }
    $path = portal_central_admin_auth_config_file();
    if (is_link($path)) {
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
        $config = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return 'error';
    }
    return is_array($config) && portal_central_admin_auth_config_is_valid($config)
        ? 'configured'
        : 'error';
}

function portal_central_admin_auth_read_config(): ?array
{
    if (portal_central_admin_auth_setup_state() !== 'configured') {
        return null;
    }
    $content = @file_get_contents(portal_central_admin_auth_config_file());
    if (!is_string($content)) {
        return null;
    }
    try {
        $config = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    return is_array($config) && portal_central_admin_auth_config_is_valid($config)
        ? $config
        : null;
}

/** Run a small operation under the credential-file lock. */
function portal_central_admin_auth_with_config_lock(callable $operation): array
{
    if (!portal_central_admin_auth_storage_is_safe()) {
        return ['ok' => false, 'code' => 'storage_error'];
    }

    $path = portal_central_admin_auth_config_file();
    $guardPath = $path . '.guard.lock';
    if (is_link($path) || is_link($guardPath)) {
        return ['ok' => false, 'code' => 'storage_error'];
    }

    $oldUmask = umask(0077);
    $lock = @fopen($guardPath, 'c');
    umask($oldUmask);
    if (!is_resource($lock)) {
        return ['ok' => false, 'code' => 'storage_error'];
    }
    @chmod($guardPath, 0600);
    if (!@flock($lock, LOCK_EX)) {
        fclose($lock);
        return ['ok' => false, 'code' => 'storage_error'];
    }

    try {
        if (is_link($path) || is_link($guardPath)) {
            return ['ok' => false, 'code' => 'storage_error'];
        }
        $state = portal_central_admin_auth_setup_state();
        $config = $state === 'configured' ? portal_central_admin_auth_read_config() : null;
        if ($state === 'error' || ($state === 'configured' && !is_array($config))) {
            return ['ok' => false, 'code' => 'storage_error'];
        }
        return $operation($state, $config, $path);
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function portal_central_admin_auth_write_config(string $path, array $config): bool
{
    if (
        !portal_central_admin_auth_storage_is_safe() ||
        !portal_central_admin_auth_config_is_valid($config) ||
        is_link($path) || is_link($path . '.lock')
    ) {
        return false;
    }
    return save_data($path, $config);
}

function portal_central_admin_auth_rate_key(): string
{
    $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!is_string($remoteAddress) || $remoteAddress === '' || strlen($remoteAddress) > 128) {
        $remoteAddress = 'unknown';
    }
    return hash('sha256', 'tadega-central-admin-login-v1|' . $remoteAddress);
}

function portal_central_admin_auth_read_attempts(): ?array
{
    if (!portal_central_admin_auth_storage_is_safe()) {
        return null;
    }
    $path = portal_central_admin_auth_attempts_file();
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
        $attempts = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    return is_array($attempts) ? $attempts : null;
}

function portal_central_admin_auth_update_attempts(callable $operation): bool
{
    if (!portal_central_admin_auth_storage_is_safe()) {
        return false;
    }
    $path = portal_central_admin_auth_attempts_file();
    $guardPath = $path . '.guard.lock';
    if (is_link($path) || is_link($path . '.lock') || is_link($guardPath)) {
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
        if (is_link($path) || is_link($path . '.lock') || is_link($guardPath)) {
            return false;
        }
        $attempts = portal_central_admin_auth_read_attempts();
        if (!is_array($attempts) || $operation($attempts) !== true) {
            return false;
        }
        return save_data($path, $attempts);
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function portal_central_admin_auth_rate_state(): array
{
    $attempts = portal_central_admin_auth_read_attempts();
    if (!is_array($attempts)) {
        return ['ok' => false, 'code' => 'storage_error'];
    }
    $entry = is_array($attempts[portal_central_admin_auth_rate_key()] ?? null)
        ? $attempts[portal_central_admin_auth_rate_key()]
        : [];
    $now = time();
    $lockedUntil = is_numeric($entry['locked_until'] ?? null) ? (int) $entry['locked_until'] : 0;
    return [
        'ok' => true,
        'locked' => $lockedUntil > $now,
        'remaining' => max(0, $lockedUntil - $now),
    ];
}

function portal_central_admin_auth_record_failure(): bool
{
    $now = time();
    $key = portal_central_admin_auth_rate_key();
    return portal_central_admin_auth_update_attempts(static function (array &$attempts) use ($now, $key): bool {
        foreach ($attempts as $entryKey => $entry) {
            $lastAttempt = is_array($entry) && is_numeric($entry['last_attempt'] ?? null)
                ? (int) $entry['last_attempt']
                : 0;
            if ($lastAttempt <= 0 || $lastAttempt < $now - 86400) {
                unset($attempts[$entryKey]);
            }
        }

        $entry = is_array($attempts[$key] ?? null) ? $attempts[$key] : [];
        $windowStart = is_numeric($entry['window_start'] ?? null) ? (int) $entry['window_start'] : 0;
        $lockedUntil = is_numeric($entry['locked_until'] ?? null) ? (int) $entry['locked_until'] : 0;
        if ($windowStart <= 0 || $windowStart < $now - 900 || ($lockedUntil > 0 && $lockedUntil <= $now)) {
            $entry = ['count' => 0, 'window_start' => $now, 'locked_until' => 0];
        }

        $entry['count'] = max(0, (int) ($entry['count'] ?? 0)) + 1;
        $entry['last_attempt'] = $now;
        if ($entry['count'] >= 5) {
            $entry['locked_until'] = $now + 900;
        }
        $attempts[$key] = $entry;

        if (count($attempts) > 2000) {
            uasort($attempts, static function (mixed $left, mixed $right): int {
                $leftTime = is_array($left) && is_numeric($left['last_attempt'] ?? null) ? (int) $left['last_attempt'] : 0;
                $rightTime = is_array($right) && is_numeric($right['last_attempt'] ?? null) ? (int) $right['last_attempt'] : 0;
                return $leftTime <=> $rightTime;
            });
            $attempts = array_slice($attempts, -1500, null, true);
        }
        return true;
    });
}

function portal_central_admin_auth_clear_failures(): bool
{
    $key = portal_central_admin_auth_rate_key();
    return portal_central_admin_auth_update_attempts(static function (array &$attempts) use ($key): bool {
        unset($attempts[$key]);
        return true;
    });
}

function portal_central_admin_auth_cookie_path(): string
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

function portal_central_admin_auth_set_cookie_params(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || headers_sent()) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => portal_central_admin_auth_cookie_path(),
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
}

/**
 * Temporarily switch from the ordinary portal session to the separate central
 * admin session, then restore the original session and its data.
 */
function portal_central_admin_auth_with_session(callable $operation, bool $createIfMissing = false): mixed
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }

    $adminSessionName = portal_central_admin_auth_session_name();
    if (session_name() === $adminSessionName) {
        return $operation();
    }

    $primaryName = session_name();
    $primaryId = session_id();
    if ($primaryName === '' || $primaryId === '') {
        return null;
    }

    $adminSessionId = $_COOKIE[$adminSessionName] ?? '';
    $hasAdminSessionId = is_string($adminSessionId)
        && preg_match('/\A[A-Za-z0-9,-]{16,128}\z/D', $adminSessionId) === 1;
    if (!$hasAdminSessionId && !$createIfMissing) {
        return null;
    }

    if (!@session_write_close() || session_status() === PHP_SESSION_ACTIVE) {
        return null;
    }
    $_SESSION = [];
    $result = null;
    $adminSessionStarted = false;

    try {
        session_name($adminSessionName);
        session_id($hasAdminSessionId ? $adminSessionId : '');
        portal_central_admin_auth_set_cookie_params();
        if (@session_start() && session_name() === $adminSessionName && session_status() === PHP_SESSION_ACTIVE) {
            $adminSessionStarted = true;
            $result = $operation();
        }
    } catch (Throwable) {
        $result = null;
    } finally {
        if ($adminSessionStarted && session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
        $_SESSION = [];
        session_name($primaryName);
        session_id($primaryId);
        if (
            !@session_start() || session_name() !== $primaryName ||
            session_status() !== PHP_SESSION_ACTIVE
        ) {
            http_response_code(503);
            exit('Portalen kunde inte återuppta den ordinarie sessionen.');
        }
    }

    return $result;
}

function portal_central_admin_auth_request_cache_clear(): void
{
    unset($GLOBALS['__tadega_central_admin_auth_cached'], $GLOBALS['__tadega_central_admin_auth_user']);
}

function portal_central_admin_auth_current_user(): ?array
{
    if (portal_central_admin_auth_is_scoped_pwa()) {
        return null;
    }
    if (($GLOBALS['__tadega_central_admin_auth_cached'] ?? false) === true) {
        $cached = $GLOBALS['__tadega_central_admin_auth_user'] ?? null;
        return is_array($cached) ? $cached : null;
    }
    $GLOBALS['__tadega_central_admin_auth_cached'] = true;
    $GLOBALS['__tadega_central_admin_auth_user'] = null;

    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    $sessionRecord = portal_central_admin_auth_with_session(static function (): ?array {
        $record = $_SESSION['portal_central_admin_auth'] ?? null;
        if (!is_array($record)) {
            return null;
        }
        $config = portal_central_admin_auth_read_config();
        if (
            !is_array($config) ||
            !is_string($record['credential_id'] ?? null) ||
            !hash_equals((string) $config['credential_id'], $record['credential_id'])
        ) {
            unset($_SESSION['portal_central_admin_auth']);
            return null;
        }
        return [
            'config' => $config,
            'authenticated_at' => is_int($record['authenticated_at'] ?? null) ? $record['authenticated_at'] : 0,
            'must_change_pin' => ($record['must_change_pin'] ?? false) === true,
            'pin_policy_update_required' => ($record['pin_policy_update_required'] ?? false) === true,
        ];
    });

    if (!is_array($sessionRecord) || !is_array($sessionRecord['config'] ?? null)) {
        return null;
    }
    $config = $sessionRecord['config'];
    $pinPolicyUpdateRequired = ($sessionRecord['pin_policy_update_required'] ?? false) === true
        || (int) ($config['pin_policy_version'] ?? 1) < 2;
    $mustChangePin = ($sessionRecord['must_change_pin'] ?? false) === true
        || $pinPolicyUpdateRequired
        || time() >= ((int) $config['pin_changed_at'] + 20 * 86400);
    $user = [
        'id' => 'central-admin',
        'username' => 'central-admin',
        'name' => 'Central administratör',
        'role' => 'admin',
        'active' => true,
        'auth_source' => 'central_admin',
        'must_change_pin' => $mustChangePin,
        'pin_policy_update_required' => $pinPolicyUpdateRequired,
        'pin_changed_at' => (int) $config['pin_changed_at'],
        'authenticated_at' => (int) ($sessionRecord['authenticated_at'] ?? 0),
    ];
    $GLOBALS['__tadega_central_admin_auth_user'] = $user;
    return $user;
}

function portal_central_admin_auth_can_access_personal_portal(): bool
{
    if (portal_central_admin_auth_is_scoped_pwa()) {
        return false;
    }
    $user = portal_central_admin_auth_current_user();
    return is_array($user) && ($user['must_change_pin'] ?? true) !== true;
}

function portal_central_admin_auth_portal_user(): ?array
{
    if (!portal_central_admin_auth_can_access_personal_portal()) {
        return null;
    }
    return [
        'id' => 'central-admin',
        'username' => 'central-admin',
        'name' => 'Central administratör',
        'role' => 'admin',
        'active' => true,
        'auth_source' => 'central_admin',
    ];
}

function portal_central_admin_auth_new_credential_id(): string|false
{
    try {
        return bin2hex(random_bytes(16));
    } catch (Throwable) {
        return false;
    }
}

function portal_central_admin_auth_open_session(
    string $credentialId,
    bool $mustChangePin = false,
    bool $pinPolicyUpdateRequired = false
): bool {
    $result = portal_central_admin_auth_with_session(static function () use ($credentialId, $mustChangePin, $pinPolicyUpdateRequired): string|false {
        if (!@session_regenerate_id(true)) {
            return false;
        }
        $_SESSION['portal_central_admin_auth'] = [
            'credential_id' => $credentialId,
            'authenticated_at' => time(),
            'must_change_pin' => $mustChangePin,
            'pin_policy_update_required' => $pinPolicyUpdateRequired,
        ];
        return session_id();
    }, true);
    portal_central_admin_auth_request_cache_clear();
    if (!is_string($result) || preg_match('/\A[A-Za-z0-9,-]{16,128}\z/D', $result) !== 1) {
        return false;
    }
    $_COOKIE[portal_central_admin_auth_session_name()] = $result;
    return true;
}

function portal_central_admin_auth_enroll(string $pin): array
{
    if (portal_central_admin_auth_is_scoped_pwa()) {
        return ['ok' => false, 'code' => 'forbidden'];
    }
    if (!function_exists('portal_invoice_auth_current_user')) {
        return ['ok' => false, 'code' => 'forbidden'];
    }
    $invoiceUser = portal_invoice_auth_current_user();
    if (
        !is_array($invoiceUser) || ($invoiceUser['role'] ?? '') !== 'admin' ||
        ($invoiceUser['auth_source'] ?? '') === 'central_admin'
    ) {
        return ['ok' => false, 'code' => 'forbidden'];
    }
    if (!portal_central_admin_auth_pin_is_valid($pin)) {
        return ['ok' => false, 'code' => 'invalid_pin'];
    }
    $hash = portal_central_admin_auth_hash_pin($pin);
    $credentialId = portal_central_admin_auth_new_credential_id();
    if (!is_string($hash) || !is_string($credentialId)) {
        return ['ok' => false, 'code' => 'storage_error'];
    }

    $now = time();
    $created = portal_central_admin_auth_with_config_lock(static function (string $state, ?array $_existingConfig, string $path) use ($hash, $credentialId, $now): array {
        if ($state === 'configured') {
            return ['ok' => false, 'code' => 'already_configured'];
        }
        if ($state !== 'unconfigured') {
            return ['ok' => false, 'code' => 'storage_error'];
        }
        $config = [
            'schema_version' => 1,
            'pin_policy_version' => 2,
            'pin_hash' => $hash,
            'credential_id' => $credentialId,
            'created_at' => $now,
            'pin_changed_at' => $now,
        ];
        if (!portal_central_admin_auth_write_config($path, $config)) {
            return ['ok' => false, 'code' => 'storage_error'];
        }
        return ['ok' => true, 'code' => 'created'];
    });
    if (($created['ok'] ?? false) !== true) {
        return $created;
    }
    // A credential reset should not inherit a previous temporary login lockout.
    portal_central_admin_auth_clear_failures();
    if (!portal_central_admin_auth_open_session($credentialId)) {
        return ['ok' => false, 'code' => 'session_error'];
    }
    return ['ok' => true, 'code' => 'created'];
}

function portal_central_admin_auth_login(string $pin): array
{
    if (session_status() !== PHP_SESSION_ACTIVE || portal_central_admin_auth_is_scoped_pwa()) {
        return ['ok' => false, 'code' => 'forbidden'];
    }
    $state = portal_central_admin_auth_setup_state();
    if ($state === 'unconfigured') {
        return ['ok' => false, 'code' => 'setup_required'];
    }
    if ($state !== 'configured') {
        return ['ok' => false, 'code' => 'storage_error'];
    }

    $rate = portal_central_admin_auth_rate_state();
    if (($rate['ok'] ?? false) !== true) {
        return ['ok' => false, 'code' => 'storage_error'];
    }
    if (($rate['locked'] ?? false) === true) {
        return ['ok' => false, 'code' => 'locked', 'remaining' => (int) ($rate['remaining'] ?? 0)];
    }

    $config = portal_central_admin_auth_read_config();
    if (!is_array($config)) {
        return ['ok' => false, 'code' => 'storage_error'];
    }
    if (!portal_central_admin_auth_verify_pin_hash($pin, (string) $config['pin_hash'])) {
        if (!portal_central_admin_auth_record_failure()) {
            return ['ok' => false, 'code' => 'storage_error'];
        }
        return ['ok' => false, 'code' => 'invalid_pin'];
    }
    $pinPolicyUpdateRequired = !portal_central_admin_auth_pin_is_valid($pin)
        || (int) ($config['pin_policy_version'] ?? 1) < 2;
    $mustChangePin = $pinPolicyUpdateRequired
        || time() >= ((int) $config['pin_changed_at'] + 20 * 86400);
    if (!portal_central_admin_auth_clear_failures()) {
        return ['ok' => false, 'code' => 'storage_error'];
    }
    if (!portal_central_admin_auth_open_session((string) $config['credential_id'], $mustChangePin, $pinPolicyUpdateRequired)) {
        return ['ok' => false, 'code' => 'session_error'];
    }
    return [
        'ok' => true,
        'code' => 'authenticated',
        'must_change_pin' => $mustChangePin,
        'pin_policy_update_required' => $pinPolicyUpdateRequired,
    ];
}

function portal_central_admin_auth_logout(): void
{
    portal_central_admin_auth_with_session(static function (): bool {
        unset($_SESSION['portal_central_admin_auth']);
        return true;
    });
    portal_central_admin_auth_request_cache_clear();

    $cookieName = portal_central_admin_auth_session_name();
    unset($_COOKIE[$cookieName]);
    if (!headers_sent()) {
        setcookie($cookieName, '', [
            'expires' => time() - 3600,
            'path' => portal_central_admin_auth_cookie_path(),
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
}

function portal_central_admin_auth_verify_current_pin(string $pin): array
{
    $current = portal_central_admin_auth_current_user();
    if (!is_array($current) || portal_central_admin_auth_is_scoped_pwa()) {
        return ['ok' => false, 'code' => 'forbidden'];
    }
    $rate = portal_central_admin_auth_rate_state();
    if (($rate['ok'] ?? false) !== true) {
        return ['ok' => false, 'code' => 'storage_error'];
    }
    if (($rate['locked'] ?? false) === true) {
        return ['ok' => false, 'code' => 'locked', 'remaining' => (int) ($rate['remaining'] ?? 0)];
    }
    $config = portal_central_admin_auth_read_config();
    if (!is_array($config)) {
        return ['ok' => false, 'code' => 'storage_error'];
    }
    if (!portal_central_admin_auth_verify_pin_hash($pin, (string) $config['pin_hash'])) {
        if (!portal_central_admin_auth_record_failure()) {
            return ['ok' => false, 'code' => 'storage_error'];
        }
        return ['ok' => false, 'code' => 'invalid_pin'];
    }
    if (!portal_central_admin_auth_clear_failures()) {
        return ['ok' => false, 'code' => 'storage_error'];
    }
    return ['ok' => true, 'code' => 'verified'];
}

function portal_central_admin_auth_change_pin(string $currentPin, string $newPin): array
{
    $currentUser = portal_central_admin_auth_current_user();
    if (!is_array($currentUser) || portal_central_admin_auth_is_scoped_pwa()) {
        return ['ok' => false, 'code' => 'forbidden'];
    }
    if (!portal_central_admin_auth_pin_is_valid($newPin)) {
        return ['ok' => false, 'code' => 'invalid_new_pin'];
    }

    $rate = portal_central_admin_auth_rate_state();
    if (($rate['ok'] ?? false) !== true) {
        return ['ok' => false, 'code' => 'storage_error'];
    }
    if (($rate['locked'] ?? false) === true) {
        return ['ok' => false, 'code' => 'locked', 'remaining' => (int) ($rate['remaining'] ?? 0)];
    }

    $newHash = portal_central_admin_auth_hash_pin($newPin);
    $newCredentialId = portal_central_admin_auth_new_credential_id();
    if (!is_string($newHash) || !is_string($newCredentialId)) {
        return ['ok' => false, 'code' => 'storage_error'];
    }
    // The request-facing user intentionally omits credential internals. Re-read
    // the current session record through the guarded session helper instead.
    $sessionCredentialId = portal_central_admin_auth_with_session(static function (): string {
        $record = $_SESSION['portal_central_admin_auth'] ?? null;
        return is_array($record) && is_string($record['credential_id'] ?? null)
            ? $record['credential_id']
            : '';
    });
    $oldCredentialId = is_string($sessionCredentialId) ? $sessionCredentialId : '';
    if ($oldCredentialId === '') {
        return ['ok' => false, 'code' => 'forbidden'];
    }

    $now = time();
    $result = portal_central_admin_auth_with_config_lock(static function (string $state, ?array $config, string $path) use (
        $currentPin,
        $newPin,
        $newHash,
        $newCredentialId,
        $oldCredentialId,
        $now
    ): array {
        if ($state !== 'configured' || !is_array($config)) {
            return ['ok' => false, 'code' => 'storage_error'];
        }
        if (!hash_equals((string) $config['credential_id'], $oldCredentialId)) {
            return ['ok' => false, 'code' => 'session_expired'];
        }
        if (!portal_central_admin_auth_verify_pin_hash($currentPin, (string) $config['pin_hash'])) {
            return ['ok' => false, 'code' => 'invalid_current_pin'];
        }
        if (portal_central_admin_auth_verify_pin_hash($newPin, (string) $config['pin_hash'])) {
            return ['ok' => false, 'code' => 'same_pin'];
        }

        $config['pin_hash'] = $newHash;
        $config['pin_policy_version'] = 2;
        $config['credential_id'] = $newCredentialId;
        $config['pin_changed_at'] = $now;
        if (!portal_central_admin_auth_write_config($path, $config)) {
            return ['ok' => false, 'code' => 'storage_error'];
        }
        return ['ok' => true, 'code' => 'changed'];
    });

    if (($result['code'] ?? '') === 'invalid_current_pin') {
        if (!portal_central_admin_auth_record_failure()) {
            return ['ok' => false, 'code' => 'storage_error'];
        }
    } elseif (($result['ok'] ?? false) === true) {
        if (!portal_central_admin_auth_clear_failures()) {
            return ['ok' => false, 'code' => 'storage_error'];
        }
        $sessionUpdated = portal_central_admin_auth_with_session(static function () use ($oldCredentialId, $newCredentialId): string|false {
            $record = $_SESSION['portal_central_admin_auth'] ?? null;
            if (
                !is_array($record) || !is_string($record['credential_id'] ?? null) ||
                !hash_equals($oldCredentialId, $record['credential_id']) ||
                !@session_regenerate_id(true)
            ) {
                unset($_SESSION['portal_central_admin_auth']);
                return false;
            }
            $_SESSION['portal_central_admin_auth'] = [
                'credential_id' => $newCredentialId,
                'authenticated_at' => time(),
            ];
            return session_id();
        });
        portal_central_admin_auth_request_cache_clear();
        if (!is_string($sessionUpdated) || preg_match('/\A[A-Za-z0-9,-]{16,128}\z/D', $sessionUpdated) !== 1) {
            return ['ok' => false, 'code' => 'session_error'];
        }
        $_COOKIE[portal_central_admin_auth_session_name()] = $sessionUpdated;
    }
    return $result;
}
