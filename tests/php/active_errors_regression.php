<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/logger.php';

$count = 0;
$assert = static function (bool $ok, string $msg) use (&$count): void {
    $count++;
    if (!$ok) {
        throw new RuntimeException("check {$count}: {$msg}");
    }
};

$base = __DIR__ . '/.active-errors-sandbox-' . bin2hex(random_bytes(4));
$storage = $base . '/storage';
$logs = $base . '/logs';
mkdir($storage, 0700, true);
mkdir($logs, 0700);
file_put_contents($storage . '/.htaccess', "Require all denied\n");
file_put_contents($logs . '/.htaccess', "Require all denied\n");

$record = static function (string $type, string $code, string $file, int $line, string $timestamp): array {
    return ['timestamp_utc' => $timestamp, 'type' => $type, 'code' => $code, 'file' => $file, 'line' => $line, 'page' => 'skadeblankett'];
};

// Two occurrences of one error plus one different error.
TadegaPrivacyLogger::upsertActiveError($record('app_error', 'skade.field_invalid', 'skadeblankett.php', 10, '2026-11-25T08:00:00Z'), $storage);
TadegaPrivacyLogger::upsertActiveError($record('app_error', 'skade.field_invalid', 'skadeblankett.php', 10, '2026-11-25T09:00:00Z'), $storage);
TadegaPrivacyLogger::upsertActiveError($record('php_error', '', 'index.php', 99, '2026-11-25T09:30:00Z'), $storage);

$active = TadegaPrivacyLogger::readActiveErrors($storage);
$assert(count($active) === 2, 'Two distinct active errors, got ' . count($active));
$assert($active[0]['type'] === 'php_error', 'Newest occurrence listed first');
$assert($active[1]['count'] === 2, 'Repeat occurrences are counted');
$assert(($active[1]['first_seen'] ?? '') === '2026-11-25T08:00:00Z', 'First occurrence is preserved');
$skadeSignature = (string) ($active[1]['signature'] ?? '');
$assert(preg_match('/\A[a-f0-9]{32}\z/', $skadeSignature) === 1, 'Signature is a stable hash');

// Raw fellogg: live file plus a rotated archive both hold the error.
$skadeLine = json_encode($record('app_error', 'skade.field_invalid', 'skadeblankett.php', 10, '2026-11-25T08:00:00Z'));
$phpLine = json_encode($record('php_error', '', 'index.php', 99, '2026-11-25T09:30:00Z'));
file_put_contents($logs . '/diagnostics.jsonl', $skadeLine . "\n" . $phpLine . "\n");
file_put_contents($logs . '/diagnostics.jsonl.1', $skadeLine . "\n");

$removed = TadegaPrivacyLogger::resolveActiveErrors([$skadeSignature], $storage, $logs);
$assert($removed === 1, 'One active error removed on resolve');
$assert(count(TadegaPrivacyLogger::readActiveErrors($storage)) === 1, 'Other active error survives');
$live = (string) file_get_contents($logs . '/diagnostics.jsonl');
$assert(!str_contains($live, 'skade.field_invalid'), 'Resolved error removed from live fellogg');
$assert(str_contains($live, 'index.php'), 'Unrelated error kept in live fellogg');
$assert(!str_contains((string) file_get_contents($logs . '/diagnostics.jsonl.1'), 'skade.field_invalid'), 'Resolved error removed from archived fellogg');

$assert(TadegaPrivacyLogger::resolveActiveErrors(['../../escape'], $storage, $logs) === 0, 'Malformed signatures are rejected');
$assert(TadegaPrivacyLogger::resolveActiveErrors([], $storage, $logs) === 0, 'Empty request is a no-op');

// The live write path must feed the active list.
$loggerSource = (string) file_get_contents(__DIR__ . '/../../includes/logger.php');
$assert(str_contains($loggerSource, 'self::upsertActiveError($record)'), 'diagnostic() upserts into the active list');

$helper = static function (string $dir) use (&$helper): void {
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
        $path = $dir . '/' . $entry;
        if (is_dir($path) && !is_link($path)) {
            $helper($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
};
$helper($base);

echo "PASS: active error log regression ({$count} checks)\n";
