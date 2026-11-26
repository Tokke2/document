<?php
declare(strict_types=1);

/** Unsigned GitHub source-release updater. The fixed public repository and TLS
 * are the trust boundary; a compromised GitHub account can publish code.
 * Data and local configuration are never candidates for installation. */
function portal_github_release_metadata(): array
{
    $response = portal_release_http_get('https://api.github.com/repos/Tokke2/document/releases/latest', ['Accept: application/vnd.github+json','X-GitHub-Api-Version: 2022-11-28'], 1048576, 15);
    if (!($response['ok'] ?? false)) return ['ok'=>false,'code'=>$response['code'] ?? 'network_error'];
    if (($response['status'] ?? 0) === 404) return ['ok'=>false,'code'=>'no_published_release'];
    if (($response['status'] ?? 0) !== 200) return ['ok'=>false,'code'=>'github_response_invalid'];
    try { $data = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR); } catch (JsonException) { return ['ok'=>false,'code'=>'github_response_invalid']; }
    if (!is_array($data) || ($data['draft'] ?? true) !== false || ($data['prerelease'] ?? true) !== false || !is_int($data['id'] ?? null) || $data['id'] <= 0 || !is_string($data['tag_name'] ?? null) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9.+_-]{0,79}\z/D', $data['tag_name']) !== 1) return ['ok'=>false,'code'=>'github_response_invalid'];
    // Resolve the explicit tag ref (not a same-named branch) to an immutable commit.
    $commitResponse = portal_release_http_get('https://api.github.com/repos/Tokke2/document/commits/'.rawurlencode('refs/tags/'.$data['tag_name']), ['Accept: application/vnd.github+json','X-GitHub-Api-Version: 2022-11-28'], 1048576, 15);
    if (!($commitResponse['ok'] ?? false) || ($commitResponse['status'] ?? 0) !== 200) return ['ok'=>false,'code'=>'github_response_invalid'];
    try { $commitData = json_decode($commitResponse['body'], true, 32, JSON_THROW_ON_ERROR); } catch (JsonException) { return ['ok'=>false,'code'=>'github_response_invalid']; }
    $sha = $commitData['sha'] ?? null;
    if (!is_string($sha) || preg_match('/\A[a-f0-9]{40}\z/D',$sha)!==1) return ['ok'=>false,'code'=>'github_response_invalid'];
    return ['ok'=>true,'code'=>'latest_release_found','tag'=>$data['tag_name'],'release_id'=>$data['id'],'commit'=>$sha];
}

/** Cache metadata only; called on an authenticated admin GET, not by cron.
 * A failed check is also cached to avoid repeated requests and rate limits. */
