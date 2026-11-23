<?php
declare(strict_types=1);

/** Server-side, per-account autosaved form drafts; never an audit log. */
function portal_autosaved_drafts_file(): string
{
    return __DIR__ . '/../storage/autosaved_drafts.json';
}

function portal_autosaved_drafts_ttl(): int
{
    return 3 * 86400;
}

function portal_autosaved_drafts_storage_is_safe(): bool
{
    $storageDir = dirname(portal_autosaved_drafts_file());
    $guard = $storageDir . '/.htaccess';
    $root = realpath(dirname(__DIR__));
    $realDir = realpath($storageDir);
    if (is_link($storageDir) || is_link($guard) || !is_file($guard) || $root === false || $realDir === false || !is_dir($realDir)) {
        return false;
    }
    return strpos($realDir, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0;
}

function portal_autosaved_drafts_read(): ?array
{
    $path = portal_autosaved_drafts_file();
    if (!portal_autosaved_drafts_storage_is_safe() || is_link($path) || app_safe_data_path($path) === null) {
        return null;
    }
    if (!file_exists($path)) {
        return [];
    }
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $content = @file_get_contents($path);
    if (!is_string($content) || trim($content) === '') {
        return null;
    }
    try {
        $drafts = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    return is_array($drafts) ? $drafts : null;
}

/**
 * Run an atomic draft-store update. The guard lock protects read/modify/write;
 * save_data() then performs its usual locked/atomic JSON write.
 */
function portal_autosaved_drafts_update(callable $mutator): array
{
    $path = portal_autosaved_drafts_file();
    $guardPath = $path . '.guard.lock';
    if (
        !portal_autosaved_drafts_storage_is_safe() || app_safe_data_path($path) === null ||
        is_link($path) || is_link($path . '.lock') || is_link($guardPath)
    ) {
        return ['ok' => false, 'code' => 'storage_error'];
    }
    $oldUmask = umask(0077);
    $lock = @fopen($guardPath, 'c');
    umask($oldUmask);
    if (!is_resource($lock)) {
        return ['ok' => false, 'code' => 'storage_error'];
    }
    @chmod($guardPath, 0600);
    if (!@flock($lock, LOCK_EX)) {
        fclose($lock);
        return ['ok' => false, 'code' => 'storage_error'];
    }
    try {
        $drafts = portal_autosaved_drafts_read();
        if (!is_array($drafts)) {
            return ['ok' => false, 'code' => 'storage_error'];
        }
        $before = $drafts;
        $now = time();
        foreach ($drafts as $draftId => $draft) {
            if (!is_array($draft) || (int) ($draft['updated_at'] ?? 0) < $now - portal_autosaved_drafts_ttl()) {
                unset($drafts[$draftId]);
            }
        }
        $purged = $before !== $drafts;
        $result = $mutator($drafts, $now);
        if (!is_array($result)) {
            return ['ok' => false, 'code' => 'storage_error'];
        }
        $write = $purged || (($result['_write'] ?? false) === true);
        unset($result['_write']);
        if ($write && !save_data($path, $drafts)) {
            return ['ok' => false, 'code' => 'storage_error'];
        }
        return $result;
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function portal_autosaved_drafts_new_id(): string
{
    try {
        return bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        return '';
    }
}

function portal_autosaved_drafts_text(array $source, string $key, int $maxLength): ?string
{
    $value = $source[$key] ?? '';
    if (!is_string($value)) {
        return null;
    }
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    if (preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)) {
        return null;
    }
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    if ($length > $maxLength) {
        return null;
    }
    return trim($value);
}

function portal_autosaved_drafts_sanitize_payload(string $type, array $post, bool $isInvoiceAdmin = false): ?array
{
    if (!in_array($type, ['invoice', 'protocol'], true)) {
        return null;
    }
    $formKey = $type === 'invoice' ? 'i' : 'p';
    $form = $post[$formKey] ?? [];
    if (!is_array($form)) {
        return null;
    }

    $keys = $type === 'invoice'
        ? ['id' => 128, 'date' => 10, 'ref' => 160, 'customer' => 180, 'description' => 5000, 'vat_mode' => 8, 'source_protocol_id' => 128]
        : ['id' => 128, 'reg' => 20, 'date' => 10, 'km' => 20, 'customer' => 180, 'tech' => 120, 'description' => 5000];
    $cleanForm = [];
    foreach ($keys as $key => $maxLength) {
        $value = portal_autosaved_drafts_text($form, $key, $maxLength);
        if ($value === null) {
            return null;
        }
        $cleanForm[$key] = $value;
    }
    if ($cleanForm['id'] !== '' && !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $cleanForm['id'])) {
        return null;
    }
    if ($cleanForm['date'] !== '' && !preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $cleanForm['date'])) {
        return null;
    }
    if ($type === 'invoice') {
        if ($cleanForm['source_protocol_id'] !== '' && !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $cleanForm['source_protocol_id'])) {
            return null;
        }
        if (!in_array($cleanForm['vat_mode'], ['ex', 'inc'], true)) {
            return null;
        }
        // Only an invoice admin can keep this protected field in a draft.
        if ($isInvoiceAdmin) {
            $fortnox = portal_autosaved_drafts_text($form, 'fortnox_invoice_number', 80);
            if ($fortnox === null || ($fortnox !== '' && !preg_match('/\A[\pL\pN][\pL\pN .\/_-]{0,79}\z/u', $fortnox))) {
                return null;
            }
            $cleanForm['fortnox_invoice_number'] = $fortnox;
            $fortnoxDate = portal_autosaved_drafts_text($form, 'fortnox_invoice_date', 10);
            if ($fortnoxDate === null || ($fortnoxDate !== '' && !preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $fortnoxDate))) {
                return null;
            }
            $cleanForm['fortnox_invoice_date'] = $fortnoxDate;
        }
    } else {
        if ($cleanForm['reg'] !== '' && strlen($cleanForm['reg']) > 12) {
            return null;
        }
        if ($cleanForm['km'] !== '' && (!is_numeric($cleanForm['km']) || (float) $cleanForm['km'] < 0 || (float) $cleanForm['km'] > 99999999)) {
            return null;
        }
    }

    $rawItems = $post['items'] ?? [];
    if (!is_array($rawItems)) {
        return null;
    }
    $cleanItems = [];
    foreach (array_slice($rawItems, 0, 100, true) as $item) {
        if (!is_array($item)) {
            continue;
        }
        if ($type === 'invoice') {
            $art = portal_autosaved_drafts_text($item, 'art', 200);
            $unit = portal_autosaved_drafts_text($item, 'unit', 12);
            $qty = portal_autosaved_drafts_text($item, 'qty', 32);
            $price = portal_autosaved_drafts_text($item, 'price', 32);
            if ($art === null || $unit === null || $qty === null || $price === null) {
                return null;
            }
            if ($unit === '') {
                $unit = 'st';
            }
            if (!array_key_exists($unit, portal_invoice_catalog_units())) {
                return null;
            }
            if (($qty !== '' && (!is_numeric($qty) || (float) $qty < 0 || (float) $qty > 100000)) || ($price !== '' && (!is_numeric($price) || (float) $price < 0 || (float) $price > 10000000))) {
                return null;
            }
            $itemData = ['art' => $art, 'unit' => $unit, 'qty' => $qty, 'price' => $price];
            $voiceToken = $item['voice_token'] ?? '';
            if (!is_string($voiceToken) || strlen($voiceToken) > 4096 || ($voiceToken !== '' && preg_match('/\A[a-f0-9]{32}\.[A-Za-z0-9_-]+\z/D', $voiceToken) !== 1)) return null;
            if ($voiceToken !== '') $itemData['voice_token'] = $voiceToken;
            $fixedLock = $item['fixed_lock'] ?? '';
            if (!is_string($fixedLock) || ($fixedLock !== '' && preg_match('/\A[a-f0-9]{32}\z/D', $fixedLock) !== 1)) return null;
            if ($fixedLock !== '') $itemData['fixed_lock'] = $fixedLock;
            $cleanItems[] = $itemData;
        } else {
            $art = portal_autosaved_drafts_text($item, 'art', 200);
            $qty = portal_autosaved_drafts_text($item, 'qty', 32);
            $spec = portal_autosaved_drafts_text($item, 'spec', 300);
            if ($art === null || $qty === null || $spec === null) {
                return null;
            }
            if ($qty !== '' && (!is_numeric($qty) || (float) $qty < 0 || (float) $qty > 100000)) {
                return null;
            }
            $cleanItems[] = ['art' => $art, 'qty' => $qty, 'spec' => $spec];
        }
    }
    return [$formKey => $cleanForm, 'items' => $cleanItems];
}

