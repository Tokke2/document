<?php
declare(strict_types=1);

/** Normalize a stored value for safe, memory-only global-search matching. */
function portal_global_search_clean_text(mixed $value, int $maxCharacters = 4000): string
{
    if (!is_scalar($value)) {
        return '';
    }
    $text = (string) $value;
    if (function_exists('app_legacy_text')) {
        $text = app_legacy_text($text);
    }
    if (preg_match('//u', $text) !== 1) {
        $converted = function_exists('iconv') ? @iconv('UTF-8', 'UTF-8//IGNORE', $text) : false;
        $text = is_string($converted) ? $converted : '';
    }
    $text = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $text);
    $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? '';
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    $maxCharacters = max(1, min(10000, $maxCharacters));
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxCharacters, 'UTF-8');
    }
    $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($characters)
        ? implode('', array_slice($characters, 0, $maxCharacters))
        : substr($text, 0, $maxCharacters);
}

function portal_global_search_lower(string $value): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value, 'UTF-8');
    }
    if (function_exists('iconv')) {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($transliterated)) {
            return strtolower($transliterated);
        }
    }
    return strtolower($value);
}

function portal_global_search_contains(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return false;
    }
    if (function_exists('mb_stripos')) {
        return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
    }
    return stripos($haystack, $needle) !== false;
}

/** Split a bounded query into terms; every term must match the record. */
function portal_global_search_terms(string $query): array
{
    $query = portal_global_search_clean_text($query, 120);
    if (function_exists('mb_strlen')) {
        $queryLength = mb_strlen($query, 'UTF-8');
    } elseif (preg_match_all('/./us', $query, $queryCharacters) !== false) {
        $queryLength = count($queryCharacters[0] ?? []);
    } else {
        $queryLength = strlen($query);
    }
    if ($queryLength < 2) {
        return [];
    }
    $parts = preg_split('/\s+/u', portal_global_search_lower($query), -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($parts) || $parts === []) {
        return [];
    }
    return array_values(array_unique(array_slice($parts, 0, 8)));
}

/** Return only the approved searchable fields for the requested record type. */
function portal_global_search_record_fields(string $area, array $record): array
{
    $fields = [];
    $add = static function (mixed $value) use (&$fields): void {
        $text = portal_global_search_clean_text($value);
        if ($text !== '') {
            $fields[] = $text;
        }
    };

    if ($area === 'customers') {
        foreach (['name', 'phone', 'email', 'property_id', 'reference'] as $key) {
            $add($record[$key] ?? '');
        }
        $references = is_array($record['references'] ?? null) ? array_slice($record['references'], 0, 20) : [];
        foreach ($references as $reference) {
            $add($reference);
        }
        // Deliberately do not search personal_id or any other identity number.
        return $fields;
    }

    if ($area === 'invoice') {
        foreach (['nummer', 'fortnox_invoice_number', 'fortnox_invoice_date', 'date', 'customer', 'ref', 'description'] as $key) {
            $add($record[$key] ?? '');
        }
        $items = is_array($record['items'] ?? null) ? array_slice($record['items'], 0, 100) : [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $add($item['art'] ?? '');
            }
        }
        // Prices, line totals, VAT values, email status, and private files are never searched.
        return $fields;
    }

    if ($area === 'protocol') {
        foreach (['nummer', 'reg', 'regnr', 'date', 'km', 'customer', 'tech', 'description'] as $key) {
            $add($record[$key] ?? '');
        }
        $items = is_array($record['items'] ?? null) ? array_slice($record['items'], 0, 100) : [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $add($item['art'] ?? '');
                $add($item['spec'] ?? '');
            }
        }
        // Meter photos, signatures, private file names, and email metadata are never searched.
        return $fields;
    }

    return [];
}

function portal_global_search_priority_fields(string $area, array $record): array
{
    if ($area === 'customers') {
        return [
            $record['name'] ?? '', $record['phone'] ?? '', $record['email'] ?? '',
            $record['property_id'] ?? '', $record['reference'] ?? '',
        ];
    }
    if ($area === 'invoice') {
        return [
            $record['nummer'] ?? '', $record['fortnox_invoice_number'] ?? '',
            $record['customer'] ?? '', $record['ref'] ?? '', $record['date'] ?? '',
        ];
    }
    if ($area === 'protocol') {
        $registration = portal_global_search_clean_text($record['reg'] ?? '', 40);
        if ($registration === '') {
            $registration = $record['regnr'] ?? '';
        }
        return [
            $record['nummer'] ?? '', $registration,
            $record['customer'] ?? '', $record['date'] ?? '', $record['tech'] ?? '',
        ];
    }
    return [];
}

