<?php
declare(strict_types=1);

/**
 * Transactional file installer shared by the signed legacy and unsigned GitHub source modes.
 *
 * In legacy signed mode, the release ZIP contains manifest.json and manifest.sig at its root. The
 * exact manifest bytes are signed with RSA/ECDSA + SHA-256; the public key is
 * pinned in the local, web-denied release_config.php file. ZIP entries are
 * read as streams and never extracted. Paths are allowlisted, storage/ and
 * other data directories are excluded, and the installer replaces only files
 * whose installed SHA-256 differs from the signed manifest. Temporary download,
 * staging, and rollback snapshots live outside the application/storage tree.
 */

function portal_release_path_is_allowed(string $relativePath): bool
{
    if (
        $relativePath === '' || strlen($relativePath) > 240 ||
        str_contains($relativePath, "\0") || str_contains($relativePath, chr(92)) ||
        str_starts_with($relativePath, '/') || preg_match('/\A[A-Za-z]:/', $relativePath) === 1
    ) {
        return false;
    }

    $segments = explode('/', $relativePath);
    if (count($segments) > 16) {
        return false;
    }
    $protectedNames = ['storage', 'logs', 'pdf', 'svg', 'uploads', 'backups', 'data', '.git'];
    foreach ($segments as $segment) {
        if (
            $segment === '' || $segment === '.' || $segment === '..' ||
            strlen($segment) > 100 || str_starts_with($segment, '.') ||
            preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $segment) !== 1 ||
            in_array(strtolower($segment), $protectedNames, true)
        ) {
            return false;
        }
    }

    $topLevel = $segments[0];
    if (in_array($topLevel, ['assets', 'includes', 'tabs'], true)) {
        // Keep this local trust configuration outside normal release packages.
        return strtolower($relativePath) !== 'includes/release_config.php';
    }

    return count($segments) === 1 && in_array($topLevel, [
        'index.php',
        'functions.php',
        'pwa-protokoll.php',
        'pwa-underlag.php',
        'pwa-kalender.php',
        'skadeblankett-kund.php',
        'service-worker.js',
        'offline.html',
        'manifest.webmanifest',
        'manifest-protokoll.webmanifest',
        'manifest-underlag.webmanifest',
        'manifest-kalender.webmanifest',
        'manifest-skadeblankett.webmanifest',
        'favicon.ico',
        'browserconfig.xml',
    ], true);
}

function portal_release_directory_is_allowed(string $directoryPath, array $manifestPaths): bool
{
    if (
        $directoryPath === '' || strlen($directoryPath) > 240 ||
        str_contains($directoryPath, "\0") || str_contains($directoryPath, chr(92)) ||
        str_starts_with($directoryPath, '/') || preg_match('/\A[A-Za-z]:/', $directoryPath) === 1
    ) {
        return false;
    }
    $segments = explode('/', $directoryPath);
    if (count($segments) > 16) {
        return false;
    }
    $protectedNames = ['storage', 'logs', 'pdf', 'svg', 'uploads', 'backups', 'data', '.git'];
    foreach ($segments as $segment) {
        if (
            $segment === '' || $segment === '.' || $segment === '..' ||
            strlen($segment) > 100 || str_starts_with($segment, '.') ||
            preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $segment) !== 1 ||
            in_array(strtolower($segment), $protectedNames, true)
        ) {
            return false;
        }
    }
    if (count($segments) === 1 && in_array($segments[0], ['assets', 'includes', 'tabs'], true)) {
        return true;
    }
    foreach ($manifestPaths as $manifestPath) {
        if (is_string($manifestPath) && str_starts_with($manifestPath, $directoryPath . '/')) {
            return true;
        }
    }
    return false;
}

/** Return validated, non-secret configuration; blank defaults keep updates disabled. */
function portal_release_configuration(): array
{
    $configFile = __DIR__ . DIRECTORY_SEPARATOR . 'release_config.php';
    if (is_link($configFile) || !is_file($configFile) || !is_readable($configFile)) {
        return ['ok' => false, 'code' => 'configuration_missing'];
    }
    try {
        $raw = require $configFile;
    } catch (Throwable) {
        return ['ok' => false, 'code' => 'configuration_invalid'];
    }
    if (!is_array($raw)) {
        return ['ok' => false, 'code' => 'configuration_invalid'];
    }

    $repositoryUrl = isset($raw['repository_url']) && is_string($raw['repository_url']) ? trim($raw['repository_url']) : '';
    $assetName = isset($raw['asset_name']) && is_string($raw['asset_name']) ? trim($raw['asset_name']) : '';
    $publicKeyPem = isset($raw['public_key_pem']) && is_string($raw['public_key_pem']) ? $raw['public_key_pem'] : '';
    $publicKeyPem = str_replace([chr(92) . 'r', chr(92) . 'n'], ["\r", "\n"], $publicKeyPem);

    if ($repositoryUrl === '' || $assetName === '' || trim($publicKeyPem) === '') {
        return ['ok' => false, 'code' => 'configuration_incomplete'];
    }
    if (
        strlen($repositoryUrl) > 300 || strlen($assetName) > 120 || strlen($publicKeyPem) > 16384 ||
        preg_match('/\Ahttps:\/\/github\.com\/([A-Za-z0-9-]{1,39})\/([A-Za-z0-9._-]{1,100})(?:\.git)?\/?\z/iD', $repositoryUrl, $parts) !== 1 ||
        in_array($parts[2] ?? '', ['.', '..'], true) ||
        preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\.zip\z/iD', $assetName) !== 1 ||
        (!str_starts_with(ltrim($publicKeyPem), '-----BEGIN PUBLIC KEY-----') && !str_starts_with(ltrim($publicKeyPem), '-----BEGIN RSA PUBLIC KEY-----'))
    ) {
        return ['ok' => false, 'code' => 'configuration_invalid'];
    }

    $owner = $parts[1];
    $repository = preg_replace('/\.git\z/i', '', $parts[2]) ?? '';
    if ($repository === '' || in_array($repository, ['.', '..'], true)) {
        return ['ok' => false, 'code' => 'configuration_invalid'];
    }

    return [
        'ok' => true,
        'code' => 'ready',
        'owner' => $owner,
        'repository' => $repository,
        'asset_name' => $assetName,
        'public_key_pem' => trim($publicKeyPem),
        'api_url' => 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repository) . '/releases/latest',
    ];
}

