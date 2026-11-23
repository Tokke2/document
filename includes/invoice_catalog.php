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

/** The fixed-price audit is stored in the same atomic JSON write as the catalog.
 * It is never part of the public/voice article list. Null means corrupt history. */
function portal_invoice_catalog_price_history(array $records): ?array
{
    $history = null;
    foreach ($records as $record) {
        if (!is_array($record) || ($record['record_type'] ?? '') !== 'portal_fixed_price_history') continue;
        if ($history !== null || isset($record['id']) || isset($record['fixed_price']) || !is_array($record['events'] ?? null) || !array_is_list($record['events']) || count($record['events']) > 20000) return null;
        $history = $record['events'];
        foreach ($history as $event) {
            if (!is_array($event) || !is_string($event['at'] ?? null) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $event['at']) ||
                !is_string($event['article_id'] ?? null) || !preg_match('/\A[a-f0-9]{32}\z/iD', $event['article_id']) ||
                !is_string($event['article_name'] ?? null) || !portal_invoice_catalog_history_text($event['article_name'], 800) ||
                !is_string($event['actor_id'] ?? null) || !portal_invoice_catalog_history_text($event['actor_id'], 480) ||
                !is_string($event['actor_name'] ?? null) || !portal_invoice_catalog_history_text($event['actor_name'], 480) ||
                !in_array($event['action'] ?? null, ['created','enabled','changed','disabled','deleted'], true) ||
                !is_bool($event['old_fixed'] ?? null) || !is_bool($event['new_fixed'] ?? null) ||
                !array_key_exists('old_price', $event) || !array_key_exists('new_price', $event)) return null;
            foreach (['old_price','new_price'] as $key) if ($event[$key] !== null && (!is_numeric($event[$key]) || !is_finite((float)$event[$key]) || (float)$event[$key] < 0 || (float)$event[$key] > 10000000)) return null;
            foreach (['old_unit','new_unit'] as $key) if (!array_key_exists($key, $event) || ($event[$key] !== null && (!is_string($event[$key]) || !array_key_exists($event[$key], portal_invoice_catalog_units())))) return null;
        }
    }
    return $history ?? [];
}
function portal_invoice_catalog_history_text(string $text, int $max): bool
{
    return trim($text) !== '' && strlen($text) <= $max && preg_match('//u', $text) === 1 && preg_match('/[\x00-\x1f\x7f]/u', $text) !== 1;
}
/** Caller supplies the authenticated personal administrator, never POST fields. */
function portal_invoice_catalog_append_history(array &$records, array $old, ?array $new, array $actor): string
{
    $id = $actor['id'] ?? null;
    $actorName = $actor['name'] ?? ($actor['username'] ?? null);
    if (!is_string($id) || !portal_invoice_catalog_history_text($id, 480) || !is_string($actorName) || !portal_invoice_catalog_history_text($actorName, 480)) return 'history_actor';
    $events = portal_invoice_catalog_price_history($records);
    if ($events === null) return 'history_error';
    if (count($events) >= 20000) return 'history_full';
    $oldFixed = ($old['fixed_price'] ?? false) === true;
    $newFixed = ($new['fixed_price'] ?? false) === true;
    $action = $new === null ? 'deleted' : (!$old ? 'created' : (!$oldFixed && $newFixed ? 'enabled' : ($oldFixed && !$newFixed ? 'disabled' : 'changed')));
    $events[] = [
        'at'=>gmdate('Y-m-d\TH:i:s\Z'), 'article_id'=>$new['id'] ?? $old['id'], 'article_name'=>$new['name'] ?? $old['name'],
        'actor_id'=>$id, 'actor_name'=>trim($actorName), 'action'=>$action,
        'old_fixed'=>$oldFixed, 'new_fixed'=>$newFixed,
        'old_price'=>$old ? round((float)$old['price'],2) : null, 'new_price'=>$new === null ? null : round((float)$new['price'],2),
        'old_unit'=>$old['unit'] ?? null, 'new_unit'=>$new['unit'] ?? null,
    ];
    $entry = ['record_type'=>'portal_fixed_price_history', 'events'=>$events];
    foreach ($records as $i=>$record) if (is_array($record) && ($record['record_type'] ?? '') === 'portal_fixed_price_history') { $records[$i] = $entry; return ''; }
    $records[] = $entry;
    return '';
}

