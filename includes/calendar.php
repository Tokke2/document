<?php
declare(strict_types=1);

/** Private planning records. The shared central PIN is never a calendar identity. */
function portal_calendar_path(): string { return dirname(__DIR__) . '/storage/calendar_events.json'; }

function portal_calendar_valid_id(mixed $id): bool
{
    return is_string($id) && preg_match('/\A[a-f0-9]{32}\z/D', $id) === 1;
}

function portal_calendar_read(): ?array
{
    $path = portal_calendar_path();
    $dir = dirname($path);
    $guard = $dir . '/.htaccess';
    if (is_link($dir) || is_link($guard) || !is_file($guard) || is_link($path) || app_safe_data_path($path) === null) return null;
    $guardText = @file_get_contents($guard);
    if (!is_string($guardText) || !preg_match('/^\s*(?:Require\s+all\s+denied|Deny\s+from\s+all)\b/im', $guardText)) return null;
    if (!file_exists($path)) return [];
    if (!is_file($path) || !is_readable($path) || filesize($path) > 10485760) return null;
    $raw = @file_get_contents($path);
    if (!is_string($raw)) return null;
    try { $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); } catch (JsonException) { return null; }
    if (!is_array($data) || !array_is_list($data)) return null;
    $ids = [];
    foreach ($data as $record) {
        if (!is_array($record) || !portal_calendar_valid_id($record['id'] ?? null) || !portal_calendar_valid_id($record['owner_id'] ?? null) || isset($ids[$record['id']]) || !is_array($record['shared_with'] ?? null) || !array_is_list($record['shared_with']) || !is_int($record['revision'] ?? null) || $record['revision'] < 1) return null;
        foreach ($record['shared_with'] as $share) if (!portal_calendar_valid_id($share)) return null;
        foreach (['title','notes','date','start_time','end_time','status','created_at','updated_at'] as $field) if (!is_string($record[$field] ?? null)) return null;
        $ids[$record['id']] = true;
    }
    return $data;
}

/** Filtering happens server-side before rendering or serialization. */
function portal_calendar_visible(array $records, string $userId): array
{
    if (!portal_calendar_valid_id($userId)) return [];
    return array_values(array_filter($records, static function (mixed $event) use ($userId): bool {
        if (!is_array($event) || !portal_calendar_valid_id($event['id'] ?? null) || !portal_calendar_valid_id($event['owner_id'] ?? null)) return false;
        return $event['owner_id'] === $userId || (is_array($event['shared_with'] ?? null) && in_array($userId, $event['shared_with'], true));
    }));
}