function portal_github_release_auto_status(string $root, ?callable $fetch = null, ?int $now = null, bool $force = false): array
{
    $now = $now ?? time(); $dir = $root . '/storage'; $guard = $dir . '/.htaccess';
    if (is_link($dir) || !is_dir($dir) || is_link($guard) || !is_file($guard) || !preg_match('/^\s*(?:Require\s+all\s+denied|Deny\s+from\s+all)\b/im', (string)@file_get_contents($guard))) return ['ok'=>false,'code'=>'cache_unavailable'];
    $path=$dir.'/github_release_status.json'; $guardPath=$path.'.guard.lock';
    if (is_link($path) || is_link($guardPath) || is_link($path.'.lock')) return ['ok'=>false,'code'=>'cache_unavailable'];
    $mask=umask(0077); $lock=@fopen($guardPath,'c'); umask($mask);
    if (!is_resource($lock)) return ['ok'=>false,'code'=>'cache_unavailable'];
    @chmod($guardPath,0600);
    if (!@flock($lock,LOCK_EX)) { fclose($lock); return ['ok'=>false,'code'=>'cache_unavailable']; }
    try {
        $cached = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
        if (is_array($cached) && is_int($cached['checked_at'] ?? null) && is_bool($cached['ok'] ?? null) && is_string($cached['code'] ?? null)) {
            // A concurrent check at or after the manual check's start may be newer.
            if ($force && $cached['checked_at'] >= $now) return $cached;
            if (!$force && $cached['checked_at'] <= $now && $cached['checked_at'] > $now - 21600) return $cached;
        }
        $result = $fetch ? $fetch() : portal_github_release_metadata();
        if (!is_array($result) || !is_bool($result['ok'] ?? null) || !is_string($result['code'] ?? null)) $result=['ok'=>false,'code'=>'network_error'];
        $data=['ok'=>$result['ok'],'code'=>$result['code'],'checked_at'=>$now];
        if ($result['ok'] && is_string($result['tag'] ?? null) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9.+_-]{0,79}\z/D',$result['tag'])) $data['tag']=$result['tag'];
        if ($result['ok'] && is_string($result['commit'] ?? null) && preg_match('/\A[a-f0-9]{40}\z/D',$result['commit'])) $data['commit']=$result['commit'];
        if (!function_exists('save_data') || !save_data($path,$data)) return ['ok'=>false,'code'=>'cache_unavailable'];
        return $data;
    } finally { @flock($lock,LOCK_UN); fclose($lock); }
}

/** Best-effort marker only: describes the last release installed by this
 * panel, not necessarily the currently running code after manual deployments. */
function portal_github_release_install_marker(string $root, ?string $tag = null, ?string $commit = null, ?string $outcome = null): ?array
{
    $dir=$root.'/storage'; $guard=$dir.'/.htaccess'; $path=$dir.'/github_release_installed.json';
    $guardPath=$path.'.guard.lock';
    if (is_link($dir) || !is_dir($dir) || is_link($guard) || !is_file($guard) || !preg_match('/^\s*(?:Require\s+all\s+denied|Deny\s+from\s+all)\b/im',(string)@file_get_contents($guard)) || is_link($path) || is_link($path.'.lock') || is_link($guardPath)) return null;
    if ($tag !== null) {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9.+_-]{0,79}\z/D',$tag)!==1 || !is_string($commit) || preg_match('/\A[a-f0-9]{40}\z/D',$commit)!==1 || !in_array($outcome,[null,'installed','already_current'],true) || !function_exists('save_data')) return null;
        $mask=umask(0077); $lock=@fopen($guardPath,'c'); umask($mask);
        if (!is_resource($lock)) return null;
        @chmod($guardPath,0600);
        if (!@flock($lock,LOCK_EX)) { fclose($lock); return null; }
        try {
            if (is_link($path) || is_link($path.'.lock') || is_link($guardPath)) return null;
            $previous=portal_github_release_install_marker($root);
            $now=time();
            $updatedAt=$outcome==='installed' ? $now : ($previous['last_updated_at'] ?? null);
            $data=['tag'=>$tag,'commit'=>$commit,'installed_at'=>$now,'last_updated_at'=>$updatedAt];
            if (!save_data($path,$data)) return null;
            return ['tag'=>$tag,'commit'=>$commit];
        } finally { @flock($lock,LOCK_UN); fclose($lock); }
    }
    if (!is_file($path) || !is_readable($path) || (int)@filesize($path)>4096) return null;
    $data=json_decode((string)@file_get_contents($path),true);
    if (!is_array($data) || !is_string($data['tag'] ?? null) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9.+_-]{0,79}\z/D',$data['tag'])!==1) return null;
    $sha=$data['commit'] ?? null;
    return ['tag'=>$data['tag'],'commit'=>is_string($sha) && preg_match('/\A[a-f0-9]{40}\z/D',$sha)===1 ? $sha : null,
        'installed_at'=>is_int($data['installed_at'] ?? null) && $data['installed_at'] > 0 ? $data['installed_at'] : null,
        'last_updated_at'=>is_int($data['last_updated_at'] ?? null) && $data['last_updated_at'] > 0 ? $data['last_updated_at'] : null];
}

