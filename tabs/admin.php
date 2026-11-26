<?php
declare(strict_types=1);

$centralAdminSetupState = is_string($centralAdminSetupState ?? null)
    ? $centralAdminSetupState
    : portal_central_admin_auth_setup_state();
$centralAdminUser = is_array($centralAdminUser ?? null) ? $centralAdminUser : null;
$invoiceAuthUser = is_array($invoiceAuthUser ?? null) ? $invoiceAuthUser : null;
$canEnrollCentralAdmin = $centralAdminSetupState === 'unconfigured'
    && is_array($invoiceAuthUser)
    && ($invoiceAuthUser['role'] ?? '') === 'admin'
    && ($invoiceAuthUser['auth_source'] ?? '') !== 'central_admin';
$centralAdminIsAuthenticated = is_array($centralAdminUser);
$centralAdminMustChangePin = $centralAdminIsAuthenticated
    && ($centralAdminUser['must_change_pin'] ?? false) === true;
$centralAdminPinPolicyUpdateRequired = $centralAdminIsAuthenticated
    && ($centralAdminUser['pin_policy_update_required'] ?? false) === true;
$releaseUpdateConfiguration = ['ok' => false, 'code' => 'configuration_missing'];
$releaseUpdateResult = null;
$releaseAutoStatus = null;
$releaseInstalledMarker = null;
if ($centralAdminIsAuthenticated && !$centralAdminMustChangePin && !$centralAdminPinPolicyUpdateRequired && function_exists('portal_github_release_check_latest')) {
    $releaseUpdateConfiguration = ['ok'=>true,'code'=>'ready'];
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && function_exists('portal_github_release_auto_status')) $releaseAutoStatus = portal_github_release_auto_status(dirname(__DIR__));
    if (function_exists('portal_github_release_install_marker')) $releaseInstalledMarker = portal_github_release_install_marker(dirname(__DIR__));
    $releaseCandidate = is_array($portalReleaseResult ?? null) ? $portalReleaseResult : null;
    if (is_array($releaseCandidate)) {
        $candidateAction = is_string($releaseCandidate['action'] ?? null) ? $releaseCandidate['action'] : '';
        $candidateCode = is_string($releaseCandidate['code'] ?? null) ? $releaseCandidate['code'] : '';
        if (in_array($candidateAction, ['check', 'install'], true) && preg_match('/\\A[a-z0-9_]{1,80}\\z/', $candidateCode) === 1) {
            $releaseUpdateResult = $releaseCandidate;
        }
    }
}

$adminHealthRows = [];
$adminHealthHasError = false;
$adminHealthHasWarning = false;
if ($centralAdminIsAuthenticated && !$centralAdminMustChangePin) {
    $addAdminHealthRow = static function (string $label, string $state, string $detail) use (&$adminHealthRows, &$adminHealthHasError, &$adminHealthHasWarning): void {
        $stateLabels = ['ok' => 'OK', 'info' => 'Information', 'warning' => 'Varning', 'error' => 'Åtgärd krävs'];
        if (!isset($stateLabels[$state])) {
            $state = 'error';
        }
        $adminHealthRows[] = ['label' => $label, 'state' => $state, 'state_label' => $stateLabels[$state], 'detail' => $detail];
        if ($state === 'error') {
            $adminHealthHasError = true;
        } elseif ($state === 'warning') {
            $adminHealthHasWarning = true;
        }
    };
    $hasDenyRule = static function (string $path): bool {
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            return false;
        }
        $contents = @file_get_contents($path);
        return is_string($contents) && preg_match('/^\\s*(?:Require\\s+all\\s+denied|Deny\\s+from\\s+all)\\b/im', $contents) === 1;
    };

    $projectRoot = realpath(dirname(__DIR__));
    $appRoot = $projectRoot !== false ? $projectRoot : dirname(__DIR__);
    $pdfRootPath = $appRoot . DIRECTORY_SEPARATOR . 'pdf';
    $pdfRootReal = realpath($pdfRootPath);
    $pdfRootReady = $projectRoot !== false
        && !is_link($pdfRootPath)
        && is_dir($pdfRootPath)
        && $pdfRootReal !== false
        && dirname($pdfRootReal) === $projectRoot
        && is_readable($pdfRootPath)
        && is_writable($pdfRootPath);
    $addAdminHealthRow(
        'PDF-arkivets rot',
        $pdfRootReady ? 'ok' : 'error',
        $pdfRootReady ? 'pdf/ finns och kan läsas och skrivas av portalen.' : 'pdf/ saknas, är osäker eller kan inte läsas och skrivas.'
    );
    $pdfGuardReady = $pdfRootReady && $hasDenyRule($pdfRootPath . DIRECTORY_SEPARATOR . '.htaccess');
    $addAdminHealthRow(
        'PDF-direktåtkomstskydd',
        $pdfGuardReady ? 'ok' : 'error',
        $pdfGuardReady ? 'pdf/.htaccess innehåller ett direktåtkomstförbud.' : 'Kontrollera pdf/.htaccess och se till att PDF-filer inte kan öppnas direkt via webbadress.'
    );

    $pdfArchiveFolders = [
        ['Originalmallar', 'mallar'],
        ['Skadeblanketter', 'skadeblanketter'],
        ['Överlåtelseavtal', 'overlatelseavtal'],
        ['Service', 'service'],
        ['Fakturaunderlag', 'fakturaunderlag'],
    ];
    foreach ($pdfArchiveFolders as [$folderLabel, $folderName]) {
        $folderPath = $pdfRootPath . DIRECTORY_SEPARATOR . $folderName;
        if (!$pdfRootReady) {
            $addAdminHealthRow('PDF-mapp · ' . $folderLabel, 'info', 'Kan inte kontrolleras innan pdf/ är tillgänglig.');
            continue;
        }
        if (is_link($folderPath) || (file_exists($folderPath) && !is_dir($folderPath))) {
            $addAdminHealthRow('PDF-mapp · ' . $folderLabel, 'error', 'Mappen är en länk eller har en annan filtyp än katalog.');
            continue;
        }
        if (!is_dir($folderPath)) {
            $addAdminHealthRow('PDF-mapp · ' . $folderLabel, 'info', 'Inte skapad ännu; skapas automatiskt vid första uppladdningen.');
            continue;
        }
        $folderReal = realpath($folderPath);
        $folderReady = $folderReal !== false
            && dirname($folderReal) === $pdfRootReal
            && is_readable($folderPath)
            && is_writable($folderPath);
        $addAdminHealthRow(
            'PDF-mapp · ' . $folderLabel,
            $folderReady ? 'ok' : 'error',
            $folderReady ? 'Mappen finns och kan läsas och skrivas.' : 'Kontrollera mappens placering och läs-/skrivrättigheter.'
        );
    }

    $logsPath = $appRoot . DIRECTORY_SEPARATOR . 'logs';
    $logsReal = realpath($logsPath);
    $logsReady = $projectRoot !== false && !is_link($logsPath) && is_dir($logsPath)
        && $logsReal !== false && dirname($logsReal) === $projectRoot
        && is_writable($logsPath) && $hasDenyRule($logsPath . DIRECTORY_SEPARATOR . '.htaccess');
    $addAdminHealthRow('Loggmapp', $logsReady ? 'ok' : 'error', $logsReady
        ? 'logs/ är skrivbar och har en nekande .htaccess. Kontrollera även webbserverns faktiska åtkomstskydd.'
        : 'logs/ eller dess skydd saknas eller är inte skrivbart. Kontrollera mapp, rättigheter och direktåtkomst.');

    $storagePath = $appRoot . DIRECTORY_SEPARATOR . 'storage';
    $storageReal = realpath($storagePath);
    $storageRootReady = $projectRoot !== false
        && !is_link($storagePath)
        && is_dir($storagePath)
        && $storageReal !== false
        && dirname($storageReal) === $projectRoot
        && is_readable($storagePath)
        && is_writable($storagePath);
    $addAdminHealthRow(
        'Privat lagring',
        $storageRootReady ? 'ok' : 'error',
        $storageRootReady ? 'storage/ finns och kan läsas och skrivas.' : 'storage/ saknas, är osäker eller kan inte läsas och skrivas.'
    );
    $storageGuardReady = $storageRootReady && $hasDenyRule($storagePath . DIRECTORY_SEPARATOR . '.htaccess');
    $addAdminHealthRow(
        'Lagringens direktåtkomstskydd',
        $storageGuardReady ? 'ok' : 'error',
        $storageGuardReady ? 'storage/.htaccess innehåller ett direktåtkomstförbud.' : 'Kontrollera storage/.htaccess så att privata data inte kan hämtas direkt via webbadress.'
    );

    $sessionPath = $storagePath . DIRECTORY_SEPARATOR . 'sessions';
    $sessionReal = realpath($sessionPath);
    $sessionsReady = $storageRootReady
        && !is_link($sessionPath)
        && is_dir($sessionPath)
        && $sessionReal !== false
        && dirname($sessionReal) === $storageReal
        && is_readable($sessionPath)
        && is_writable($sessionPath);
    $addAdminHealthRow(
        'Privat sessionsmapp',
        $sessionsReady ? 'ok' : 'error',
        $sessionsReady ? 'storage/sessions/ finns och är åtkomlig för portalen.' : 'Kontrollera storage/sessions/ och dess rättigheter via SFTP.'
    );

    $backupPath = $storagePath . DIRECTORY_SEPARATOR . 'backups';
    if (!$storageRootReady) {
        $backupState = 'error';
        $backupDetail = 'Kan inte kontrolleras eftersom storage/ inte är tillgänglig.';
    } elseif (is_link($backupPath) || (file_exists($backupPath) && !is_dir($backupPath))) {
        $backupState = 'error';
        $backupDetail = 'Säkerhetskopiemappen är en länk eller har en annan filtyp än katalog.';
    } elseif (!is_dir($backupPath)) {
        $backupState = 'info';
        $backupDetail = 'Inte skapad ännu; skapas när den första säkerhetskopian görs.';
    } else {
        $backupReal = realpath($backupPath);
        $backupReady = $backupReal !== false
            && dirname($backupReal) === $storageReal
            && is_readable($backupPath)
            && is_writable($backupPath);
        $backupState = $backupReady ? 'ok' : 'error';
        $backupDetail = $backupReady ? 'Privat säkerhetskopiemapp finns och är åtkomlig.' : 'Kontrollera mappens placering och läs-/skrivrättigheter.';
    }
    $addAdminHealthRow('Privat säkerhetskopiemapp', $backupState, $backupDetail);

    $emailTransportHealth = function_exists('portal_email_transport_status')
        ? portal_email_transport_status()
        : ['transport' => 'unavailable', 'configured' => false, 'tested' => false];
    $emailTransportType = is_string($emailTransportHealth['transport'] ?? null) ? $emailTransportHealth['transport'] : 'unavailable';
    if ($emailTransportType === 'smtp' && ($emailTransportHealth['configured'] ?? false) === true) {
        $smtpTested = ($emailTransportHealth['tested'] ?? false) === true;
        $addAdminHealthRow(
            'E-postkonfiguration',
            $smtpTested ? 'ok' : 'warning',
            $smtpTested
                ? 'SMTP-inställningen är giltig och markerad som testad. Inga servervärden eller hemligheter visas.'
                : 'SMTP-inställningen är giltig men behöver testas efter senaste ändringen. Inga servervärden eller hemligheter visas.'
        );
    } elseif ($emailTransportType === 'php_mail') {
        $addAdminHealthRow('E-postkonfiguration', 'warning', 'SMTP är inte konfigurerad; webbhotellets PHP mail()-reserv kan användas. Testa transporten från E-poststatus.');
    } else {
        $addAdminHealthRow('E-postkonfiguration', 'error', 'Transportinställningen kunde inte verifieras. Kontrollera privat lagring och E-poststatus; inga konfigurationsvärden visas.');
    }

    $emailStatusData = function_exists('portal_email_status_read') ? portal_email_status_read() : null;
    if (!is_array($emailStatusData)) {
        $addAdminHealthRow('Senaste e-posttest', 'error', 'Teststatus kunde inte läsas ur den privata statusfilen. Kontrollera storage/ och E-poststatus.');
    } else {
        $latestEmailTest = null;
        $latestEmailTestTimestamp = 0;
        foreach (['invoice', 'skadeblankett'] as $emailService) {
            $events = is_array($emailStatusData[$emailService]['events'] ?? null) ? $emailStatusData[$emailService]['events'] : [];
            foreach ($events as $event) {
                if (!is_array($event) || ($event['kind'] ?? '') !== 'test') {
                    continue;
                }
                $eventTimestamp = is_string($event['at'] ?? null) ? strtotime($event['at']) : false;
                $eventTimestamp = $eventTimestamp === false ? 0 : (int) $eventTimestamp;
                if ($latestEmailTest === null || $eventTimestamp >= $latestEmailTestTimestamp) {
                    $latestEmailTestTimestamp = $eventTimestamp;
                    $latestEmailTest = ['result' => (string) ($event['result'] ?? 'failed')];
                }
            }
        }
        if ($latestEmailTest === null) {
            $addAdminHealthRow('Senaste e-posttest', 'warning', 'Inget testresultat finns ännu. Kör ett avsiktligt test från Fakturaunderlag → E-poststatus.');
        } else {
            $testTimeLabel = $latestEmailTestTimestamp > 0 ? date('Y-m-d H:i', $latestEmailTestTimestamp) . ' (serverns tid)' : 'tidpunkt saknas';
            if ($latestEmailTest['result'] === 'accepted') {
                $addAdminHealthRow('Senaste e-posttest', 'ok', 'Transporten accepterade testanropet ' . $testTimeLabel . '. Det bekräftar inte leverans till inkorgen.');
            } else {
                $addAdminHealthRow('Senaste e-posttest', 'warning', 'Senaste testet accepterades inte eller inget utskick gjordes (' . $testTimeLabel . '). Kontrollera E-poststatus.');
            }
        }
    }
    $adminHealthSummary = $adminHealthHasError
        ? 'Åtgärd krävs'
        : ($adminHealthHasWarning ? 'Kontrollera status' : 'Kontrollerad');
}