/** Verify a detached RSA/ECDSA SHA-256 signature over the exact manifest bytes. */
function portal_release_verify_manifest(string $manifestBytes, string $signatureBase64, string $publicKeyPem): array
{
    $failure = static function (string $code): array {
        return ['ok' => false, 'code' => $code, 'manifest' => null];
    };

    if (
        $manifestBytes === '' || strlen($manifestBytes) > 1048576 ||
        $signatureBase64 === '' || strlen($signatureBase64) > 8192 ||
        $publicKeyPem === '' || strlen($publicKeyPem) > 16384 ||
        (!str_starts_with(ltrim($publicKeyPem), '-----BEGIN PUBLIC KEY-----') && !str_starts_with(ltrim($publicKeyPem), '-----BEGIN RSA PUBLIC KEY-----')) ||
        !function_exists('openssl_verify') || !function_exists('openssl_pkey_get_public') ||
        !function_exists('openssl_pkey_get_details')
    ) {
        return $failure('verification_unavailable');
    }

    $publicKey = @openssl_pkey_get_public($publicKeyPem);
    if ($publicKey === false) {
        return $failure('public_key_invalid');
    }
    $keyDetails = @openssl_pkey_get_details($publicKey);
    if (!is_array($keyDetails)) {
        return $failure('public_key_invalid');
    }
    $keyType = $keyDetails['type'] ?? null;
    $keyBits = (int) ($keyDetails['bits'] ?? 0);
    if (
        ($keyType === OPENSSL_KEYTYPE_RSA && $keyBits < 2048) ||
        ($keyType === OPENSSL_KEYTYPE_EC && $keyBits < 256) ||
        !in_array($keyType, [OPENSSL_KEYTYPE_RSA, OPENSSL_KEYTYPE_EC], true)
    ) {
        return $failure('public_key_weak_or_unsupported');
    }

    $signatureText = preg_replace('/\s+/', '', trim($signatureBase64));
    $signature = is_string($signatureText) ? base64_decode($signatureText, true) : false;
    if (!is_string($signature) || $signature === '') {
        return $failure('signature_invalid');
    }
    $signatureResult = @openssl_verify($manifestBytes, $signature, $publicKey, OPENSSL_ALGO_SHA256);
    if ($signatureResult !== 1) {
        return $failure('signature_invalid');
    }

    try {
        $decoded = json_decode($manifestBytes, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return $failure('manifest_invalid');
    }
    if (
        !is_array($decoded) || ($decoded['schema'] ?? null) !== 1 ||
        !is_string($decoded['version'] ?? null) ||
        preg_match('/\A[A-Za-z0-9][A-Za-z0-9.+_-]{0,79}\z/D', $decoded['version']) !== 1 ||
        !is_array($decoded['files'] ?? null) || !array_is_list($decoded['files']) ||
        $decoded['files'] === [] || count($decoded['files']) > 5000
    ) {
        return $failure('manifest_invalid');
    }

    $validatedFiles = [];
    $seenPaths = [];
    $totalSize = 0;
    foreach ($decoded['files'] as $entry) {
        if (
            !is_array($entry) || !is_string($entry['path'] ?? null) ||
            !is_string($entry['sha256'] ?? null) ||
            preg_match('/\A[a-f0-9]{64}\z/D', $entry['sha256']) !== 1 ||
            !is_int($entry['size'] ?? null) || $entry['size'] < 0 || $entry['size'] > 52428800 ||
            !portal_release_path_is_allowed($entry['path'])
        ) {
            return $failure('manifest_invalid');
        }
        $pathKey = strtolower($entry['path']);
        if (isset($seenPaths[$pathKey])) {
            return $failure('manifest_duplicate_path');
        }
        $seenPaths[$pathKey] = true;
        $totalSize += $entry['size'];
        if ($totalSize > 209715200) {
            return $failure('manifest_too_large');
        }
        $validatedFiles[] = [
            'path' => $entry['path'],
            'sha256' => $entry['sha256'],
            'size' => $entry['size'],
        ];
    }

    return [
        'ok' => true,
        'code' => 'verified',
        'manifest' => [
            'version' => $decoded['version'],
            'files' => $validatedFiles,
        ],
    ];
}

/** ZIP metadata plus manifest verification; no archive entry is extracted. */
function portal_release_read_verified_zip(ZipArchive $zip, string $publicKeyPem): array
{
    $failure = static function (string $code): array {
        return ['ok' => false, 'code' => $code, 'manifest' => null, 'indices' => [], 'manifest_sha256' => ''];
    };
    if (
        !method_exists($zip, 'getExternalAttributesIndex') ||
        $zip->numFiles < 3 || $zip->numFiles > 5002
    ) {
        return $failure('zip_entry_count_invalid');
    }

    $manifestIndex = null;
    $signatureIndex = null;
    $indices = [];
    $directories = [];
    $seenEntries = [];
    $totalUncompressed = 0;
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $entryName = $zip->getNameIndex($index);
        if (!is_string($entryName) || $entryName === '' || strlen($entryName) > 255 || str_contains($entryName, chr(92))) {
            return $failure('zip_entry_invalid');
        }
        $entryKey = strtolower($entryName);
        if (isset($seenEntries[$entryKey])) {
            return $failure('zip_duplicate_entry');
        }
        $seenEntries[$entryKey] = true;

        $entryStat = $zip->statIndex($index);
        if (!is_array($entryStat) || !is_int($entryStat['size'] ?? null) || $entryStat['size'] < 0) {
            return $failure('zip_entry_invalid');
        }
        $totalUncompressed += $entryStat['size'];
        if ($totalUncompressed > 209715200) {
            return $failure('zip_uncompressed_too_large');
        }

        $operatingSystem = 0;
        $externalAttributes = 0;
        if (!$zip->getExternalAttributesIndex($index, $operatingSystem, $externalAttributes, ZipArchive::FL_UNCHANGED)) {
            return $failure('zip_entry_attributes_unavailable');
        }
        $fileType = $operatingSystem === ZipArchive::OPSYS_UNIX
            ? (($externalAttributes >> 16) & 0170000)
            : 0;
        if (in_array($fileType, [0120000, 0020000, 0060000, 0010000, 0140000], true)) {
            return $failure('zip_special_file_forbidden');
        }
        if (!in_array($fileType, [0, 0100000, 0040000], true)) {
            return $failure('zip_special_file_forbidden');
        }

        if ($entryName === 'manifest.json') {
            if ($entryStat['size'] <= 0 || $entryStat['size'] > 1048576 || $fileType === 0040000) {
                return $failure('manifest_invalid');
            }
            $manifestIndex = $index;
            continue;
        }
        if ($entryName === 'manifest.sig') {
            if ($entryStat['size'] <= 0 || $entryStat['size'] > 8192 || $fileType === 0040000) {
                return $failure('signature_invalid');
            }
            $signatureIndex = $index;
            continue;
        }

        $endsWithSlash = str_ends_with($entryName, '/');
        if (($fileType === 0040000 && !$endsWithSlash) || ($fileType === 0100000 && $endsWithSlash)) {
            return $failure('zip_entry_type_mismatch');
        }
        if ($endsWithSlash) {
            $directories[] = substr($entryName, 0, -1);
            continue;
        }
        if ($fileType === 0040000 || !portal_release_path_is_allowed($entryName)) {
            return $failure('zip_path_forbidden');
        }
        $indices[$entryName] = $index;
    }

    if (!is_int($manifestIndex) || !is_int($signatureIndex)) {
        return $failure('release_metadata_missing');
    }
    $manifestBytes = $zip->getFromIndex($manifestIndex);
    $signatureBase64 = $zip->getFromIndex($signatureIndex);
    if (!is_string($manifestBytes) || !is_string($signatureBase64)) {
        return $failure('release_metadata_unreadable');
    }
    $verification = portal_release_verify_manifest($manifestBytes, $signatureBase64, $publicKeyPem);
    if (($verification['ok'] ?? false) !== true || !is_array($verification['manifest'] ?? null)) {
        return $failure(is_string($verification['code'] ?? null) ? $verification['code'] : 'manifest_invalid');
    }
    $manifest = $verification['manifest'];
    $manifestPaths = array_column($manifest['files'], 'path');
    foreach ($directories as $directoryPath) {
        if (!portal_release_directory_is_allowed($directoryPath, $manifestPaths)) {
            return $failure('zip_directory_forbidden');
        }
    }

    $expectedPaths = $manifestPaths;
    $actualPaths = array_keys($indices);
    sort($expectedPaths, SORT_STRING);
    sort($actualPaths, SORT_STRING);
    if ($expectedPaths !== $actualPaths) {
        return $failure('zip_manifest_mismatch');
    }

    return [
        'ok' => true,
        'code' => 'verified',
        'manifest' => $manifest,
        'indices' => $indices,
        'manifest_sha256' => hash('sha256', $manifestBytes),
    ];
}

