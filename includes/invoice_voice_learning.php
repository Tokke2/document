<?php
declare(strict_types=1);

/** Personal corrections. Never store audio, voice profiles or full transcripts. */
function portal_voice_learning_id_valid(mixed $id): bool
{
    return is_string($id) && preg_match('/\A[a-f0-9]{32}\z/D', $id) === 1;
}
function portal_voice_learning_empty(): array
{
    return ['version'=>1, 'learning'=>false, 'auto'=>false, 'numeric_learning'=>false, 'share_learning'=>false, 'reviewed'=>0, 'observation_ids'=>[], 'corrections'=>[], 'numeric_corrections'=>[]];
}
function portal_voice_learning_text(mixed $value): ?string
{
    if (!is_string($value) || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/u', $value)) return null;
    $value = trim($value);
    return strlen($value) <= 800 && preg_match_all('/./us', $value) <= 200 ? $value : null;
}
function portal_voice_learning_number(mixed $value, string $field): ?string
{
    if (!is_string($value) || !preg_match('/\A\d{1,9}(?:[.,]\d{1,2})?\z/D', $value)) return null;
    $number = (float) str_replace(',', '.', $value);
    if (!is_finite($number) || $number > ($field === 'qty' ? 100000 : 10000000)) return null;
    return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.') ?: '0';
}
function portal_voice_learning_unit(mixed $unit): bool
{
    return is_string($unit) && in_array($unit, ['st','tim','km','dag','m','kg','other'], true);
}
/** Only changed numeric values are retained as advisory, context-bound examples. */
function portal_voice_learning_record_numeric(array &$profile, string $heard, string $unit, string $field, string $from, string $to): void
{
    $profile['numeric_corrections'] = array_values(array_filter($profile['numeric_corrections'], static fn($e) => !($e['heard'] === $heard && $e['unit'] === $unit && $e['field'] === $field && $e['from'] === $from)));
    if ($from !== $to) $profile['numeric_corrections'][] = ['heard'=>$heard, 'unit'=>$unit, 'field'=>$field, 'from'=>$from, 'to'=>$to];
    $profile['numeric_corrections'] = array_slice($profile['numeric_corrections'], -200);
}
function portal_voice_learning_path(string $id): ?string
{
    if (!portal_voice_learning_id_valid($id)) return null;
    $dir = dirname(__DIR__) . '/storage'; $guard = $dir . '/.htaccess';
    if (is_link($dir) || !is_dir($dir) || is_link($guard) || !is_file($guard)) return null;
    $guardText = @file_get_contents($guard);
    if (!is_string($guardText) || !preg_match('/^\s*(?:Require\s+all\s+denied|Deny\s+from\s+all)\b/im', $guardText)) return null;
    $path = $dir . '/voice_learning_' . $id . '.json';
    return app_safe_data_path($path) !== null && !is_link($path) ? $path : null;
}
function portal_voice_learning_read(string $id): ?array
{
    $path = portal_voice_learning_path($id);
    if ($path === null) return null;
    if (!file_exists($path)) return portal_voice_learning_empty();
    if (!is_file($path) || !is_readable($path) || filesize($path) > 524288) return null;
    $raw = @file_get_contents($path);
    if (!is_string($raw)) return null;
    try { $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); } catch (JsonException) { return null; }
    if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_bool($data['learning'] ?? null) || !is_bool($data['auto'] ?? null) || !is_int($data['reviewed'] ?? null) || $data['reviewed'] < 0 || $data['reviewed'] > 50 || !is_array($data['observation_ids'] ?? null) || !array_is_list($data['observation_ids']) || count($data['observation_ids']) > 500 || !is_array($data['corrections'] ?? null) || !array_is_list($data['corrections']) || count($data['corrections']) > 200 || ($data['auto'] && (!$data['learning'] || $data['reviewed'] < 50))) return null;
    // Profiles created before numeric learning remain opted out.
    $data += ['numeric_learning'=>false, 'numeric_corrections'=>[], 'share_learning'=>false];
    if (!is_bool($data['share_learning']) || !is_bool($data['numeric_learning']) || !is_array($data['numeric_corrections']) || !array_is_list($data['numeric_corrections']) || count($data['numeric_corrections']) > 200) return null;
    foreach ($data['numeric_corrections'] as $entry) {
        if (!is_array($entry) || !is_string($entry['heard'] ?? null) || $entry['heard'] === '' || portal_voice_learning_text($entry['heard']) !== $entry['heard'] || !portal_voice_learning_unit($entry['unit'] ?? null) || !in_array($entry['field'] ?? null, ['qty','price'], true) || !is_string($entry['from'] ?? null) || !is_string($entry['to'] ?? null) || portal_voice_learning_number($entry['from'], $entry['field']) !== $entry['from'] || portal_voice_learning_number($entry['to'], $entry['field']) !== $entry['to']) return null;
    }
    if (isset($data['provenance_key']) && (!is_string($data['provenance_key']) || preg_match('/\A[a-f0-9]{64}\z/D', $data['provenance_key']) !== 1)) return null;
    foreach ($data['observation_ids'] as $observation) if (!portal_voice_learning_id_valid($observation)) return null;
    foreach ($data['corrections'] as $entry) if (!is_array($entry) || !is_string($entry['heard'] ?? null) || !is_string($entry['approved'] ?? null) || portal_voice_learning_text($entry['heard']) !== $entry['heard'] || portal_voice_learning_text($entry['approved']) !== $entry['approved'] || $entry['heard'] === '' || $entry['approved'] === '') return null;
    return $data;
}
function portal_voice_learning_public(array $data): array
{
    return ['learning'=>$data['learning'], 'auto'=>$data['auto'], 'reviewed'=>$data['reviewed'], 'corrections'=>$data['corrections'], 'numeric_learning'=>$data['numeric_learning'], 'share_learning'=>$data['share_learning'], 'numeric_corrections'=>$data['numeric_corrections']];
}
/** Do not let edits by a different account become the speaker's feedback. */
function portal_voice_learning_filter_shared_tokens(array $items, array $old, string $actorId): array
{
    foreach ($items as &$item) {
        $token = $item['voice_token'] ?? '';
        if ($token === '' || (portal_voice_learning_id_valid($actorId) && str_starts_with($token, $actorId . '.'))) continue;
        $unchanged = false;
        foreach (is_array($old['items'] ?? null) ? $old['items'] : [] as $oldItem) {
            if (!is_array($oldItem) || ($oldItem['voice_token'] ?? '') !== $token) continue;
            if (is_string($oldItem['art'] ?? null) && is_string($oldItem['unit'] ?? null) && is_numeric($oldItem['qty'] ?? null) && is_numeric($oldItem['price'] ?? null)
                && $oldItem['art'] === $item['art'] && $oldItem['unit'] === $item['unit'] && (float) $oldItem['qty'] === $item['qty'] && (float) $oldItem['price'] === $item['price']) $unchanged = true;
            break;
        }
        if (!$unchanged) unset($item['voice_token']);
    }
    unset($item);
    return $items;
}
/** Authenticated, owner-bound provenance; the original description is never put
 * into a shared invoice or draft in plaintext. Forgetting the profile destroys
 * the key (existing backups may still retain it). */
