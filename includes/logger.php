<?php
declare(strict_types=1);
/**
 * Privacy-first audit logger for the Tadega portal (PHP 8.5+).
 *
 * Audit entries use fixed event names and random request IDs. Diagnostic entries
 * use allow-listed error types/codes/field keys and sanitized technical metadata.
 * Neither log stores raw messages, IP, headers, URLs, credentials, customer or
 * form values, tokens, or file/PDF content; optional account IDs are pseudonymized.
 */

if (!class_exists('TadegaPrivacyLogger', false)) {
    final class TadegaPrivacyLogger
    {
        private const array ALLOWED_EVENTS = [
            'portal.activity',
            'customer.created', 'customer.updated', 'customer.deleted',
            'inventory.created', 'inventory.updated', 'inventory.deleted',
            'inventory.login_success', 'inventory.login_failed', 'inventory.logout', 'inventory.admin_bootstrapped',
            'inventory.article_saved', 'inventory.article_save_failed', 'inventory.article_deleted', 'inventory.article_delete_blocked',
            'inventory.labels_created', 'inventory.labels_failed', 'inventory.session_started', 'inventory.scan_recorded', 'inventory.session_closed',
            'inventory.count_reconciled', 'inventory.count_reconcile_failed', 'inventory.transfer_completed', 'inventory.transfer_failed',
            'inventory.stock_adjusted', 'inventory.adjustment_failed', 'inventory.workplace_saved', 'inventory.workplace_save_failed',
            'inventory.password_changed', 'inventory.password_change_failed', 'inventory.password_reset', 'inventory.password_reset_failed',
            'inventory.user_created', 'inventory.user_create_failed', 'inventory.user_status_changed', 'inventory.user_status_change_failed',
            'protocol.created', 'protocol.updated', 'protocol.email_accepted', 'protocol.email_failed', 'protocol.email_not_configured',
            'invoice.created', 'invoice.updated', 'invoice.email_accepted', 'invoice.email_failed', 'invoice.email_not_configured', 'invoice.email_retry',
            'document.created', 'document.updated', 'document.deleted',
            'file.uploaded', 'file.deleted', 'settings.updated', 'settings.save_failed', 'backup.created', 'release.auto_installed', 'release.auto_failed',
            'skade.form_received', 'skade.signature_drawn', 'skade.pdf_generated', 'skade.pdf_failed',
            'skade.email_sent', 'skade.email_accepted', 'skade.email_failed', 'skade.email_not_configured', 'skade.email_retry_accepted',
            'skade.email_retry_failed', 'skade.email_status_save_failed', 'skade.template_selected',
            'skade.fallback_used',
            'system.logger_event', 'system.logger_debug', 'system.logger_info',
            'system.logger_warning', 'system.logger_error', 'system.exception',
            'system.php_error', 'system.php_fatal', 'system.timer_completed',
        ];

        private const array ALLOWED_APP_ERROR_CODES = [
            'skade.form_missing', 'skade.client_validation_failed', 'skade.token_invalid', 'skade.duplicate_submission', 'skade.token_state_invalid',
            'skade.field_invalid', 'skade.signature_required', 'skade.signature_processor_unavailable',
            'skade.signature_data_invalid', 'skade.signature_image_invalid', 'skade.registration_invalid',
            'skade.required_fields_missing', 'skade.email_invalid', 'skade.phone_invalid',
            'skade.accident_date_invalid', 'skade.signature_date_invalid', 'skade.replacement_date_invalid',
            'skade.appointment_invalid', 'skade.numeric_invalid', 'skade.integer_invalid', 'skade.choice_invalid',
            'skade.damage_description_missing', 'skade.checkbox_invalid', 'skade.declaration_unconfirmed',
            'skade.template_missing', 'skade.archive_path_invalid', 'skade.pdf_directory_create_failed',
            'skade.pdf_directory_unwritable', 'skade.secure_filename_failed', 'skade.token_processing_failed',
            'skade.pdf_output_path_invalid', 'skade.pdf_output_unwritable', 'skade.pdf_library_missing',
            'skade.pdf_signature_temp_unavailable', 'skade.pdf_signature_write_failed', 'skade.pdf_signature_invalid',
            'skade.pdf_output_not_written', 'skade.pdf_generation_failed', 'skade.pdf_text_unfit', 'skade.record_id_failed',
            'skade.record_save_failed', 'skade.unclassified_failure',
        ];

        private const array ALLOWED_DIAGNOSTIC_FIELD_KEYS = [
            'regnr', 'namn_adress', 'person_orgnr', 'telefon', 'epost', 'modell', 'matarstallning',
            'forsakringsbolag', 'skadedatum', 'skadeplats', 'skadenummer', 'inbokad', 'other_cause',
            'stone_count', 'mount_time', 'glass_price', 'notes', 'wiper_price', 'washer_liters',
            'solar_price', 'payment_amount', 'signature_date', 'replacement_date', 'signature_png',
            'signature_confirmed', 'damage_cause', 'payment_method', 'glass_locations', 'actions',
            'equipment', 'foretag', 'nya_torkarblad_bestalls', 'wiper_service', 'solar_film', 'consent',
        ];

        private const DEFAULT_RELEASE_LABEL = 'oversionerad';

        private static bool $enabled = true;
        private static ?string $requestId = null;
        private static ?string $diagnosticBuildId = null;
        private static bool $diagnosticWriting = false;
        private static bool $errorHandlersRegistered = false;
        private static bool $shutdownRegistered = false;

        public static function init(array $options = []): void
        {
            if (array_key_exists('enabled', $options)) {
                self::$enabled = (bool) $options['enabled'];
            }
        }

        public static function setEnabled(bool $enabled): void
        {
            self::$enabled = $enabled;
        }

        // Compatibility method; levels are intentionally not written to disk.
        public static function setMinLevel(mixed $level): void
        {
            // No-op by design: only explicit allow-listed events are recorded.
        }

        public static function getRequestId(): string
        {
            if (self::$requestId === null) {
                try {
                    self::$requestId = bin2hex(random_bytes(12));
                } catch (Throwable $e) {
                    self::$requestId = bin2hex(pack('N2', mt_rand(), mt_rand()));
                }
            }
            return self::$requestId;
        }

        private static function diagnosticReleaseLabel(): string
        {
            $release = getenv('TADEGA_PORTAL_RELEASE');
            if (!is_string($release) || preg_match('/\\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\\z/', $release) !== 1) {
                return self::DEFAULT_RELEASE_LABEL;
            }
            return $release;
        }

        /** Return a stable fingerprint of deployed application code and static templates only. */
        private static function diagnosticBuildId(): string
        {
            if (self::$diagnosticBuildId !== null) {
                return self::$diagnosticBuildId;
            }
            try {
                $root = realpath(dirname(__DIR__));
                if ($root === false) {
                    return self::$diagnosticBuildId = 'unavailable';
                }
                $files = [];
                $markers = [];
                $rootFiles = [
                    '.htaccess', 'index.php', 'functions.php', 'skadeblankett-kund.php', 'service-worker.js',
                    'offline.html', 'manifest.webmanifest', 'manifest-skadeblankett.webmanifest',
                    'json/index.php', 'json/.htaccess', 'storage/index.php', 'storage/.htaccess',
                    'logs/index.php', 'logs/.htaccess', 'pdf/.htaccess', 'png/.htaccess',
                ];
                foreach ($rootFiles as $relativePath) {
                    $path = $root . DIRECTORY_SEPARATOR . $relativePath;
                    if (is_link($path)) {
                        $markers[] = 'symlink:' . $relativePath;
                    } elseif (is_file($path)) {
                        $files[$relativePath] = $path;
                    } else {
                        $markers[] = 'missing:' . $relativePath;
                    }
                }
                foreach (new DirectoryIterator($root) as $entry) {
                    if ($entry->isDot()) {
                        continue;
                    }
                    $relativePath = $entry->getFilename();
                    if (strtolower(pathinfo($relativePath, PATHINFO_EXTENSION)) !== 'php') {
                        continue;
                    }
                    if ($entry->isLink()) {
                        $markers[] = 'symlink:' . $relativePath;
                    } elseif ($entry->isFile()) {
                        $files[$relativePath] = $entry->getPathname();
                    }
                }
                foreach (['includes', 'tabs', 'assets', 'svg'] as $directoryName) {
                    $directory = $root . DIRECTORY_SEPARATOR . $directoryName;
                    if (is_link($directory) || !is_dir($directory)) {
                        $markers[] = 'unavailable-directory:' . $directoryName;
                        continue;
                    }
                    try {
                        $iterator = new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
                        );
                        foreach ($iterator as $entry) {
                            $entryPath = $entry->getPathname();
                            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($entryPath, strlen($root) + 1));
                            if ($entry->isLink()) {
                                $markers[] = 'symlink:' . $relativePath;
                                continue;
                            }
                            if (!$entry->isFile()) {
                                continue;
                            }
                            $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
                            if (basename($relativePath) === '.htaccess' || in_array($extension, ['php', 'js', 'css', 'html', 'svg', 'webmanifest', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'ico', 'woff', 'woff2', 'ttf', 'otf'], true)) {
                                $files[$relativePath] = $entryPath;
                            }
                        }
                    } catch (Throwable $e) {
                        $markers[] = 'scan-failed:' . $directoryName;
                    }
                }
                foreach ([
                    'pdf/Bilrutan Skadeblankett.pdf', 'pdf/Bilrutan_Skadeblankett.pdf',
                    'png/blankett.png', 'png/Bilrutan_Skadeblankett.png',
                ] as $relativePath) {
                    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
                    if (is_link($path)) {
                        $markers[] = 'symlink:' . $relativePath;
                    } elseif (is_file($path)) {
                        $files[$relativePath] = $path;
                    } else {
                        $markers[] = 'missing:' . $relativePath;
                    }
                }
                ksort($files, SORT_STRING);
                sort($markers, SORT_STRING);
                $context = hash_init('sha256');
                foreach ($markers as $marker) {
                    hash_update($context, 'M' . "\0" . $marker . "\0");
                }
                foreach ($files as $relativePath => $path) {
                    $fileHash = @hash_file('sha256', $path);
                    if (!is_string($fileHash)) {
                        hash_update($context, 'U' . "\0" . $relativePath . "\0");
                        continue;
                    }
                    hash_update($context, 'F' . "\0" . $relativePath . "\0" . $fileHash . "\0");
                }
                return self::$diagnosticBuildId = 'sha256-' . substr(hash_final($context), 0, 20);
            } catch (Throwable $e) {
                return self::$diagnosticBuildId = 'unavailable';
            }
        }

        public static function event(string $event): void
        {
            if (!self::$enabled || !in_array($event, self::ALLOWED_EVENTS, true)) {
                return;
            }

            $dir = __DIR__ . '/../logs';
            $path = $dir . '/audit.jsonl';
            $lockPath = $path . '.lock';
            $backupPath = $path . '.1';

            if (is_link($dir) || is_link($path) || is_link($lockPath) || is_link($backupPath)) {
                error_log('Tadega audit logger could not write: unsafe path.');
                return;
            }

            if (!is_dir($dir)) {
                $oldUmask = umask(0077);
                $created = @mkdir($dir, 0700, true);
                umask($oldUmask);
                if (!$created && !is_dir($dir)) {
                    error_log('Tadega audit logger could not create its private directory.');
                    return;
                }
            }
            @chmod($dir, 0700);

            $oldUmask = umask(0077);
            $lock = @fopen($lockPath, 'c');
            umask($oldUmask);
            if (!is_resource($lock)) {
                error_log('Tadega audit logger could not open its lock file.');
                return;
            }
            @chmod($lockPath, 0600);

            if (!@flock($lock, LOCK_EX)) {
                fclose($lock);
                error_log('Tadega audit logger could not lock its file.');
                return;
            }

            try {
                clearstatcache(true, $path);
                if (is_file($path) && @filesize($path) >= 1048576) {
                    if (is_file($backupPath) && !@unlink($backupPath)) {
                        error_log('Tadega audit logger rotation failed.');
                        return;
                    }
                    if (!@rename($path, $backupPath)) {
                        error_log('Tadega audit logger rotation failed.');
                        return;
                    }
                    @chmod($backupPath, 0600);
                }

                $oldUmask = umask(0077);
                $file = @fopen($path, 'ab');
                umask($oldUmask);
                if (!is_resource($file)) {
                    error_log('Tadega audit logger could not open its log file.');
                    return;
                }
                @chmod($path, 0600);

                $line = json_encode([
                    'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                    'event' => $event,
                    'request_id' => self::getRequestId(),
                ], JSON_UNESCAPED_SLASHES);

                if (is_string($line)) {
                    $written = @fwrite($file, $line . PHP_EOL);
                    @fflush($file);
                    if ($written === false) {
                        error_log('Tadega audit logger write failed.');
                    }
                }
                fclose($file);
            } finally {
                @flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        private static function diagnosticActorHash(string $area, string $userId): ?string
        {
            $storageDir = __DIR__ . '/../storage';
            $guard = $storageDir . '/.htaccess';
            $root = realpath(dirname(__DIR__));
            $realStorage = realpath($storageDir);
            if (is_link($storageDir) || is_link($guard) || !is_file($guard) || $root === false || $realStorage === false || strpos($realStorage, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) {
                return null;
            }
            $keyPath = $realStorage . '/diagnostic_actor.key';
            if (is_link($keyPath)) {
                return null;
            }
            if (!file_exists($keyPath)) {
                try {
                    $newKey = random_bytes(32);
                } catch (Throwable $e) {
                    return null;
                }
                $oldUmask = umask(0077);
                $handle = @fopen($keyPath, 'x');
                umask($oldUmask);
                if (is_resource($handle)) {
                    $written = @fwrite($handle, $newKey);
                    @fflush($handle);
                    fclose($handle);
                    @chmod($keyPath, 0600);
                    if ($written !== 32) {
                        @unlink($keyPath);
                        return null;
                    }
                }
            }
            if (is_link($keyPath) || !is_file($keyPath) || !is_readable($keyPath)) {
                return null;
            }
            $key = @file_get_contents($keyPath);
            if (!is_string($key) || strlen($key) !== 32) {
                return null;
            }
            return substr(hash_hmac('sha256', $area . ':' . $userId, $key), 0, 24);
        }

        private static function diagnosticActor(): array
        {
            if (session_status() !== PHP_SESSION_ACTIVE || !is_array($_SESSION ?? null)) {
                return [];
            }
            $area = '';
            $userId = '';
            $role = '';
            $invoiceSession = $_SESSION['portal_invoice_auth'] ?? null;
            if (is_array($invoiceSession) && is_string($invoiceSession['user_id'] ?? null) && $invoiceSession['user_id'] !== '') {
                $area = 'invoice';
                $userId = $invoiceSession['user_id'];
                $role = 'invoice_user';
                if (function_exists('portal_invoice_auth_read_users')) {
                    $users = portal_invoice_auth_read_users();
                    if (is_array($users)) {
                        foreach ($users as $user) {
                            if (is_array($user) && (string) ($user['id'] ?? '') === $userId && in_array(($user['role'] ?? ''), ['admin', 'staff'], true)) {
                                $role = (string) $user['role'];
                                break;
                            }
                        }
                    }
                }
            } else {
                $protocolSession = $_SESSION['portal_protocol_auth'] ?? null;
                if (is_array($protocolSession) && is_string($protocolSession['user_id'] ?? null) && $protocolSession['user_id'] !== '') {
                    $area = 'protocol';
                    $userId = $protocolSession['user_id'];
                    $role = 'protocol_user';
                }
            }
            if ($area === '' || $userId === '') {
                return [];
            }
            $hash = self::diagnosticActorHash($area, $userId);
            return $hash === null ? ['actor_area' => $area, 'actor_role' => $role] : ['actor_hash' => $hash, 'actor_area' => $area, 'actor_role' => $role];
        }

        /** Remove diagnostic records older than the requested retention period. */
        private static function pruneDiagnosticFiles(string $path, string $backupPath, int $cutoff): void
        {
            foreach ([$backupPath, $path] as $logPath) {
                if (!is_file($logPath) || is_link($logPath) || !is_readable($logPath)) {
                    continue;
                }
                $contents = @file_get_contents($logPath);
                if (!is_string($contents)) {
                    continue;
                }
                $kept = [];
                foreach (preg_split('/\\r?\\n/', $contents) ?: [] as $line) {
                    if ($line === '') {
                        continue;
                    }
                    try {
                        $entry = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                    } catch (JsonException) {
                        continue;
                    }
                    $timestamp = is_array($entry) && is_string($entry['timestamp_utc'] ?? null) ? strtotime($entry['timestamp_utc']) : false;
                    if (is_array($entry) && $timestamp !== false && $timestamp >= $cutoff) {
                        $kept[] = $line;
                    }
                }
                $newContents = $kept ? implode(PHP_EOL, $kept) . PHP_EOL : '';
                if ($newContents === $contents) {
                    continue;
                }
                $temp = @tempnam(dirname($logPath), '.diagnostics-prune-');
                if (!is_string($temp) || $temp === '') {
                    continue;
                }
                $written = @file_put_contents($temp, $newContents, LOCK_EX);
                if ($written === false || $written !== strlen($newContents)) {
                    @unlink($temp);
                    continue;
                }
                @chmod($temp, 0600);
                if (!@rename($temp, $logPath)) {
                    @unlink($temp);
                    continue;
                }
                @chmod($logPath, 0600);
            }
        }

        /** Read only sanitized, recent error entries for an admin diagnostics page. */
        public static function readDiagnostics(int $days = 30, int $limit = 200): ?array
        {
            $dir = __DIR__ . '/../logs';
            $path = $dir . '/diagnostics.jsonl';
            $backupPath = $path . '.1';
            $lockPath = $path . '.lock';
            $guard = $dir . '/.htaccess';
            $root = realpath(dirname(__DIR__));
            $realDir = realpath($dir);
            if (is_link($dir) || is_link($guard) || !is_file($guard) || is_link($path) || is_link($backupPath) || is_link($lockPath) || $root === false || $realDir === false || strpos($realDir, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) {
                return null;
            }
            if (!is_file($path) && !is_file($backupPath)) {
                return [];
            }
            $oldUmask = umask(0077);
            $lock = @fopen($lockPath, 'c');
            umask($oldUmask);
            if (!is_resource($lock) || !@flock($lock, LOCK_EX)) {
                if (is_resource($lock)) {
                    fclose($lock);
                }
                return null;
            }
            @chmod($lockPath, 0600);
            try {
                $cutoff = time() - max(1, min($days, 365)) * 86400;
                self::pruneDiagnosticFiles($path, $backupPath, $cutoff);
                $entries = [];
                foreach ([$backupPath, $path] as $logPath) {
                    if (is_link($logPath) || (file_exists($logPath) && !is_file($logPath))) {
                        return null;
                    }
                    if (!is_file($logPath)) {
                        continue;
                    }
                    if (!is_readable($logPath)) {
                        return null;
                    }
                    $contents = @file_get_contents($logPath);
                    if (!is_string($contents)) {
                        return null;
                    }
                    foreach (preg_split('/\\r?\\n/', $contents) ?: [] as $line) {
                        if ($line === '') {
                            continue;
                        }
                        try {
                            $raw = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                        } catch (JsonException) {
                            continue;
                        }
                        if (!is_array($raw)) {
                            continue;
                        }
                        $type = is_string($raw['type'] ?? null) && in_array($raw['type'], ['php_error', 'php_fatal', 'uncaught_exception', 'js_error', 'js_unhandled_rejection', 'app_error'], true) ? $raw['type'] : '';
                        $timestamp = is_string($raw['timestamp_utc'] ?? null) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $raw['timestamp_utc']) ? $raw['timestamp_utc'] : '';
                        $timeValue = $timestamp !== '' ? strtotime($timestamp) : false;
                        if ($type === '' || $timeValue === false || $timeValue < $cutoff) {
                            continue;
                        }
                        $applicationCode = $type === 'app_error' && is_string($raw['code'] ?? null) && in_array($raw['code'], self::ALLOWED_APP_ERROR_CODES, true)
                            ? $raw['code']
                            : '';
                        if ($type === 'app_error' && $applicationCode === '') {
                            continue;
                        }
                        $applicationFields = [];
                        if ($type === 'app_error' && is_array($raw['fields'] ?? null)) {
                            foreach ($raw['fields'] as $fieldKey) {
                                if (is_string($fieldKey) && in_array($fieldKey, self::ALLOWED_DIAGNOSTIC_FIELD_KEYS, true)) {
                                    $applicationFields[] = $fieldKey;
                                }
                            }
                            $applicationFields = array_values(array_unique($applicationFields));
                        }
                        $fileName = is_string($raw['file'] ?? null) ? basename(str_replace('\\', '/', $raw['file'])) : 'unknown';
                        $fileName = preg_replace('/[^A-Za-z0-9._-]/', '_', $fileName) ?? 'unknown';
                        $safe = [
                            'timestamp_utc' => $timestamp,
                            'type' => $type,
                            'code' => $applicationCode,
                            'fields' => $applicationFields,
                            'severity' => is_string($raw['severity'] ?? null) && in_array($raw['severity'], ['APP_ERROR', 'E_ERROR', 'E_WARNING', 'E_PARSE', 'E_NOTICE', 'E_CORE_ERROR', 'E_CORE_WARNING', 'E_COMPILE_ERROR', 'E_COMPILE_WARNING', 'E_USER_ERROR', 'E_USER_WARNING', 'E_USER_NOTICE', 'E_RECOVERABLE_ERROR', 'E_DEPRECATED', 'E_USER_DEPRECATED'], true) ? $raw['severity'] : '',
                            'file' => substr($fileName !== '' ? $fileName : 'unknown', 0, 120),
                            'line' => max(0, min((int) ($raw['line'] ?? 0), 999999)),
                            'column' => max(0, min((int) ($raw['column'] ?? 0), 999999)),
                            'request_id' => is_string($raw['request_id'] ?? null) && preg_match('/\\A[a-f0-9]{16,64}\\z/', $raw['request_id']) ? $raw['request_id'] : '',
                            'release' => is_string($raw['release'] ?? null) && preg_match('/\\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\\z/', $raw['release']) ? $raw['release'] : 'unknown',
                            'build_id' => is_string($raw['build_id'] ?? null) && (preg_match('/\\Asha256-[a-f0-9]{20}\\z/', $raw['build_id']) || $raw['build_id'] === 'unavailable') ? $raw['build_id'] : 'unknown',
                            'page' => is_string($raw['page'] ?? null) && in_array($raw['page'], ['protocol', 'invoice', 'skadeblankett', 'files', 'archive', 'customers', 'settings', 'portal'], true) ? $raw['page'] : 'portal',
                            'exception_class' => is_string($raw['exception_class'] ?? null) ? substr(preg_replace('/[^A-Za-z0-9_\\\\]/', '', $raw['exception_class']) ?? '', 0, 120) : '',
                            'actor_hash' => is_string($raw['actor_hash'] ?? null) && preg_match('/\\A[a-f0-9]{24}\\z/', $raw['actor_hash']) ? $raw['actor_hash'] : '',
                            'actor_area' => is_string($raw['actor_area'] ?? null) && in_array($raw['actor_area'], ['invoice', 'protocol'], true) ? $raw['actor_area'] : '',
                            'actor_role' => is_string($raw['actor_role'] ?? null) && in_array($raw['actor_role'], ['admin', 'staff', 'invoice_user', 'protocol_user'], true) ? $raw['actor_role'] : '',
                        ];
                        $entries[] = $safe;
                    }
                }
                usort($entries, static fn(array $a, array $b): int => strcmp((string) $b['timestamp_utc'], (string) $a['timestamp_utc']));
                return array_slice($entries, 0, max(1, min($limit, 500)));
            } finally {
                @flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        /** Prune expired diagnostic rows during normal PHP requests, at most daily. */
        public static function cleanupDiagnostics(int $days = 30): void
        {
            $dir = __DIR__ . '/../logs';
            $path = $dir . '/diagnostics.jsonl';
            $backupPath = $path . '.1';
            $lockPath = $path . '.lock';
            $markerPath = $dir . '/.diagnostics-pruned';
            $guard = $dir . '/.htaccess';
            $root = realpath(dirname(__DIR__));
            $realDir = realpath($dir);
            if (
                (!is_file($path) && !is_file($backupPath)) || is_link($dir) || is_link($guard) || !is_file($guard) ||
                is_link($path) || is_link($backupPath) || is_link($lockPath) || is_link($markerPath) ||
                $root === false || $realDir === false ||
                strpos($realDir, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0
            ) {
                return;
            }
            clearstatcache(true, $markerPath);
            $markerTime = is_file($markerPath) ? @filemtime($markerPath) : false;
            if ($markerTime !== false && $markerTime >= time() - 86400) {
                return;
            }
            $oldUmask = umask(0077);
            $lock = @fopen($lockPath, 'c');
            umask($oldUmask);
            if (!is_resource($lock) || !@flock($lock, LOCK_EX)) {
                if (is_resource($lock)) {
                    fclose($lock);
                }
                return;
            }
            @chmod($lockPath, 0600);
            try {
                clearstatcache(true, $markerPath);
                $markerTime = is_file($markerPath) ? @filemtime($markerPath) : false;
                if ($markerTime === false || $markerTime < time() - 86400) {
                    $cutoff = time() - max(1, min($days, 365)) * 86400;
                    self::pruneDiagnosticFiles($path, $backupPath, $cutoff);
                    if (is_file($markerPath)) {
                        @touch($markerPath);
                    } else {
                        $oldUmask = umask(0077);
                        $marker = @fopen($markerPath, 'x');
                        umask($oldUmask);
                        if (is_resource($marker)) {
                            fclose($marker);
                        }
                        @touch($markerPath);
                    }
                    @chmod($markerPath, 0600);
                }
            } finally {
                @flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        /**
         * Delete rotated/archived log files (audit/diagnostics/requests
         * *.jsonl.N) once they are older than the retention period. Live files
         * are never touched here; they keep their own entry-level pruning.
         * Empty archives left behind by entry pruning are removed immediately.
         *
         * @param string|null $directory Optional directory override (tests/maintenance).
         */
        public static function cleanupArchivedLogs(int $days = 7, ?string $directory = null): int
        {
            $dir = $directory ?? (__DIR__ . '/../logs');
            $guard = $dir . '/.htaccess';
            $markerPath = $dir . '/.archives-pruned';
            $root = realpath(dirname(__DIR__));
            $realDir = realpath($dir);
            if (
                is_link($dir) || !is_file($guard) || is_link($guard) || is_link($markerPath) ||
                $root === false || $realDir === false ||
                strpos($realDir, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0
            ) {
                return 0;
            }
            clearstatcache(true, $markerPath);
            $markerTime = is_file($markerPath) ? @filemtime($markerPath) : false;
            if ($markerTime !== false && $markerTime >= time() - 86400) {
                return 0;
            }
            $cutoff = time() - max(1, min($days, 365)) * 86400;
            $removed = 0;
            $archives = glob($realDir . DIRECTORY_SEPARATOR . '*.jsonl.*') ?: [];
            foreach ($archives as $path) {
                if (is_link($path) || !is_file($path)) {
                    continue;
                }
                if (preg_match('/\\.jsonl\\.\\d+\\z/', $path) !== 1) {
                    continue;
                }
                $size = @filesize($path);
                $mtime = @filemtime($path);
                $isArchive = $size === 0 || ($mtime !== false && $mtime < $cutoff);
                if ($isArchive && @unlink($path)) {
                    $removed++;
                }
            }
            if (!is_file($markerPath)) {
                $oldUmask = umask(0077);
                $marker = @fopen($markerPath, 'x');
                umask($oldUmask);
                if (is_resource($marker)) {
                    fclose($marker);
                }
            }
            @touch($markerPath);
            @chmod($markerPath, 0600);
            return $removed;
        }

        /**
         * Archive every log whose last write belongs to a previous day so
         * "yesterday's logs" always end up in *.jsonl.1–.3, where the
         * seven-day archive cleanup removes them. Runs at most once per day.
         *
         * @param string|null $directory Optional directory override (tests/maintenance).
         */
        public static function archiveYesterdayLogs(?string $directory = null): int
        {
            $dir = $directory ?? (__DIR__ . '/../logs');
            $guard = $dir . '/.htaccess';
            $markerPath = $dir . '/.daily-rotated';
            $root = realpath(dirname(__DIR__));
            $realDir = realpath($dir);
            if (
                is_link($dir) || !is_file($guard) || is_link($guard) || is_link($markerPath) ||
                $root === false || $realDir === false ||
                strpos($realDir, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0
            ) {
                return 0;
            }
            $todayStart = strtotime('today');
            if ($todayStart === false) {
                return 0;
            }
            clearstatcache(true, $markerPath);
            $markerTime = is_file($markerPath) ? @filemtime($markerPath) : false;
            if ($markerTime !== false && $markerTime >= $todayStart) {
                return 0;
            }
            $rotated = 0;
            foreach (['audit', 'diagnostics', 'requests'] as $name) {
                $path = $realDir . DIRECTORY_SEPARATOR . $name . '.jsonl';
                $lockPath = $path . '.lock';
                if (is_link($path) || !is_file($path) || is_link($lockPath)) {
                    continue;
                }
                clearstatcache(true, $path);
                $mtime = @filemtime($path);
                if ($mtime === false || $mtime >= $todayStart || @filesize($path) === 0) {
                    continue;
                }
                $oldUmask = umask(0077);
                $lock = @fopen($lockPath, 'c');
                umask($oldUmask);
                if (!is_resource($lock) || !@flock($lock, LOCK_EX)) {
                    if (is_resource($lock)) {
                        fclose($lock);
                    }
                    continue;
                }
                @chmod($lockPath, 0600);
                try {
                    clearstatcache(true, $path);
                    $mtime = @filemtime($path);
                    if ($mtime === false || $mtime >= $todayStart || @filesize($path) === 0) {
                        continue;
                    }
                    $shift = static function (string $from, string $to): bool {
                        if (!is_file($from)) {
                            return true;
                        }
                        if (!@rename($from, $to)) {
                            return false;
                        }
                        @chmod($to, 0600);
                        return true;
                    };
                    $level3 = $path . '.3';
                    $level2 = $path . '.2';
                    $level1 = $path . '.1';
                    if (is_file($level3) && !@unlink($level3)) {
                        continue;
                    }
                    if (!$shift($level2, $level3) || !$shift($level1, $level2) || !$shift($path, $level1)) {
                        continue;
                    }
                    $rotated++;
                } finally {
                    @flock($lock, LOCK_UN);
                    fclose($lock);
                }
            }
            if (!is_file($markerPath)) {
                $oldUmask = umask(0077);
                $marker = @fopen($markerPath, 'x');
                umask($oldUmask);
                if (is_resource($marker)) {
                    fclose($marker);
                }
            }
            @touch($markerPath);
            @chmod($markerPath, 0600);
            return $rotated;
        }

        /** Grouping key for the active-error list (type+code+location). */
        private static function diagnosticSignature(array $entry): string
        {
            return substr(hash('sha256', implode('|', [
                (string) ($entry['type'] ?? ''),
                (string) ($entry['code'] ?? ''),
                (string) ($entry['file'] ?? ''),
                (string) ($entry['line'] ?? ''),
                (string) ($entry['exception_class'] ?? ''),
            ])), 0, 32);
        }

        /** @return array{0:string,1:string}|null Storage file and lock path, or null when unsafe. */
        private static function activeErrorPaths(?string $storageDirectory): ?array
        {
            $dir = $storageDirectory ?? (__DIR__ . '/../storage');
            $guard = $dir . '/.htaccess';
            $root = realpath(dirname(__DIR__));
            $realDir = realpath($dir);
            if (
                is_link($dir) || !is_file($guard) || is_link($guard) ||
                $root === false || $realDir === false ||
                strpos($realDir, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0
            ) {
                return null;
            }
            $file = $realDir . '/active_errors.json';
            $lock = $realDir . '/active_errors.json.lock';
            if (is_link($file) || is_link($lock)) {
                return null;
            }
            return [$file, $lock];
        }

        /**
         * Upsert one diagnostic record into the persistent active-error list.
         * The list is the complete fellogg: it survives log rotation and only
         * shrinks when an error is marked resolved (or exceeds the cap).
         */
        public static function upsertActiveError(array $entry, ?string $storageDirectory = null): void
        {
            $paths = self::activeErrorPaths($storageDirectory);
            if ($paths === null) {
                return;
            }
            [$file, $lockPath] = $paths;
            $oldUmask = umask(0077);
            $lock = @fopen($lockPath, 'c');
            umask($oldUmask);
            if (!is_resource($lock) || !@flock($lock, LOCK_EX)) {
                if (is_resource($lock)) {
                    fclose($lock);
                }
                return;
            }
            @chmod($lockPath, 0600);
            try {
                $active = [];
                if (is_file($file)) {
                    $raw = @file_get_contents($file);
                    $decoded = is_string($raw) ? json_decode($raw, true) : null;
                    if (is_array($decoded)) {
                        $active = $decoded;
                    }
                }
                $signature = self::diagnosticSignature($entry);
                $timestamp = is_string($entry['timestamp_utc'] ?? null) ? $entry['timestamp_utc'] : gmdate('Y-m-d\TH:i:s\Z');
                $existing = isset($active[$signature]) && is_array($active[$signature]) ? $active[$signature] : [];
                $active[$signature] = [
                    'type' => substr((string) ($entry['type'] ?? ''), 0, 40),
                    'code' => substr((string) ($entry['code'] ?? ''), 0, 120),
                    'file' => substr((string) ($entry['file'] ?? ''), 0, 120),
                    'line' => max(0, min((int) ($entry['line'] ?? 0), 999999)),
                    'exception_class' => substr((string) ($entry['exception_class'] ?? ''), 0, 120),
                    'page' => substr((string) ($entry['page'] ?? ''), 0, 40),
                    'count' => (int) ($existing['count'] ?? 0) + 1,
                    'first_seen' => (string) ($existing['first_seen'] ?? $timestamp),
                    'last_seen' => $timestamp,
                ];
                if (count($active) > 200) {
                    uasort($active, static function (array $a, array $b): int {
                        return strcmp((string) ($a['last_seen'] ?? ''), (string) ($b['last_seen'] ?? ''));
                    });
                    $active = array_slice($active, -200, null, true);
                }
                $json = json_encode($active, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                if (!is_string($json)) {
                    return;
                }
                $temp = @tempnam(dirname($file), '.active-errors-');
                if (!is_string($temp) || $temp === '') {
                    return;
                }
                $written = @file_put_contents($temp, $json, LOCK_EX);
                if ($written === false || $written !== strlen($json)) {
                    @unlink($temp);
                    return;
                }
                @chmod($temp, 0600);
                if (!@rename($temp, $file)) {
                    @unlink($temp);
                }
            } finally {
                @flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        /** Read the persistent active-error list, newest occurrence first. */
        public static function readActiveErrors(?string $storageDirectory = null): array
        {
            $paths = self::activeErrorPaths($storageDirectory);
            if ($paths === null) {
                return [];
            }
            $raw = @file_get_contents($paths[0]);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($decoded)) {
                return [];
            }
            $list = [];
            foreach ($decoded as $signature => $entry) {
                if (!is_string($signature) || !is_array($entry)) {
                    continue;
                }
                $list[] = array_merge(['signature' => $signature], $entry);
            }
            usort($list, static function (array $a, array $b): int {
                return strcmp((string) ($b['last_seen'] ?? ''), (string) ($a['last_seen'] ?? ''));
            });
            return $list;
        }

        /**
         * Mark errors as resolved: remove them from the active list and delete
         * their raw entries from the diagnostics fellogg (live + archives).
         * Returns how many active errors were removed.
         *
         * @param string[] $signatures
         * @param string|null $storageDirectory Override for tests.
         * @param string|null $logsDirectory Override for tests.
         */
        public static function resolveActiveErrors(array $signatures, ?string $storageDirectory = null, ?string $logsDirectory = null): int
        {
            $signatures = array_values(array_unique(array_filter($signatures, static function (string $signature): bool {
                return preg_match('/\\A[a-f0-9]{32}\\z/D', $signature) === 1;
            })));
            if ($signatures === []) {
                return 0;
            }
            $wanted = array_flip($signatures);
            $removedActive = 0;
            $paths = self::activeErrorPaths($storageDirectory);
            if ($paths !== null) {
                [$file, $lockPath] = $paths;
                $oldUmask = umask(0077);
                $lock = @fopen($lockPath, 'c');
                umask($oldUmask);
                if (is_resource($lock) && @flock($lock, LOCK_EX)) {
                    @chmod($lockPath, 0600);
                    try {
                        $raw = is_file($file) ? @file_get_contents($file) : '';
                        $active = is_string($raw) ? json_decode($raw, true) : null;
                        if (is_array($active)) {
                            foreach (array_keys($active) as $signature) {
                                if (is_string($signature) && isset($wanted[$signature])) {
                                    unset($active[$signature]);
                                    $removedActive++;
                                }
                            }
                            $json = json_encode($active, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                            if (is_string($json)) {
                                $temp = @tempnam(dirname($file), '.active-errors-');
                                if (is_string($temp) && $temp !== '') {
                                    $written = @file_put_contents($temp, $json, LOCK_EX);
                                    if ($written !== false && $written === strlen($json)) {
                                        @chmod($temp, 0600);
                                        if (!@rename($temp, $file)) {
                                            @unlink($temp);
                                        }
                                    } else {
                                        @unlink($temp);
                                    }
                                }
                            }
                        }
                    } finally {
                        @flock($lock, LOCK_UN);
                        fclose($lock);
                    }
                }
            }
            // Remove the matching raw records from the fellogg and its archives.
            $dir = $logsDirectory ?? (__DIR__ . '/../logs');
            $root = realpath(dirname(__DIR__));
            $realDir = realpath($dir);
            if (
                !is_link($dir) && is_file($dir . '/.htaccess') && !is_link($dir . '/.htaccess') &&
                $root !== false && $realDir !== false &&
                strpos($realDir, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0
            ) {
                foreach (['diagnostics.jsonl', 'diagnostics.jsonl.1', 'diagnostics.jsonl.2', 'diagnostics.jsonl.3'] as $name) {
                    $path = $realDir . DIRECTORY_SEPARATOR . $name;
                    if (is_link($path) || !is_file($path) || !is_readable($path)) {
                        continue;
                    }
                    $contents = @file_get_contents($path);
                    if (!is_string($contents) || $contents === '') {
                        continue;
                    }
                    $kept = [];
                    foreach (preg_split('/\\r?\\n/', $contents) ?: [] as $line) {
                        if ($line === '') {
                            continue;
                        }
                        try {
                            $entry = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                        } catch (JsonException) {
                            $kept[] = $line;
                            continue;
                        }
                        $signature = is_array($entry) ? self::diagnosticSignature($entry) : '';
                        if ($signature === '' || !isset($wanted[$signature])) {
                            $kept[] = $line;
                        }
                    }
                    $newContents = $kept ? implode(PHP_EOL, $kept) . PHP_EOL : '';
                    if ($newContents === $contents) {
                        continue;
                    }
                    $lockPath = $path . '.lock';
                    $oldUmask = umask(0077);
                    $lock = @fopen($lockPath, 'c');
                    umask($oldUmask);
                    if (!is_resource($lock) || !@flock($lock, LOCK_EX)) {
                        if (is_resource($lock)) {
                            fclose($lock);
                        }
                        continue;
                    }
                    @chmod($lockPath, 0600);
                    try {
                        $temp = @tempnam($realDir, '.diagnostic-resolve-');
                        if (!is_string($temp) || $temp === '') {
                            continue;
                        }
                        $written = @file_put_contents($temp, $newContents, LOCK_EX);
                        if ($written === false || $written !== strlen($newContents)) {
                            @unlink($temp);
                            continue;
                        }
                        @chmod($temp, 0600);
                        if (!@rename($temp, $path)) {
                            @unlink($temp);
                            continue;
                        }
                        @chmod($path, 0600);
                    } finally {
                        @flock($lock, LOCK_UN);
                        fclose($lock);
                    }
                }
            }
            return $removedActive;
        }

        public static function applicationError(string $code, array $fieldKeys = [], ?Throwable $exception = null): void
        {
            self::diagnostic(
                'app_error',
                null,
                $exception instanceof Throwable ? $exception->getFile() : 'index.php',
                $exception instanceof Throwable ? $exception->getLine() : 0,
                $exception instanceof Throwable ? get_class($exception) : '',
                'skadeblankett',
                0,
                $code,
                $fieldKeys
            );
        }

        /**
         * Write a privacy-minimized diagnostic record. Raw error messages, full paths,
         * stack traces, request data, credentials, and customer information are omitted.
         */
        public static function diagnostic(string $type, ?int $severity, string $file, int $line, string $exceptionClass = '', string $page = '', int $column = 0, string $applicationCode = '', array $applicationFields = []): void
        {
            if (
                !self::$enabled || self::$diagnosticWriting ||
                !in_array($type, ['php_error', 'php_fatal', 'uncaught_exception', 'js_error', 'js_unhandled_rejection', 'app_error'], true)
            ) {
                return;
            }
            if ($type === 'app_error') {
                if (!in_array($applicationCode, self::ALLOWED_APP_ERROR_CODES, true)) {
                    return;
                }
                $safeApplicationFields = [];
                foreach ($applicationFields as $fieldKey) {
                    if (is_string($fieldKey) && in_array($fieldKey, self::ALLOWED_DIAGNOSTIC_FIELD_KEYS, true)) {
                        $safeApplicationFields[] = $fieldKey;
                    }
                }
                $safeApplicationFields = array_values(array_unique($safeApplicationFields));
                $page = 'skadeblankett';
            } else {
                $applicationCode = '';
                $safeApplicationFields = [];
            }
            self::$diagnosticWriting = true;

            try {
                $dir = __DIR__ . '/../logs';
                $path = $dir . '/diagnostics.jsonl';
                $lockPath = $path . '.lock';
                $backupPath = $path . '.1';
                $guard = $dir . '/.htaccess';
                $root = realpath(dirname(__DIR__));
                $realDir = realpath($dir);
                if (
                    is_link($dir) || is_link($guard) || !is_file($guard) ||
                    is_link($path) || is_link($lockPath) || is_link($backupPath) ||
                    $root === false || $realDir === false ||
                    strpos($realDir, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0
                ) {
                    error_log('Tadega diagnostic logger could not write: unsafe or unprotected path.');
                    return;
                }
                @chmod($dir, 0700);

                $oldUmask = umask(0077);
                $lock = @fopen($lockPath, 'c');
                umask($oldUmask);
                if (!is_resource($lock)) {
                    error_log('Tadega diagnostic logger could not open its lock file.');
                    return;
                }
                @chmod($lockPath, 0600);
                if (!@flock($lock, LOCK_EX)) {
                    fclose($lock);
                    error_log('Tadega diagnostic logger could not lock its file.');
                    return;
                }

                try {
                    self::pruneDiagnosticFiles($path, $backupPath, time() - (30 * 86400));
                    clearstatcache(true, $path);
                    if (is_file($path) && @filesize($path) >= 1048576) {
                        if (is_file($backupPath) && !@unlink($backupPath)) {
                            error_log('Tadega diagnostic logger rotation failed.');
                            return;
                        }
                        if (!@rename($path, $backupPath)) {
                            error_log('Tadega diagnostic logger rotation failed.');
                            return;
                        }
                        @chmod($backupPath, 0600);
                    }

                    $oldUmask = umask(0077);
                    $handle = @fopen($path, 'ab');
                    umask($oldUmask);
                    if (!is_resource($handle)) {
                        error_log('Tadega diagnostic logger could not open its log file.');
                        return;
                    }
                    @chmod($path, 0600);

                    $fileName = basename(str_replace('\\', '/', $file));
                    $fileName = preg_replace('/[^A-Za-z0-9._-]/', '_', $fileName) ?? '';
                    $fileName = substr($fileName, 0, 120);
                    $severityNames = [
                        E_ERROR => 'E_ERROR', E_WARNING => 'E_WARNING', E_PARSE => 'E_PARSE',
                        E_NOTICE => 'E_NOTICE', E_CORE_ERROR => 'E_CORE_ERROR',
                        E_CORE_WARNING => 'E_CORE_WARNING', E_COMPILE_ERROR => 'E_COMPILE_ERROR',
                        E_COMPILE_WARNING => 'E_COMPILE_WARNING', E_USER_ERROR => 'E_USER_ERROR',
                        E_USER_WARNING => 'E_USER_WARNING', E_USER_NOTICE => 'E_USER_NOTICE',
                        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR', E_DEPRECATED => 'E_DEPRECATED',
                        E_USER_DEPRECATED => 'E_USER_DEPRECATED',
                    ];
                    $allowedPages = ['protocol', 'invoice', 'skadeblankett', 'files', 'archive', 'customers', 'settings', 'portal'];
                    if (!in_array($page, $allowedPages, true)) {
                        $requestedPage = isset($_GET['tab']) && is_string($_GET['tab']) ? $_GET['tab'] : 'portal';
                        $page = in_array($requestedPage, $allowedPages, true) ? $requestedPage : 'portal';
                    }
                    $safeExceptionClass = preg_replace('/[^A-Za-z0-9_\\\\]/', '', $exceptionClass) ?? '';
                    $record = [
                        'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                        'type' => $type,
                        'severity' => $type === 'app_error' ? 'APP_ERROR' : ($severity === null ? '' : ($severityNames[$severity] ?? 'E_' . max(0, min($severity, 9999)))), 
                        'file' => $fileName !== '' ? $fileName : 'unknown',
                        'line' => max(0, min($line, 999999)),
                        'column' => max(0, min($column, 999999)),
                        'request_id' => self::getRequestId(),
                        'release' => self::diagnosticReleaseLabel(),
                        'build_id' => self::diagnosticBuildId(),
                        'page' => $page,
                    ];
                    if ($type === 'app_error') {
                        $record['code'] = $applicationCode;
                        $record['fields'] = $safeApplicationFields;
                    }
                    if ($safeExceptionClass !== '') {
                        $record['exception_class'] = substr($safeExceptionClass, 0, 120);
                    }
                    foreach (self::diagnosticActor() as $key => $value) {
                        $record[$key] = $value;
                    }
                    $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                    if (is_string($json)) {
                        $written = @fwrite($handle, $json . PHP_EOL);
                        @fflush($handle);
                        if ($written === false) {
                            error_log('Tadega diagnostic logger write failed.');
                        }
                    }
                    // Keep the persistent active-error list complete even when
                    // the raw fellogg is rotated or later cleaned up.
                    self::upsertActiveError($record);
                    fclose($handle);
                } finally {
                    @flock($lock, LOCK_UN);
                    fclose($lock);
                }
            } catch (Throwable $e) {
                error_log('Tadega diagnostic logger failed unexpectedly.');
            } finally {
                self::$diagnosticWriting = false;
            }
        }

        // Backwards-compatible methods. Message/context arguments are discarded.
        public static function debug(mixed $message, array $context = [], mixed $category = null): void
        {
            self::event('system.logger_debug');
        }

        public static function info(mixed $message, array $context = [], mixed $category = null): void
        {
            self::event('system.logger_info');
        }

        public static function warning(mixed $message, array $context = [], mixed $category = null): void
        {
            self::event('system.logger_warning');
        }

        public static function error(mixed $message, array $context = [], mixed $category = null): void
        {
            self::event('system.logger_error');
        }

        public static function exception(Throwable $exception, mixed $contextOrMessage = null, mixed $category = 'EXCEPTION'): void
        {
            self::event('system.exception');
        }

        public static function log(mixed $a, mixed $b = null, array $context = [], mixed $category = null): void
        {
            self::event('system.logger_event');
        }

        public static function registerErrorHandlers(): void
        {
            if (!self::$errorHandlersRegistered) {
                self::$errorHandlersRegistered = true;
                set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
                    $loggable = [E_WARNING, E_USER_WARNING, E_USER_ERROR, E_RECOVERABLE_ERROR, E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED];
                    if ((error_reporting() & $severity) !== 0 && in_array($severity, $loggable, true)) {
                        self::diagnostic('php_error', $severity, $file, $line);
                    }
                    // Keep diagnostics out of the response; the message may contain submitted data.
                    return true;
                });
                set_exception_handler(static function (Throwable $exception): void {
                    self::diagnostic('uncaught_exception', null, $exception->getFile(), $exception->getLine(), get_class($exception));
                });
            }
            self::registerShutdown();
        }

        public static function registerShutdown(): void
        {
            if (self::$shutdownRegistered) {
                return;
            }
            self::$shutdownRegistered = true;
            register_shutdown_function(static function (): void {
                $error = error_get_last();
                if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                    self::diagnostic('php_fatal', (int) $error['type'], (string) ($error['file'] ?? ''), (int) ($error['line'] ?? 0));
                }
            });
        }

        public static function timerStart(string $name = 'default', array $context = []): void
        {
            // Names/context are intentionally not persisted.
        }

        public static function timerEnd(string $name = 'default', array $extraContext = []): null
        {
            self::event('system.timer_completed');
            return null;
        }
    }
}

// Existing code may refer to InvoiceSystem\Logger. Keep that API but route all
// output through the privacy-first implementation above.
if (!class_exists('InvoiceSystem\\Logger', false)) {
    class_alias('TadegaPrivacyLogger', 'InvoiceSystem\\Logger');
}
