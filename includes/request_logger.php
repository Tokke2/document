<?php
declare(strict_types=1);

/** Privacy-minimized operational request log, stored only in the app's logs/ directory. */
final class TadegaRequestLogger
{
    private static float $started = 0.0;
    private static string $outcome = 'not_recorded';
    private static bool $registered = false;

    public static function start(string $root): void
    {
        if (self::$registered || PHP_SAPI === 'cli') return;
        self::$registered = true;
        self::$started = microtime(true);
        register_shutdown_function(static function () use ($root): void {
            $error = error_get_last();
            $fatal = is_array($error) && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
            $status = http_response_code();
            $ok = self::write($root, [
                'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                'page' => $_GET['tab'] ?? 'protocol',
                'action' => $_POST['action'] ?? '',
                'status' => $fatal ? 500 : (is_int($status) ? $status : 200),
                'duration_ms' => (int)round((microtime(true) - self::$started) * 1000),
                'peak_kb' => (int)ceil(memory_get_peak_usage(true) / 1024),
                'outcome' => $fatal ? 'fatal' : self::$outcome,
                'request_id' => TadegaPrivacyLogger::getRequestId(),
            ]);
            if (!$ok) error_log('Tadega request logger could not write: check protected logs directory.');
        });
    }

    public static function outcome(string $outcome): void
    {
        if (in_array($outcome, ['success', 'failure', 'denied', 'rollback_failed'], true)) self::$outcome = $outcome;
    }

    /** No arbitrary caller-supplied strings are ever written. */
    public static function write(string $root, array $data): bool
    {
        $pages = ['protocol','invoice','skadeblankett','files','archive','customers','settings','admin','calendar','search'];
        $actions = ['central_admin_login','central_admin_logout','central_admin_enroll','central_admin_change_pin',
            'invoice_pin_login','invoice_pin_logout','protocol_pin_login','protocol_pin_logout',
            'portal_release_check','portal_release_install','portal_release_install_latest','save_protocol','save_invoice','generate_skadeblankett',
            'calendar_save','calendar_delete','client_diagnostic','autosave_draft','voice_learning'];
        $allowed = static fn(mixed $value, array $list, string $fallback): string => is_string($value) && in_array($value, $list, true) ? $value : $fallback;
        $number = static fn(mixed $value, int $max): int => is_int($value) ? max(0, min($value, $max)) : 0;
        $status = $number($data['status'] ?? 0, 599);
        $record = [
            'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'request_id' => is_string($data['request_id'] ?? null) && preg_match('/\A[a-f0-9]{16,64}\z/D', $data['request_id']) ? $data['request_id'] : '',
            'method' => $allowed($data['method'] ?? '', ['GET','POST','HEAD'], 'OTHER'),
            'page' => $allowed($data['page'] ?? '', $pages, 'other'),
            'action' => $allowed($data['action'] ?? '', $actions, 'other'),
            'http_status' => $status >= 100 ? $status : 0,
            'duration_ms' => $number($data['duration_ms'] ?? 0, 86400000),
            'peak_memory_kb' => $number($data['peak_kb'] ?? 0, 1073741824),
            'outcome' => $allowed($data['outcome'] ?? '', ['not_recorded','success','failure','denied','rollback_failed','fatal'], 'not_recorded'),
        ];
        $rootReal = realpath($root); $dir = $root . '/logs'; $realDir = realpath($dir); $guard = $dir.'/.htaccess';
        if ($rootReal === false || is_link($root) || is_link($dir) || $realDir === false || dirname($realDir) !== $rootReal || is_link($guard) || !is_file($guard) || !preg_match('/^\s*(?:Require\s+all\s+denied|Deny\s+from\s+all)\b/im', (string)@file_get_contents($guard))) return false;
        $path = $dir.'/requests.jsonl'; $lockPath = $path.'.lock'; $marker = $dir.'/.requests-pruned';
        $paths = [$path, $path.'.1', $path.'.2', $path.'.3', $lockPath, $marker];
        foreach ($paths as $p) if (is_link($p) || (file_exists($p) && !is_file($p))) return false;
        $old = umask(0077); $lock = @fopen($lockPath, 'c'); umask($old);
        if (!is_resource($lock)) return false;
        @chmod($lockPath, 0600);
        if (!@flock($lock, LOCK_EX)) { fclose($lock); return false; }
        try {
            foreach ($paths as $p) if (is_link($p) || (file_exists($p) && !is_file($p))) return false;
            clearstatcache(true, $marker);
            if (!is_file($marker) || (int)@filemtime($marker) < time()-86400) {
                if (!self::prune([$path,$path.'.1',$path.'.2',$path.'.3'], time()-30*86400)) return false;
                $old = umask(0077); $m = @fopen($marker, 'c'); umask($old);
                if (is_resource($m)) { fclose($m); @chmod($marker,0600); @touch($marker); }
            }
            clearstatcache(true, $path);
            if (is_file($path) && (int)@filesize($path) >= 1048576) {
                if (is_file($path.'.3') && !@unlink($path.'.3')) return false;
                foreach ([2,1] as $n) if (is_file($path.'.'.$n) && !@rename($path.'.'.$n, $path.'.'.($n+1))) return false;
                if (!@rename($path, $path.'.1')) return false;
            }
            $line = json_encode($record, JSON_UNESCAPED_SLASHES);
            if (!is_string($line)) return false;
            $old = umask(0077); $file = @fopen($path, 'ab'); umask($old);
            if (!is_resource($file)) return false;
            @chmod($path, 0600); $payload = $line."\n";
            $written = @fwrite($file, $payload); $flushed = @fflush($file); fclose($file);
            return $written === strlen($payload) && $flushed;
        } finally { @flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function prune(array $paths, int $cutoff): bool
    {
        foreach ($paths as $path) {
            if (!is_file($path)) continue;
            // Refuse unexpectedly large or linked files instead of reading unlimited data.
            if (is_link($path) || (int)@filesize($path) > 2097152) return false;
            $contents = @file_get_contents($path); if (!is_string($contents)) return false;
            $kept = [];
            foreach (explode("\n", $contents) as $line) {
                if ($line === '') continue;
                $entry = json_decode($line, true);
                $time = is_array($entry) && is_string($entry['timestamp_utc'] ?? null) ? strtotime($entry['timestamp_utc']) : false;
                if ($time !== false && $time >= $cutoff) $kept[] = $line;
            }
            $new = $kept ? implode("\n", $kept)."\n" : '';
            if ($new === $contents) continue;
            $old = umask(0077); $temp = @tempnam(dirname($path), '.requests-prune-'); umask($old);
            if (!is_string($temp)) return false;
            $written = @file_put_contents($temp, $new); @chmod($temp,0600);
            if ($written !== strlen($new) || !@rename($temp,$path)) { @unlink($temp); return false; }
        }
        return true;
    }
}
