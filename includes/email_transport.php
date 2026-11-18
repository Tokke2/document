<?php
declare(strict_types=1);

/**
 * Outbound email transport for the portal. SMTP credentials are read only from
 * the protected storage/email_transport.json file; they never belong in source,
 * logs, backups, or the browser. Without a file, the legacy PHP mail() fallback
 * remains available. A present but invalid SMTP config fails closed.
 */
function portal_email_transport_storage_is_safe(): bool
{
    $storageDir = __DIR__ . '/../storage';
    $guardPath = $storageDir . '/.htaccess';
    $root = realpath(dirname(__DIR__));
    $realStorage = realpath($storageDir);
    return !is_link($storageDir) && !is_link($guardPath) && is_file($guardPath) &&
        $root !== false && $realStorage !== false && is_dir($realStorage) &&
        strpos($realStorage, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0;
}

function portal_email_transport_config_path(): string
{
    return __DIR__ . '/../storage/email_transport.json';
}

function portal_email_transport_revision(array $config, string $seed): string
{
    $parts = [
        (string) ($config['host'] ?? ''),
        (string) ($config['port'] ?? ''),
        (string) ($config['security'] ?? ''),
        (string) ($config['username'] ?? ''),
        (string) ($config['password'] ?? ''),
        (string) ($config['from_email'] ?? ''),
        (string) ($config['from_name'] ?? ''),
        (string) ($config['timeout'] ?? ''),
        $seed,
    ];
    return substr(hash('sha256', implode("\0", $parts)), 0, 32);
}

function portal_email_transport_normalize_config(array $config): array
{
    foreach (['host', 'security', 'username', 'password', 'from_email', 'from_name'] as $stringKey) {
        if (array_key_exists($stringKey, $config) && !is_string($config[$stringKey])) {
            return ['mode' => 'invalid', 'code' => 'config_invalid_values'];
        }
    }
    foreach (['port', 'timeout'] as $numericKey) {
        if (array_key_exists($numericKey, $config) && !is_numeric($config[$numericKey])) {
            return ['mode' => 'invalid', 'code' => 'config_invalid_values'];
        }
    }
    foreach (['locked', 'tested'] as $boolKey) {
        if (array_key_exists($boolKey, $config) && !is_bool($config[$boolKey])) {
            return ['mode' => 'invalid', 'code' => 'config_invalid_values'];
        }
    }
    $host = isset($config['host']) && is_string($config['host']) ? trim($config['host']) : 'smtp.m101.websupport.se';
    $port = is_numeric($config['port'] ?? null) ? (int) $config['port'] : 465;
    $security = isset($config['security']) && is_string($config['security']) ? strtolower(trim($config['security'])) : ($port === 587 ? 'tls' : 'ssl');
    $username = isset($config['username']) && is_string($config['username']) ? trim($config['username']) : '';
    $password = isset($config['password']) && is_string($config['password']) ? $config['password'] : '';
    $fromEmail = isset($config['from_email']) && is_string($config['from_email']) ? trim($config['from_email']) : $username;
    $fromName = isset($config['from_name']) && is_string($config['from_name']) ? trim($config['from_name']) : 'Tadega Portal';
    $timeout = is_numeric($config['timeout'] ?? null) ? (int) $config['timeout'] : 15;

    if (
        $host === '' || strlen($host) > 253 || !preg_match('/\A[A-Za-z0-9.-]+\z/', $host) ||
        !in_array([$security, $port], [['ssl', 465], ['tls', 587]], true) ||
        $username === '' || strlen($username) > 254 || filter_var($username, FILTER_VALIDATE_EMAIL) === false ||
        $password === '' || strlen($password) > 360 || preg_match('/[\x00-\x1F\x7F]/', $password) ||
        $fromEmail === '' || strlen($fromEmail) > 254 || filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false ||
        $fromName === '' || strlen($fromName) > 120 || preg_match('/[\x00-\x1F\x7F]/', $fromName) ||
        $timeout < 5 || $timeout > 30
    ) {
        return ['mode' => 'invalid', 'code' => 'config_invalid_values'];
    }

    $revisionSeed = isset($config['revision']) && is_string($config['revision']) && preg_match('/\A[a-f0-9]{32}\z/', $config['revision'])
        ? $config['revision']
        : 'legacy';
    $revision = portal_email_transport_revision([
        'host' => $host, 'port' => $port, 'security' => $security,
        'username' => $username, 'password' => $password,
        'from_email' => $fromEmail, 'from_name' => $fromName, 'timeout' => $timeout,
    ], $revisionSeed);
    $testedRevision = isset($config['tested_revision']) && is_string($config['tested_revision']) && preg_match('/\A[a-f0-9]{32}\z/', $config['tested_revision'])
        ? $config['tested_revision']
        : '';
    return [
        'mode' => 'smtp',
        'code' => 'configured',
        'host' => $host,
        'port' => $port,
        'security' => $security,
        'username' => $username,
        'password' => $password,
        'from_email' => $fromEmail,
        'from_name' => $fromName,
        'timeout' => $timeout,
        'locked' => ($config['locked'] ?? false) === true,
        'tested' => ($config['tested'] ?? false) === true && $testedRevision !== '' && hash_equals($revision, $testedRevision),
        'revision' => $revision,
        'revision_seed' => $revisionSeed,
        'tested_revision' => $testedRevision,
    ];
}

function portal_email_transport_config(): array
{
    $configPath = portal_email_transport_config_path();
    if (!portal_email_transport_storage_is_safe() || is_link($configPath)) {
        return ['mode' => 'invalid', 'code' => 'unsafe_config'];
    }
    if (!file_exists($configPath)) {
        return ['mode' => 'mail', 'code' => 'smtp_not_configured', 'locked' => false, 'tested' => false, 'revision' => ''];
    }
    if (!is_file($configPath) || !is_readable($configPath) || (int) @filesize($configPath) > 8192) {
        return ['mode' => 'invalid', 'code' => 'config_unreadable'];
    }

    // Normalize permissions for the secret file and refuse to use it if the
    // host leaves it accessible to group/other accounts.
    @chmod($configPath, 0600);
    clearstatcache(true, $configPath);
    $permissions = @fileperms($configPath);
    if ($permissions === false || ($permissions & 0077) !== 0) {
        return ['mode' => 'invalid', 'code' => 'config_permissions'];
    }

    $raw = @file_get_contents($configPath);
    if (!is_string($raw) || $raw === '' || strlen($raw) > 8192) {
        return ['mode' => 'invalid', 'code' => 'config_unreadable'];
    }
    try {
        $config = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return ['mode' => 'invalid', 'code' => 'config_invalid_json'];
    }
    if (!is_array($config)) {
        return ['mode' => 'invalid', 'code' => 'config_invalid_json'];
    }
    return portal_email_transport_normalize_config($config);
}

/** Return non-secret transport information suitable for the admin status page. */
function portal_email_transport_status(): array
{
    $config = portal_email_transport_config();
    if (($config['mode'] ?? '') === 'smtp') {
        return [
            'transport' => 'smtp',
            'configured' => true,
            'host' => (string) $config['host'],
            'port' => (int) $config['port'],
            'security' => (string) $config['security'],
            'username' => (string) $config['username'],
            'from_email' => (string) $config['from_email'],
            'from_name' => (string) $config['from_name'],
            'has_password' => (string) $config['password'] !== '',
            'locked' => (bool) ($config['locked'] ?? false),
            'tested' => (bool) ($config['tested'] ?? false),
            'code' => 'configured',
        ];
    }
    $base = [
        'transport' => ($config['mode'] ?? '') === 'mail' ? 'php_mail' : 'unavailable',
        'configured' => false,
        'host' => 'smtp.m101.websupport.se',
        'port' => 465,
        'security' => 'ssl',
        'username' => '',
        'from_email' => '',
        'from_name' => 'Tadega Portal',
        'has_password' => false,
        'locked' => false,
        'tested' => false,
        'code' => (string) ($config['code'] ?? 'config_invalid'),
    ];
    return $base;
}

function portal_email_transport_write_config(array $config): bool
{
    $path = portal_email_transport_config_path();
    if (
        !portal_email_transport_storage_is_safe() || !function_exists('save_data') ||
        is_link($path) || is_link($path . '.lock')
    ) {
        return false;
    }
    $data = [
        'host' => (string) ($config['host'] ?? ''),
        'port' => (int) ($config['port'] ?? 0),
        'security' => (string) ($config['security'] ?? ''),
        'username' => (string) ($config['username'] ?? ''),
        'password' => (string) ($config['password'] ?? ''),
        'from_email' => (string) ($config['from_email'] ?? ''),
        'from_name' => (string) ($config['from_name'] ?? ''),
        'timeout' => (int) ($config['timeout'] ?? 15),
        'locked' => ($config['locked'] ?? false) === true,
        'tested' => ($config['tested'] ?? false) === true,
        'revision' => is_string($config['revision_seed'] ?? null) ? $config['revision_seed'] : (is_string($config['revision'] ?? null) ? $config['revision'] : 'legacy'),
        'tested_revision' => is_string($config['tested_revision'] ?? null) ? $config['tested_revision'] : '',
    ];
    if (!save_data($path, $data)) {
        return false;
    }
    @chmod($path, 0600);
    clearstatcache(true, $path);
    $permissions = @fileperms($path);
    return $permissions !== false && ($permissions & 0077) === 0;
}

function portal_email_transport_save_settings(array $input): array
{
    $current = portal_email_transport_config();
    if (($current['mode'] ?? '') === 'invalid') {
        return ['ok' => false, 'code' => (string) ($current['code'] ?? 'config_invalid')];
    }
    if (($current['mode'] ?? '') === 'smtp' && ($current['locked'] ?? false) === true) {
        return ['ok' => false, 'code' => 'locked'];
    }

    $profile = isset($input['smtp_profile']) && is_string($input['smtp_profile']) ? $input['smtp_profile'] : 'ssl465';
    $profileValues = [
        'ssl465' => ['port' => 465, 'security' => 'ssl'],
        'tls587' => ['port' => 587, 'security' => 'tls'],
    ];
    if (!isset($profileValues[$profile])) {
        return ['ok' => false, 'code' => 'config_invalid_values'];
    }
    $passwordInput = isset($input['smtp_password']) && is_string($input['smtp_password']) ? $input['smtp_password'] : '';
    $password = $passwordInput !== ''
        ? $passwordInput
        : (($current['mode'] ?? '') === 'smtp' ? (string) $current['password'] : '');
    $username = isset($input['smtp_username']) && is_string($input['smtp_username']) ? trim($input['smtp_username']) : '';
    $fromEmail = isset($input['smtp_from_email']) && is_string($input['smtp_from_email']) ? trim($input['smtp_from_email']) : '';
    $candidate = portal_email_transport_normalize_config([
        'host' => isset($input['smtp_host']) && is_string($input['smtp_host']) ? $input['smtp_host'] : 'smtp.m101.websupport.se',
        'port' => $profileValues[$profile]['port'],
        'security' => $profileValues[$profile]['security'],
        'username' => $username,
        'password' => $password,
        'from_email' => $fromEmail !== '' ? $fromEmail : $username,
        'from_name' => isset($input['smtp_from_name']) && is_string($input['smtp_from_name']) ? $input['smtp_from_name'] : 'Tadega Portal',
        'timeout' => 15,
        'locked' => false,
        'tested' => false,
    ]);
    if (($candidate['mode'] ?? '') !== 'smtp') {
        return ['ok' => false, 'code' => (string) ($candidate['code'] ?? 'config_invalid_values')];
    }
    try {
        $candidate['revision_seed'] = bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        return ['ok' => false, 'code' => 'storage_error'];
    }
    $candidate['revision'] = portal_email_transport_revision($candidate, $candidate['revision_seed']);
    $candidate['locked'] = false;
    $candidate['tested'] = false;
    $candidate['tested_revision'] = '';
    return portal_email_transport_write_config($candidate)
        ? ['ok' => true, 'code' => 'saved']
        : ['ok' => false, 'code' => 'storage_error'];
}

function portal_email_transport_set_locked(bool $locked, bool $adminPinVerified = false): array
{
    if (!$locked && !$adminPinVerified) {
        return ['ok' => false, 'code' => 'pin_required'];
    }
    $config = portal_email_transport_config();
    if (($config['mode'] ?? '') !== 'smtp') {
        return ['ok' => false, 'code' => 'not_configured'];
    }
    if ($locked && ($config['tested'] ?? false) !== true) {
        return ['ok' => false, 'code' => 'test_required'];
    }
    $config['locked'] = $locked;
    return portal_email_transport_write_config($config)
        ? ['ok' => true, 'code' => $locked ? 'locked' : 'unlocked']
        : ['ok' => false, 'code' => 'storage_error'];
}

function portal_email_transport_mark_tested(string $expectedRevision = ''): bool
{
    $config = portal_email_transport_config();
    if (($config['mode'] ?? '') !== 'smtp') {
        return false;
    }
    if ($expectedRevision !== '' && !hash_equals((string) ($config['revision'] ?? ''), $expectedRevision)) {
        return false;
    }
    if (($config['locked'] ?? false) === true) {
        return ($config['tested'] ?? false) === true;
    }
    $config['tested'] = true;
    $config['tested_revision'] = (string) ($config['revision'] ?? '');
    return portal_email_transport_write_config($config);
}

function portal_email_smtp_read_reply(mixed $socket): array
{
    if (!is_resource($socket)) {
        return ['code' => 0, 'lines' => []];
    }
    $lines = [];
    $expectedCode = 0;
    $totalBytes = 0;
    for ($i = 0; $i < 30; $i++) {
        $line = @fgets($socket, 2048);
        if (!is_string($line) || $line === '') {
            return ['code' => 0, 'lines' => $lines];
        }
        $totalBytes += strlen($line);
        if ($totalBytes > 8192) {
            return ['code' => 0, 'lines' => $lines];
        }
        $line = rtrim($line, "\r\n");
        if (!preg_match('/\A([0-9]{3})([ -])/', $line, $match)) {
            return ['code' => 0, 'lines' => $lines];
        }
        $code = (int) $match[1];
        if ($expectedCode !== 0 && $code !== $expectedCode) {
            return ['code' => 0, 'lines' => $lines];
        }
        $expectedCode = $code;
        $lines[] = $line;
        if ($match[2] === ' ') {
            return ['code' => $expectedCode, 'lines' => $lines];
        }
    }
    return ['code' => 0, 'lines' => $lines];
}

function portal_email_smtp_has_starttls(array $reply): bool
{
    $lines = is_array($reply['lines'] ?? null) ? array_values(array_filter($reply['lines'], 'is_string')) : [];
    $capabilities = strtoupper(implode("\n", $lines));
    return preg_match('/(?:\A|\n)250[- ]STARTTLS(?:[ \t\n]|\z)/', $capabilities) === 1;
}

function portal_email_smtp_write_all(mixed $socket, string $data): bool
{
    if (!is_resource($socket)) {
        return false;
    }
    $length = strlen($data);
    $offset = 0;
    while ($offset < $length) {
        $written = @fwrite($socket, substr($data, $offset));
        if (!is_int($written) || $written <= 0) {
            return false;
        }
        $offset += $written;
    }
    return true;
}

function portal_email_smtp_command(mixed $socket, string $command): array
{
    if (str_contains($command, "\r") || str_contains($command, "\n") || !portal_email_smtp_write_all($socket, $command . "\r\n")) {
        return ['code' => 0, 'lines' => []];
    }
    return portal_email_smtp_read_reply($socket);
}

function portal_email_smtp_build_message(string $recipient, string $subject, string $body, string $headers, array $config): ?string
{
    if (str_contains($body, "\0") || strlen($body) > 30000000 || strlen($headers) > 8192) {
        return null;
    }
    $subject = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $subject) ?? '');
    if ($subject === '' || strlen($subject) > 900) {
        return null;
    }
    $replyTo = '';
    $contentType = 'text/plain; charset=UTF-8';
    $contentTransferEncoding = '';
    $normalizedHeaders = str_replace(["\r\n", "\r"], "\n", $headers);
    foreach (explode("\n", $normalizedHeaders) as $headerLine) {
        if ($headerLine === '') {
            continue;
        }
        if (!preg_match('/\A([A-Za-z0-9-]+):[ \t]*(.*)\z/', $headerLine, $match)) {
            continue;
        }
        $name = strtolower($match[1]);
        $value = trim($match[2]);
        if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            continue;
        }
        if ($name === 'reply-to' && filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            $replyTo = $value;
        } elseif ($name === 'content-type' && strlen($value) <= 500 && preg_match('/\A[A-Za-z0-9!#$&^_.+\/-]+\/[A-Za-z0-9!#$&^_.+\/-]+(?:;[ \t]*[A-Za-z0-9!#$&^_.+\/-]+=(?:"[^"\r\n]{1,250}"|[A-Za-z0-9_.+\/-]{1,250}))*\z/', $value)) {
            $contentType = $value;
        } elseif ($name === 'content-transfer-encoding' && in_array(strtolower($value), ['7bit', '8bit', 'base64', 'quoted-printable'], true)) {
            $contentTransferEncoding = strtolower($value);
        }
    }

    $encodedSubject = preg_match('/\A=\?[^?]+\?[BbQq]\?.{1,850}\?=\z/', $subject)
        ? $subject
        : '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedFromName = '=?UTF-8?B?' . base64_encode((string) $config['from_name']) . '?=';
    try {
        $messageId = bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        return null;
    }

    $messageHeaders = [
        'Date: ' . date(DATE_RFC2822),
        'Message-ID: <' . $messageId . '@tadega.com>',
        'From: ' . $encodedFromName . ' <' . $config['from_email'] . '>',
        'To: ' . $recipient,
        'Subject: ' . $encodedSubject,
        'MIME-Version: 1.0',
        'Content-Type: ' . $contentType,
    ];
    if ($replyTo !== '') {
        $messageHeaders[] = 'Reply-To: ' . $replyTo;
    }
    if ($contentTransferEncoding !== '') {
        $messageHeaders[] = 'Content-Transfer-Encoding: ' . $contentTransferEncoding;
    }

    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = str_replace("\n", "\r\n", $body);
    return implode("\r\n", $messageHeaders) . "\r\n\r\n" . $body;
}

