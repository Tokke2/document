<?php
declare(strict_types=1);

/** Shared review never exposes numeric corrections, audio, or full transcripts.
 * The source remains the contributor's opted-in personal profile; no separate
 * queue is created. Admin decisions modify only catalog aliases under its lock. */
function portal_voice_shared_is_admin(mixed $user): bool
{
    return is_array($user) && ($user['role'] ?? '') === 'admin' && ($user['auth_source'] ?? '') !== 'central_admin'
        && is_string($user['id'] ?? null) && trim($user['id']) !== '';
}
function portal_voice_shared_active_user(array $users, string $id): ?array
{
    if (!portal_voice_learning_id_valid($id)) return null;
    foreach ($users as $user) {
        if (is_array($user) && ($user['id'] ?? '') === $id && ($user['active'] ?? false) === true) return $user;
    }
    return null;
}
/** @return list<array{source_id:string,source_name:string,heard:string,approved:string}> */
function portal_voice_shared_candidates(array $requester, array $users, array $articles): array
{
    if (!portal_voice_shared_is_admin($requester)) return [];
    $result = []; $seenUsers = [];
    foreach ($users as $user) {
        $id = is_array($user) ? ($user['id'] ?? '') : '';
        if (!is_string($id) || !portal_voice_learning_id_valid($id) || ($user['active'] ?? false) !== true || isset($seenUsers[$id])) continue;
        $seenUsers[$id] = true;
        $profile = portal_voice_learning_read($id);
        if (!is_array($profile) || !$profile['learning'] || !$profile['share_learning']) continue;
        foreach ($profile['corrections'] as $item) {
            $heard = $item['heard']; $approved = $item['approved'];
            if (portal_invoice_catalog_same_name($heard, $approved)) continue;
            $alreadyKnown = false;
            foreach ($articles as $article) if (portal_invoice_catalog_has_name($article, $heard)) { $alreadyKnown = true; break; }
            if ($alreadyKnown) continue;
            $name = is_string($user['name'] ?? null) && trim($user['name']) !== '' ? $user['name'] : (is_string($user['username'] ?? null) ? $user['username'] : $id);
            $result[] = ['source_id'=>$id, 'source_name'=>$name, 'heard'=>$heard, 'approved'=>$approved];
        }
    }
    return $result;
}
/** Lock the contributor's profile before catalog: opt-out or a correction that
 * changes concurrently cannot be approved from a stale form. The caller passes
 * the authenticated requester and the current account list, never POST identity. */