/** @param array<int, mixed> $records @return array<int, array{id:string,name:string,unit:string,price:float}> */
function portal_invoice_catalog_normalize(array $records): array
{
    $articles = [];
    $allowedUnits = portal_invoice_catalog_units();
    foreach ($records as $record) {
        if (!is_array($record) || ($record['record_type'] ?? '') === 'portal_fixed_price_history') {
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
        $aliases = portal_invoice_catalog_alias_list($record['aliases'] ?? []);
        if ($aliases === null) continue;
        $articles[] = ['id' => $id, 'name' => $name, 'unit' => $unit, 'price' => round($price, 2), 'fixed_price' => ($record['fixed_price'] ?? false) === true, 'aliases'=>$aliases];
    }

    usort($articles, static function (array $left, array $right): int {
        return strnatcasecmp($left['name'], $right['name']);
    });
    return $articles;
}

/** Validated names only; aliases are configured by a personal invoice admin. */
function portal_invoice_catalog_alias_list(mixed $raw): ?array
{
    if (!is_array($raw) || !array_is_list($raw) || count($raw) > 20) return null;
    $out = [];
    foreach ($raw as $alias) {
        if (!is_string($alias) || preg_match('//u', $alias) !== 1 || preg_match('/[\x00-\x1f\x7f]/u', $alias)) return null;
        $alias = trim($alias);
        if ($alias === '' || strlen($alias) > 800 || preg_match_all('/./us', $alias) > 200) return null;
        foreach ($out as $existing) if (portal_invoice_catalog_same_name($existing, $alias)) return null;
        $out[] = $alias;
    }
    return $out;
}
function portal_invoice_catalog_names(array $article): array
{
    return array_merge([$article['name']], is_array($article['aliases'] ?? null) ? $article['aliases'] : []);
}
function portal_invoice_catalog_has_name(array $article, string $name): bool
{
    foreach (portal_invoice_catalog_names($article) as $candidate) if (portal_invoice_catalog_same_name($candidate, $name)) return true;
    return false;
}
/** Exact description match, ignoring case and runs of whitespace. */
function portal_invoice_catalog_same_name(string $left, string $right): bool
{
    $a = preg_replace('/\s+/u', ' ', trim($left));
    $b = preg_replace('/\s+/u', ' ', trim($right));
    return is_string($a) && is_string($b) && $a !== '' && preg_match('/\A' . preg_quote($a, '/') . '\z/iuD', $b) === 1;
}
/** Return null if a fixed item is malformed: never silently ignore a broken lock. */
function portal_invoice_catalog_fixed_rules(array $records): ?array
{
    foreach ($records as $record) {
        if (!is_array($record)) return null;
        if (($record['record_type'] ?? '') === 'portal_fixed_price_history') { if (isset($record['fixed_price']) || isset($record['id'])) return null; continue; }
        if (isset($record['fixed_price']) && !is_bool($record['fixed_price'])) return null;
        if (portal_invoice_catalog_alias_list($record['aliases'] ?? []) === null) return null;
        if (($record['fixed_price'] ?? false) === true && count(portal_invoice_catalog_normalize([$record])) !== 1) return null;
    }
    return portal_invoice_catalog_normalize($records);
}
/** Validate prices and bind new fixed rows to immutable historical snapshots.
 * A saved fixed lock can only be reused for its own unchanged name/unit/price.
 * Quantity remains editable. Legacy rows get a lock on their next save. */
function portal_invoice_catalog_enforce_fixed(array $items, array $old, array $articles): ?array
{
    $remaining = is_array($old['items'] ?? null) ? array_values($old['items']) : [];
    $usedLocks = [];
    foreach ($items as &$item) {
        $lock = $item['fixed_lock'] ?? '';
        if ($lock !== '') {
            if (!is_string($lock) || !preg_match('/\A[a-f0-9]{32}\z/D', $lock) || isset($usedLocks[$lock])) return null;
            $found = false;
            foreach ($remaining as $i=>$saved) {
                if (!is_array($saved) || ($saved['fixed_lock'] ?? '') !== $lock) continue;
                if (!is_string($saved['art'] ?? null) || $saved['art'] !== $item['art'] || ($saved['unit'] ?? null) !== $item['unit'] || !is_numeric($saved['price'] ?? null) || (float)$saved['price'] !== (float)$item['price']) return null;
                unset($remaining[$i]); $found = true; break;
            }
            if (!$found) return null;
            $usedLocks[$lock] = true;
            continue;
        }
        $matching = array_values(array_filter($articles, static fn($a) => portal_invoice_catalog_has_name($a, $item['art'])));
        $fixed = array_values(array_filter($matching, static fn($a) => $a['fixed_price'] === true));
        if (!$fixed) continue;
        if (count($fixed) !== 1 || count($matching) !== 1) return null;
        $article = $fixed[0];
        if ($item['unit'] !== $article['unit'] || (float)$item['price'] !== (float)$article['price']) {
            $found = false;
            foreach ($remaining as $i=>$saved) {
                if (is_array($saved) && empty($saved['fixed_lock']) && is_string($saved['art'] ?? null) && portal_invoice_catalog_same_name($item['art'], $saved['art']) && ($saved['unit'] ?? '') === $item['unit'] && is_numeric($saved['price'] ?? null) && (float)$saved['price'] === (float)$item['price']) {
                    unset($remaining[$i]); $found = true; break;
                }
            }
            if (!$found) return null;
        }
        try { $item['fixed_lock'] = bin2hex(random_bytes(16)); } catch (Throwable) { return null; }
    }
    unset($item);
    return $items;
}
function portal_invoice_catalog_validate_fixed(array $items, array $old, array $articles): bool
{
    return portal_invoice_catalog_enforce_fixed($items, $old, $articles) !== null;
}
/** @return array<int, array{id:string,name:string,unit:string,price:float}> */
function portal_invoice_catalog_list(): array
{
    $records = portal_invoice_catalog_read();
    return is_array($records) ? portal_invoice_catalog_normalize($records) : [];
}

/** Serialize catalogue changes and invoice fixed-price validation. */
function portal_invoice_catalog_lock()
{
    $path = portal_invoice_catalog_path() . '.guard.lock';
    if (is_link(dirname($path)) || is_link($path) || app_safe_data_path($path) === null) return null;
    $mask = umask(0077); $lock = @fopen($path, 'c'); umask($mask);
    if (!is_resource($lock)) return null;
    @chmod($path, 0600);
    if (!@flock($lock, LOCK_EX)) { fclose($lock); return null; }
    return $lock;
}
function portal_invoice_catalog_unlock($lock): void
{
    if (is_resource($lock)) { @flock($lock, LOCK_UN); fclose($lock); }
}
function portal_invoice_catalog_save(array $source, bool $isAdmin = false, array $actor = []): array
{
    $lock = portal_invoice_catalog_lock();
    if (!is_resource($lock)) return ['ok'=>false, 'code'=>'storage_error'];
    try { return portal_invoice_catalog_save_locked($source, $isAdmin, $actor); }
    finally { portal_invoice_catalog_unlock($lock); }
}
function portal_invoice_catalog_delete(string $id, bool $isAdmin = false, array $actor = []): array
{
    $lock = portal_invoice_catalog_lock();
    if (!is_resource($lock)) return ['ok'=>false, 'code'=>'storage_error'];
    try { return portal_invoice_catalog_delete_locked($id, $isAdmin, $actor); }
    finally { portal_invoice_catalog_unlock($lock); }
}
/** @return array{ok:bool,code:string,id?:string} */
function portal_invoice_catalog_save_locked(array $source, bool $isAdmin, array $actor = []): array
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
    if (!is_array($records) || portal_invoice_catalog_fixed_rules($records) === null) {
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
    $fixedInput = $input['fixed_price'] ?? null;
    if ($fixedInput !== null && !in_array($fixedInput, ['0', '1'], true)) return ['ok'=>false, 'code'=>'invalid_input'];
    if (!$isAdmin && ($fixedInput !== null || ($existing['fixed_price'] ?? false) === true)) return ['ok'=>false, 'code'=>'admin_required'];
    $fixedPrice = $isAdmin && $fixedInput === '1';
    $aliases = portal_invoice_catalog_alias_list($existing['aliases'] ?? []);
    if ($aliases === null) return ['ok'=>false,'code'=>'storage_error'];
    if (array_key_exists('aliases', $input)) {
        if (!$isAdmin) return ['ok'=>false,'code'=>'admin_required'];
        if (!is_string($input['aliases']) || strlen($input['aliases']) > 16000) return ['ok'=>false,'code'=>'invalid_alias'];
        $lines = preg_split('/\R/u', trim($input['aliases']));
        if (!is_array($lines)) return ['ok'=>false,'code'=>'invalid_alias'];
        $lines = array_values(array_filter(array_map('trim', $lines), static fn($line) => $line !== ''));
        $aliases = portal_invoice_catalog_alias_list($lines);
        if ($aliases === null) return ['ok'=>false,'code'=>'invalid_alias'];
    }
    foreach ($aliases as $alias) if (portal_invoice_catalog_same_name($name, $alias)) return ['ok'=>false,'code'=>'alias_duplicate'];
    foreach (portal_invoice_catalog_normalize($records) as $other) {
        if ($other['id'] === $id) continue;
        if (($fixedPrice || $other['fixed_price']) && portal_invoice_catalog_same_name($name, $other['name'])) return ['ok'=>false, 'code'=>'fixed_duplicate'];
        foreach (portal_invoice_catalog_names($other) as $existingName) {
            if (portal_invoice_catalog_same_name($name, $existingName) && !portal_invoice_catalog_same_name($existingName, $other['name'])) return ['ok'=>false,'code'=>'alias_duplicate'];
            foreach ($aliases as $alias) if (portal_invoice_catalog_same_name($alias, $existingName)) return ['ok'=>false,'code'=>'alias_duplicate'];
        }
    }
    if ($existingIndex === null && count(array_filter($records, static fn($r) => !is_array($r) || ($r['record_type'] ?? '') !== 'portal_fixed_price_history')) >= 500) {
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
        'fixed_price' => $fixedPrice,
        'aliases' => $aliases,
        'created_at' => is_string($existing['created_at'] ?? null) ? $existing['created_at'] : $now,
        'updated_at' => $now,
    ];
    $oldFixed = ($existing['fixed_price'] ?? false) === true;
    $requiresHistory = ($oldFixed || $fixedPrice) && (!$existing || $oldFixed !== $fixedPrice || (float)($existing['price'] ?? -1) !== (float)$article['price'] || ($existing['unit'] ?? '') !== $unit);
    if ($requiresHistory) {
        $historyError = portal_invoice_catalog_append_history($records, $existing, $article, $actor);
        if ($historyError !== '') return ['ok'=>false, 'code'=>$historyError];
    }
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
function portal_invoice_catalog_delete_locked(string $id, bool $isAdmin, array $actor = []): array
{
    if (!preg_match('/\A[a-f0-9]{32}\z/i', $id)) {
        return ['ok' => false, 'code' => 'invalid_id'];
    }
    $records = portal_invoice_catalog_read();
    if (!is_array($records) || portal_invoice_catalog_fixed_rules($records) === null) {
        return ['ok' => false, 'code' => 'storage_error'];
    }

    $found = null;
    foreach ($records as $record) {
        if (is_array($record) && (string) ($record['id'] ?? '') === $id) { $found = $record; break; }
    }
    if ($found === null) return ['ok'=>false, 'code'=>'not_found'];
    if (($found['fixed_price'] ?? false) === true) {
        if (!$isAdmin) return ['ok'=>false, 'code'=>'admin_required'];
        $historyError = portal_invoice_catalog_append_history($records, $found, null, $actor);
        if ($historyError !== '') return ['ok'=>false, 'code'=>$historyError];
    }
    $remaining = array_values(array_filter($records, static fn($record) => !is_array($record) || (string)($record['id'] ?? '') !== $id));
    if (!function_exists('save_data') || !save_data(portal_invoice_catalog_path(), array_values($remaining))) {
        return ['ok' => false, 'code' => 'storage_error'];
    }
    return ['ok' => true, 'code' => 'deleted'];
}