/** Resolve a target while refusing symlinks, absent parent directories, and escapes. */
function portal_release_resolve_target(string $applicationRootReal, string $relativePath): array
{
    if (!portal_release_path_is_allowed($relativePath)) {
        return ['ok' => false, 'code' => 'target_path_forbidden', 'path' => ''];
    }
    $segments = explode('/', $relativePath);
    $rootPrefix = rtrim($applicationRootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $cursor = $applicationRootReal;
    foreach ($segments as $index => $segment) {
        $cursor .= DIRECTORY_SEPARATOR . $segment;
        if (is_link($cursor)) {
            return ['ok' => false, 'code' => 'target_symlink', 'path' => ''];
        }
        if ($index < count($segments) - 1) {
            if (file_exists($cursor) && !is_dir($cursor)) {
                return ['ok' => false, 'code' => 'target_path_conflict', 'path' => ''];
            }
            if (!is_dir($cursor)) {
                return ['ok' => false, 'code' => 'target_parent_missing', 'path' => ''];
            }
            $realDirectory = realpath($cursor);
            if ($realDirectory === false || !str_starts_with(rtrim($realDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, $rootPrefix)) {
                return ['ok' => false, 'code' => 'target_path_escape', 'path' => ''];
            }
        }
    }
    if (file_exists($cursor) && !is_file($cursor)) {
        return ['ok' => false, 'code' => 'target_file_invalid', 'path' => ''];
    }
    return ['ok' => true, 'code' => 'ok', 'path' => $cursor];
}

/** Hash one ZIP entry incrementally without extracting or buffering the full file. */
function portal_release_verify_zip_entry(ZipArchive $zip, int $entryIndex, array $manifestEntry): bool
{
    $entryStat = $zip->statIndex($entryIndex);
    if (!is_array($entryStat) || (int) ($entryStat['size'] ?? -1) !== $manifestEntry['size']) {
        return false;
    }
    $entryName = $zip->getNameIndex($entryIndex);
    if (!is_string($entryName)) {
        return false;
    }
    $stream = $zip->getStream($entryName);
    if (!is_resource($stream)) {
        return false;
    }
    $context = hash_init('sha256');
    $readBytes = 0;
    $valid = true;
    while (!feof($stream)) {
        $chunk = fread($stream, 8192);
        if (!is_string($chunk)) {
            $valid = false;
            break;
        }
        if ($chunk === '') {
            if (feof($stream)) {
                break;
            }
            $valid = false;
            break;
        }
        $readBytes += strlen($chunk);
        if ($readBytes > $manifestEntry['size'] || $readBytes > 52428800) {
            $valid = false;
            break;
        }
        hash_update($context, $chunk);
    }
    fclose($stream);
    $hash = hash_final($context);
    return $valid && $readBytes === $manifestEntry['size'] && hash_equals($manifestEntry['sha256'], $hash);
}

/** Read-only changed/unchanged comparison for a signed, flat-layout release ZIP. */
function portal_release_compare_zip_files(string $applicationRoot, string $zipPath, string $publicKeyPem): array
{
    $failure = static function (string $code): array {
        return ['ok' => false, 'code' => $code, 'version' => '', 'manifest_sha256' => '', 'changed' => [], 'unchanged' => [], 'before' => []];
    };
    if (
        !class_exists(ZipArchive::class) || !is_file($zipPath) || is_link($zipPath) ||
        !is_readable($zipPath) || !is_int(@filesize($zipPath)) ||
        (int) @filesize($zipPath) <= 0 || (int) @filesize($zipPath) > 262144000
    ) {
        return $failure('zip_unavailable');
    }
    $applicationRootReal = realpath($applicationRoot);
    if ($applicationRootReal === false || is_link($applicationRoot) || !is_dir($applicationRootReal)) {
        return $failure('application_root_unavailable');
    }
    $zipReal = realpath($zipPath);
    if ($zipReal === false || str_starts_with($zipReal, rtrim($applicationRootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
        return $failure('zip_path_not_isolated');
    }

    $zip = new ZipArchive();
    if ($zip->open($zipReal, ZipArchive::RDONLY) !== true) {
        return $failure('zip_invalid');
    }
    try {
        $verifiedZip = portal_release_read_verified_zip($zip, $publicKeyPem);
        if (($verifiedZip['ok'] ?? false) !== true || !is_array($verifiedZip['manifest'] ?? null)) {
            return $failure((string) ($verifiedZip['code'] ?? 'zip_invalid'));
        }
        $manifest = $verifiedZip['manifest'];
        $changed = [];
        $unchanged = [];
        $before = [];
        foreach ($manifest['files'] as $entry) {
            $entryIndex = $verifiedZip['indices'][$entry['path']] ?? null;
            if (!is_int($entryIndex) || !portal_release_verify_zip_entry($zip, $entryIndex, $entry)) {
                return $failure('zip_file_hash_mismatch');
            }
            $target = portal_release_resolve_target($applicationRootReal, $entry['path']);
            if (($target['ok'] ?? false) !== true) {
                return $failure((string) ($target['code'] ?? 'target_invalid'));
            }
            $targetPath = $target['path'];
            if (!file_exists($targetPath)) {
                $before[$entry['path']] = null;
                $changed[] = $entry['path'];
                continue;
            }
            $installedHash = @hash_file('sha256', $targetPath);
            if (!is_string($installedHash)) {
                return $failure('target_read_failed');
            }
            $before[$entry['path']] = $installedHash;
            if (hash_equals($entry['sha256'], $installedHash)) {
                $unchanged[] = $entry['path'];
            } else {
                $changed[] = $entry['path'];
            }
        }
        sort($changed, SORT_STRING);
        sort($unchanged, SORT_STRING);
        return [
            'ok' => true,
            'code' => 'comparison_complete',
            'version' => $manifest['version'],
            'manifest_sha256' => $verifiedZip['manifest_sha256'],
            'changed' => $changed,
            'unchanged' => $unchanged,
            'before' => $before,
        ];
    } finally {
        $zip->close();
    }
}

/** Bounded HTTPS GET for GitHub API JSON (curl is required; there is no insecure fallback). */
function portal_release_http_get(string $url, array $headers, int $maxBytes, int $timeoutSeconds = 20): array
{
    if (!function_exists('curl_init') || !defined('CURLOPT_PROTOCOLS') || !defined('CURLPROTO_HTTPS')) {
        return ['ok' => false, 'code' => 'curl_unavailable', 'status' => 0, 'body' => '', 'headers' => []];
    }
    $body = '';
    $bodyTooLarge = false;
    $responseHeaders = [];
    $handle = @curl_init($url);
    if ($handle === false) {
        return ['ok' => false, 'code' => 'network_unavailable', 'status' => 0, 'body' => '', 'headers' => []];
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => max(5, min($timeoutSeconds, 60)),
        CURLOPT_USERAGENT => 'Documentportal-Release-Checker/1.0',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_ENCODING => '',
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
            $length = strlen($line);
            $separator = strpos($line, ':');
            if ($separator !== false) {
                $name = strtolower(trim(substr($line, 0, $separator)));
                if (in_array($name, ['location', 'content-type', 'content-length'], true)) {
                    $responseHeaders[$name] = trim(substr($line, $separator + 1));
                }
            }
            return $length;
        },
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$bodyTooLarge, $maxBytes): int {
            $length = strlen($chunk);
            if ($length > $maxBytes - strlen($body)) {
                $bodyTooLarge = true;
                return 0;
            }
            $body .= $chunk;
            return $length;
        },
    ]);
    $executed = @curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_errno($handle);
    curl_close($handle);
    if ($executed === false || $error !== 0) {
        return ['ok' => false, 'code' => $bodyTooLarge ? 'response_too_large' : 'network_error', 'status' => $status, 'body' => '', 'headers' => []];
    }
    return ['ok' => true, 'code' => 'ok', 'status' => $status, 'body' => $body, 'headers' => $responseHeaders];
}

/** Download one GitHub-hosted release asset with manually validated HTTPS redirects. */
function portal_release_download_asset(string $url, string $destination, int $expectedSize): array
{
    if (
        !function_exists('curl_init') || !defined('CURLOPT_PROTOCOLS') || !defined('CURLPROTO_HTTPS') ||
        $expectedSize <= 0 || $expectedSize > 262144000 || is_link($destination) || !is_file($destination)
    ) {
        return ['ok' => false, 'code' => 'download_unavailable'];
    }

    $allowedHosts = [
        'github.com',
        'release-assets.githubusercontent.com',
        'objects.githubusercontent.com',
        'github-releases.githubusercontent.com',
    ];
    $currentUrl = $url;
    for ($redirect = 0; $redirect <= 3; $redirect++) {
        $parts = parse_url($currentUrl);
        $host = is_array($parts) && is_string($parts['host'] ?? null) ? strtolower($parts['host']) : '';
        if (
            !is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' ||
            !in_array($host, $allowedHosts, true) || ($redirect === 0 && $host !== 'github.com') ||
            isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['port']) || isset($parts['fragment'])
        ) {
            return ['ok' => false, 'code' => 'download_host_rejected'];
        }

        $responseHeaders = [];
        $file = @fopen($destination, 'c+b');
        if (!is_resource($file) || !@ftruncate($file, 0) || !@rewind($file)) {
            if (is_resource($file)) {
                fclose($file);
            }
            return ['ok' => false, 'code' => 'download_temp_error'];
        }
        $handle = @curl_init($currentUrl);
        if ($handle === false) {
            fclose($file);
            return ['ok' => false, 'code' => 'network_unavailable'];
        }
        $curlError = 0;
        $downloadTooLarge = false;
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'Documentportal-Release-Checker/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/octet-stream'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $separator = strpos($line, ':');
                if ($separator !== false) {
                    $name = strtolower(trim(substr($line, 0, $separator)));
                    if (in_array($name, ['location', 'content-type', 'content-length'], true)) {
                        $responseHeaders[$name] = trim(substr($line, $separator + 1));
                    }
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($file, &$downloadTooLarge, $expectedSize): int {
                $length = strlen($chunk);
                $position = ftell($file);
                if (!is_int($position) || $length > $expectedSize - $position) {
                    $downloadTooLarge = true;
                    return 0;
                }
                $written = fwrite($file, $chunk);
                return is_int($written) ? $written : 0;
            },
        ]);
        $executed = @curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_errno($handle);
        curl_close($handle);
        fflush($file);
        $downloadedBytes = ftell($file);
        fclose($file);
        if ($executed === false || $curlError !== 0) {
            return ['ok' => false, 'code' => $downloadTooLarge ? 'download_too_large' : 'network_error'];
        }

        if (in_array($status, [301, 302, 303, 307, 308], true)) {
            $location = $responseHeaders['location'] ?? '';
            if ($location === '' || $redirect >= 3) {
                return ['ok' => false, 'code' => 'download_redirect_invalid'];
            }
            // GitHub release assets should redirect to its static asset domains.
            // Reject relative URLs and all non-HTTPS/unknown destinations.
            $currentUrl = $location;
            continue;
        }
        if ($status !== 200 || !is_int($downloadedBytes) || $downloadedBytes !== $expectedSize) {
            return ['ok' => false, 'code' => 'download_response_invalid'];
        }
        $contentType = strtolower((string) ($responseHeaders['content-type'] ?? ''));
        if (str_starts_with($contentType, 'text/html')) {
            return ['ok' => false, 'code' => 'download_content_invalid'];
        }
        return ['ok' => true, 'code' => 'downloaded'];
    }

    return ['ok' => false, 'code' => 'download_redirect_invalid'];
}

