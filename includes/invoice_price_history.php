<?php
declare(strict_types=1);

/** Latest prior price per exact description, unit and VAT mode, without customer data. */
function portal_invoice_price_history(array $records, string $excludeId = ''): array
{
    $latest = [];
    foreach (array_slice($records, 0, 1000) as $record) {
        if (!is_array($record) || ($excludeId !== '' && ($record['id'] ?? '') === $excludeId)) {
            continue;
        }
        $vat = ($record['vat_mode'] ?? 'ex') === 'inc' ? 'inc' : 'ex';
        $date = is_string($record['date'] ?? null) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $record['date']) ? $record['date'] : '';
        foreach (array_slice(is_array($record['items'] ?? null) ? $record['items'] : [], 0, 100) as $item) {
            if (!is_array($item) || !is_string($item['art'] ?? null) || !is_numeric($item['price'] ?? null)) continue;
            $name = trim(app_legacy_text($item['art']));
            $unit = is_string($item['unit'] ?? null) && array_key_exists($item['unit'], portal_invoice_catalog_units()) ? $item['unit'] : 'st';
            $price = (float) $item['price'];
            if ($name === '' || strlen($name) > 800 || !is_finite($price) || $price < 0 || $price > 10000000) continue;
            $normalized = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower(strtr($name, ['Å' => 'å', 'Ä' => 'ä', 'Ö' => 'ö']));
            $key = $normalized . "\0" . $unit . "\0" . $vat;
            if (!isset($latest[$key]) && count($latest) < 1000) {
                $latest[$key] = ['name' => $name, 'unit' => $unit, 'vat' => $vat, 'price' => $price, 'date' => $date];
            }
        }
    }
    return array_values($latest);
}