function portal_voice_learning_seal(string $id, string $key, string $heard, string $baseline, ?array $numeric = null): ?string
{
    if (!function_exists('openssl_encrypt')) return null;
    try { $iv = random_bytes(12); } catch (Throwable) { return null; }
    $plain = json_encode(['heard' => $heard, 'baseline' => $baseline, 'numeric' => $numeric], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($plain)) return null;
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', hex2bin($key), OPENSSL_RAW_DATA, $iv, $tag, 'invoice-voice-v1:' . $id, 16);
    if (!is_string($cipher) || strlen($tag) !== 16) return null;
    return $id . '.' . rtrim(strtr(base64_encode($iv . $tag . $cipher), '+/', '-_'), '=');
}
function portal_voice_learning_open(string $id, ?string $key, mixed $token): ?array
{
    if (!$key || !is_string($token) || strlen($token) > 4096 || !preg_match('/\A([a-f0-9]{32})\.([A-Za-z0-9_-]+)\z/D', $token, $m) || !hash_equals($id, $m[1]) || !function_exists('openssl_decrypt')) return null;
    $raw = base64_decode(strtr($m[2], '-_', '+/'), true);
    if (!is_string($raw) || strlen($raw) < 29) return null;
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hex2bin($key), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'invoice-voice-v1:' . $id);
    if (!is_string($plain)) return null;
    $data = json_decode($plain, true);
    if (!is_array($data) || !is_string($data['heard'] ?? null) || !is_string($data['baseline'] ?? null) || portal_voice_learning_text($data['heard']) !== $data['heard'] || portal_voice_learning_text($data['baseline']) !== $data['baseline'] || $data['heard'] === '' || $data['baseline'] === '') return null;
    if (isset($data['numeric'])) {
        $n = $data['numeric'];
        if (!is_array($n) || !portal_voice_learning_unit($n['unit'] ?? null)) return null;
        foreach (['qty','price'] as $field) {
            if ($field === 'price' && array_key_exists('from_price', $n) && array_key_exists('baseline_price', $n) && $n['from_price'] === null && $n['baseline_price'] === null) continue;
            foreach (['from_','baseline_'] as $prefix) if (!is_string($n[$prefix.$field] ?? null) || portal_voice_learning_number($n[$prefix.$field], $field) !== $n[$prefix.$field]) return null;
        }
    }
    return $data;
}
/** No learned unit-price correction may compete with an administrative fixed price. */
function portal_voice_learning_may_learn_price(string $heard, string $approved): bool
{
    if (!function_exists('portal_invoice_catalog_read') || !function_exists('portal_invoice_catalog_fixed_rules')) return false;
    $records = portal_invoice_catalog_read();
    $articles = is_array($records) ? portal_invoice_catalog_fixed_rules($records) : null;
    if (!is_array($articles)) return false;
    foreach ($articles as $article) {
        if ($article['fixed_price'] && (portal_invoice_catalog_has_name($article, $heard) || portal_invoice_catalog_has_name($article, $approved))) return false;
    }
    return true;
}
function portal_voice_learning_update(string $id, string $op, array $post): array
{
    $path = portal_voice_learning_path($id);
    if ($path === null || is_link($path . '.guard.lock') || is_link($path . '.lock')) return ['ok'=>false,'code'=>'storage_error'];
    $mask = umask(0077); $lock = @fopen($path . '.guard.lock', 'c'); umask($mask);
    if (!is_resource($lock)) return ['ok'=>false,'code'=>'storage_error'];
    @chmod($path . '.guard.lock', 0600);
    if (!@flock($lock, LOCK_EX)) { fclose($lock); return ['ok'=>false,'code'=>'storage_error']; }
    try {
        $profile = portal_voice_learning_read($id);
        if ($profile === null) return ['ok'=>false,'code'=>'storage_error'];
        $token = null;
        switch ($op) {
            case 'start': $profile['learning'] = true; break;
            case 'pause': $profile['learning'] = false; $profile['auto'] = false; $profile['share_learning'] = false; break;
            case 'auto_on':
                if (!$profile['learning'] || $profile['reviewed'] < 50) return ['ok'=>false,'code'=>'not_ready'];
                $profile['auto'] = true; break;
            case 'auto_off': $profile['auto'] = false; break;
            case 'share_on': if (!$profile['learning']) return ['ok'=>false,'code'=>'learning_disabled']; $profile['share_learning'] = true; break;
            case 'share_off': $profile['share_learning'] = false; break;
            case 'numeric_on': if (!$profile['learning']) return ['ok'=>false,'code'=>'learning_disabled']; $profile['numeric_learning'] = true; break;
            case 'numeric_off': $profile['numeric_learning'] = false; break;
            case 'forget': $profile = portal_voice_learning_empty(); break;
            case 'track_auto':
                if (!$profile['learning'] || !$profile['auto'] || $profile['reviewed'] < 50) return ['ok'=>false,'code'=>'not_ready'];
                $heard = portal_voice_learning_text($post['heard'] ?? null);
                $approved = portal_voice_learning_text($post['approved'] ?? null);
                if ($heard === null || $approved === null || $heard === '' || $approved === '') return ['ok'=>false,'code'=>'invalid'];
                if (!isset($profile['provenance_key'])) {
                    try { $profile['provenance_key'] = bin2hex(random_bytes(32)); } catch (Throwable) { return ['ok'=>false,'code'=>'storage_error']; }
                }
                $numeric = null;
                if ($profile['numeric_learning']) {
                    $qty = portal_voice_learning_number($post['numeric_qty'] ?? null, 'qty');
                    $price = isset($post['numeric_price']) ? portal_voice_learning_number($post['numeric_price'], 'price') : null;
                    $unit = $post['numeric_unit'] ?? null;
                    if ($qty === null || (isset($post['numeric_price']) && $price === null) || !portal_voice_learning_unit($unit)) return ['ok'=>false,'code'=>'invalid'];
                    $numeric = ['unit'=>$unit, 'from_qty'=>$qty, 'baseline_qty'=>$qty, 'from_price'=>$price, 'baseline_price'=>$price];
                }
                $token = portal_voice_learning_seal($id, $profile['provenance_key'], $heard, $approved, $numeric);
                if ($token === null) return ['ok'=>false,'code'=>'storage_error'];
                break;
            case 'sync_auto':
                if (!$profile['learning']) return ['ok'=>false,'code'=>'learning_disabled'];
                $approved = portal_voice_learning_text($post['approved'] ?? null);
                if ($approved === null || $approved === '') return ['ok'=>false,'code'=>'invalid'];
                $provenance = portal_voice_learning_open($id, $profile['provenance_key'] ?? null, $post['voice_token'] ?? null);
                if ($provenance === null) return ['ok'=>false,'code'=>'invalid_token'];
                $heard = $provenance['heard'];
                $numeric = $provenance['numeric'] ?? null;
                $changed = $approved !== $provenance['baseline'];
                if ($changed) {
                    $profile['corrections'] = array_values(array_filter($profile['corrections'], static fn($item) => $item['heard'] !== $heard));
                    if ($heard !== $approved) $profile['corrections'][] = ['heard'=>$heard, 'approved'=>$approved];
                    $profile['corrections'] = array_slice($profile['corrections'], -200);
                }
                // Do not retroactively learn edits made while numeric learning
                // was paused: detach this row's numeric provenance on sync.
                if (!$profile['numeric_learning'] && is_array($numeric)) { $numeric = null; $changed = true; }
                if ($profile['numeric_learning'] && is_array($numeric) && isset($post['numeric_qty'], $post['numeric_price'], $post['numeric_unit'])) {
                    $qty = portal_voice_learning_number($post['numeric_qty'], 'qty');
                    $price = portal_voice_learning_number($post['numeric_price'], 'price');
                    $unit = $post['numeric_unit'];
                    if ($qty !== null && $price !== null && portal_voice_learning_unit($unit)) {
                        if ($unit !== $numeric['unit']) { $numeric = null; $changed = true; }
                        else foreach (['qty'=>$qty, 'price'=>$price] as $field=>$value) {
                            if ($field === 'price' && $numeric['from_price'] === null) continue;
                            if ($numeric['baseline_'.$field] !== $value) {
                                if ($field !== 'price' || portal_voice_learning_may_learn_price($heard, $approved)) portal_voice_learning_record_numeric($profile, $heard, $unit, $field, $numeric['from_'.$field], $value);
                                $numeric['baseline_'.$field] = $value; $changed = true;
                            }
                        }
                    }
                }
                if (!$changed) return ['ok'=>true, 'profile'=>portal_voice_learning_public($profile), 'token'=>$post['voice_token']];
                $token = portal_voice_learning_seal($id, $profile['provenance_key'], $heard, $approved, $numeric);
                if ($token === null) return ['ok'=>false,'code'=>'storage_error'];
                break;
            case 'dictionary_update':
            case 'dictionary_delete':
                // The caller owns this profile. Compare the displayed old value
                // under the profile lock so stale tabs cannot overwrite a newer edit.
                $heard = $post['heard'] ?? null; $old = $post['old_approved'] ?? null;
                if (!is_string($heard) || $heard === '' || portal_voice_learning_text($heard) !== $heard || !is_string($old) || $old === '' || portal_voice_learning_text($old) !== $old) return ['ok'=>false,'code'=>'invalid'];
                $found = null;
                foreach ($profile['corrections'] as $i => $item) if ($item['heard'] === $heard && $item['approved'] === $old) { $found = $i; break; }
                if ($found === null) return ['ok'=>false,'code'=>'stale'];
                if ($op === 'dictionary_delete') array_splice($profile['corrections'], $found, 1);
                else {
                    $new = $post['approved'] ?? null;
                    if (!is_string($new) || $new === '' || portal_voice_learning_text($new) !== $new || $new === $heard) return ['ok'=>false,'code'=>'invalid'];
                    $profile['corrections'][$found]['approved'] = $new;
                }
                break;
            case 'review':
            case 'correction':
                if (!$profile['learning']) return ['ok'=>false,'code'=>'learning_disabled'];
                $heard = portal_voice_learning_text($post['heard'] ?? '');
                $approved = portal_voice_learning_text($post['approved'] ?? '');
                if ($heard === null || $approved === null || $approved === '') return ['ok'=>false,'code'=>'invalid'];
                if ($op === 'review') {
                    $observation = $post['observation_id'] ?? '';
                    if (!portal_voice_learning_id_valid($observation)) return ['ok'=>false,'code'=>'invalid'];
                    if (in_array($observation, $profile['observation_ids'], true)) return ['ok'=>true,'profile'=>portal_voice_learning_public($profile)];
                    $profile['observation_ids'][] = $observation;
                    $profile['observation_ids'] = array_slice($profile['observation_ids'], -500);
                    $profile['reviewed'] = min(50, $profile['reviewed'] + 1);
                }
                if ($op === 'review' && $profile['numeric_learning'] && $heard !== '' && isset($post['from_qty'], $post['from_price'], $post['to_qty'], $post['to_price'], $post['numeric_unit'], $post['approved_unit']) && $post['numeric_unit'] === $post['approved_unit'] && portal_voice_learning_unit($post['numeric_unit'])) {
                    foreach (['qty','price'] as $field) {
                        $from = portal_voice_learning_number($post['from_'.$field], $field);
                        $to = portal_voice_learning_number($post['to_'.$field], $field);
                        if ($from !== null && $to !== null && $from !== $to && ($field !== 'price' || portal_voice_learning_may_learn_price($heard, $approved))) portal_voice_learning_record_numeric($profile, $heard, $post['numeric_unit'], $field, $from, $to);
                    }
                }
                // Text mappings are separate from the advisory numeric examples.
                if ($heard !== '') {
                    $profile['corrections'] = array_values(array_filter($profile['corrections'], static fn($item) => $item['heard'] !== $heard));
                    if ($heard !== $approved) $profile['corrections'][] = ['heard'=>$heard, 'approved'=>$approved];
                    $profile['corrections'] = array_slice($profile['corrections'], -200);
                }
                break;
            default: return ['ok'=>false,'code'=>'invalid'];
        }
        if (!save_data($path, $profile)) return ['ok'=>false,'code'=>'storage_error'];
        $result = ['ok'=>true,'profile'=>portal_voice_learning_public($profile)];
        if ($token !== null) $result['token'] = $token;
        return $result;
    } finally { @flock($lock, LOCK_UN); fclose($lock); }
}
