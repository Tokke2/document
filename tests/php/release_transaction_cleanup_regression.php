<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/release_updater.php';

$count = 0;
$assert = static function (bool $ok, string $msg) use (&$count): void {
    $count++;
    if (!$ok) {
        throw new RuntimeException($msg);
    }
};

$tempRoot = realpath(sys_get_temp_dir());
$assert(is_string($tempRoot) && $tempRoot !== false, 'System temp available');

// A transaction directory an install left behind when it was killed before
// finally could run: backup content inside, mtime older than the sweep window.
$stale = $tempRoot . '/tadega-release-txn-' . bin2hex(random_bytes(8));
mkdir($stale . '/backup', 0700, true);
file_put_contents($stale . '/backup/index.php', "old-index\n");
file_put_contents($stale . '/stage.json', '{}');
touch($stale, time() - 90000);

// A fresh transaction directory (concurrent install) must survive.
$fresh = $tempRoot . '/tadega-release-txn-' . bin2hex(random_bytes(8));
mkdir($fresh, 0700);
file_put_contents($fresh . '/stage.json', '{}');
touch($fresh, time() - 30);

// Symlinked lookalikes are never followed or removed.
$linkTarget = $tempRoot . '/tadega-txn-link-target-' . bin2hex(random_bytes(6));
mkdir($linkTarget, 0700);
file_put_contents($linkTarget . '/keep.txt', 'keep');
touch($linkTarget, time() - 90000);
$link = $tempRoot . '/tadega-release-txn-' . bin2hex(random_bytes(8));
symlink($linkTarget, $link);

$removed = portal_release_cleanup_stale_transactions();
$assert($removed >= 1, 'At least the stale transaction directory is removed');
$assert(!is_dir($stale), 'Stale transaction directory (stage + backup) is gone');
$assert(is_dir($fresh), 'Fresh transaction directory is left alone');
$assert(is_dir($linkTarget) && is_file($linkTarget . '/keep.txt'), 'Symlink target is never followed or deleted');
$assert(is_link($link), 'Symlinked lookalike itself is skipped');

portal_release_remove_tree($fresh);
portal_release_remove_tree($linkTarget);
unlink($link);

echo "PASS: release transaction cleanup regression ({$count} checks)\n";