/** Format saved timestamps in Swedish local time, including daylight saving. */
function portal_github_release_stockholm_time(mixed $timestamp): ?string
{
    if (!is_int($timestamp) || $timestamp <= 0) return null;
    try { return (new DateTimeImmutable('@'.$timestamp))->setTimezone(new DateTimeZone('Europe/Stockholm'))->format('Y-m-d H:i'); }
    catch (Throwable $e) { return null; }
}

/** Record a successful manual check without replacing a concurrent newer check. */
function portal_github_release_record_manual_status(string $root, array $result, int $startedAt): void
{
    if (($result['ok'] ?? false) !== true || !is_string($result['tag'] ?? null) || !is_string($result['commit'] ?? null)
        || preg_match('/\A[A-Za-z0-9][A-Za-z0-9.+_-]{0,79}\z/D', $result['tag']) !== 1
        || preg_match('/\A[a-f0-9]{40}\z/D', $result['commit']) !== 1 || $startedAt <= 0 || $startedAt > time()) return;
    $status = ['ok'=>true, 'code'=>'latest_release_found', 'tag'=>$result['tag'], 'commit'=>$result['commit']];
    portal_github_release_auto_status($root, static fn(): array => $status, $startedAt, true);
}

function portal_github_release_status_predates_marker(array $status, array $marker): bool
{
    return is_int($status['checked_at'] ?? null) && is_int($marker['installed_at'] ?? null)
        && $status['checked_at'] <= $marker['installed_at'];
}

function portal_github_release_marker_differs(array $status, array $marker): bool
{
    if (!($status['ok'] ?? false) || !is_string($status['tag'] ?? null) || !is_string($status['commit'] ?? null) || !is_string($marker['tag'] ?? null) || !is_string($marker['commit'] ?? null)) return false;
    return $status['tag'] !== $marker['tag'] || !hash_equals($status['commit'], $marker['commit']);
}

/** Bounded direct HTTPS download from GitHub's codeload endpoint. No redirects. */
function portal_github_release_download(string $commit, string $destination): array
{
    if (preg_match('/\A[a-f0-9]{40}\z/D',$commit)!==1 || !function_exists('curl_init') || !defined('CURLOPT_PROTOCOLS') || !defined('CURLPROTO_HTTPS') || is_link($destination) || !is_file($destination)) return ['ok'=>false,'code'=>'download_unavailable'];
    $file=@fopen($destination,'c+b'); if (!is_resource($file) || !@ftruncate($file,0)) { if (is_resource($file)) fclose($file); return ['ok'=>false,'code'=>'download_temp_error']; }
    $handle=@curl_init('https://codeload.github.com/Tokke2/document/zip/'.$commit);
    if ($handle===false) { fclose($file); return ['ok'=>false,'code'=>'network_unavailable']; }
    $size=0; $tooLarge=false; $type='';
    curl_setopt_array($handle,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>120,CURLOPT_USERAGENT=>'Documentportal-Release-Checker/1.0',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_NOSIGNAL=>true,
        CURLOPT_HEADERFUNCTION=>static function($c,string $line) use (&$type): int { if (stripos($line,'content-type:')===0) $type=trim(substr($line,13)); return strlen($line); },
        CURLOPT_WRITEFUNCTION=>static function($c,string $chunk) use($file,&$size,&$tooLarge): int { $length=strlen($chunk); if ($size+$length>262144000) { $tooLarge=true; return 0; } $written=fwrite($file,$chunk); if (!is_int($written) || $written!==$length) return 0; $size+=$written; return $written; },
    ]);
    $ok=@curl_exec($handle); $status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE); $error=curl_errno($handle); curl_close($handle); fflush($file); fclose($file);
    if ($ok===false || $error!==0) return ['ok'=>false,'code'=>$tooLarge?'download_too_large':'network_error'];
    if ($status!==200 || $size<=0 || stripos($type,'text/html')===0) return ['ok'=>false,'code'=>'download_response_invalid'];
    return ['ok'=>true,'code'=>'downloaded'];
}