$adminPages = [
    ['protocol', 'Serviceprotokoll', 'Skapa och hantera verkstadsprotokoll.'],
    ['search', 'Global sökning', 'Sök i register och dokument du har behörighet att se.'],
    ['invoice', 'Fakturaunderlag', 'Hantera underlag, artiklar, kunder och konton.', '?tab=invoice&invoice_view=admin'],
    ['skadeblankett', 'Skadeblankett', 'Skapa och hantera skadeblanketter i personalvyn.'],
    ['files', 'PDF-arkiv', 'Hantera arkiverade PDF-dokument.'],
    ['archive', 'Historik', 'Öppna portalens sparade dokumenthistorik.'],
    ['customers', 'Kundregister', 'Visa och hantera kunder med fakturabehörighet.', '?tab=invoice&invoice_view=customers'],
    ['settings', 'Inställningar', 'Hantera portalinställningar och driftverktyg.'],
];
$releaseResultAction = is_string($releaseUpdateResult['action'] ?? null) ? $releaseUpdateResult['action'] : '';
$releaseResultCode = is_string($releaseUpdateResult['code'] ?? null) ? $releaseUpdateResult['code'] : '';
$releaseResultTone = 'warning';
$releaseResultMessage = '';
$releaseChangedFiles = [];
$releaseChangedFileTotal = 0;
$releaseApprovedTag = is_string($releaseUpdateResult['tag'] ?? null) ? $releaseUpdateResult['tag'] : '';
$releaseApprovedVersion = is_string($releaseUpdateResult['version'] ?? null) ? $releaseUpdateResult['version'] : '';
$releaseCriticalFailure = $releaseResultAction === 'install' && $releaseResultCode === 'rollback_failed';
if (is_array($releaseUpdateResult)) {
    $releaseSucceeded = ($releaseUpdateResult['ok'] ?? false) === true;
    if ($releaseSucceeded && $releaseResultAction === 'check') {
        $releaseChangedFiles = is_array($releaseUpdateResult['changed'] ?? null) ? $releaseUpdateResult['changed'] : [];
        $releaseResultTone = $releaseChangedFiles === [] ? 'success' : 'warning';
        $releaseResultMessage = $releaseChangedFiles === []
            ? 'Senaste GitHub-releasen har jämförts. Inga tillåtna programfiler skiljer sig från de installerade filerna.'
            : 'GitHub-releasen har jämförts utan separat signatur. Ingen fil har ändrats; använd uppdateringsknappen för att installera.';
    } elseif ($releaseSucceeded && $releaseResultAction === 'install' && $releaseResultCode === 'installed') {
        $releaseChangedFiles = is_array($releaseUpdateResult['changed'] ?? null) ? $releaseUpdateResult['changed'] : [];
        $releaseResultTone = 'success';
        $releaseResultMessage = 'Uppdateringen installerades. Endast de listade filer som skiljde sig skrevs.';
    } elseif ($releaseSucceeded && $releaseResultAction === 'install' && $releaseResultCode === 'already_current') {
        $releaseResultTone = 'success';
        $releaseResultMessage = 'Den godkända releasen matchar redan de installerade filerna. Inga filer skrevs.';
    } else {
        $releaseErrorMessages = [
            'configuration_incomplete' => 'GitHub-repo, release-ZIP-filnamn och betrodd publik signeringsnyckel måste anges innan någon releasekontroll kan köras.',
            'configuration_invalid' => 'Den lokala releasekonfigurationen är ogiltig. Ingen nätverkskontroll eller filändring gjordes.',
            'curl_unavailable' => 'PHP cURL saknas på servern. Releasekontrollen är stoppad och har ingen osäker reservmetod.',
            'zip_extension_unavailable' => 'PHP ZipArchive saknas på servern. Ingen releasefil kan behandlas säkert.',
            'openssl_unavailable', 'verification_unavailable' => 'OpenSSL-signaturverifiering saknas på servern. Ingen release accepteras utan den.',
            'no_published_release' => 'Repo:t har ingen publicerad stabil GitHub-release ännu.',
            'github_rate_limited_or_forbidden' => 'GitHub nekade releasekontrollen eller API-gränsen har nåtts. Försök senare.',
            'release_asset_missing' => 'Den konfigurerade release-ZIP-filen saknas i senaste releasen.',
            'release_asset_ambiguous' => 'Releasen innehåller flera matchande ZIP-filer; kontrollera assetnamnet.',
            'release_asset_url_invalid', 'download_host_rejected' => 'Nedladdningsadressen eller en omdirigering klarade inte HTTPS-/GitHub-kontrollen.',
            'signature_invalid' => 'Releasemanifestets signatur är ogiltig. Inga filer ändrades.',
            'public_key_invalid', 'public_key_weak_or_unsupported' => 'Den lokala publika verifieringsnyckeln är ogiltig eller för svag.',
            'manifest_invalid', 'manifest_duplicate_path', 'manifest_too_large', 'zip_path_forbidden', 'zip_directory_forbidden', 'zip_manifest_mismatch', 'zip_special_file_forbidden' => 'Releasepaketets manifest eller sökvägar är ogiltiga. Inga filer ändrades.',
            'zip_file_hash_mismatch' => 'Minst en ZIP-fil stämmer inte med jämförelsens SHA-256. Inga filer ändrades.',
            'temporary_storage_unavailable' => 'Servern saknar en säker tillfällig arbetsyta utanför portalens program- och datakataloger.',
            'target_directory_not_writable', 'target_file_not_writable' => 'Servern saknar skrivbehörighet för minst en ändrad programfil. Inga filer installerades.',
            'target_parent_missing' => 'En målmapp saknas. Uppdateraren skapar inte nya mappar automatiskt.',
            'target_symlink', 'target_path_escape', 'target_path_conflict' => 'En målsökväg innehåller en länk eller en osäker sökväg. Installationen stoppades.',
            'target_changed_since_check' => 'En fil ändrades under uppdateringen. Försök igen.',
            'update_lock_unavailable' => 'En annan uppdatering pågår eller portalens låsfil kan inte läsas. Försök igen när den är klar.',
            'approval_required' => 'Installationen kräver en uttrycklig kryssrute-bekräftelse från admin.',
            'approval_invalid', 'approval_mismatch' => 'Godkännandet har gått ut eller stämmer inte med den granskade releasen eller filerna. Kontrollera releasen igen.',
            'release_changed_since_approval' => 'GitHub ändrade releasen under kontrollen. Ingen installation gjordes; försök igen.',
            'install_failed_no_changes' => 'Installationen stoppades innan någon programfil ändrades. Kontrollera serverns filrättigheter och försök igen efter manuell backup.',
            'install_failed_rolled_back' => 'Installationen misslyckades. Uppdateraren återställde och verifierade de filer den hunnit ersätta.',
            'rollback_failed' => 'KRITISKT: återställningen kunde inte verifieras. Sluta använda portalen och återställ från webbhotellets manuella säkerhetskopia innan fortsatt drift.',
            'backup_failed' => 'Den privata tillfälliga återställningskopian kunde inte verifieras. Inga programfiler installerades.',
            'staging_failed' => 'Releasefilen kunde inte förberedas och hashkontrolleras. Inga programfiler installerades.',
        ];
        $releaseResultMessage = $releaseErrorMessages[$releaseResultCode] ?? 'Releasekontrollen eller installationen stoppades säkert. Teknisk kod: ' . ($releaseResultCode !== '' ? $releaseResultCode : 'unknown');
        $releaseResultTone = $releaseResultCode === 'rollback_failed' ? 'error' : 'warning';
    }
    $releaseChangedFiles = array_values(array_filter($releaseChangedFiles, static fn ($path): bool => is_string($path) && portal_release_path_is_allowed($path)));
    $releaseChangedFileTotal = count($releaseChangedFiles);
}
?>
<style>
    .central-admin-page { display:grid; gap:18px; }
    .central-admin-hero { display:flex; justify-content:space-between; align-items:flex-start; gap:18px; padding:22px; border:1px solid #cfe2e8; border-radius:20px; background:radial-gradient(ellipse at 100% 0,rgba(17,132,150,.13),transparent 45%),linear-gradient(135deg,#fff,#f4fafb); box-shadow:0 12px 32px rgba(16,57,73,.07); }
    .central-admin-hero h2 { margin:0; color:#173747; font-size:1.22rem; letter-spacing:-.025em; }
    .central-admin-hero p { max-width:760px; margin:7px 0 0; color:#536b77; font-size:.87rem; }
    .central-admin-status { display:inline-flex; align-items:center; gap:8px; flex:0 0 auto; padding:8px 12px; border:1px solid #b8e5d0; border-radius:999px; background:#effaf3; color:#05603a; font-size:.75rem; font-weight:800; }
    .central-admin-status::before { content:""; width:8px; height:8px; border-radius:50%; background:#12a66a; box-shadow:0 0 0 4px rgba(18,166,106,.12); }
    .central-admin-status-warning { border-color:#fedf89; background:#fffaeb; color:#93370d; }
    .central-admin-status-warning::before { background:#dc6803; box-shadow:0 0 0 4px rgba(220,104,3,.12); }
    .central-admin-card { min-width:0; padding:clamp(18px,2.8vw,25px); border:1px solid #dbe7eb; border-radius:17px; background:#fff; box-shadow:0 9px 24px rgba(16,57,73,.055); }
    .central-admin-card h2 { margin:0; color:#173747; font-size:1rem; letter-spacing:-.02em; }
    .central-admin-card > p { margin:8px 0 0; color:#536b77; font-size:.83rem; line-height:1.55; }
    .central-admin-health-heading { display:flex; justify-content:space-between; align-items:flex-start; gap:14px; }
    .central-admin-health-heading p { max-width:760px; margin:6px 0 0; color:var(--color-muted); font-size:.78rem; line-height:1.5; }
    .central-admin-health-list { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:9px; margin:16px 0 0; padding:0; list-style:none; }
    .central-admin-health-item { display:flex; align-items:flex-start; gap:10px; min-width:0; padding:11px; border:1px solid var(--color-border); border-radius:12px; background:var(--color-surface); color:var(--color-ink); }
    .central-admin-health-mark { display:grid; flex:0 0 25px; width:25px; height:25px; place-items:center; border-radius:8px; background:var(--color-info-soft); color:var(--color-info); }
    .central-admin-health-mark .ui-icon { width:16px; height:16px; }
    .central-admin-health-item.is-ok .central-admin-health-mark { background:var(--color-success-soft); color:var(--color-success); }
    .central-admin-health-item.is-warning .central-admin-health-mark { background:var(--color-warning-soft); color:var(--color-warning); }
    .central-admin-health-item.is-error .central-admin-health-mark { background:var(--color-danger-soft); color:var(--color-danger); }
    .central-admin-health-copy { display:grid; gap:3px; min-width:0; }
    .central-admin-health-copy strong { color:var(--color-ink); font-size:.77rem; line-height:1.35; }
    .central-admin-health-copy small { color:var(--color-muted); font-size:.7rem; line-height:1.45; }
    .central-admin-health-state { color:var(--color-muted); font-size:.65rem; font-weight:800; text-transform:uppercase; letter-spacing:.05em; }
    .central-admin-health-footer { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:14px; }
    .central-admin-health-footer p { max-width:760px; margin:0; color:var(--color-muted); font-size:.7rem; line-height:1.5; }
    .central-admin-health-footer .btn { flex:0 0 auto; }
    .central-admin-layout { display:grid; grid-template-columns:minmax(0,1.2fr) minmax(290px,.8fr); gap:16px; align-items:start; }
    .central-admin-pages { display:grid; grid-template-columns:repeat(auto-fit,minmax(185px,1fr)); gap:11px; margin-top:16px; }
    .central-admin-page-link { display:flex; min-height:106px; flex-direction:column; justify-content:space-between; gap:12px; padding:15px; border:1px solid #e1eaed; border-radius:13px; background:linear-gradient(145deg,#fff,#f8fbfc); color:#173747; text-decoration:none; transition:transform .15s,border-color .15s,box-shadow .15s; }
    .central-admin-page-link:hover { transform:translateY(-2px); border-color:#a9d2da; box-shadow:0 8px 18px rgba(16,57,73,.08); }
    .central-admin-page-link strong { font-size:.87rem; }
    .central-admin-page-link span { color:#647b85; font-size:.75rem; line-height:1.4; }
    .central-admin-page-link b { align-self:flex-end; color:#0a7888; font-size:.77rem; }
    .central-admin-meta { display:grid; gap:8px; margin:15px 0 0; }
    .central-admin-meta-row { display:flex; justify-content:space-between; gap:12px; padding:9px 0; border-top:1px solid #eef2f4; color:#536b77; font-size:.77rem; }
    .central-admin-meta-row strong { color:#173747; text-align:right; }
    .central-admin-form { display:grid; gap:13px; margin-top:17px; }
    .central-admin-form label { display:block; margin-bottom:6px; color:#405b68; font-size:.78rem; font-weight:760; }
    .central-admin-form input { display:block; width:100%; min-height:45px; padding:10px 13px; border:1px solid #cfdee3; border-radius:10px; background:#fff; color:#173747; font:inherit; font-size:.93rem; }
    .central-admin-form input:focus { border-color:#78b3bf; outline:0; box-shadow:0 0 0 4px rgba(17,132,150,.14); }
    .central-admin-form .btn { width:100%; min-height:45px; margin-top:3px; }
    .central-admin-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .central-admin-help { margin:12px 0 0; color:#667d86; font-size:.75rem; line-height:1.5; }
    .central-admin-actions { display:flex; flex-wrap:wrap; gap:9px; margin-top:15px; }
    .central-admin-sftp { border-left:4px solid #0b7a72; }
    .central-admin-release { border:1px solid #dce1fb; border-top:4px solid #5b6fd8; background:linear-gradient(135deg,#fff 58%,#f8f8ff); }
    .central-admin-release-kicker { display:block; margin-bottom:5px; color:#4f5fc4; font-size:.66rem; font-weight:850; letter-spacing:.09em; text-transform:uppercase; }
    .central-admin-release-steps { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; margin:17px 0 0; padding:0; list-style:none; }
    .central-admin-release-steps li { display:grid; grid-template-columns:27px minmax(0,1fr); align-items:start; gap:9px; min-width:0; padding:12px; border:1px solid #e4e7f7; border-radius:12px; background:rgba(255,255,255,.82); }
    .central-admin-release-step-number { display:grid; width:26px; height:26px; place-items:center; border-radius:8px; background:#eeefff; color:#4f5fc4; font-size:.73rem; font-weight:850; }
    .central-admin-release-step-copy { display:grid; gap:3px; min-width:0; }
    .central-admin-release-step-copy strong { color:#25335d; font-size:.76rem; }
    .central-admin-release-step-copy small { color:#66728a; font-size:.69rem; line-height:1.45; }
    .central-admin-release-note { display:grid; grid-template-columns:28px minmax(0,1fr); gap:10px; margin-top:14px; padding:13px 14px; border:1px solid var(--color-warning-border); border-radius:12px; background:var(--color-warning-soft); }
    p.central-admin-release-note { display:block; overflow-wrap:anywhere; }
    .central-admin-release-note-mark { display:grid; width:25px; height:25px; place-items:center; border-radius:50%; background:#fff1c2; color:#8a4b08; font-size:.86rem; font-weight:900; }
    .central-admin-release-note-copy { display:grid; gap:3px; min-width:0; }
    .central-admin-release-note-copy strong { color:#7a2e0e; font-size:.78rem; }
    .central-admin-release-note-copy p { margin:0; color:#7d4b28; font-size:.75rem; line-height:1.5; }
    .central-admin-release-result { margin-top:14px; padding:14px; border:1px solid var(--color-border); border-radius:12px; background:var(--color-surface); color:var(--color-ink); }
    .central-admin-release-result.is-success { border-color:var(--color-success-border); background:var(--color-success-soft); }
    .central-admin-release-result.is-warning { border-color:var(--color-warning-border); background:var(--color-warning-soft); }
    .central-admin-release-result.is-error { border-color:var(--color-danger-border); background:var(--color-danger-soft); }
    .central-admin-release-result p { margin:0; color:inherit; font-size:.8rem; line-height:1.55; }
    .central-admin-release-result p + p { margin-top:7px; }
    .central-admin-release-meta { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px; margin:12px 0 0; }
    .central-admin-release-meta > div { min-width:0; padding:9px 11px; border:1px solid rgba(91,111,216,.16); border-radius:9px; background:rgba(255,255,255,.65); }
    .central-admin-release-meta dt { color:var(--color-muted); font-size:.66rem; font-weight:750; }
    .central-admin-release-meta dd { margin:4px 0 0; color:var(--color-ink); font-size:.78rem; font-weight:800; overflow-wrap:anywhere; }
    .central-admin-release-meta code { font-size:.74rem; }
    .central-admin-release-digest { margin-top:9px; }
    .central-admin-release-digest summary { color:var(--color-muted); cursor:pointer; font-size:.7rem; font-weight:700; }
    .central-admin-release-digest code { display:block; margin-top:7px; padding:8px; border:1px solid var(--color-border); border-radius:8px; background:rgba(255,255,255,.7); font-size:.68rem; overflow-wrap:anywhere; }
    .central-admin-release-file-plan { margin-top:12px; padding:12px; border:1px solid rgba(91,111,216,.18); border-radius:10px; background:rgba(255,255,255,.62); }
    .central-admin-release-file-plan > p { color:var(--color-ink); font-size:.76rem; }
    .central-admin-release-files { max-height:220px; overflow:auto; margin:8px 0 0; padding:0 0 0 20px; color:var(--color-muted); font: .72rem/1.55 ui-monospace,SFMono-Regular,Consolas,monospace; }
    .central-admin-release-approval { display:grid; gap:11px; margin-top:12px; padding:13px; border:1px solid rgba(91,111,216,.2); border-radius:10px; background:rgba(255,255,255,.78); }
    .central-admin-release-backup-note { padding:10px 11px; border-left:3px solid var(--color-warning); border-radius:0 8px 8px 0; background:var(--color-warning-soft); }
    .central-admin-release-backup-note strong { display:block; margin-bottom:3px; color:var(--color-ink); font-size:.75rem; }
    .central-admin-release-backup-note p { margin:0; color:var(--color-muted); font-size:.72rem; line-height:1.5; }
    .central-admin-release-confirm-title { margin:3px 0 0; color:#183c38; font-size:1rem; line-height:1.35; }
    .central-admin-release-approval label.central-admin-release-confirm { display:flex; align-items:flex-start; gap:15px; padding:16px; border:2px solid #0b7770; border-radius:12px; background:#eef9f5; color:#173b36; font-size:1rem; line-height:1.5; cursor:pointer; }
    .central-admin-release-confirm strong { display:block; margin-bottom:4px; color:#123c35; font-size:1.05rem; }
    .central-admin-release-approval input[type=checkbox] { flex:0 0 auto; width:27px; height:27px; margin:1px 0 0; accent-color:#08756b; cursor:pointer; }
    .central-admin-release-approval input[type=checkbox]:focus-visible { outline:3px solid #123c35; outline-offset:3px; }
    .central-admin-release-approval .central-admin-release-next { margin:2px 0 0; color:var(--color-ink); font-size:.9rem; font-weight:750; }
    .central-admin-release-approval .btn { width:100%; min-height:52px; font-size:1rem; }
    .central-admin-release ol { margin:12px 0 0; padding-left:21px; color:#536b77; font-size:.78rem; line-height:1.6; }
    .central-admin-sftp code { padding:2px 5px; border-radius:5px; background:#f1f6f7; color:#173747; font-size:.75rem; overflow-wrap:anywhere; }
    .central-admin-warning { border-color:#fedf89; background:#fffbef; }
    .central-admin-warning h2 { color:#7a2e0e; }
    .central-admin-warning p { color:#7d4b28; }
    .central-admin-account-note { padding:12px 14px; border:1px solid #dce9ed; border-radius:12px; background:#f6fafb; color:#536b77; font-size:.78rem; line-height:1.5; }
    @media (max-width:850px) { .central-admin-layout { grid-template-columns:1fr; } }
    @media (max-width:620px) { .central-admin-hero { flex-direction:column; padding:18px; } .central-admin-status { align-self:flex-start; } .central-admin-form-grid { grid-template-columns:1fr; } .central-admin-pages { grid-template-columns:1fr; } .central-admin-page-link { min-height:88px; } .central-admin-health-heading { flex-direction:column; } .central-admin-health-list { grid-template-columns:1fr; } .central-admin-health-footer { align-items:stretch; flex-direction:column; } .central-admin-release-steps,.central-admin-release-meta { grid-template-columns:1fr; } }
</style>

<section class="central-admin-page" aria-label="Central Adminpanel">
    <?php if ($centralAdminIsAuthenticated && !$centralAdminMustChangePin): ?>
        <header class="central-admin-hero">
            <div>
                <h2><?= ($portalLanguage ?? 'sv') === 'en' ? 'Central administrator access is active' : 'Central adminåtkomst är aktiv' ?></h2>
                <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'You are signed in as central administrator. The same PIN grants administrator access to staff pages. Customer standalone apps are excluded, and existing invoice and service report accounts remain unchanged.' : 'Du är inloggad som central administratör. Samma PIN ger adminbehörighet över personalsidorna. Kundernas fristående PWA:er hålls utanför och befintliga faktura- och protokollkonton finns kvar.' ?></p>
            </div>
            <span class="central-admin-status"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Admin active' : 'Admin aktiv' ?></span>
        </header>

        <?php $companySettings = function_exists('load_settings') ? load_settings() : []; ?>
        <section class="central-admin-card" aria-labelledby="company-settings-title">
            <h2 id="company-settings-title"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Company and damage forms' : 'Företag och skadeblanketter' ?></h2>
            <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'The company name appears in the portal header and invoice and service report views. The logo appears in the portal header, not in PDFs or standalone apps. Upload an SVG under Settings before selecting it here. No damage form email is sent without a recipient.' : 'Företagsnamnet visas i portalens sidhuvud och faktura- och protokollvyer. Logotypen visas i portalens sidhuvud, inte i PDF eller fristående appar. Ladda upp en SVG under Inställningar innan du väljer den här. Utan mottagare skickas inget skadeblankettmejl.' ?></p>
            <form method="post" action="?tab=admin" class="central-admin-form central-admin-form-grid">
                <input type="hidden" name="action" value="save_company_settings">
                <?= csrf_field() ?>
                <label><?= ($portalLanguage ?? 'sv') === 'en' ? 'Company name' : 'Företagsnamn' ?> <input class="settings-input" name="company_name" maxlength="100" value="<?= h($companySettings['company_name'] ?? '') ?>"></label>
                <label><?= ($portalLanguage ?? 'sv') === 'en' ? 'Logo' : 'Logotyp' ?> <select class="settings-input" name="company_logo" id="company-logo-select"><option value=""><?= ($portalLanguage ?? 'sv') === 'en' ? 'No logo' : 'Ingen logotyp' ?></option><?php
                    $logoFiles = is_link(__DIR__ . '/../svg') ? [] : (glob(__DIR__ . '/../svg/logo-*.svg') ?: []);
                    foreach ($logoFiles as $logoPath): $logoName = basename($logoPath); if (is_link($logoPath) || !is_file($logoPath) || !preg_match('/\Alogo-[a-f0-9]{16}\.svg\z/D', $logoName)) continue;
                ?><option value="<?= h($logoName) ?>" <?= ($companySettings['company_logo'] ?? '') === $logoName ? 'selected' : '' ?>><?= h($logoName) ?></option><?php endforeach; ?></select></label>
                <img id="company-logo-preview" alt="Förhandsvisning av vald logotyp" style="max-width:180px;max-height:80px;object-fit:contain" <?= !empty($companySettings['company_logo']) && is_string($companySettings['company_logo']) && preg_match('/\Alogo-[a-f0-9]{16}\.svg\z/D', $companySettings['company_logo']) ? 'src="?view_file=' . rawurlencode('svg/' . $companySettings['company_logo']) . '"' : 'hidden' ?>>
                <script>document.getElementById('company-logo-select').addEventListener('change',function(){var img=document.getElementById('company-logo-preview');img.hidden=!this.value;if(this.value)img.src='?view_file='+encodeURIComponent('svg/'+this.value);else img.removeAttribute('src')});</script>
                <label><?= ($portalLanguage ?? 'sv') === 'en' ? 'Damage form recipient' : 'Mottagare för skadeblanketter' ?> <input class="settings-input" type="email" name="skade_recipient_email" maxlength="254" value="<?= h($companySettings['skade_recipient_email'] ?? '') ?>"></label>
                <button class="btn btn-main" type="submit" <?= empty($csrfReady) ? 'disabled' : '' ?>><?= ($portalLanguage ?? 'sv') === 'en' ? 'Save company settings' : 'Spara företagsinställningar' ?></button>
            </form>
        </section>

        <section class="central-admin-card central-admin-health-card" aria-labelledby="central-admin-health-title">
            <div class="central-admin-health-heading">
                <div>
                    <h2 id="central-admin-health-title"><?= ($portalLanguage ?? 'sv') === 'en' ? 'System health' : 'Driftshälsa' ?></h2>
                    <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'Checks of PDF folders, private storage and email configuration. Results are a snapshot of technical status only.' : 'Kontroller av PDF-mappar, privat lagring och e-postkonfiguration. Resultaten är en ögonblicksbild och visar endast teknisk status.' ?></p>
                </div>
                <span class="central-admin-status <?= ($adminHealthHasError || $adminHealthHasWarning) ? 'central-admin-status-warning' : '' ?>"><?= h($adminHealthSummary) ?></span>
            </div>
            <ul class="central-admin-health-list">
                <?php foreach ($adminHealthRows as $healthRow): ?>
                    <li class="central-admin-health-item is-<?= h($healthRow['state']) ?>">
                        <span class="central-admin-health-mark" aria-hidden="true">
                            <svg class="ui-icon" viewBox="0 0 24 24" focusable="false">
                                <?php if ($healthRow['state'] === 'ok'): ?>
                                    <path d="m5 12 4 4L19 6"/>
                                <?php elseif ($healthRow['state'] === 'info'): ?>
                                    <circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>
                                <?php else: ?>
                                    <path d="M12 8v5M12 16h.01"/><path d="M10.3 3.9 2.6 17.2A2 2 0 0 0 4.3 20h15.4a2 2 0 0 0 1.7-2.8L13.7 3.9a2 2 0 0 0-3.4 0z"/>
                                <?php endif; ?>
                            </svg>
                        </span>
                        <span class="central-admin-health-copy">
                            <strong><?= h($healthRow['label']) ?></strong>
                            <span class="central-admin-health-state"><?= h($healthRow['state_label']) ?></span>
                            <small><?= h($healthRow['detail']) ?></small>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="central-admin-health-footer">
                <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'The check does not show server names, email addresses, usernames or secrets. An accepted email test does not confirm inbox delivery.' : 'Kontrollen visar inte servernamn, e-postadresser, användarnamn eller hemligheter. Ett accepterat e-posttest bekräftar inte leverans till inkorgen.' ?></p>
                <a class="btn btn-outline" href="?tab=invoice&amp;invoice_view=email">Öppna E-poststatus</a>
            </div>
        </section>

        <section class="central-admin-card central-admin-release" aria-labelledby="central-admin-release-title">
            <div class="central-admin-health-heading">
                <div>
                    <span class="central-admin-release-kicker">Endast central admin · uppdatera nu</span>
                    <h2 id="central-admin-release-title"><?= ($portalLanguage ?? 'sv') === 'en' ? 'GitHub release check' : 'GitHub-releasekontroll' ?></h2>
                    <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'One press checks and installs the latest stable release right away. The portal also updates itself from git at most once per hour, so this button only removes the wait.' : 'Ett knapptryck kontrollerar och installerar senaste stabila releasen direkt. Portalen uppdaterar sig dessutom själv från git högst en gång i timmen – knappen tar bara bort väntan.' ?></p>
                </div>
                <span class="central-admin-status <?= ($releaseUpdateConfiguration['ok'] ?? false) === true ? '' : 'central-admin-status-warning' ?>">
                    <?= ($releaseUpdateConfiguration['ok'] ?? false) === true ? 'Aktiv' : 'Avstängd' ?>
                </span>
            </div>

            <p><strong><?= ($portalLanguage ?? 'sv') === 'en' ? 'Take a separate backup before updating.' : 'Ta en separat backup före uppdatering.' ?></strong> <?= ($portalLanguage ?? 'sv') === 'en' ? 'The button installs without showing a file list first. Rollback is best effort and does not replace a backup.' : 'Knappen installerar utan att först visa en fillista. Återställning vid fel är inte garanterad och ersätter inte backup.' ?></p>

            <?php if (($releaseUpdateConfiguration['ok'] ?? false) !== true): ?>
                <div class="central-admin-release-note" role="status">
                    <span class="central-admin-release-note-mark" aria-hidden="true">!</span>
                    <div class="central-admin-release-note-copy"><strong><?= ($portalLanguage ?? 'sv') === 'en' ? 'Updater unavailable' : 'Uppdateraren är inte tillgänglig' ?></strong><p><?= ($portalLanguage ?? 'sv') === 'en' ? 'The update module is missing. No installation is possible.' : 'Uppdateringsmodulen saknas. Ingen installation är möjlig.' ?></p></div>
                </div>
            <?php else: ?>
                
                <p class="central-admin-release-note"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Source: Tokke2/document. No separate cryptographic signature is checked; only trust releases published by the authorized GitHub account. Hashes compare files and do not authenticate the publisher.' : 'Källa: Tokke2/document. Ingen separat signatur kontrolleras; lita endast på releaser från det behöriga GitHub-kontot. Hashar jämför filer men bevisar inte vem som publicerat dem.' ?></p>
                <?php if (is_array($releaseAutoStatus)): ?>
                    <p role="status"><?php if (($releaseAutoStatus['ok'] ?? false) === true && is_string($releaseAutoStatus['tag'] ?? null)): ?><?= ($portalLanguage ?? 'sv') === 'en' ? 'Last release check:' : 'Senaste releasekontroll:' ?> <strong><?= h($releaseAutoStatus['tag']) ?></strong><?php if (is_string($releaseAutoStatus['commit'] ?? null)): ?> · <code><?= h(substr($releaseAutoStatus['commit'],0,12)) ?></code><?php endif; ?><?php if (is_int($releaseAutoStatus['checked_at'] ?? null)): ?> · <?= h(gmdate('Y-m-d H:i', $releaseAutoStatus['checked_at'])) ?> UTC<?php endif; ?> · <?= ($portalLanguage ?? 'sv') === 'en' ? 'Checks may be cached for up to six hours. The update button checks again.' : 'Kontrollen kan vara upp till sex timmar gammal. Uppdateringsknappen kontrollerar på nytt.' ?><?php elseif (($releaseAutoStatus['code'] ?? '') === 'no_published_release'): ?><?= ($portalLanguage ?? 'sv') === 'en' ? 'No stable release was found at the last check.' : 'Ingen stabil release hittades vid senaste kontrollen.' ?><?php else: ?><?= ($portalLanguage ?? 'sv') === 'en' ? 'Automatic check unavailable. The update button retries and installs if successful.' : 'Automatisk kontroll kunde inte slutföras. Uppdateringsknappen försöker igen och installerar om kontrollen lyckas.' ?><?php endif; ?></p>
                <?php endif; ?>
                <?php if (is_array($releaseInstalledMarker)): ?><p><?= ($portalLanguage ?? 'sv') === 'en' ? 'Last installed or matched through this panel:' : 'Senast installerad eller matchad via panelen:' ?> <strong><?= h($releaseInstalledMarker['tag']) ?></strong><?php if (is_string($releaseInstalledMarker['commit'] ?? null)): ?> · <code><?= h(substr($releaseInstalledMarker['commit'],0,12)) ?></code><?php endif; ?><?php if (is_array($releaseAutoStatus) && portal_github_release_marker_differs($releaseAutoStatus,$releaseInstalledMarker)): ?><?php if (portal_github_release_status_predates_marker($releaseAutoStatus,$releaseInstalledMarker)): ?> · <?= ($portalLanguage ?? 'sv') === 'en' ? 'The cached check predates the last installation or match and may be out of date.' : 'Den sparade kontrollen är äldre än senaste installation eller matchning och kan vara inaktuell.' ?><?php else: ?> · <?= ($portalLanguage ?? 'sv') === 'en' ? 'A different release commit was reported at the last check, even if the version number is unchanged.' : 'En annan releasecommit rapporterades vid senaste kontrollen, även om versionsnumret är oförändrat.' ?><?php endif; ?><?php elseif (!is_string($releaseInstalledMarker['commit'] ?? null)): ?> · <?= ($portalLanguage ?? 'sv') === 'en' ? 'Use the update button to identify the installed commit.' : 'Använd uppdateringsknappen för att identifiera installerad commit.' ?><?php endif; ?></p><?php endif; ?>
                <?php if (is_array($releaseInstalledMarker)): ?>
                    <?php $lastReleaseMatchTime = portal_github_release_stockholm_time($releaseInstalledMarker['installed_at'] ?? null); $lastReleaseUpdateTime = portal_github_release_stockholm_time($releaseInstalledMarker['last_updated_at'] ?? null); ?>
                    <p><?php if ($lastReleaseUpdateTime !== null): ?><strong><?= ($portalLanguage ?? 'sv') === 'en' ? 'Last file update:' : 'Senast uppdaterad:' ?></strong> <?= h($lastReleaseUpdateTime) ?> (Europe/Stockholm)<?php else: ?><?= ($portalLanguage ?? 'sv') === 'en' ? 'The last actual update time is unknown; older versions did not distinguish installation from a match.' : 'Datum för faktisk uppdatering saknas; äldre versioner skilde inte på installation och matchning.' ?><?php endif; ?><?php if ($lastReleaseMatchTime !== null): ?> · <?= ($portalLanguage ?? 'sv') === 'en' ? 'Last installed or matched:' : 'Senast installerad eller matchad:' ?> <?= h($lastReleaseMatchTime) ?> (Europe/Stockholm)<?php endif; ?></p>
                <?php endif; ?>
                <?php if ($releaseCriticalFailure): ?>
                    <div class="central-admin-release-result is-error" role="alert">Återställningen misslyckades. Använd inte uppdateraren igen förrän du har återställt från webbhotellets kända säkerhetskopia och verifierat appen manuellt.</div>
                <?php else: ?>
                    <form class="central-admin-actions central-admin-release-one-click" method="post" action="?tab=admin">
                        <input type="hidden" name="action" value="portal_release_install_latest">
                        <?= csrf_field() ?>
                        <button class="btn btn-main" type="submit" <?= !$csrfReady ? 'disabled' : '' ?>><?= ($portalLanguage ?? 'sv') === 'en' ? 'Update the app now' : 'Uppdatera appen nu' ?></button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (is_array($releaseUpdateResult)): ?>
                <div class="central-admin-release-result is-<?= h($releaseResultTone) ?>" role="<?= $releaseResultTone === 'error' ? 'alert' : 'status' ?>" aria-live="polite">
                    <p><strong><?= h($releaseResultMessage) ?></strong></p>
                    <?php if (($releaseUpdateResult['ok'] ?? false) === true): ?>
                        <dl class="central-admin-release-meta">
                            <div><dt><?= ($portalLanguage ?? 'sv') === 'en' ? 'Release version' : 'Releaseversion' ?></dt><dd><?= h($releaseApprovedVersion) ?></dd></div>
                            <div><dt><?= ($portalLanguage ?? 'sv') === 'en' ? 'GitHub tag' : 'GitHub-tagg' ?></dt><dd><code><?= h($releaseApprovedTag) ?></code></dd></div>
                            <?php if (is_string($releaseUpdateResult['commit'] ?? null)): ?><div><dt>Commit</dt><dd><code><?= h($releaseUpdateResult['commit']) ?></code></dd></div><?php endif; ?>
                        </dl>
                        <?php if ($releaseResultAction === 'install' && $releaseResultCode === 'installed'): ?>
                            <p><?= h((string) $releaseChangedFileTotal) ?> <?= $releaseChangedFileTotal === 1 ? 'ändrad fil installerades.' : 'ändrade filer installerades.' ?> Identiska filer lämnades orörda.</p>
                            <?php if ($releaseChangedFileTotal > 0): ?>
                                <ul class="central-admin-release-files" aria-label="Installerade filer">
                                    <?php foreach ($releaseChangedFiles as $releasePath): ?><li><?= h($releasePath) ?></li><?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <div class="central-admin-layout">
            <section class="central-admin-card" aria-labelledby="central-admin-pages-title">
                <h2 id="central-admin-pages-title"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Portal staff pages' : 'Portalens personalsidor' ?></h2>
                <p>Öppna ett område. Adminrollen följer med mellan sidorna medan den centrala PIN-sessionen är aktiv.</p>
                <div class="central-admin-pages">
                    <?php foreach ($adminPages as $page): ?>
                        <?php
                        $pageTab = $page[0];
                        $pageHref = $page[3] ?? ('?tab=' . rawurlencode($pageTab));
                        ?>
                        <a class="central-admin-page-link" href="<?= h($pageHref) ?>">
                            <strong><?= h($page[1]) ?></strong>
                            <span><?= h($page[2]) ?></span>
                            <b>Öppna →</b>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="central-admin-page">
                <section class="central-admin-card" aria-labelledby="central-admin-session-title">
                    <h2 id="central-admin-session-title"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Administrator PIN and session' : 'Admin-PIN och session' ?></h2>
                    <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'The central PIN must be changed at least every 20 days. You can change it here at any time.' : 'Den centrala PIN:en måste bytas senast var 20:e dag. Du kan byta den när som helst här.' ?></p>
                    <div class="central-admin-meta">
                        <div class="central-admin-meta-row"><span><?= ($portalLanguage ?? 'sv') === 'en' ? 'Last changed' : 'Senast bytt' ?></span><strong><?= h(date('Y-m-d', (int) ($centralAdminUser['pin_changed_at'] ?? time()))) ?></strong></div>
                        <div class="central-admin-meta-row"><span><?= ($portalLanguage ?? 'sv') === 'en' ? 'Next change due' : 'Nästa byte senast' ?></span><strong><?= h(date('Y-m-d', (int) ($centralAdminUser['pin_changed_at'] ?? time()) + 20 * 86400)) ?></strong></div>
                    </div>
                    <form class="central-admin-form" method="post" action="?tab=admin" autocomplete="off">
                        <input type="hidden" name="action" value="central_admin_change_pin">
                        <?= csrf_field() ?>
                        <div><label for="central-admin-current-pin"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Current central PIN' : 'Nuvarande central PIN-kod' ?></label><input id="central-admin-current-pin" name="current_pin" type="password" maxlength="128" inputmode="text" autocomplete="off" required></div>
                        <div class="central-admin-form-grid">
                            <div><label for="central-admin-new-pin"><?= ($portalLanguage ?? 'sv') === 'en' ? 'New central PIN' : 'Ny central PIN-kod' ?></label><input id="central-admin-new-pin" name="new_pin" type="password" minlength="6" maxlength="12" pattern="[0-9!@#$%&amp;*+=_\-]{6,12}" inputmode="text" autocomplete="off" spellcheck="false" placeholder="6–10 siffror · upp till 2 symboler" required></div>
                            <div><label for="central-admin-confirm-pin"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Repeat new PIN' : 'Upprepa ny PIN-kod' ?></label><input id="central-admin-confirm-pin" name="pin_confirm" type="password" minlength="6" maxlength="12" pattern="[0-9!@#$%&amp;*+=_\-]{6,12}" inputmode="text" autocomplete="off" spellcheck="false" placeholder="<?= ($portalLanguage ?? 'sv') === 'en' ? 'Repeat PIN' : 'Upprepa PIN-koden' ?>" required></div>
                        </div>
                        <button class="btn btn-main" type="submit" <?= !$csrfReady ? 'disabled' : '' ?>><?= ($portalLanguage ?? 'sv') === 'en' ? 'Change central PIN' : 'Byt central PIN-kod' ?></button>
                    </form>
                    <p class="central-admin-help">PIN-koden ska innehålla 6–10 siffror och får ha högst 2 valfria specialtecken ur <code>!@#$%&amp;*+-_=</code>. Inga bokstäver eller mellanslag. För bäst skydd: använd 10 siffror och 2 symboler. PIN-koden lagras som envägshash och visas aldrig i gränssnittet eller loggen.</p>
                    <div class="central-admin-actions">
                        <form method="post" action="?tab=admin">
                            <input type="hidden" name="action" value="central_admin_logout">
                            <?= csrf_field() ?>
                            <button class="btn btn-outline" type="submit"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Sign out of central administration' : 'Logga ut från central admin' ?></button>
                        </form>
                    </div>
                </section>

                <section class="central-admin-card central-admin-sftp" aria-labelledby="central-admin-sftp-title">
                    <h2 id="central-admin-sftp-title"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Forgotten PIN? Reset via SFTP' : 'Glömd PIN? Återställ via SFTP' ?></h2>
                    <ol>
                        <li>Ta en säkerhetskopia och radera <code>storage/central_admin.json</code> via SFTP. Om inloggningen är spärrad eller försöksfilen är skadad, radera även <code>storage/central_admin_login_attempts.json</code>.</li>
                        <li>Logga in med ett befintligt Fakturaunderlag-adminkonto och öppna Adminpanelen.</li>
                        <li>Välj en ny central admin-PIN. Faktura- och protokollkontonas filer ska inte ändras.</li>
                    </ol>
                    <p class="central-admin-help">Borttagningen ogiltigförklarar alla tidigare centrala admin-sessioner. Om filen är skadad visas också ett säkert stopp tills den har återställts via SFTP.</p>
                </section>
            </div>
        </div>

    <?php elseif ($centralAdminIsAuthenticated && $centralAdminMustChangePin): ?>
        <header class="central-admin-hero">
            <div>
                <h2><?= ($portalLanguage ?? 'sv') === 'en' ? 'Central PIN change required' : 'Byte av central PIN-kod krävs' ?></h2>
                <p><?= $centralAdminPinPolicyUpdateRequired ? 'Din centrala PIN-kod följer ett äldre format. Bekräfta den nuvarande koden och välj en ny enligt kraven nedan.' : 'Det har gått 20 dagar sedan senaste bytet. Bekräfta din nuvarande PIN-kod och välj en ny innan adminåtkomsten kan användas igen.' ?></p>
            </div>
            <span class="central-admin-status central-admin-status-warning"><?= ($portalLanguage ?? 'sv') === 'en' ? 'PIN change required' : 'PIN-byte krävs' ?></span>
        </header>
        <section class="central-admin-card" aria-labelledby="central-admin-expired-title">
            <h2 id="central-admin-expired-title"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Choose a new PIN to continue' : 'Välj en ny PIN-kod för att fortsätta' ?></h2>
            <form class="central-admin-form" method="post" action="?tab=admin" autocomplete="off">
                <input type="hidden" name="action" value="central_admin_change_pin">
                <?= csrf_field() ?>
                <div><label for="central-admin-expired-current-pin"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Current central PIN' : 'Nuvarande central PIN-kod' ?></label><input id="central-admin-expired-current-pin" name="current_pin" type="password" maxlength="128" inputmode="text" autocomplete="off" required></div>
                <div class="central-admin-form-grid">
                    <div><label for="central-admin-expired-new-pin"><?= ($portalLanguage ?? 'sv') === 'en' ? 'New central PIN' : 'Ny central PIN-kod' ?></label><input id="central-admin-expired-new-pin" name="new_pin" type="password" minlength="6" maxlength="12" pattern="[0-9!@#$%&amp;*+=_\-]{6,12}" inputmode="text" autocomplete="off" spellcheck="false" placeholder="6–10 siffror · upp till 2 symboler" required></div>
                    <div><label for="central-admin-expired-confirm-pin"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Repeat new PIN' : 'Upprepa ny PIN-kod' ?></label><input id="central-admin-expired-confirm-pin" name="pin_confirm" type="password" minlength="6" maxlength="12" pattern="[0-9!@#$%&amp;*+=_\-]{6,12}" inputmode="text" autocomplete="off" spellcheck="false" placeholder="<?= ($portalLanguage ?? 'sv') === 'en' ? 'Repeat PIN' : 'Upprepa PIN-koden' ?>" required></div>
                </div>
                <button class="btn btn-main" type="submit" <?= !$csrfReady ? 'disabled' : '' ?>><?= ($portalLanguage ?? 'sv') === 'en' ? 'Change PIN and continue' : 'Byt PIN-kod och fortsätt' ?></button>
            </form>
            <p class="central-admin-help">6–10 siffror och högst 2 valfria specialtecken ur <code>!@#$%&amp;*+-_=</code>; inga bokstäver eller mellanslag. För bäst skydd: använd 10 siffror och 2 symboler.</p>
            <div class="central-admin-actions">
                <form method="post" action="?tab=admin"><input type="hidden" name="action" value="central_admin_logout"><?= csrf_field() ?><button class="btn btn-outline" type="submit"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Sign out' : 'Logga ut' ?></button></form>
            </div>
        </section>

    <?php elseif ($centralAdminSetupState === 'error'): ?>
        <header class="central-admin-hero">
            <div><h2><?= ($portalLanguage ?? 'sv') === 'en' ? 'Central administrator settings need checking' : 'Central admininställning behöver kontrolleras' ?></h2><p>Portalen kan inte verifiera den centrala adminfilen. Åtkomst till centrala adminfunktioner är stängd tills den privata filen kontrollerats.</p></div>
        </header>
        <section class="central-admin-card central-admin-warning" aria-labelledby="central-admin-storage-error-title">
            <h2 id="central-admin-storage-error-title"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Check private storage using SFTP' : 'Kontrollera privat lagring via SFTP' ?></h2>
            <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'Check' : 'Kontrollera' ?> <code>storage/central_admin.json</code>, eventuell <code>storage/central_admin_login_attempts.json</code>, katalogen <code>storage</code> och filrättigheterna. Vid PIN-återställning, säkerhetskopiera och radera den centrala adminfilen; ta bort försöksfilen endast om den är skadad eller inloggningen är spärrad. Ändra inte <code>invoice_users.json</code> eller protokollkontonas fil.</p>
        </section>

    <?php elseif ($centralAdminSetupState === 'unconfigured' && $canEnrollCentralAdmin): ?>
        <header class="central-admin-hero">
            <div>
                <h2><?= ($portalLanguage ?? 'sv') === 'en' ? 'Choose central administrator PIN' : 'Välj central admin-PIN' ?></h2>
                <p>Du är inloggad med det befintliga Fakturaunderlag-adminet. Välj nu en separat PIN som ger adminåtkomst till personalsidorna utan att ersätta faktura- eller protokollkontona.</p>
            </div>
            <span class="central-admin-status"><?= ($portalLanguage ?? 'sv') === 'en' ? 'First-time setup' : 'Förstagångsinställning' ?></span>
        </header>
        <section class="central-admin-card" aria-labelledby="central-admin-enroll-title">
            <h2 id="central-admin-enroll-title"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Create central administrator access' : 'Skapa central adminåtkomst' ?></h2>
            <form class="central-admin-form" method="post" action="?tab=admin" autocomplete="off">
                <input type="hidden" name="action" value="central_admin_enroll">
                <?= csrf_field() ?>
                <div class="central-admin-form-grid">
                    <div><label for="central-admin-enroll-pin"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Choose central PIN' : 'Välj central PIN-kod' ?></label><input id="central-admin-enroll-pin" name="new_pin" type="password" minlength="6" maxlength="12" pattern="[0-9!@#$%&amp;*+=_\-]{6,12}" inputmode="text" autocomplete="off" spellcheck="false" placeholder="6–10 siffror · upp till 2 symboler" required></div>
                    <div><label for="central-admin-enroll-confirm"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Repeat PIN' : 'Upprepa PIN-koden' ?></label><input id="central-admin-enroll-confirm" name="pin_confirm" type="password" minlength="6" maxlength="12" pattern="[0-9!@#$%&amp;*+=_\-]{6,12}" inputmode="text" autocomplete="off" spellcheck="false" placeholder="<?= ($portalLanguage ?? 'sv') === 'en' ? 'Repeat PIN' : 'Upprepa PIN-koden' ?>" required></div>
                </div>
                <button class="btn btn-main" type="submit" <?= !$csrfReady ? 'disabled' : '' ?>><?= ($portalLanguage ?? 'sv') === 'en' ? 'Create central administrator PIN' : 'Skapa central admin-PIN' ?></button>
            </form>
            <p class="central-admin-help">Krav: 6–10 siffror och högst 2 valfria specialtecken ur <code>!@#$%&amp;*+-_=</code>; inga bokstäver eller mellanslag. För bäst skydd: använd 10 siffror och 2 symboler. Den första PIN-koden väljs av ett befintligt Fakturaunderlag-admin och måste bytas var 20:e dag.</p>
        </section>

    <?php else: ?>
        <header class="central-admin-hero">
            <div>
                <h2><?= $centralAdminSetupState === 'unconfigured' ? 'Central Adminpanel är inte inställd ännu' : 'Central admininloggning' ?></h2>
                <p>Logga in med den separata centrala admin-PIN:en för att få adminåtkomst till personalsidorna. Kundernas fristående PWA:er ger inte åtkomst till denna panel.</p>
            </div>
        </header>

        <?php if ($centralAdminSetupState === 'unconfigured' && $invoiceSetupState === 'unconfigured'): ?>
            <section class="central-admin-card" aria-labelledby="central-admin-first-account-title">
                <h2 id="central-admin-first-account-title">Först behövs det befintliga faktura-adminet</h2>
                <p>Skapa eller återställ först det vanliga Fakturaunderlag-adminet. Därefter loggar du in med dess befintliga sexsiffriga PIN och väljer den separata central admin-PIN:en här.</p>
                <div class="central-admin-actions"><a class="btn btn-main" href="?tab=invoice">Öppna första faktura-admininställningen</a></div>
            </section>
        <?php elseif ($centralAdminSetupState === 'unconfigured' && $invoiceSetupState === 'error'): ?>
            <section class="central-admin-card central-admin-warning" aria-labelledby="central-admin-invoice-error-title">
                <h2 id="central-admin-invoice-error-title">Befintligt faktura-admin kunde inte verifieras</h2>
                <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'Check' : 'Kontrollera' ?> <code>storage/invoice_users.json</code> via SFTP. Central admin-PIN kan inte sättas utan ett fungerande Fakturaunderlag-admin.</p>
            </section>
        <?php elseif ($centralAdminSetupState === 'unconfigured'): ?>
            <section class="central-admin-card" aria-labelledby="central-admin-enrollment-required-title">
                <h2 id="central-admin-enrollment-required-title">Första inställningen kräver Fakturaunderlag-admin</h2>
                <p>Logga in med ett befintligt faktura-adminkonto. Inloggade fakturamedarbetare får inte skapa eller återställa den centrala admin-PIN:en.</p>
                <?php if (is_array($invoiceAuthUser) && ($invoiceAuthUser['role'] ?? '') !== 'admin'): ?>
                    <p class="central-admin-account-note">Den här webbläsaren är för närvarande inloggad som fakturapersonal. Logga ut från det fakturakontot och använd ett befintligt Fakturaunderlag-admin.</p>
                <?php endif; ?>
                <div class="central-admin-actions"><a class="btn btn-main" href="?tab=invoice&amp;invoice_view=admin">Öppna fakturakonton / logga in</a></div>
            </section>
        <?php else: ?>
            <section class="central-admin-card" aria-labelledby="central-admin-login-title">
                <h2 id="central-admin-login-title"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Sign in as central administrator' : 'Logga in som central admin' ?></h2>
                <form class="central-admin-form" method="post" action="?tab=admin" autocomplete="off">
                    <input type="hidden" name="action" value="central_admin_login">
                    <?= csrf_field() ?>
                    <div><label for="central-admin-login-pin"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Central administrator PIN' : 'Central admin-PIN-kod' ?></label><input id="central-admin-login-pin" name="central_pin" type="password" maxlength="128" inputmode="text" autocomplete="off" required></div>
                    <button class="btn btn-main" type="submit" <?= !$csrfReady ? 'disabled' : '' ?>><?= ($portalLanguage ?? 'sv') === 'en' ? 'Sign in' : 'Logga in' ?></button>
                </form>
                <p class="central-admin-help">Nya PIN-koder innehåller 6–10 siffror och högst 2 valfria symboler ur <code>!@#$%&amp;*+-_=</code>; inga bokstäver eller mellanslag. Om du redan har en äldre PIN-kod kan du logga in med den en gång och blir då ombedd att välja en ny. Om PIN-koden har glömts bort: återställ via SFTP enligt instruktionen nedan. Faktura- och protokollkontonas PIN-koder ändras inte.</p>
            </section>
        <?php endif; ?>

        <section class="central-admin-card central-admin-sftp" aria-labelledby="central-admin-sftp-login-title">
            <h2 id="central-admin-sftp-login-title"><?= ($portalLanguage ?? 'sv') === 'en' ? 'SFTP reset' : 'SFTP-återställning' ?></h2>
            <ol>
                <li>Via SFTP, säkerhetskopiera och radera <code>storage/central_admin.json</code>. Om inloggningen är spärrad eller försöksfilen är skadad, ta även bort <code>storage/central_admin_login_attempts.json</code>.</li>
                <li>Logga in med ett befintligt Fakturaunderlag-admin och öppna Adminpanelen.</li>
                <li>Välj en ny central admin-PIN. Ändra inte de separata faktura- och protokollkontona.</li>
            </ol>
        </section>
    <?php endif; ?>
</section>
