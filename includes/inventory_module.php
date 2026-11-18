<?php
declare(strict_types=1);
/**
 * Phase-one inventory domain for Tadega Portal.
 * JSON remains the source of truth for compatibility with the existing portal.
 * Multi-file writes are serialized by a portal-local lock; failed commits are
 * rolled back where possible. Offline-first synchronization is a later phase.
 */

const INVENTORY_WORKPLACES_FILE = __DIR__ . '/../workplaces.json';
const INVENTORY_UNITS_FILE = __DIR__ . '/../inventory_units.json';
const INVENTORY_TRANSACTIONS_FILE = __DIR__ . '/../inventory_transactions.json';
const INVENTORY_SESSIONS_FILE = __DIR__ . '/../inventory_sessions.json';
const INVENTORY_USERS_FILE = __DIR__ . '/../inventory_users.json';

function inv_timestamp(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function inv_random_id(int $bytes = 12): string
{
    return bin2hex(random_bytes($bytes));
}

function inv_site_qr_code(string $siteId): string
{
    $suffix = preg_replace('/[^A-Za-z0-9]/', '', preg_replace('/^site-/', '', $siteId) ?? $siteId) ?? '';
    if ($suffix === '') {
        $suffix = strtoupper(substr(hash('sha256', $siteId), 0, 10));
    }
    return 'SITE-' . strtoupper(substr($suffix, 0, 32));
}

function inv_default_workplaces(): array
{
    $now = inv_timestamp();
    return [
        ['id' => 'site-orebro', 'name' => 'Örebro', 'active' => true, 'qr_code' => 'SITE-OREBRO', 'created_at' => $now],
        ['id' => 'site-laxa', 'name' => 'Laxå', 'active' => true, 'qr_code' => 'SITE-LAXA', 'created_at' => $now],
        ['id' => 'site-unassigned', 'name' => 'Ej fördelat', 'active' => true, 'system' => true, 'qr_code' => 'SITE-UNASSIGNED', 'created_at' => $now],
    ];
}

function inv_with_lock(callable $callback): mixed
{
    $storage = dirname(__DIR__) . '/storage';
    $lockPath = $storage . '/inventory.lock';
    if (!is_dir($storage) || is_link($storage) || is_link($lockPath)) {
        return false;
    }
    $oldUmask = umask(0077);
    $handle = @fopen($lockPath, 'c');
    umask($oldUmask);
    if (!is_resource($handle)) {
        return false;
    }
    @chmod($lockPath, 0600);
    if (!@flock($handle, LOCK_EX)) {
        fclose($handle);
        return false;
    }
    try {
        return $callback();
    } catch (Throwable $e) {
        return false;
    } finally {
        @flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/** Atomically replace a set of JSON files with best-effort rollback. */
function inv_commit(array $changes): bool
{
    $before = [];
    $written = [];
    foreach ($changes as $file => $newValue) {
        if (!is_string($file)) {
            return false;
        }
        $before[$file] = load_data($file, []);
        if (!save_data($file, $newValue)) {
            foreach (array_reverse($written) as $doneFile) {
                save_data($doneFile, $before[$doneFile]);
            }
            return false;
        }
        $written[] = $file;
    }
    return true;
}

/** Seed the JSON stores and migrate legacy aggregate stock without losing it. */
function inv_bootstrap(): bool
{
    return (bool) inv_with_lock(static function (): bool {
        $defaults = [
            INVENTORY_WORKPLACES_FILE => inv_default_workplaces(),
            INVENTORY_UNITS_FILE => [],
            INVENTORY_TRANSACTIONS_FILE => [],
            INVENTORY_SESSIONS_FILE => [],
            INVENTORY_USERS_FILE => [],
        ];
        foreach ($defaults as $file => $contents) {
            if (!is_file($file) && !save_data($file, $contents)) {
                return false;
            }
        }

        $workplaces = array_values(array_filter(load_data(INVENTORY_WORKPLACES_FILE, []), 'is_array'));
        $workplaceChanged = false;
        if ($workplaces === []) {
            $workplaces = inv_default_workplaces();
            $workplaceChanged = true;
        }
        $siteIds = [];
        foreach ($workplaces as &$site) {
            $siteId = is_string($site['id'] ?? null) ? $site['id'] : '';
            if ($siteId === '') {
                continue;
            }
            $siteIds[] = $siteId;
            if (!isset($site['active'])) {
                $site['active'] = true;
                $workplaceChanged = true;
            }
            $code = is_string($site['qr_code'] ?? null) ? strtoupper(trim($site['qr_code'])) : '';
            if (!preg_match('/\ASITE-[A-Z0-9-]{1,40}\z/', $code)) {
                $site['qr_code'] = inv_site_qr_code($siteId);
                $workplaceChanged = true;
            }
        }
        unset($site);
        if (!in_array('site-unassigned', $siteIds, true)) {
            $workplaces[] = ['id' => 'site-unassigned', 'name' => 'Ej fördelat', 'active' => true, 'system' => true, 'qr_code' => 'SITE-UNASSIGNED', 'created_at' => inv_timestamp()];
            $siteIds[] = 'site-unassigned';
            $workplaceChanged = true;
        }
        if ($workplaceChanged && !save_data(INVENTORY_WORKPLACES_FILE, $workplaces)) {
            return false;
        }

        $items = array_values(array_filter(load_data(INVENTORY_FILE, []), 'is_array'));
        $units = array_values(array_filter(load_data(INVENTORY_UNITS_FILE, []), 'is_array'));
        $changed = false;
        $maxArticleNumber = 0;
        foreach ($items as $item) {
            if (preg_match('/\AA(\d{4,})\z/', (string) ($item['article_no'] ?? ''), $match)) {
                $maxArticleNumber = max($maxArticleNumber, (int) $match[1]);
            }
        }
        $claimedNumbers = [];
        foreach ($items as &$item) {
            if (!isset($item['name']) && isset($item['namn'])) { $item['name'] = $item['namn']; $changed = true; }
            if (!isset($item['unit']) && isset($item['enhet'])) { $item['unit'] = $item['enhet']; $changed = true; }
            if (!isset($item['price']) && isset($item['pris'])) { $item['price'] = $item['pris']; $changed = true; }
            if (!isset($item['stock']) && isset($item['lager'])) { $item['stock'] = $item['lager']; $changed = true; }
            if (!isset($item['id']) || !is_string($item['id']) || $item['id'] === '') {
                $item['id'] = inv_random_id(16);
                $changed = true;
            }
            $number = (string) ($item['article_no'] ?? '');
            if (!preg_match('/\AA\d{4,}\z/', $number) || isset($claimedNumbers[$number])) {
                do {
                    $maxArticleNumber++;
                    $number = 'A' . str_pad((string) $maxArticleNumber, 4, '0', STR_PAD_LEFT);
                } while (isset($claimedNumbers[$number]));
                $item['article_no'] = $number;
                $changed = true;
            }
            $claimedNumbers[$number] = true;
            if (!in_array(($item['tracking_mode'] ?? ''), ['quantity', 'qr'], true)) {
                $item['tracking_mode'] = 'quantity';
                $changed = true;
            }
            if (!isset($item['min_stock']) || !is_numeric($item['min_stock'])) {
                $item['min_stock'] = 2;
                $changed = true;
            }
            $oldMap = $item['workplace_stock'] ?? null;
            if (!is_array($oldMap)) {
                $oldBalance = is_numeric($item['stock'] ?? null) ? max(0.0, (float) $item['stock']) : 0.0;
                $oldMap = ['site-unassigned' => $oldBalance];
                $changed = true;
            }
            $map = [];
            foreach ($siteIds as $siteId) {
                $value = $oldMap[$siteId] ?? 0;
                $map[$siteId] = is_numeric($value) && is_finite((float) $value) ? max(0.0, (float) $value) : 0.0;
                if (!array_key_exists($siteId, $oldMap)) { $changed = true; }
            }
            $item['workplace_stock'] = $map;
            if (($item['tracking_mode'] ?? 'quantity') === 'qr') {
                $qrMap = array_fill_keys($siteIds, 0.0);
                foreach ($units as $unit) {
                    if ((string) ($unit['article_id'] ?? '') === (string) $item['id'] && ($unit['status'] ?? '') === 'active') {
                        $siteId = (string) ($unit['workplace_id'] ?? '');
                        if (array_key_exists($siteId, $qrMap)) { $qrMap[$siteId] += 1.0; }
                    }
                }
                if ($qrMap != $item['workplace_stock']) { $item['workplace_stock'] = $qrMap; $changed = true; }
            }
            $total = array_sum($item['workplace_stock']);
            if (!isset($item['stock']) || (float) $item['stock'] !== (float) $total) {
                $item['stock'] = $total;
                $changed = true;
            }
        }
        unset($item);
        if ($changed && !save_data(INVENTORY_FILE, $items)) {
            return false;
        }
        return true;
    });
}

function inv_make_unit_code(array $article, array $units): string
{
    $sequence = max(0, (int) ($article['unit_counter'] ?? 0));
    foreach ($units as $unit) {
        if ((string) ($unit['article_id'] ?? '') === (string) ($article['id'] ?? '') && preg_match('/-U-(\d+)\z/', (string) ($unit['code'] ?? ''), $match)) {
            $sequence = max($sequence, (int) $match[1]);
        }
    }
    return (string) ($article['article_no'] ?? 'A0000') . '-U-' . str_pad((string) ($sequence + 1), 5, '0', STR_PAD_LEFT);
}

function inv_new_transaction_id(): string
{
    return 'TR-' . gmdate('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function inv_find_row_index(array $rows, string $key, string $value): ?int
{
    foreach ($rows as $index => $row) {
        if (is_array($row) && (string) ($row[$key] ?? '') === $value) {
            return $index;
        }
    }
    return null;
}

function inv_unit_code_from_payload(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '' || strlen($raw) > 1200) {
        return '';
    }
    if (filter_var($raw, FILTER_VALIDATE_URL)) {
        $query = parse_url($raw, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $parameters);
            if (is_string($parameters['qr'] ?? null)) {
                $raw = trim($parameters['qr']);
            }
        }
    }
    $raw = strtoupper($raw);
    return preg_match('/\A[A-Z0-9][A-Z0-9-]{2,63}\z/', $raw) ? $raw : '';
}
