<?php
declare(strict_types=1);

/** Build a reviewable invoice from explicitly selected service-protocol rows. */
function portal_invoice_from_protocol(array $protocol, mixed $selectedRows): array
{
    $sourceRows = is_array($protocol['items'] ?? null) ? array_values(array_slice($protocol['items'], 0, 100)) : [];
    $indices = [];
    if (is_array($selectedRows)) {
        foreach (array_slice($selectedRows, 0, 100) as $index) {
            if (is_string($index) && preg_match('/\A(?:0|[1-9][0-9]?)\z/D', $index)) $indices[(int) $index] = true;
        }
    }
    $clip = static function (mixed $value, int $limit): string {
        $text = app_legacy_text($value);
        if (preg_match('//u', $text) !== 1) return '';
        return implode('', array_slice(preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, $limit));
    };
    $items = [];
    foreach ($sourceRows as $index => $row) {
        if (!isset($indices[$index]) || !is_array($row)) continue;
        $name = trim(app_legacy_text($row['art'] ?? ''));
        if ($name === '') continue;
        $spec = trim(app_legacy_text($row['spec'] ?? ''));
        $name = trim($clip($name . ($spec !== '' ? ' – ' . $spec : ''), 200));
        if ($name === '') continue;
        $rawQty = $row['qty'] ?? '';
        $qty = is_numeric($rawQty) && is_finite((float) $rawQty) && (float) $rawQty >= 0 && (float) $rawQty <= 100000 ? (string) $rawQty : '';
        $items[] = ['art' => $name, 'qty' => $qty, 'unit' => 'st', 'price' => ''];
    }
    $sourceId = is_string($protocol['id'] ?? null) ? $protocol['id'] : '';
    $sourceNumber = $clip($protocol['nummer'] ?? '', 150);
    $reference = trim('Protokoll ' . $sourceNumber);
    return [
        'id' => '', 'date' => date('Y-m-d'),
        'customer' => $clip($protocol['customer'] ?? '', 180),
        'ref' => $reference,
        'description' => $clip($protocol['description'] ?? '', 5000),
        'vat_mode' => 'ex', 'items' => $items, 'source_protocol_id' => $sourceId,
    ];
}