function portal_autosaved_drafts_has_content(string $type, array $payload): bool
{
    $formKey = $type === 'invoice' ? 'i' : 'p';
    $form = is_array($payload[$formKey] ?? null) ? $payload[$formKey] : [];
    $contentKeys = $type === 'invoice'
        ? ['ref', 'customer', 'description']
        : ['reg', 'km', 'customer', 'tech', 'description'];
    foreach ($contentKeys as $key) {
        if (trim((string) ($form[$key] ?? '')) !== '') {
            return true;
        }
    }
    foreach (is_array($payload['items'] ?? null) ? $payload['items'] : [] as $item) {
        foreach (is_array($item) ? $item : [] as $itemKey => $value) {
            if ($type === 'invoice' && $itemKey === 'unit') {
                continue;
            }
            if (trim((string) $value) !== '') {
                return true;
            }
        }
    }
    return false;
}

function portal_autosaved_drafts_save(string $type, string $userId, string $draftId, array $post, bool $isInvoiceAdmin = false): array
{
    if (!in_array($type, ['invoice', 'protocol'], true) || $userId === '' || strlen($userId) > 128 || !preg_match('/\A[a-f0-9]{32}\z/', $draftId)) {
        return ['ok' => false, 'code' => 'invalid_input'];
    }
    $payload = portal_autosaved_drafts_sanitize_payload($type, $post, $isInvoiceAdmin);
    if (!is_array($payload)) {
        return ['ok' => false, 'code' => 'invalid_input'];
    }
    $hasContent = portal_autosaved_drafts_has_content($type, $payload);
    return portal_autosaved_drafts_update(static function (array &$drafts, int $now) use ($type, $userId, $draftId, $payload, $hasContent): array {
        $existing = $drafts[$draftId] ?? null;
        if (is_array($existing) && (($existing['type'] ?? '') !== $type || (string) ($existing['user_id'] ?? '') !== $userId)) {
            return ['ok' => false, 'code' => 'forbidden'];
        }
        if (!$hasContent) {
            if (is_array($existing)) {
                unset($drafts[$draftId]);
                return ['ok' => true, 'deleted' => true, '_write' => true];
            }
            return ['ok' => true, 'deleted' => false];
        }
        $drafts[$draftId] = [
            'id' => $draftId,
            'type' => $type,
            'user_id' => $userId,
            'created_at' => is_array($existing) ? (int) ($existing['created_at'] ?? $now) : $now,
            'updated_at' => $now,
            'data' => $payload,
        ];
        return ['ok' => true, 'draft_id' => $draftId, 'updated_at' => $now, '_write' => true];
    });
}