function portal_global_search_result(string $area, array $record): ?array
{
    $id = portal_global_search_clean_text($record['id'] ?? '', 128);
    if ($id === '' || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $id)) {
        return null;
    }

    if ($area === 'customers') {
        $title = portal_global_search_clean_text($record['name'] ?? '', 180);
        if ($title === '') {
            $title = 'Kund utan namn';
        }
        $details = [];
        foreach (['phone', 'email'] as $key) {
            $value = portal_global_search_clean_text($record[$key] ?? '', 180);
            if ($value !== '') {
                $details[] = $value;
            }
        }
        return [
            'id' => $id,
            'title' => $title,
            'subtitle' => implode(' · ', $details) ?: 'Kundregister',
        ];
    }

    if ($area === 'invoice') {
        $number = portal_global_search_clean_text($record['nummer'] ?? '', 80);
        $title = 'Underlag ' . ($number !== '' ? $number : 'utan nummer');
        $details = [];
        foreach (['date', 'customer', 'ref'] as $key) {
            $value = portal_global_search_clean_text($record[$key] ?? '', $key === 'customer' ? 180 : 120);
            if ($value !== '') {
                $details[] = $value;
            }
        }
        $fortnoxNumber = portal_global_search_clean_text($record['fortnox_invoice_number'] ?? '', 80);
        if ($fortnoxNumber !== '') {
            $details[] = 'Fortnox ' . $fortnoxNumber;
        }
        return [
            'id' => $id,
            'title' => $title,
            'subtitle' => implode(' · ', $details) ?: 'Fakturaunderlag',
        ];
    }

    if ($area === 'protocol') {
        $number = portal_global_search_clean_text($record['nummer'] ?? '', 80);
        $title = 'Protokoll ' . ($number !== '' ? $number : 'utan nummer');
        $registration = portal_global_search_clean_text($record['reg'] ?? '', 40);
        if ($registration === '') {
            $registration = portal_global_search_clean_text($record['regnr'] ?? '', 40);
        }
        $details = [];
        foreach (['date', 'customer'] as $key) {
            $value = portal_global_search_clean_text($record[$key] ?? '', $key === 'customer' ? 180 : 120);
            if ($value !== '') {
                $details[] = $value;
            }
        }
        if ($registration !== '') {
            $details[] = $registration;
        }
        return [
            'id' => $id,
            'title' => $title,
            'subtitle' => implode(' · ', $details) ?: 'Serviceprotokoll',
        ];
    }

    return null;
}

/** Search a pre-authorized record set and return a small, non-sensitive result list. */
function portal_global_search_records(string $area, array $records, string $query, int $limit = 10): array
{
    if (!in_array($area, ['customers', 'invoice', 'protocol'], true)) {
        return [];
    }
    $terms = portal_global_search_terms($query);
    if ($terms === []) {
        return [];
    }
    $queryLower = portal_global_search_lower(portal_global_search_clean_text($query, 120));
    $matches = [];
    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }
        $fields = portal_global_search_record_fields($area, $record);
        if ($fields === []) {
            continue;
        }
        $haystack = portal_global_search_lower(implode(' ', $fields));
        $allTermsMatch = true;
        foreach ($terms as $term) {
            if (!portal_global_search_contains($haystack, $term)) {
                $allTermsMatch = false;
                break;
            }
        }
        if (!$allTermsMatch) {
            continue;
        }
        $result = portal_global_search_result($area, $record);
        if (!is_array($result)) {
            continue;
        }
        $score = 100;
        foreach (portal_global_search_priority_fields($area, $record) as $index => $priorityField) {
            $priorityText = portal_global_search_lower(portal_global_search_clean_text($priorityField));
            if (portal_global_search_contains($priorityText, $queryLower)) {
                $score = 1000 - ((int) $index * 25);
                break;
            }
        }
        $sortDate = portal_global_search_clean_text($record['updated_at'] ?? $record['date'] ?? $record['created_at'] ?? '', 40);
        $matches[] = ['score' => $score, 'sort_date' => $sortDate, 'result' => $result];
    }

    usort($matches, static function (array $left, array $right): int {
        $scoreOrder = (int) ($right['score'] ?? 0) <=> (int) ($left['score'] ?? 0);
        if ($scoreOrder !== 0) {
            return $scoreOrder;
        }
        return strcmp((string) ($right['sort_date'] ?? ''), (string) ($left['sort_date'] ?? ''));
    });

    $limit = max(1, min(20, $limit));
    return array_values(array_map(
        static fn (array $entry): array => $entry['result'],
        array_slice($matches, 0, $limit)
    ));
}
