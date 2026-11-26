<?php
declare(strict_types=1);

/**
 * Private reusable-article catalogue for service-protocol material/work rows.
 * This stays separate from the invoice catalogue: protocol entries contain a
 * row description and optional technical specification, never invoice prices.
 */
function portal_protocol_catalog_path(): string
{
    return dirname(__DIR__) . '/storage/protocol_articles.json';
}

function portal_protocol_catalog_storage_is_safe(): bool
{
    $root = realpath(dirname(__DIR__));
    $storage = realpath(dirname(portal_protocol_catalog_path()));
    $guard = dirname(portal_protocol_catalog_path()) . '/.htaccess';
    return $root !== false
        && $storage !== false
        && dirname($storage) === $root
        && !is_link(dirname(portal_protocol_catalog_path()))
        && !is_link($guard)
        && is_file($guard);
}

/** @return array<int, mixed>|null Null means the private catalogue was unreadable. */
function portal_protocol_catalog_read(): ?array
{
    $path = portal_protocol_catalog_path();
    if (!portal_protocol_catalog_storage_is_safe() || is_link($path)) {
        return null;
    }
    if (!file_exists($path)) {
        return [];
    }
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $contents = @file_get_contents($path);
    if (!is_string($contents) || trim($contents) === '') {
        return null;
    }
    try {
        $records = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    return is_array($records) ? $records : null;
}

/** @param array<int, mixed> $records @return array<int, array{id:string,name:string,spec:string}> */
function portal_protocol_catalog_normalize(array $records): array
{
    $articles = [];
    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }
        $id = is_scalar($record['id'] ?? null) ? (string) $record['id'] : '';
        $name = is_scalar($record['name'] ?? null) ? trim((string) $record['name']) : '';
        $spec = is_scalar($record['spec'] ?? null) ? trim((string) $record['spec']) : '';
        if (
            !preg_match('/\\A[a-f0-9]{32}\\z/i', $id)
            || $name === ''
            || preg_match('//u', $name) !== 1
            || preg_match('//u', $spec) !== 1
            || preg_match('/[\\x00-\\x1F\\x7F]/u', $name . $spec) === 1
        ) {
            continue;
        }
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        $specLength = function_exists('mb_strlen') ? mb_strlen($spec, 'UTF-8') : strlen($spec);
        if ($nameLength > 200 || $specLength > 300) {
            continue;
        }
        $articles[] = ['id' => $id, 'name' => $name, 'spec' => $spec];
    }
    usort($articles, static fn(array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));
    return $articles;
}

/** @return array<int, array{id:string,name:string,spec:string}> */
/** Remove one article by id from the service report register. It never
 * touches the invoice catalogue: storage/protocol_articles.json stays separate
 * from storage/invoice_articles.json. */
function portal_protocol_catalog_delete(string $id): array
{
    if (preg_match('/\\A[a-f0-9]{32}\\z/D', $id) !== 1) {
        return ['ok' => false, 'code' => 'invalid_input'];
    }
    if (!portal_protocol_catalog_storage_is_safe()) {
        return ['ok' => false, 'code' => 'storage_error'];
    }
    $path = portal_protocol_catalog_path();
    $guardPath = $path . '.guard.lock';
    if (is_link($path) || is_link($path . '.lock') || is_link($guardPath)) {
        return ['ok' => false, 'code' => 'storage_error'];
    }
    $oldUmask = umask(0077);
    $guard = @fopen($guardPath, 'c');
    umask($oldUmask);
    if (!is_resource($guard)) {
        return ['ok' => false, 'code' => 'storage_error'];
    }
    @chmod($guardPath, 0600);
    if (!@flock($guard, LOCK_EX)) {
        fclose($guard);
        return ['ok' => false, 'code' => 'storage_error'];
    }
    try {
        $records = portal_protocol_catalog_read();
        if (!is_array($records)) {
            return ['ok' => false, 'code' => 'storage_error'];
        }
        $kept = [];
        $removed = false;
        foreach ($records as $record) {
            if (is_array($record) && ($record['id'] ?? null) === $id) {
                $removed = true;
                continue;
            }
            $kept[] = $record;
        }
        if (!$removed) {
            return ['ok' => false, 'code' => 'not_found'];
        }
        if (!function_exists('save_data') || !save_data($path, array_values($kept))) {
            return ['ok' => false, 'code' => 'storage_error'];
        }
        return ['ok' => true, 'code' => 'deleted'];
    } finally {
        @flock($guard, LOCK_UN);
        fclose($guard);
    }
}

