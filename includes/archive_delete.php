<?php
declare(strict_types=1);

/** Remove one archive file only. The original templates are deliberately protected. */
function portal_archive_delete_file(string $root, mixed $relative): bool
{
    if (!is_string($relative) || $relative === '' || strlen($relative) > 1024 ||
        str_contains($relative, "\0") || str_contains($relative, '\\') ||
        $relative[0] === '/' || is_link($root)) {
        return false;
    }
    $parts = explode('/', $relative);
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..' || $part[0] === '.') {
            return false;
        }
    }
    $name = end($parts);
    if (strtolower($parts[0]) === 'mallar' ||
        in_array(strtolower($name), ['bilrutan skadeblankett.pdf', 'bilrutan_skadeblankett.pdf'], true) ||
        !in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['pdf', 'png', 'jpg', 'jpeg'], true)) {
        return false;
    }
    $realRoot = realpath($root);
    if ($realRoot === false || !is_dir($realRoot)) {
        return false;
    }
    $path = $realRoot;
    foreach ($parts as $part) {
        $path .= DIRECTORY_SEPARATOR . $part;
        if (is_link($path)) {
            return false;
        }
    }
    $realFile = realpath($path);
    if ($realFile === false || !is_file($realFile) ||
        !str_starts_with($realFile, rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
        return false;
    }
    return @unlink($realFile);
}