function portal_github_release_allowed_file(string $path): bool
{
    if (!portal_release_path_is_allowed($path)) return false;
    if (preg_match('/\Aincludes\/fpdf\/font\/(?:courier|courierb|courierbi|courieri|helvetica|helveticab|helveticabi|helveticai|symbol|times|timesb|timesbi|timesi|zapfdingbats)\.json\z/D', $path) === 1 || $path === 'includes/fpdf/license.txt') return true;
    if (str_starts_with($path,'includes/') || str_starts_with($path,'tabs/')) return str_ends_with(strtolower($path),'.php');
    if (str_starts_with($path,'assets/')) return preg_match('/\.(?:js|css|svg|png|jpe?g|webp|gif|ico|woff2?|ttf|otf|txt)\z/iD',$path)===1;
    return true;
}

/** Validate every entry, ignore non-code data, build a deterministic content
 * manifest and original ZIP indices. Hashes detect changes, not publisher. */
function portal_github_release_read_zip(ZipArchive $zip, string $tag): array
{
    $fail=static fn(string $code): array => ['ok'=>false,'code'=>$code];
    if ($zip->numFiles < 1 || $zip->numFiles > 10000 || !method_exists($zip,'getExternalAttributesIndex')) return $fail('zip_entry_count_invalid');
    $prefix=null; $seen=[]; $indices=[]; $entries=[]; $total=0;
    for ($i=0; $i<$zip->numFiles; $i++) {
        $name=$zip->getNameIndex($i); $stat=$zip->statIndex($i);
        if (!is_string($name) || $name==='' || strlen($name)>400 || str_contains($name,chr(92)) || str_contains($name,"\0") || str_starts_with($name,'/') || !is_array($stat) || !is_int($stat['size'] ?? null) || $stat['size']<0) return $fail('zip_entry_invalid');
        $total += $stat['size']; if ($total>209715200 || $stat['size']>52428800) return $fail('zip_uncompressed_too_large');
        $parts=explode('/',rtrim($name,'/')); $root=array_shift($parts);
        if (!is_string($root) || preg_match('/\Adocument-[A-Za-z0-9._-]{1,150}\z/iD',$root)!==1 || ($prefix!==null && $prefix!==$root)) return $fail('zip_root_invalid');
        $prefix=$root;
        foreach ($parts as $part) if ($part==='' || $part==='.' || $part==='..' || strlen($part)>150 || preg_match('/[\x00-\x1f\x7f]/',$part)) return $fail('zip_path_forbidden');
        $key=strtolower($name); if (isset($seen[$key])) return $fail('zip_duplicate_entry'); $seen[$key]=true;
        $os=0; $attr=0; if (!$zip->getExternalAttributesIndex($i,$os,$attr,ZipArchive::FL_UNCHANGED)) return $fail('zip_entry_attributes_unavailable');
        $fileType=$os===ZipArchive::OPSYS_UNIX ? (($attr>>16)&0170000) : 0;
        if (!in_array($fileType,[0,0100000,0040000],true)) return $fail('zip_special_file_forbidden');
        $directory=str_ends_with($name,'/');
        if (($fileType===0040000 && !$directory) || ($fileType===0100000 && $directory)) return $fail('zip_entry_type_mismatch');
        if ($directory || !$parts) continue;
        $relative=implode('/',$parts);
        if (!portal_github_release_allowed_file($relative)) continue;
        $stream=$zip->getStream($name); if (!is_resource($stream)) return $fail('zip_entry_unreadable');
        $ctx=hash_init('sha256'); $read=0; $valid=true;
        while (!feof($stream)) { $chunk=fread($stream,8192); if (!is_string($chunk) || ($chunk==='' && !feof($stream))) { $valid=false; break; } $read+=strlen($chunk); if ($read>$stat['size']) { $valid=false; break; } hash_update($ctx,$chunk); }
        fclose($stream); $hash=hash_final($ctx); if (!$valid || $read!==$stat['size']) return $fail('zip_entry_unreadable');
        $entries[]=['path'=>$relative,'size'=>$read,'sha256'=>$hash]; $indices[$relative]=$i;
    }
    if (!$entries || !isset($indices['index.php']) || !isset($indices['functions.php'])) return $fail('zip_incomplete');
    usort($entries,static fn($a,$b)=>strcmp($a['path'],$b['path']));
    $manifest=['version'=>$tag,'files'=>$entries];
    $json=json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    return ['ok'=>true,'code'=>'ok','manifest'=>$manifest,'indices'=>$indices,'manifest_sha256'=>hash('sha256',$json)];
}