function portal_protocol_catalog_list(): array
{
    $records = portal_protocol_catalog_read();
    return is_array($records) ? portal_protocol_catalog_normalize($records) : [];
}

/** @return array{ok:bool,code:string,id?:string} */
function portal_protocol_catalog_save(array $source): array
{
    $input = is_array($source['article'] ?? null) ? $source['article'] : [];
    $name = $input['name'] ?? null;
    $spec = $input['spec'] ?? '';
    if (!is_string($name) || !is_string($spec)) {
        return ['ok' => false, 'code' => 'invalid_input'];
    }
    $name = trim($name);
    $spec = trim($spec);
    $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
    $specLength = function_exists('mb_strlen') ? mb_strlen($spec, 'UTF-8') : strlen($spec);
    if (
        $name === '' || $nameLength > 200 || $specLength > 300
        || preg_match('//u', $name) !== 1 || preg_match('//u', $spec) !== 1
        || preg_match('/[\\x00-\\x1F\\x7F]/u', $name . $spec) === 1
    ) {
        return ['ok' => false, 'code' => 'invalid_input'];
    }
    if (!portal_protocol_catalog_storage_is_safe()) {
        return ['ok' => false, 'code' => 'storage_error'];
    }

    $path = portal_protocol_catalog_path();
    $guardPath = $path . '.guard.lock';
    if (is_link($path) || is_link($path . '.lock') || is_link($guardPath)) {
        return ['ok' => false, 'code' => 'storage_error'];
    }
    $oldUmask = umask(0077);
    $guard = @fopen($guardPath, 'c');
    umask($oldUmask);
    if (!is_resource($guard)) {
        return ['ok' => false, 'code' => 'storage_error'];
    }
    @chmod($guardPath, 0600);
    if (!@flock($guard, LOCK_EX)) {
        fclose($guard);
        return ['ok' => false, 'code' => 'storage_error'];
    }

    try {
        $records = portal_protocol_catalog_read();
        if (!is_array($records)) {
            return ['ok' => false, 'code' => 'storage_error'];
        }
        $normalizedName = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        $normalizedSpec = function_exists('mb_strtolower') ? mb_strtolower($spec, 'UTF-8') : strtolower($spec);
        foreach (portal_protocol_catalog_normalize($records) as $existing) {
            $existingName = function_exists('mb_strtolower') ? mb_strtolower($existing['name'], 'UTF-8') : strtolower($existing['name']);
            $existingSpec = function_exists('mb_strtolower') ? mb_strtolower($existing['spec'], 'UTF-8') : strtolower($existing['spec']);
            if ($existingName === $normalizedName && $existingSpec === $normalizedSpec) {
                return ['ok' => true, 'code' => 'exists', 'id' => $existing['id']];
            }
        }
        if (count($records) >= 500) {
            return ['ok' => false, 'code' => 'catalog_full'];
        }
        try {
            $id = bin2hex(random_bytes(16));
        } catch (Throwable) {
            return ['ok' => false, 'code' => 'storage_error'];
        }
        $records[] = ['id' => $id, 'name' => $name, 'spec' => $spec];
        if (!function_exists('save_data') || !save_data($path, array_values($records))) {
            return ['ok' => false, 'code' => 'storage_error'];
        }
        return ['ok' => true, 'code' => 'saved', 'id' => $id];
    } finally {
        @flock($guard, LOCK_UN);
        fclose($guard);
    }
}