/**
 * Send one message. A missing SMTP config uses the existing PHP mail() fallback;
 * a present but unsafe/invalid SMTP config never silently downgrades to mail().
 * The result contains a fixed transport/code and an internal revision token only.
 */
function portal_email_transport_send(string $recipient, string $subject, string $body, string $headers = ''): array
{
    if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n\x00]/', $recipient)) {
        return ['accepted' => false, 'transport' => 'none', 'code' => 'invalid_recipient'];
    }
    $config = portal_email_transport_config();
    if (($config['mode'] ?? '') === 'mail') {
        $accepted = function_exists('mail') && @mail($recipient, $subject, $body, $headers) === true;
        return ['accepted' => $accepted, 'transport' => 'php_mail', 'code' => $accepted ? 'accepted' : 'php_mail_failed'];
    }
    if (($config['mode'] ?? '') !== 'smtp') {
        return ['accepted' => false, 'transport' => 'unavailable', 'code' => (string) ($config['code'] ?? 'config_invalid')];
    }

    $delivery = portal_email_smtp_send($recipient, $subject, $body, $headers, $config);
    $delivery['revision'] = is_string($config['revision'] ?? null) ? $config['revision'] : 'legacy';
    return $delivery;
}

function portal_email_smtp_send(string $recipient, string $subject, string $body, string $headers, array $config): array
{
    $rawMessage = portal_email_smtp_build_message($recipient, $subject, $body, $headers, $config);
    if (!is_string($rawMessage)) {
        return ['accepted' => false, 'transport' => 'smtp', 'code' => 'message_invalid'];
    }
    $host = (string) $config['host'];
    $port = (int) $config['port'];
    $timeout = (int) $config['timeout'];
    $sslContext = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ],
    ]);
    $target = (($config['security'] ?? '') === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $errno = 0;
    $errorText = '';
    $socket = @stream_socket_client($target, $errno, $errorText, $timeout, STREAM_CLIENT_CONNECT, $sslContext);
    if (!is_resource($socket)) {
        return ['accepted' => false, 'transport' => 'smtp', 'code' => 'connect_failed'];
    }
    stream_set_timeout($socket, $timeout);

    try {
        $reply = portal_email_smtp_read_reply($socket);
        if (($reply['code'] ?? 0) !== 220) {
            return ['accepted' => false, 'transport' => 'smtp', 'code' => 'greeting_failed'];
        }
        $reply = portal_email_smtp_command($socket, 'EHLO tadega.com');
        if (($reply['code'] ?? 0) !== 250) {
            return ['accepted' => false, 'transport' => 'smtp', 'code' => 'ehlo_failed'];
        }
        if (($config['security'] ?? '') === 'tls') {
            if (!portal_email_smtp_has_starttls($reply)) {
                return ['accepted' => false, 'transport' => 'smtp', 'code' => 'starttls_unavailable'];
            }
            $reply = portal_email_smtp_command($socket, 'STARTTLS');
            if (($reply['code'] ?? 0) !== 220 || @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                return ['accepted' => false, 'transport' => 'smtp', 'code' => 'tls_failed'];
            }
            $reply = portal_email_smtp_command($socket, 'EHLO tadega.com');
            if (($reply['code'] ?? 0) !== 250) {
                return ['accepted' => false, 'transport' => 'smtp', 'code' => 'ehlo_failed'];
            }
        }

        $reply = portal_email_smtp_command($socket, 'AUTH LOGIN');
        if (($reply['code'] ?? 0) !== 334) {
            return ['accepted' => false, 'transport' => 'smtp', 'code' => 'auth_failed'];
        }
        $reply = portal_email_smtp_command($socket, base64_encode((string) $config['username']));
        if (($reply['code'] ?? 0) !== 334) {
            return ['accepted' => false, 'transport' => 'smtp', 'code' => 'auth_failed'];
        }
        $reply = portal_email_smtp_command($socket, base64_encode((string) $config['password']));
        if (($reply['code'] ?? 0) !== 235) {
            return ['accepted' => false, 'transport' => 'smtp', 'code' => 'auth_failed'];
        }

        $reply = portal_email_smtp_command($socket, 'MAIL FROM:<' . $config['from_email'] . '>');
        if (($reply['code'] ?? 0) !== 250) {
            return ['accepted' => false, 'transport' => 'smtp', 'code' => 'sender_rejected'];
        }
        $reply = portal_email_smtp_command($socket, 'RCPT TO:<' . $recipient . '>');
        if (!in_array(($reply['code'] ?? 0), [250, 251], true)) {
            return ['accepted' => false, 'transport' => 'smtp', 'code' => 'recipient_rejected'];
        }
        $reply = portal_email_smtp_command($socket, 'DATA');
        if (($reply['code'] ?? 0) !== 354) {
            return ['accepted' => false, 'transport' => 'smtp', 'code' => 'data_rejected'];
        }

        $lines = explode("\r\n", $rawMessage);
        foreach ($lines as &$line) {
            if (str_starts_with($line, '.')) {
                $line = '.' . $line;
            }
        }
        unset($line);
        $wireMessage = implode("\r\n", $lines) . "\r\n.\r\n";
        if (!portal_email_smtp_write_all($socket, $wireMessage)) {
            return ['accepted' => false, 'transport' => 'smtp', 'code' => 'write_failed'];
        }
        $reply = portal_email_smtp_read_reply($socket);
        if (($reply['code'] ?? 0) !== 250) {
            return ['accepted' => false, 'transport' => 'smtp', 'code' => 'message_rejected'];
        }
        portal_email_smtp_command($socket, 'QUIT');
        return ['accepted' => true, 'transport' => 'smtp', 'code' => 'accepted'];
    } catch (Throwable $e) {
        return ['accepted' => false, 'transport' => 'smtp', 'code' => 'connection_failed'];
    } finally {
        if (is_resource($socket)) {
            fclose($socket);
        }
    }
}