function portal_github_release_compare(string $root, string $zipPath, string $tag): array
{
    $fail=static fn(string $code): array => ['ok'=>false,'code'=>$code,'changed'=>[],'unchanged'=>[],'before'=>[]];
    $realRoot=realpath($root); $realZip=realpath($zipPath);
    if (!class_exists(ZipArchive::class) || !$realRoot || is_link($root) || !$realZip || is_link($zipPath) || !is_file($realZip) || str_starts_with($realZip,rtrim($realRoot,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR) || (int)@filesize($realZip)>262144000) return $fail('zip_unavailable');
    $zip=new ZipArchive(); if ($zip->open($realZip,ZipArchive::RDONLY)!==true) return $fail('zip_invalid');
    try {
        $data=portal_github_release_read_zip($zip,$tag); if (!($data['ok'] ?? false)) return $fail($data['code'] ?? 'zip_invalid');
        $changed=[]; $unchanged=[]; $before=[];
        foreach ($data['manifest']['files'] as $entry) {
            $target=portal_release_resolve_target($realRoot,$entry['path']); if (!($target['ok'] ?? false)) return $fail($target['code'] ?? 'target_invalid');
            if (!file_exists($target['path'])) { $before[$entry['path']]=null; $changed[]=$entry['path']; continue; }
            $hash=@hash_file('sha256',$target['path']); if (!is_string($hash)) return $fail('target_unreadable');
            $before[$entry['path']]=$hash;
            if (hash_equals($entry['sha256'],$hash)) $unchanged[]=$entry['path']; else $changed[]=$entry['path'];
        }
        return ['ok'=>true,'code'=>'compared','version'=>$tag,'manifest_sha256'=>$data['manifest_sha256'],'changed'=>$changed,'unchanged'=>$unchanged,'before'=>$before];
    } finally { $zip->close(); }
}

function portal_github_release_check_latest(string $root): array
{
    if (!class_exists(ZipArchive::class)) return ['ok'=>false,'code'=>'zip_extension_unavailable'];
    $release=portal_github_release_metadata(); if (!($release['ok'] ?? false)) return $release;
    $rootReal=realpath($root); if (!$rootReal || is_link($root)) return ['ok'=>false,'code'=>'application_root_unavailable'];
    $zip=portal_release_create_temp_file($rootReal); if (!is_string($zip)) return ['ok'=>false,'code'=>'temporary_storage_unavailable'];
    try {
        $download=portal_github_release_download($release['commit'],$zip); if (!($download['ok'] ?? false)) return $download;
        $result=portal_github_release_compare($rootReal,$zip,$release['tag']); if (!($result['ok'] ?? false)) return $result;
        $result['tag']=$release['tag']; $result['release_id']=$release['release_id']; $result['commit']=$release['commit']; $result['code']='release_checked'; return $result;
    } finally { @unlink($zip); }
}

function portal_github_release_install_approved(string $root, array $review): array
{
    $release=portal_github_release_metadata(); if (!($release['ok'] ?? false)) return $release;
    if (!isset($review['tag'],$review['release_id'],$review['manifest_sha256'],$review['before'],$review['commit']) || !is_string($review['tag']) || !is_int($review['release_id']) || !is_string($review['manifest_sha256']) || !is_array($review['before']) || $release['tag']!==$review['tag'] || $release['release_id']!==$review['release_id'] || !is_string($review['commit']) || $release['commit']!==$review['commit']) return ['ok'=>false,'code'=>'release_changed_since_approval'];
    $rootReal=realpath($root); if (!$rootReal || is_link($root)) return ['ok'=>false,'code'=>'application_root_unavailable'];
    $zip=portal_release_create_temp_file($rootReal); if (!is_string($zip)) return ['ok'=>false,'code'=>'temporary_storage_unavailable'];
    try {
        $download=portal_github_release_download($release['commit'],$zip); if (!($download['ok'] ?? false)) return $download;
        return portal_release_install_package($rootReal,$zip,'',$review['tag'],$review['tag'],$review['manifest_sha256'],'github',$review['before']);
    } finally { @unlink($zip); }
}

/** A single explicit admin action: compare, then revalidate and install the same
 * release. No client-supplied plan or persistent approval is trusted. */
function portal_github_release_install_latest(string $root, ?callable $check = null, ?callable $install = null): array
{
    $plan = $check ? $check($root) : portal_github_release_check_latest($root);
    if (!is_array($plan) || ($plan['ok'] ?? false) !== true) return is_array($plan) ? $plan : ['ok'=>false,'code'=>'check_failed'];
    if (!is_string($plan['tag'] ?? null) || !is_string($plan['commit'] ?? null)) return ['ok'=>false,'code'=>'check_failed'];
    $result = $install ? $install($root, $plan) : portal_github_release_install_approved($root, $plan);
    if (!is_array($result)) return ['ok'=>false,'code'=>'install_failed'];
    if (($result['ok'] ?? false) === true) {
        $result['tag'] = $plan['tag'];
        $result['commit'] = $plan['commit'];
    }
    return $result;
}

/** Hourly auto-update from the fixed repository (hard rule): a full-portal GET
 * re-checks the latest stable release at most once per interval and installs it
 * through the same reviewed, allowlisted, transactional path as the manual
 * Adminpanel button. Failures are cached by portal_github_release_auto_status
 * too, so a broken network or missing configuration retries hourly, not per
 * page view. Never called during form posts, customer mode or scoped PWAs. */
function portal_github_release_auto_update_if_due(string $root, int $intervalSeconds = 3600): array
{
    if ($intervalSeconds < 600) return ['ok' => false, 'code' => 'skipped'];
    $status = portal_github_release_auto_status($root);
    $checkedAt = is_array($status) && is_int($status['checked_at'] ?? null) ? (int) $status['checked_at'] : 0;
    if ($checkedAt > 0 && time() - $checkedAt < $intervalSeconds) return ['ok' => false, 'code' => 'not_due'];
    $fresh = portal_github_release_auto_status($root, null, null, true);
    if (!is_array($fresh) || ($fresh['ok'] ?? false) !== true || !is_string($fresh['tag'] ?? null) || !is_string($fresh['commit'] ?? null)) {
        return ['ok' => false, 'code' => is_array($fresh) && is_string($fresh['code'] ?? null) ? $fresh['code'] : 'check_failed'];
    }
    $marker = portal_github_release_install_marker($root);
    if (is_array($marker) && is_string($marker['commit'] ?? null) && !portal_github_release_marker_differs($fresh, $marker)) {
        return ['ok' => false, 'code' => 'already_current'];
    }
    $result = portal_github_release_install_latest($root);
    if (!is_array($result)) return ['ok' => false, 'code' => 'install_failed'];
    return $result;
}