/** Fetch and validate the GitHub latest stable release response. */
function portal_release_fetch_latest_metadata(array $configuration): array
{
    if (($configuration['ok'] ?? false) !== true || !is_string($configuration['api_url'] ?? null)) {
        return ['ok' => false, 'code' => 'not_configured'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'code' => 'curl_unavailable'];
    }
    $response = portal_release_http_get(
        $configuration['api_url'],
        ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'],
        1048576,
        20
    );
    if (($response['ok'] ?? false) !== true) {
        return ['ok' => false, 'code' => (string) ($response['code'] ?? 'network_error')];
    }
    if (($response['status'] ?? 0) === 404) {
        return ['ok' => false, 'code' => 'no_published_release'];
    }
    if (($response['status'] ?? 0) === 403) {
        return ['ok' => false, 'code' => 'github_rate_limited_or_forbidden'];
    }
    if (($response['status'] ?? 0) !== 200) {
        return ['ok' => false, 'code' => 'github_response_invalid'];
    }
    try {
        $release = json_decode($response['body'], true, 48, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return ['ok' => false, 'code' => 'github_response_invalid'];
    }
    if (
        !is_array($release) || ($release['draft'] ?? true) !== false || ($release['prerelease'] ?? true) !== false ||
        !is_string($release['tag_name'] ?? null) || strlen($release['tag_name']) > 120 ||
        preg_match('/\A[A-Za-z0-9][A-Za-z0-9.+_-]{0,119}\z/D', $release['tag_name']) !== 1 ||
        !is_array($release['assets'] ?? null) || !array_is_list($release['assets'])
    ) {
        return ['ok' => false, 'code' => 'github_response_invalid'];
    }

    $wantedAsset = $configuration['asset_name'];
    $asset = null;
    foreach ($release['assets'] as $candidate) {
        if (!is_array($candidate) || ($candidate['name'] ?? null) !== $wantedAsset) {
            continue;
        }
        if ($asset !== null) {
            return ['ok' => false, 'code' => 'release_asset_ambiguous'];
        }
        $asset = $candidate;
    }
    if (!is_array($asset)) {
        return ['ok' => false, 'code' => 'release_asset_missing'];
    }
    $assetUrl = $asset['browser_download_url'] ?? null;
    $assetSize = $asset['size'] ?? null;
    if (
        !is_string($assetUrl) || !is_int($assetSize) || $assetSize <= 0 || $assetSize > 262144000 ||
        !is_int($release['id'] ?? null)
    ) {
        return ['ok' => false, 'code' => 'release_asset_invalid'];
    }
    $urlParts = parse_url($assetUrl);
    $expectedPathPrefix = '/' . $configuration['owner'] . '/' . $configuration['repository'] . '/releases/download/';
    if (
        !is_array($urlParts) || strtolower((string) ($urlParts['scheme'] ?? '')) !== 'https' ||
        strtolower((string) ($urlParts['host'] ?? '')) !== 'github.com' ||
        isset($urlParts['user']) || isset($urlParts['pass']) || isset($urlParts['port']) || isset($urlParts['fragment']) ||
        !str_starts_with(strtolower((string) ($urlParts['path'] ?? '')), strtolower($expectedPathPrefix))
    ) {
        return ['ok' => false, 'code' => 'release_asset_url_invalid'];
    }

    return [
        'ok' => true,
        'code' => 'latest_release_found',
        'release_id' => $release['id'],
        'tag' => $release['tag_name'],
        'asset_url' => $assetUrl,
        'asset_size' => $assetSize,
    ];
}

/** Create one private temporary file outside the application root. */
function portal_release_create_temp_file(string $applicationRootReal): string|false
{
    $temporaryRoot = realpath(sys_get_temp_dir());
    if ($temporaryRoot === false || is_link(sys_get_temp_dir())) {
        return false;
    }
    $appPrefix = rtrim($applicationRootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $tempPrefix = rtrim($temporaryRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($temporaryRoot === $applicationRootReal || str_starts_with($temporaryRoot, $appPrefix)) {
        return false;
    }
    $path = @tempnam($temporaryRoot, 'tadega-release-');
    if (!is_string($path) || $path === '' || is_link($path)) {
        return false;
    }
    $realPath = realpath($path);
    if ($realPath === false || !str_starts_with($realPath, $tempPrefix) || str_starts_with($realPath, $appPrefix)) {
        @unlink($path);
        return false;
    }
    if (!@chmod($realPath, 0600)) {
        @unlink($realPath);
        return false;
    }
    $permissions = @fileperms($realPath);
    if (!is_int($permissions) || ($permissions & 0077) !== 0) {
        @unlink($realPath);
        return false;
    }
    return $realPath;
}

/** Inspect the configured GitHub latest release; all downloaded bytes are temporary. */
function portal_release_check_latest(string $applicationRoot): array
{
    $configuration = portal_release_configuration();
    if (($configuration['ok'] ?? false) !== true) {
        return ['ok' => false, 'code' => (string) ($configuration['code'] ?? 'configuration_invalid')];
    }
    if (!class_exists(ZipArchive::class)) {
        return ['ok' => false, 'code' => 'zip_extension_unavailable'];
    }
    if (!function_exists('openssl_verify') || !function_exists('openssl_pkey_get_public')) {
        return ['ok' => false, 'code' => 'openssl_unavailable'];
    }

    $applicationRootReal = realpath($applicationRoot);
    if ($applicationRootReal === false || is_link($applicationRoot) || !is_dir($applicationRootReal)) {
        return ['ok' => false, 'code' => 'application_root_unavailable'];
    }
    $release = portal_release_fetch_latest_metadata($configuration);
    if (($release['ok'] ?? false) !== true) {
        return ['ok' => false, 'code' => (string) ($release['code'] ?? 'github_response_invalid')];
    }
    $zipPath = portal_release_create_temp_file($applicationRootReal);
    if (!is_string($zipPath)) {
        return ['ok' => false, 'code' => 'temporary_storage_unavailable'];
    }
    try {
        $download = portal_release_download_asset($release['asset_url'], $zipPath, $release['asset_size']);
        if (($download['ok'] ?? false) !== true) {
            return ['ok' => false, 'code' => (string) ($download['code'] ?? 'download_failed')];
        }
        $comparison = portal_release_compare_zip_files($applicationRootReal, $zipPath, $configuration['public_key_pem']);
        if (($comparison['ok'] ?? false) !== true) {
            return ['ok' => false, 'code' => (string) ($comparison['code'] ?? 'comparison_failed')];
        }
        return [
            'ok' => true,
            'code' => 'release_checked',
            'tag' => $release['tag'],
            'version' => $comparison['version'],
            'manifest_sha256' => $comparison['manifest_sha256'],
            'changed' => $comparison['changed'],
            'unchanged' => $comparison['unchanged'],
            'release_id' => $release['release_id'],
            'asset_size' => $release['asset_size'],
        ];
    } finally {
        @unlink($zipPath);
    }
}

/** Create a random private transaction directory outside both app and storage. */
function portal_release_create_transaction_directory(string $applicationRootReal): string|false
{
    $temporaryRoot = realpath(sys_get_temp_dir());
    if ($temporaryRoot === false || is_link(sys_get_temp_dir())) {
        return false;
    }
    $appPrefix = rtrim($applicationRootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $temporaryPrefix = rtrim($temporaryRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($temporaryRoot === $applicationRootReal || str_starts_with($temporaryRoot, $appPrefix)) {
        return false;
    }
    try {
        $directory = $temporaryPrefix . 'tadega-release-txn-' . bin2hex(random_bytes(16));
    } catch (Throwable) {
        return false;
    }
    $oldUmask = umask(0077);
    $created = @mkdir($directory, 0700);
    umask($oldUmask);
    if (!$created || is_link($directory)) {
        return false;
    }
    if (!@chmod($directory, 0700)) {
        @rmdir($directory);
        return false;
    }
    $permissions = @fileperms($directory);
    $realDirectory = realpath($directory);
    if (
        !is_int($permissions) || ($permissions & 0077) !== 0 ||
        $realDirectory === false || !str_starts_with($realDirectory, $temporaryPrefix) ||
        str_starts_with($realDirectory, $appPrefix)
    ) {
        @rmdir($directory);
        return false;
    }
    return $realDirectory;
}

function portal_release_remove_tree(string $path): bool
{
    if (is_link($path) || is_file($path)) {
        return @unlink($path);
    }
    if (!is_dir($path)) {
        return true;
    }
    $entries = @scandir($path);
    if (!is_array($entries)) {
        return false;
    }
    $success = true;
    foreach ($entries as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $success = portal_release_remove_tree($path . DIRECTORY_SEPARATOR . $entry) && $success;
        }
    }
    return @rmdir($path) && $success;
}

function portal_release_create_private_subdirectory(string $parent, string $name): string|false
{
    if (!in_array($name, ['stage', 'backup'], true) || is_link($parent) || !is_dir($parent)) {
        return false;
    }
    $path = $parent . DIRECTORY_SEPARATOR . $name;
    $oldUmask = umask(0077);
    $created = @mkdir($path, 0700);
    umask($oldUmask);
    if (!$created || is_link($path) || !is_dir($path) || !@chmod($path, 0700)) {
        return false;
    }
    $permissions = @fileperms($path);
    if (!is_int($permissions) || ($permissions & 0077) !== 0) {
        return false;
    }
    return realpath($path) ?: false;
}

function portal_release_write_zip_stream(ZipArchive $zip, int $entryIndex, string $destination, int $expectedSize, string $expectedHash): bool
{
    $stream = $zip->getStream((string) $zip->getNameIndex($entryIndex));
    if (!is_resource($stream)) {
        return false;
    }
    $directory = dirname($destination);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        fclose($stream);
        return false;
    }
    if (is_link($directory) || file_exists($destination) || is_link($destination)) {
        fclose($stream);
        return false;
    }
    $output = @fopen($destination, 'xb');
    if (!is_resource($output)) {
        fclose($stream);
        return false;
    }
    @chmod($destination, 0600);
    $context = hash_init('sha256');
    $writtenBytes = 0;
    $valid = true;
    while (!feof($stream)) {
        $chunk = fread($stream, 8192);
        if (!is_string($chunk)) {
            $valid = false;
            break;
        }
        if ($chunk === '') {
            if (feof($stream)) {
                break;
            }
            $valid = false;
            break;
        }
        $writtenBytes += strlen($chunk);
        if ($writtenBytes > $expectedSize || $writtenBytes > 52428800) {
            $valid = false;
            break;
        }
        hash_update($context, $chunk);
        $offset = 0;
        $chunkLength = strlen($chunk);
        while ($offset < $chunkLength) {
            $count = fwrite($output, substr($chunk, $offset));
            if (!is_int($count) || $count <= 0) {
                $valid = false;
                break 2;
            }
            $offset += $count;
        }
    }
    fflush($output);
    fclose($output);
    fclose($stream);
    $actualHash = hash_final($context);
    if (!$valid || $writtenBytes !== $expectedSize || !hash_equals($expectedHash, $actualHash)) {
        @unlink($destination);
        return false;
    }
    return true;
}

/** Lock a pre-existing, non-updatable portal guard file without writing to it. */
function portal_release_acquire_install_lock(string $applicationRootReal): mixed
{
    $lockAnchor = $applicationRootReal . DIRECTORY_SEPARATOR . '.htaccess';
    if (is_link($lockAnchor) || !is_file($lockAnchor) || !is_readable($lockAnchor)) {
        return false;
    }
    $handle = @fopen($lockAnchor, 'rb');
    if (!is_resource($handle)) {
        return false;
    }
    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return false;
    }
    return $handle;
}

/** Validate all changed targets are writable before making any application change. */
function portal_release_preflight_install_targets(string $applicationRootReal, array $plan): array
{
    $states = [];
    foreach ($plan['changed'] as $relativePath) {
        if (!is_string($relativePath) || !portal_release_path_is_allowed($relativePath)) {
            return ['ok' => false, 'code' => 'manifest_invalid', 'states' => []];
        }
        $target = portal_release_resolve_target($applicationRootReal, $relativePath);
        if (($target['ok'] ?? false) !== true) {
            return ['ok' => false, 'code' => (string) ($target['code'] ?? 'target_invalid'), 'states' => []];
        }
        $targetPath = $target['path'];
        $parent = dirname($targetPath);
        if (!is_dir($parent) || is_link($parent) || !is_writable($parent)) {
            return ['ok' => false, 'code' => 'target_directory_not_writable', 'states' => []];
        }
        $existed = file_exists($targetPath);
        $mode = 0644;
        $owner = null;
        $group = null;
        if ($existed) {
            if (!is_file($targetPath) || !is_writable($targetPath)) {
                return ['ok' => false, 'code' => 'target_file_not_writable', 'states' => []];
            }
            $permissions = @fileperms($targetPath);
            $owner = @fileowner($targetPath);
            $group = @filegroup($targetPath);
            if (!is_int($permissions) || $permissions < 0) {
                return ['ok' => false, 'code' => 'target_permissions_unavailable', 'states' => []];
            }
            $mode = $permissions & 0777;
            if ($mode === 0) {
                return ['ok' => false, 'code' => 'target_permissions_invalid', 'states' => []];
            }
            $beforeHash = $plan['before'][$relativePath] ?? null;
            if (!is_string($beforeHash) || preg_match('/\A[a-f0-9]{64}\z/D', $beforeHash) !== 1) {
                return ['ok' => false, 'code' => 'target_changed_since_check', 'states' => []];
            }
            $currentHash = @hash_file('sha256', $targetPath);
            if (!is_string($currentHash) || !hash_equals($beforeHash, $currentHash)) {
                return ['ok' => false, 'code' => 'target_changed_since_check', 'states' => []];
            }
        } elseif (array_key_exists($relativePath, $plan['before'] ?? []) && $plan['before'][$relativePath] !== null) {
            return ['ok' => false, 'code' => 'target_changed_since_check', 'states' => []];
        }
        $states[] = [
            'path' => $relativePath,
            'target' => $targetPath,
            'existed' => $existed,
            'mode' => $mode,
            'owner' => is_int($owner) ? $owner : null,
            'group' => is_int($group) ? $group : null,
            'before_hash' => $existed ? (string) $plan['before'][$relativePath] : null,
        ];
    }
    return ['ok' => true, 'code' => 'preflight_ok', 'states' => $states];
}

/** Create or verify private nested directories beneath the transaction backup root. */
function portal_release_ensure_private_directory_tree(string $privateRoot, string $relativeDirectory): string|false
{
    if (strlen($relativeDirectory) > 240 || str_contains($relativeDirectory, "\0") || str_contains($relativeDirectory, chr(92)) || str_starts_with($relativeDirectory, '/') || preg_match('/\\A[A-Za-z]:/', $relativeDirectory) === 1) {
        return false;
    }
    $segments = $relativeDirectory === '' ? [] : explode('/', $relativeDirectory);
    if (count($segments) > 16) {
        return false;
    }
    $protectedNames = ['storage', 'logs', 'pdf', 'svg', 'uploads', 'backups', 'data', '.git'];
    foreach ($segments as $segment) {
        if (
            $segment === '' || $segment === '.' || $segment === '..' || strlen($segment) > 100 ||
            str_starts_with($segment, '.') ||
            preg_match('/\\A[A-Za-z0-9][A-Za-z0-9._-]*\\z/D', $segment) !== 1 ||
            in_array(strtolower($segment), $protectedNames, true)
        ) {
            return false;
        }
    }

    $rootReal = realpath($privateRoot);
    if ($rootReal === false || is_link($privateRoot) || !is_dir($rootReal)) {
        return false;
    }
    $rootPermissions = @fileperms($rootReal);
    if (!is_int($rootPermissions) || ($rootPermissions & 0077) !== 0) {
        return false;
    }
    $rootPrefix = rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $cursor = $rootReal;
    foreach ($segments as $segment) {
        $next = $cursor . DIRECTORY_SEPARATOR . $segment;
        if (is_link($next) || (file_exists($next) && !is_dir($next))) {
            return false;
        }
        if (!is_dir($next)) {
            $oldUmask = umask(0077);
            $created = @mkdir($next, 0700);
            umask($oldUmask);
            if (!$created && !is_dir($next)) {
                return false;
            }
        }
        if (is_link($next) || !is_dir($next) || !@chmod($next, 0700)) {
            return false;
        }
        $realDirectory = realpath($next);
        $permissions = @fileperms($next);
        if (
            $realDirectory === false || !str_starts_with(rtrim($realDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, $rootPrefix) ||
            !is_int($permissions) || ($permissions & 0077) !== 0
        ) {
            return false;
        }
        $cursor = $realDirectory;
    }
    return $cursor;
}

function portal_release_copy_backup(string $source, string $backupRoot, string $relativePath, string $expectedHash): bool
{
    if (!portal_release_path_is_allowed($relativePath)) {
        return false;
    }
    $relativeDirectory = dirname($relativePath);
    $backupParent = portal_release_ensure_private_directory_tree($backupRoot, $relativeDirectory === '.' ? '' : $relativeDirectory);
    if (!is_string($backupParent)) {
        return false;
    }
    $destination = $backupParent . DIRECTORY_SEPARATOR . basename($relativePath);
    if (is_link($source) || !is_file($source) || is_link($destination) || file_exists($destination)) {
        return false;
    }
    $size = @filesize($source);
    if (!is_int($size) || $size < 0 || $size > 52428800 || !@copy($source, $destination)) {
        return false;
    }
    if (!@chmod($destination, 0600)) {
        @unlink($destination);
        return false;
    }
    $permissions = @fileperms($destination);
    $hash = @hash_file('sha256', $destination);
    if (!is_int($permissions) || ($permissions & 0077) !== 0 || !is_string($hash) || !hash_equals($expectedHash, $hash)) {
        @unlink($destination);
        return false;
    }
    return true;
}

function portal_release_write_staged_target(array $state, string $stagedPath): bool
{
    $target = $state['target'];
    $parent = dirname($target);
    if (is_link($parent) || !is_dir($parent) || !is_writable($parent) || is_link($target)) {
        return false;
    }
    if (($state['existed'] ?? false) === true) {
        if (!is_file($target) || !is_writable($target)) {
            return false;
        }
        $currentHash = @hash_file('sha256', $target);
        if (!is_string($currentHash) || !hash_equals((string) $state['before_hash'], $currentHash)) {
            return false;
        }
        $owner = @fileowner($target);
        $group = @filegroup($target);
        if ((is_int($state['owner']) && $owner !== $state['owner']) || (is_int($state['group']) && $group !== $state['group'])) {
            return false;
        }
    } elseif (file_exists($target)) {
        return false;
    }

    $temporaryFile = @tempnam($parent, '.tadega-release-');
    if (!is_string($temporaryFile) || $temporaryFile === '' || is_link($temporaryFile)) {
        return false;
    }
    $ok = @copy($stagedPath, $temporaryFile);
    if ($ok) {
        $temporaryOwner = @fileowner($temporaryFile);
        $temporaryGroup = @filegroup($temporaryFile);
        if (
            (is_int($state['owner']) && is_int($temporaryOwner) && $state['owner'] !== $temporaryOwner) ||
            (is_int($state['group']) && is_int($temporaryGroup) && $state['group'] !== $temporaryGroup)
        ) {
            $ok = false;
        }
    }
    if ($ok) {
        $ok = @chmod($temporaryFile, (int) $state['mode']);
    }
    if ($ok) {
        $ok = @rename($temporaryFile, $target);
    }
    if (!$ok) {
        @unlink($temporaryFile);
        return false;
    }
    return true;
}

function portal_release_rollback_targets(array $applied, string $transactionDirectory): bool
{
    $allRestored = true;
    foreach (array_reverse($applied) as $state) {
        $target = $state['target'];
        if (($state['existed'] ?? false) !== true) {
            if (file_exists($target) || is_link($target)) {
                $allRestored = @unlink($target) && $allRestored;
            }
            continue;
        }
        $backup = $transactionDirectory . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $state['path']);
        $parent = dirname($target);
        if (!is_file($backup) || is_link($backup) || !is_dir($parent) || is_link($parent)) {
            $allRestored = false;
            continue;
        }
        $restoreTemp = @tempnam($parent, '.tadega-rollback-');
        if (!is_string($restoreTemp) || $restoreTemp === '' || is_link($restoreTemp)) {
            $allRestored = false;
            continue;
        }
        $restored = @copy($backup, $restoreTemp)
            && @chmod($restoreTemp, (int) $state['mode'])
            && @rename($restoreTemp, $target);
        if (!$restored) {
            @unlink($restoreTemp);
            $allRestored = false;
            continue;
        }
        $restoredHash = @hash_file('sha256', $target);
        if (!is_string($restoredHash) || !hash_equals((string) $state['before_hash'], $restoredHash)) {
            $allRestored = false;
        }
    }
    return $allRestored;
}

/** Install one already-downloaded package only after an explicit version+manifest approval. */
function portal_release_install_package(
    string $applicationRoot,
    string $zipPath,
    string $publicKeyPem,
    string $approvedTag,
    string $approvedVersion,
    string $approvedManifestSha256,
    string $mode = 'signed',
    ?array $reviewedBefore = null
): array {
    $failure = static function (string $code, array $extra = []): array {
        return array_merge(['ok' => false, 'code' => $code, 'changed' => []], $extra);
    };
    if (
        preg_match('/\A[A-Za-z0-9][A-Za-z0-9.+_-]{0,119}\z/D', $approvedTag) !== 1 ||
        preg_match('/\A[A-Za-z0-9][A-Za-z0-9.+_-]{0,79}\z/D', $approvedVersion) !== 1 ||
        preg_match('/\A[a-f0-9]{64}\z/D', $approvedManifestSha256) !== 1
    ) {
        return $failure('approval_invalid');
    }
    if (!in_array($mode, ['signed', 'github'], true) || ($mode === 'github' && (!function_exists('portal_github_release_compare') || !is_array($reviewedBefore) || $approvedTag !== $approvedVersion))) return $failure('approval_invalid');
    $applicationRootReal = realpath($applicationRoot);
    if ($applicationRootReal === false || is_link($applicationRoot) || !is_dir($applicationRootReal)) {
        return $failure('application_root_unavailable');
    }
    $lock = portal_release_acquire_install_lock($applicationRootReal);
    if (!is_resource($lock)) {
        return $failure('update_lock_unavailable');
    }

    $transactionDirectory = false;
    $keepRecoverySnapshot = false;
    $applied = [];
    try {
        $comparison = $mode === 'github' ? portal_github_release_compare($applicationRootReal, $zipPath, $approvedTag) : portal_release_compare_zip_files($applicationRootReal, $zipPath, $publicKeyPem);
        if (($comparison['ok'] ?? false) !== true) {
            return $failure((string) ($comparison['code'] ?? 'comparison_failed'));
        }
        if (
            !hash_equals($approvedVersion, (string) $comparison['version']) ||
            !hash_equals($approvedManifestSha256, (string) $comparison['manifest_sha256']) ||
            ($mode === 'github' && $reviewedBefore !== $comparison['before'])
        ) {
            return $failure('approval_mismatch');
        }
        if ($comparison['changed'] === []) {
            return ['ok' => true, 'code' => 'already_current', 'version' => $comparison['version'], 'changed' => [], 'unchanged' => $comparison['unchanged']];
        }

        $preflight = portal_release_preflight_install_targets($applicationRootReal, $comparison);
        if (($preflight['ok'] ?? false) !== true) {
            return $failure((string) ($preflight['code'] ?? 'preflight_failed'));
        }
        $transactionDirectory = portal_release_create_transaction_directory($applicationRootReal);
        if (!is_string($transactionDirectory)) {
            return $failure('temporary_storage_unavailable');
        }
        $stageRoot = portal_release_create_private_subdirectory($transactionDirectory, 'stage');
        $backupRoot = portal_release_create_private_subdirectory($transactionDirectory, 'backup');
        if (!is_string($stageRoot) || !is_string($backupRoot)) {
            return $failure('temporary_storage_unavailable');
        }

        $statesForApply = [];
        $expectedHashes = [];
        $zip = new ZipArchive();
        if ($zip->open(realpath($zipPath) ?: $zipPath, ZipArchive::RDONLY) !== true) {
            return $failure('zip_invalid');
        }
        try {
            $verifiedZip = $mode === 'github' ? portal_github_release_read_zip($zip, $approvedTag) : portal_release_read_verified_zip($zip, $publicKeyPem);
            if (
                ($verifiedZip['ok'] ?? false) !== true ||
                !hash_equals((string) $comparison['manifest_sha256'], (string) ($verifiedZip['manifest_sha256'] ?? '')) ||
                !hash_equals((string) $comparison['version'], (string) ($verifiedZip['manifest']['version'] ?? ''))
            ) {
                return $failure('package_changed_during_install');
            }

            $manifestEntriesByPath = [];
            foreach ($verifiedZip['manifest']['files'] as $entry) {
                $manifestEntriesByPath[$entry['path']] = $entry;
            }
            $states = $preflight['states'];
            foreach ($states as $state) {
                $relativePath = $state['path'];
                $manifestEntry = $manifestEntriesByPath[$relativePath] ?? null;
                $entryIndex = $verifiedZip['indices'][$relativePath] ?? null;
                if (!is_array($manifestEntry) || !is_int($entryIndex)) {
                    return $failure('package_changed_during_install');
                }
                $stagedPath = $stageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
                if (!portal_release_write_zip_stream($zip, $entryIndex, $stagedPath, $manifestEntry['size'], $manifestEntry['sha256'])) {
                    return $failure('staging_failed');
                }
                if ($state['existed'] && !portal_release_copy_backup($state['target'], $backupRoot, $relativePath, (string) $state['before_hash'])) {
                    return $failure('backup_failed');
                }
                $state['_staged_path'] = $stagedPath;
                $statesForApply[] = $state;
                $expectedHashes[$relativePath] = $manifestEntry['sha256'];
            }
        } finally {
            $zip->close();
        }

        foreach ($statesForApply as $state) {
            if (!portal_release_write_staged_target($state, $state['_staged_path'])) {
                throw new RuntimeException('install_write_failed');
            }
            $applied[] = $state;
            $installedHash = @hash_file('sha256', $state['target']);
            $expectedHash = $expectedHashes[$state['path']] ?? '';
            if (!is_string($installedHash) || !is_string($expectedHash) || !hash_equals($expectedHash, $installedHash)) {
                throw new RuntimeException('install_verify_failed');
            }
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($state['target'], true);
            }
        }

        return [
            'ok' => true,
            'code' => 'installed',
            'version' => $comparison['version'],
            'changed' => $comparison['changed'],
            'unchanged' => $comparison['unchanged'],
        ];
    } catch (Throwable) {
        if ($applied !== []) {
            $rolledBack = is_string($transactionDirectory)
                && portal_release_rollback_targets($applied, $transactionDirectory);
            if (!$rolledBack) {
                $keepRecoverySnapshot = true;
                return $failure('rollback_failed', ['recovery_snapshot_retained' => true]);
            }
            return $failure('install_failed_rolled_back');
        }
        return $failure('install_failed_no_changes');
    } finally {
        if (is_resource($lock)) {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
        if (is_string($transactionDirectory) && !$keepRecoverySnapshot) {
            portal_release_remove_tree($transactionDirectory);
        }
    }
}

/** Re-fetch the latest release at approval time, match what was reviewed, then install. */
function portal_release_install_approved(
    string $applicationRoot,
    string $approvedTag,
    string $approvedVersion,
    string $approvedManifestSha256
): array {
    $configuration = portal_release_configuration();
    if (($configuration['ok'] ?? false) !== true) {
        return ['ok' => false, 'code' => (string) ($configuration['code'] ?? 'configuration_invalid')];
    }
    if (!class_exists(ZipArchive::class) || !function_exists('openssl_verify')) {
        return ['ok' => false, 'code' => !class_exists(ZipArchive::class) ? 'zip_extension_unavailable' : 'openssl_unavailable'];
    }
    $applicationRootReal = realpath($applicationRoot);
    if ($applicationRootReal === false || is_link($applicationRoot) || !is_dir($applicationRootReal)) {
        return ['ok' => false, 'code' => 'application_root_unavailable'];
    }
    $release = portal_release_fetch_latest_metadata($configuration);
    if (($release['ok'] ?? false) !== true) {
        return ['ok' => false, 'code' => (string) ($release['code'] ?? 'github_response_invalid')];
    }
    if (!hash_equals($approvedTag, (string) $release['tag'])) {
        return ['ok' => false, 'code' => 'release_changed_since_approval'];
    }
    $zipPath = portal_release_create_temp_file($applicationRootReal);
    if (!is_string($zipPath)) {
        return ['ok' => false, 'code' => 'temporary_storage_unavailable'];
    }
    try {
        $download = portal_release_download_asset($release['asset_url'], $zipPath, $release['asset_size']);
        if (($download['ok'] ?? false) !== true) {
            return ['ok' => false, 'code' => (string) ($download['code'] ?? 'download_failed')];
        }
        $result = portal_release_install_package(
            $applicationRootReal,
            $zipPath,
            $configuration['public_key_pem'],
            $approvedTag,
            $approvedVersion,
            $approvedManifestSha256
        );
        if (($result['ok'] ?? false) !== true) {
            return $result;
        }
        $result['tag'] = $release['tag'];
        return $result;
    } finally {
        @unlink($zipPath);
    }
}