function portal_voice_shared_approve(array $requester, array $users, mixed $sourceId, mixed $heard, mixed $approved, mixed $articleId): array
{
    if (!portal_voice_shared_is_admin($requester)) return ['ok'=>false,'code'=>'forbidden'];
    if (!is_string($sourceId) || portal_voice_shared_active_user($users, $sourceId) === null ||
        !is_string($articleId) || !preg_match('/\A[a-f0-9]{32}\z/iD', $articleId) ||
        !is_string($heard) || !is_string($approved) || $heard === '' || $approved === '' ||
        portal_voice_learning_text($heard) !== $heard || portal_voice_learning_text($approved) !== $approved ||
        portal_invoice_catalog_same_name($heard, $approved)) return ['ok'=>false,'code'=>'invalid'];
    $path = portal_voice_learning_path($sourceId);
    if ($path === null || is_link($path . '.guard.lock') || is_link($path . '.lock')) return ['ok'=>false,'code'=>'storage_error'];
    $mask = umask(0077); $profileLock = @fopen($path . '.guard.lock', 'c'); umask($mask);
    if (!is_resource($profileLock)) return ['ok'=>false,'code'=>'storage_error'];
    @chmod($path . '.guard.lock', 0600);
    if (!@flock($profileLock, LOCK_EX)) { fclose($profileLock); return ['ok'=>false,'code'=>'storage_error']; }
    try {
        $profile = portal_voice_learning_read($sourceId);
        if (!is_array($profile)) return ['ok'=>false,'code'=>'storage_error'];
        if (!$profile['learning'] || !$profile['share_learning']) return ['ok'=>false,'code'=>'not_shared'];
        $valid = false;
        foreach ($profile['corrections'] as $item) if ($item['heard'] === $heard && $item['approved'] === $approved) { $valid = true; break; }
        if (!$valid) return ['ok'=>false,'code'=>'stale'];
        $lock = portal_invoice_catalog_lock();
        if (!is_resource($lock)) return ['ok'=>false,'code'=>'storage_error'];
        try {
            $records = portal_invoice_catalog_read();
            $articles = is_array($records) ? portal_invoice_catalog_fixed_rules($records) : null;
            if (!is_array($articles)) return ['ok'=>false,'code'=>'storage_error'];
            $target = null;
            foreach ($articles as $article) {
                if (portal_invoice_catalog_has_name($article, $heard)) return ['ok'=>false,'code'=>'alias_duplicate'];
                if (strcasecmp($article['id'], $articleId) === 0) $target = $article;
            }
            if ($target === null) return ['ok'=>false,'code'=>'not_found'];
            $aliases = $target['aliases']; $aliases[] = $heard;
            if (portal_invoice_catalog_alias_list($aliases) === null) return ['ok'=>false,'code'=>'invalid_alias'];
            // Modify the original record rather than resaving normalized values:
            // legacy prices/units and unknown metadata must remain byte-for-value
            // unchanged by learning. Reject duplicate IDs rather than guessing.
            $indexes = [];
            foreach ($records as $i => $record) {
                if (is_array($record) && isset($record['id']) && is_string($record['id']) && strcasecmp($record['id'], $target['id']) === 0) $indexes[] = $i;
            }
            if (count($indexes) !== 1) return ['ok'=>false,'code'=>'storage_error'];
            $records[$indexes[0]]['aliases'] = $aliases;
            $records[$indexes[0]]['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
            if (!save_data(portal_invoice_catalog_path(), array_values($records))) return ['ok'=>false,'code'=>'storage_error'];
            return ['ok'=>true,'code'=>'saved','id'=>$target['id']];
        } finally { portal_invoice_catalog_unlock($lock); }
    } finally { @flock($profileLock, LOCK_UN); fclose($profileLock); }
}

/** Aggregate currently shared correction mappings, not recognition frequency.
 * Only unambiguous matches to an existing article are counted. One account
 * contributes at most once to a given article/heard-text pair. */
function portal_voice_shared_article_summary(array $requester, array $users, array $articles): array
{
    if (!portal_voice_shared_is_admin($requester)) return [];
    $rows = []; $seenUsers = [];
    foreach ($users as $user) {
        $id = is_array($user) ? ($user['id'] ?? '') : '';
        if (!portal_voice_learning_id_valid($id) || ($user['active'] ?? false) !== true || isset($seenUsers[$id])) continue;
        $seenUsers[$id] = true; $profile = portal_voice_learning_read($id);
        if (!is_array($profile) || !$profile['learning'] || !$profile['share_learning']) continue;
        foreach ($profile['corrections'] as $item) {
            if (portal_invoice_catalog_same_name($item['heard'], $item['approved'])) continue;
            $matches = array_values(array_filter($articles, static fn($article) => portal_invoice_catalog_has_name($article, $item['approved'])));
            if (count($matches) !== 1) continue;
            $article = $matches[0];
            $normalized = preg_replace('/\s+/u', ' ', trim($item['heard']));
            if (!is_string($normalized) || $normalized === '') continue;
            // Without mbstring, non-ASCII case variants may appear separately;
            // never combine unrelated words or count an account twice per group.
            $normalized = function_exists('mb_strtolower') ? mb_strtolower($normalized, 'UTF-8') : strtolower($normalized);
            $key = hash('sha256', $article['id'] . "\0" . $normalized);
            if (!isset($rows[$key])) $rows[$key] = ['article_id'=>$article['id'],'article_name'=>$article['name'],'heard'=>$item['heard'],'users'=>[]];
            $rows[$key]['users'][$id] = true;
        }
    }
    foreach ($rows as &$row) { $row['contributors'] = count($row['users']); unset($row['users']); } unset($row);
    $rows = array_values($rows);
    usort($rows, static fn($a,$b) => ($b['contributors'] <=> $a['contributors']) ?: strnatcasecmp($a['article_name'], $b['article_name']) ?: strnatcasecmp($a['heard'], $b['heard']));
    return $rows;
}
