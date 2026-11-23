<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/archive_delete.php';

$root = sys_get_temp_dir() . '/archive-delete-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
mkdir($root . '/mallar', 0700);
mkdir($root . '/service', 0700);
file_put_contents($root . '/service/test.pdf', 'test');
file_put_contents($root . '/mallar/template.pdf', 'template');
file_put_contents($root . '/Bilrutan Skadeblankett.pdf', 'template');
file_put_contents($root . '/secret.txt', 'secret');
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
};
try {
    $assert(!portal_archive_delete_file($root, '../secret.txt'), 'Traversal rejected');
    $assert(!portal_archive_delete_file($root, '/service/test.pdf'), 'Absolute path rejected');
    $assert(!portal_archive_delete_file($root, 'service/../secret.txt'), 'Dot segments rejected');
    $assert(!portal_archive_delete_file($root, 'mallar/template.pdf'), 'Template directory protected');
    $assert(!portal_archive_delete_file($root, 'Bilrutan Skadeblankett.pdf'), 'Original template protected');
    $assert(!portal_archive_delete_file($root, 'secret.txt'), 'Non-archive extension rejected');
    $assert(!portal_archive_delete_file($root, ['service/test.pdf']), 'Non-string rejected');
    if (@symlink($root . '/service', $root . '/link')) {
        $assert(!portal_archive_delete_file($root, 'link/test.pdf'), 'Symlink directories rejected');
        unlink($root . '/link');
    }
    $assert(portal_archive_delete_file($root, 'service/test.pdf'), 'Archive PDF deleted');
    $assert(!file_exists($root . '/service/test.pdf'), 'File no longer exists');
    $assert(!portal_archive_delete_file($root, 'service/test.pdf'), 'Missing file rejected');
    echo "PASS: {$checks} archive deletion checks.\n";
} finally {
    @unlink($root . '/service/test.pdf');
    @unlink($root . '/mallar/template.pdf');
    @unlink($root . '/Bilrutan Skadeblankett.pdf');
    @unlink($root . '/secret.txt');
    @rmdir($root . '/mallar');
    @rmdir($root . '/service');
    @rmdir($root);
}