/** Mutate only the owner's event. The client-provided owner is never trusted. */
function portal_calendar_apply(array $records, string $actorId, string $action, array $post, array $activeRecipientIds): array
{
    if (!portal_calendar_valid_id($actorId) || !in_array($action, ['save', 'delete'], true)) return ['ok' => false, 'code' => 'forbidden'];
    $id = $post['event_id'] ?? '';
    if (!is_string($id) || ($id !== '' && !portal_calendar_valid_id($id))) return ['ok' => false, 'code' => 'invalid'];
    $existingIndex = null;
    if ($id !== '') {
        foreach ($records as $index => $record) if (is_array($record) && ($record['id'] ?? '') === $id) { $existingIndex = $index; break; }
        if ($existingIndex === null || ($records[$existingIndex]['owner_id'] ?? '') !== $actorId) return ['ok' => false, 'code' => 'forbidden'];
        $revision = $post['revision'] ?? null;
        if (!is_string($revision) || !ctype_digit($revision) || (int) $revision !== (int) ($records[$existingIndex]['revision'] ?? 0)) return ['ok' => false, 'code' => 'conflict'];
    } elseif ($action === 'delete') return ['ok' => false, 'code' => 'invalid'];
    if ($action === 'delete') {
        array_splice($records, $existingIndex, 1);
        return ['ok' => true, 'records' => $records, 'code' => 'deleted'];
    }
    $text = static function (mixed $value, int $max, bool $multiline = false): ?string {
        if (!is_string($value) || preg_match('//u', $value) !== 1 || preg_match($multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', $value)) return null;
        $value = trim($value);
        return strlen($value) <= $max * 4 && preg_match_all('/./us', $value) <= $max ? $value : null;
    };
    $title = $text($post['title'] ?? '', 160);
    $notes = $text($post['notes'] ?? '', 2000, true);
    $date = $post['date'] ?? '';
    $start = $post['start_time'] ?? '';
    $end = $post['end_time'] ?? '';
    $status = $post['status'] ?? '';
    $shared = $post['shared_with'] ?? [];
    if (!is_string($date) || !preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/D', $date, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ||
        !is_string($start) || !preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/D', $start) ||
        !is_string($end) || ($end !== '' && (!preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/D', $end) || $end <= $start)) ||
        $title === null || $title === '' || $notes === null || !is_string($status) || !in_array($status, ['planned', 'in_progress', 'completed'], true) ||
        !is_array($shared) || count($shared) > 100) return ['ok' => false, 'code' => 'invalid'];
    $shares = [];
    foreach ($shared as $recipient) {
        if (!portal_calendar_valid_id($recipient) || $recipient === $actorId || !in_array($recipient, $activeRecipientIds, true)) return ['ok' => false, 'code' => 'invalid'];
        $shares[$recipient] = true;
    }
    if ($id === '') {
        try { $id = bin2hex(random_bytes(16)); } catch (Throwable) { return ['ok' => false, 'code' => 'storage_error']; }
    }
    $old = $existingIndex !== null ? $records[$existingIndex] : [];
    $event = [
        'id' => $id, 'owner_id' => $actorId, 'title' => $title, 'notes' => $notes,
        'date' => $date, 'start_time' => $start, 'end_time' => $end, 'status' => $status,
        'shared_with' => array_keys($shares), 'revision' => (int) ($old['revision'] ?? 0) + 1,
        'created_at' => is_string($old['created_at'] ?? null) ? $old['created_at'] : gmdate(DATE_ATOM),
        'updated_at' => gmdate(DATE_ATOM),
        'completed_at' => $status === 'completed' ? (($old['status'] ?? '') === 'completed' && is_string($old['completed_at'] ?? null) ? $old['completed_at'] : gmdate(DATE_ATOM)) : '',
    ];
    if ($existingIndex === null) {
        if (count($records) >= 10000) return ['ok' => false, 'code' => 'storage_error'];
        $records[] = $event;
    } else $records[$existingIndex] = $event;
    return ['ok' => true, 'records' => array_values($records), 'code' => 'saved'];
}

function portal_calendar_update(string $actorId, string $action, array $post, array $activeRecipientIds): array
{
    $path = portal_calendar_path();
    $guard = $path . '.guard.lock';
    if (portal_calendar_read() === null || is_link($guard) || is_link($path . '.lock')) return ['ok' => false, 'code' => 'storage_error'];
    $oldMask = umask(0077); $lock = @fopen($guard, 'c'); umask($oldMask);
    if (!is_resource($lock)) return ['ok' => false, 'code' => 'storage_error'];
    @chmod($guard, 0600);
    if (!@flock($lock, LOCK_EX)) { fclose($lock); return ['ok' => false, 'code' => 'storage_error']; }
    try {
        $records = portal_calendar_read();
        if (!is_array($records)) return ['ok' => false, 'code' => 'storage_error'];
        $result = portal_calendar_apply($records, $actorId, $action, $post, $activeRecipientIds);
        if (($result['ok'] ?? false) !== true) return $result;
        if (!save_data($path, $result['records'])) return ['ok' => false, 'code' => 'storage_error'];
        return ['ok' => true, 'code' => $result['code']];
    } finally { @flock($lock, LOCK_UN); fclose($lock); }
}
