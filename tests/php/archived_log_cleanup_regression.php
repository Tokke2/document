<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/logger.php';

$count = 0;
$assert = static function (bool $ok, string $msg) use (&$count): void {
    $count++;
    if (!$ok) {
        throw new RuntimeException($msg);
    }
};

$base = __DIR__ . '/.archived-log-sandbox-' . bin2hex(random_bytes(4));
mkdir($base, 0700);
file_put_contents($base . '/.htaccess', "Require all denied\n");

$make = static function (string $name, int $ageDays, string $content) use ($base): string {
    $path = $base . '/' . $name;
    file_put_contents($path, $content);
    touch($path, time() - $ageDays * 86400);
    return $path;
};

$oldArchive = $make('audit.jsonl.1', 8, "{\"event\":\"old\"}\n");
$freshArchive = $make('requests.jsonl.1', 1, "{\"event\":\"fresh\"}\n");
$emptyArchive = $make('diagnostics.jsonl.1', 0, '');
$liveFile = $make('audit.jsonl', 9, "{\"event\":\"live\"}\n");
$lockFile = $make('audit.jsonl.lock', 9, 'lock');

$removed = TadegaPrivacyLogger::cleanupArchivedLogs(7, $base);
$assert($removed === 2, 'Exactly the stale and empty archives are removed, got ' . $removed);
$assert(!is_file($oldArchive), 'Archive older than seven days is deleted');
$assert(is_file($freshArchive), 'Archive younger than seven days is kept');
$assert(!is_file($emptyArchive), 'Empty archive left by entry pruning is deleted');
$assert(is_file($liveFile), 'Live log file is never touched by archive cleanup');
$assert(is_file($lockFile), 'Lock files are never touched');
$assert(is_file($base . '/.archives-pruned'), 'Daily throttle marker is written');

$secondOld = $make('requests.jsonl.2', 9, "{\"event\":\"second\"}\n");
$again = TadegaPrivacyLogger::cleanupArchivedLogs(7, $base);
$assert($again === 0 && is_file($secondOld), 'Marker throttles cleanup to once per day');
unlink($secondOld);

$rejected = TadegaPrivacyLogger::cleanupArchivedLogs(7, sys_get_temp_dir());
$assert($rejected === 0, 'Directory outside the application root is rejected');

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

echo "PASS: archived log cleanup regression ({$count} checks)\n";