function portal_autosaved_drafts_list(string $type, string $userId): array
{
    if (!in_array($type, ['invoice', 'protocol'], true) || $userId === '') {
        return [];
    }
    $result = portal_autosaved_drafts_update(static function (array &$drafts) use ($type, $userId): array {
        $items = [];
        foreach ($drafts as $draft) {
            if (is_array($draft) && ($draft['type'] ?? '') === $type && (string) ($draft['user_id'] ?? '') === $userId) {
                $items[] = $draft;
            }
        }
        usort($items, static fn(array $a, array $b): int => (int) ($b['updated_at'] ?? 0) <=> (int) ($a['updated_at'] ?? 0));
        return ['ok' => true, 'items' => $items];
    });
    return is_array($result['items'] ?? null) ? $result['items'] : [];
}

function portal_autosaved_drafts_get(string $type, string $userId, string $draftId): ?array
{
    if (!in_array($type, ['invoice', 'protocol'], true) || $userId === '' || !preg_match('/\A[a-f0-9]{32}\z/', $draftId)) {
        return null;
    }
    $result = portal_autosaved_drafts_update(static function (array &$drafts) use ($type, $userId, $draftId): array {
        $draft = $drafts[$draftId] ?? null;
        if (!is_array($draft) || ($draft['type'] ?? '') !== $type || (string) ($draft['user_id'] ?? '') !== $userId) {
            return ['ok' => true, 'item' => null];
        }
        return ['ok' => true, 'item' => $draft];
    });
    return is_array($result['item'] ?? null) ? $result['item'] : null;
}

function portal_autosaved_drafts_delete(string $type, string $userId, string $draftId): array
{
    if (!in_array($type, ['invoice', 'protocol'], true) || $userId === '' || !preg_match('/\A[a-f0-9]{32}\z/', $draftId)) {
        return ['ok' => false, 'code' => 'invalid_input'];
    }
    return portal_autosaved_drafts_update(static function (array &$drafts) use ($type, $userId, $draftId): array {
        $draft = $drafts[$draftId] ?? null;
        if (!is_array($draft) || ($draft['type'] ?? '') !== $type || (string) ($draft['user_id'] ?? '') !== $userId) {
            return ['ok' => false, 'code' => 'not_found'];
        }
        unset($drafts[$draftId]);
        return ['ok' => true, '_write' => true];
    });
}
