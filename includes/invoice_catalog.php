<?php
declare(strict_types=1);

/**
 * Private, manually maintained article catalogue for invoice work sheets.
 * Articles contain a description, unit, and unit price; VAT is never configured
 * per article and is only stored as a yes/no inclusion flag on each invoice.
 */
function portal_invoice_catalog_units(): array
{
    return [
        'st' => 'st',
        'tim' => 'tim',
        'km' => 'km',
        'dag' => 'dag',
        'm' => 'm',
        'kg' => 'kg',
        'other' => 'övrig',
    ];
}

function portal_invoice_catalog_path(): string
{
    return dirname(__DIR__) . '/storage/invoice_articles.json';
}

/** @return array<int, mixed>|null Null means the private file could not be read safely. */
function portal_invoice_catalog_read(): ?array
{
    $path = portal_invoice_catalog_path();
    $appRoot = realpath(dirname(__DIR__));
    $storageDir = realpath(dirname($path));

    if (
        $appRoot === false || $storageDir === false || is_link(dirname($path)) || is_link($path) ||
        dirname($storageDir) !== $appRoot || basename($path) !== 'invoice_articles.json'
    ) {
        return null;
    }
    if (!file_exists($path)) {
        return [];
    }
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }

    $content = @file_get_contents($path);
    if (!is_string($content) || $content === '') {
        return null;
    }
    try {
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    return is_array($decoded) ? $decoded : null;
}

/** @param array<int, mixed> $records @return array<int, array{id:string,name:string,unit:string,price:float}> */
function portal_invoice_catalog_normalize(array $records): array
{
    $articles = [];
    $allowedUnits = portal_invoice_catalog_units();
    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }
        $id = is_scalar($record['id'] ?? null) ? (string) $record['id'] : '';
        $name = is_scalar($record['name'] ?? null) ? trim((string) $record['name']) : '';
        $unitCandidate = is_scalar($record['unit'] ?? null) ? (string) $record['unit'] : 'st';
        $unit = array_key_exists($unitCandidate, $allowedUnits) ? $unitCandidate : 'st';
        $rawPrice = $record['price'] ?? null;
        if (
            !preg_match('/\A[a-f0-9]{32}\z/i', $id) || $name === '' ||
            preg_match('//u', $name) !== 1 || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1
        ) {
            continue;
        }
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($nameLength > 200 || !is_numeric($rawPrice)) {
            continue;
        }
        $price = (float) $rawPrice;
        if (!is_finite($price) || $price < 0 || $price > 10000000) {
            continue;
        }
        $articles[] = ['id' => $id, 'name' => $name, 'unit' => $unit, 'price' => round($price, 2)];
    }

    usort($articles, static function (array $left, array $right): int {
        return strnatcasecmp($left['name'], $right['name']);
    });
    return $articles;
}

/** @return array<int, array{id:string,name:string,unit:string,price:float}> */
function portal_invoice_catalog_list(): array
{
    $records = portal_invoice_catalog_read();
    return is_array($records) ? portal_invoice_catalog_normalize($records) : [];
}

/** @return array{ok:bool,code:string,id?:string} */
function portal_invoice_catalog_save(array $source): array
{
    $input = is_array($source['article'] ?? null) ? $source['article'] : [];
    $name = $input['name'] ?? null;
    $unit = $input['unit'] ?? 'st';
    $priceRaw = $input['price'] ?? null;
    $id = $input['id'] ?? '';

    if (!is_string($name) || !is_string($unit) || !is_string($priceRaw) || !is_string($id)) {
        return ['ok' => false, 'code' => 'invalid_input'];
    }
    $name = trim($name);
    $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
    if (
        $name === '' || $nameLength > 200 || preg_match('//u', $name) !== 1 ||
        preg_match('/[\x00-\x1F\x7F]/u', $name) === 1
    ) {
        return ['ok' => false, 'code' => 'invalid_name'];
    }
    if (!array_key_exists($unit, portal_invoice_catalog_units())) {
        return ['ok' => false, 'code' => 'invalid_unit'];
    }

    $priceRaw = trim($priceRaw);
    if (!preg_match('/\A\d{1,10}(?:[.,]\d{1,2})?\z/D', $priceRaw)) {
        return ['ok' => false, 'code' => 'invalid_price'];
    }
    $price = (float) str_replace(',', '.', $priceRaw);
    if (!is_finite($price) || $price < 0 || $price > 10000000) {
        return ['ok' => false, 'code' => 'invalid_price'];
    }
    if ($id !== '' && !preg_match('/\A[a-f0-9]{32}\z/i', $id)) {
        return ['ok' => false, 'code' => 'invalid_id'];
    }

    $records = portal_invoice_catalog_read();
    if (!is_array($records)) {
        return ['ok' => false, 'code' => 'storage_error'];
    }

    $existingIndex = null;
    $existing = [];
    foreach ($records as $index => $record) {
        if (is_array($record) && (string) ($record['id'] ?? '') === $id && $id !== '') {
            $existingIndex = $index;
            $existing = $record;
            break;
        }
    }
    if ($id !== '' && $existingIndex === null) {
        return ['ok' => false, 'code' => 'not_found'];
    }
    if ($existingIndex === null && count($records) >= 500) {
        return ['ok' => false, 'code' => 'catalog_full'];
    }

    if ($id === '') {
        try {
            $id = bin2hex(random_bytes(16));
        } catch (Throwable) {
            return ['ok' => false, 'code' => 'storage_error'];
        }
    }

    $now = gmdate('Y-m-d\TH:i:s\Z');
    $article = [
        'id' => $id,
        'name' => $name,
        'unit' => $unit,
        'price' => round($price, 2),
        'created_at' => is_string($existing['created_at'] ?? null) ? $existing['created_at'] : $now,
        'updated_at' => $now,
    ];
    if ($existingIndex === null) {
        $records[] = $article;
    } else {
        $records[$existingIndex] = $article;
    }

    if (!function_exists('save_data') || !save_data(portal_invoice_catalog_path(), array_values($records))) {
        return ['ok' => false, 'code' => 'storage_error'];
    }
    return ['ok' => true, 'code' => 'saved', 'id' => $id];
}

/** @return array{ok:bool,code:string} */
function portal_invoice_catalog_delete(string $id): array
{
    if (!preg_match('/\A[a-f0-9]{32}\z/i', $id)) {
        return ['ok' => false, 'code' => 'invalid_id'];
    }
    $records = portal_invoice_catalog_read();
    if (!is_array($records)) {
        return ['ok' => false, 'code' => 'storage_error'];
    }

    $found = false;
    $remaining = [];
    foreach ($records as $record) {
        if (is_array($record) && (string) ($record['id'] ?? '') === $id) {
            $found = true;
            continue;
        }
        $remaining[] = $record;
    }
    if (!$found) {
        return ['ok' => false, 'code' => 'not_found'];
    }
    if (!function_exists('save_data') || !save_data(portal_invoice_catalog_path(), array_values($remaining))) {
        return ['ok' => false, 'code' => 'storage_error'];
    }
    return ['ok' => true, 'code' => 'deleted'];
}
