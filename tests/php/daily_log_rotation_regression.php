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

$base = __DIR__ . '/.daily-rotation-sandbox-' . bin2hex(random_bytes(4));
mkdir($base, 0700);
file_put_contents($base . '/.htaccess', "Require all denied\n");

$make = static function (string $name, string $content, int $ageSeconds) use ($base): string {
    $path = $base . '/' . $name;
    file_put_contents($path, $content);
    touch($path, time() - $ageSeconds);
    return $path;
};

$liveYesterday = $make('audit.jsonl', "{\"event\":\"yesterday\"}\n", 90000); // last write before today
$liveToday = $make('requests.jsonl', "{\"event\":\"today\"}\n", 60);
$archiveYesterday = $make('audit.jsonl.1', "{\"event\":\"older\"}\n", 90000);

$rotated = TadegaPrivacyLogger::archiveYesterdayLogs($base);
$assert($rotated === 1, 'Only the log last written yesterday is archived, got ' . $rotated);
$assert(!is_file($liveYesterday), 'Yesterday\'s live log is moved out');
$assert((string) file_get_contents($base . '/audit.jsonl.1') === "{\"event\":\"yesterday\"}\n", 'Archive holds yesterday\'s content');
$assert((string) file_get_contents($base . '/audit.jsonl.2') === "{\"event\":\"older\"}\n", 'Existing archive is chained one level up');
$assert(is_file($liveToday) && (string) file_get_contents($base . '/requests.jsonl') === "{\"event\":\"today\"}\n", 'Today\'s log stays live');
$assert(is_file($base . '/.daily-rotated'), 'Daily marker written');

$again = TadegaPrivacyLogger::archiveYesterdayLogs($base);
$assert($again === 0, 'Rotation runs at most once per day');

// Seven-day archive cleanup applies to the chained archives.
touch($base . '/audit.jsonl.2', time() - (8 * 86400));
$removed = TadegaPrivacyLogger::cleanupArchivedLogs(7, $base);
$assert($removed === 1, 'Eight-day-old archive removed by seven-day cleanup');
$assert(!is_file($base . '/audit.jsonl.2'), 'Old chained archive is gone');
$assert(is_file($base . '/audit.jsonl.1'), 'Recent archive survives the seven-day cleanup');
$assert(is_file($base . '/requests.jsonl'), 'Live logs are never deleted by archive cleanup');

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

echo "PASS: daily log rotation regression ({$count} checks)\n";
