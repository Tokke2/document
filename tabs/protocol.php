<?php
declare(strict_types=1);
$protocolAuthUser = is_array($protocolAuthUser ?? null) ? $protocolAuthUser : portal_protocol_auth_current_user();
if (!is_array($protocolAuthUser)):
    $protocolSessionReady = session_status() === PHP_SESSION_ACTIVE && session_name() === portal_protocol_auth_session_name();
?>
<div class="invoice-auth-card" aria-labelledby="protocol-auth-title">
    <div class="invoice-auth-card-head">
        <span class="invoice-auth-mark" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 5 6v5c0 4.6 2.8 7.7 7 10 4.2-2.3 7-5.4 7-10V6z"/><path d="M9.5 12.2 11.3 14l3.6-4"/></svg></span>
        <div><h2 id="protocol-auth-title">Serviceprotokoll – inloggning</h2><p>Logga in med ditt separata protokollkonto.</p></div>
    </div>
    <?php if ($protocolSessionReady && $invoiceSetupState === 'configured'): ?>
        <form class="invoice-auth-form" method="post" action="?tab=protocol" autocomplete="on">
            <input type="hidden" name="action" value="protocol_pin_login"><?= csrf_field() ?>
            <div><label for="protocol-login-username">Användarnamn</label><input id="protocol-login-username" name="username" type="text" maxlength="120" autocomplete="username" autocapitalize="none" required></div>
            <div><label for="protocol-login-pin">Sexsiffrig PIN</label><input id="protocol-login-pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="current-password" required></div>
            <button class="btn btn-main" type="submit">Logga in till serviceprotokoll</button>
        </form>
        <p class="invoice-auth-help">Protokollinloggningen är skild från Fakturaunderlaget. Be en portaladmin skapa ditt konto eller återställa din PIN. Portalens yttre lösenordsskydd gäller fortfarande.</p>
    <?php else: ?>
        <div class="app-alert app-alert-error" role="alert"><span class="app-alert-icon" aria-hidden="true">×</span><span>Protokollinloggningen kan inte användas just nu. Kontrollera att fakturakontot är korrekt konfigurerat och att privat lagring under storage är åtkomlig.</span></div>
    <?php endif; ?>
</div>
<?php return; endif; ?>
<?php
$customers = array_values(array_filter(load_data(CUSTOMERS_FILE, []), 'is_array'));
$savedProtocols = array_values(array_filter(load_data(PROTOCOLS_FILE, []), 'is_array'));
$protocolCatalogRecords = portal_protocol_catalog_read();
$protocolCatalogStorageReady = is_array($protocolCatalogRecords);
$protocolCatalogArticles = $protocolCatalogStorageReady ? portal_protocol_catalog_normalize($protocolCatalogRecords) : [];
$protocolIsAdmin = ($protocolAuthUser['role'] ?? 'staff') === 'admin';
$requestedProtocolView = isset($_GET['protocol_view']) && is_string($_GET['protocol_view']) ? $_GET['protocol_view'] : '';
if ($requestedProtocolView === 'records') {
    require __DIR__ . '/protocol_records.php';
    return;
}
$protocolOwnerId = (string) ($protocolAuthUser['id'] ?? '');
$protocolDrafts = $protocolOwnerId !== '' ? portal_autosaved_drafts_list('protocol', $protocolOwnerId) : [];
$protocolDraftId = portal_autosaved_drafts_new_id();
$protocolDraftData = null;
$requestedDraftId = isset($_GET['draft_id']) && is_string($_GET['draft_id']) ? $_GET['draft_id'] : '';
if ($requestedDraftId !== '') {
    $loadedProtocolDraft = portal_autosaved_drafts_get('protocol', $protocolOwnerId, $requestedDraftId);
    if (is_array($loadedProtocolDraft)) {
        $protocolDraftId = (string) $loadedProtocolDraft['id'];
        $protocolDraftData = is_array($loadedProtocolDraft['data'] ?? null) ? $loadedProtocolDraft['data'] : null;
    }
}
$requestedViewId = isset($_GET['view_id']) && is_string($_GET['view_id']) ? $_GET['view_id'] : '';
$editId = isset($_GET['edit_id']) && is_string($_GET['edit_id']) ? $_GET['edit_id'] : '';
if ($editId === '' && $requestedViewId !== '') {
    $editId = $requestedViewId;
}
if ($editId === '' && is_array($protocolDraftData) && isset($protocolDraftData['p']['id']) && is_string($protocolDraftData['p']['id'])) {
    $editId = $protocolDraftData['p']['id'];
}
$copyId = isset($_GET['copy_id']) && is_string($_GET['copy_id']) ? $_GET['copy_id'] : '';
if ($copyId !== '' && !preg_match('/\\A[a-f0-9]{32}\\z/i', $copyId)) {
    $copyId = '';
}
$copyingProtocol = false;
$protocolCopySourceNumber = '';
$editProtocol = null;
if ($editId !== '') {
    foreach ($savedProtocols as $record) {
        if ((string) ($record['id'] ?? '') === $editId) {
            $editProtocol = $record;
            break;
        }
    }
} elseif ($copyId !== '' && !is_array($protocolDraftData)) {
    foreach ($savedProtocols as $record) {
        if ((string) ($record['id'] ?? '') === $copyId) {
            $editProtocol = $record;
            $copyingProtocol = true;
            break;
        }
    }
}
if ($editProtocol) {
    foreach (['nummer', 'reg', 'regnr', 'date', 'km', 'customer', 'tech', 'description'] as $legacyKey) {
        if (isset($editProtocol[$legacyKey])) { $editProtocol[$legacyKey] = app_legacy_text($editProtocol[$legacyKey]); }
    }
}
if ($copyingProtocol && is_array($editProtocol)) {
    $protocolCopySourceNumber = app_legacy_text($editProtocol['nummer'] ?? '');
    $editProtocol['id'] = '';
    $editProtocol['nummer'] = get_next_protocol_number();
    $editProtocol['reg'] = app_legacy_text($editProtocol['reg'] ?? $editProtocol['regnr'] ?? '');
    $editProtocol['date'] = date('Y-m-d');
    $editProtocol['meter_photo_file'] = '';
    $editProtocol['signature_file'] = '';
}
$savedProtocolBaseline = $copyingProtocol ? null : $editProtocol;
if (is_array($protocolDraftData)) {
    $draftProtocolFields = is_array($protocolDraftData['p'] ?? null) ? $protocolDraftData['p'] : [];
    $editProtocol = array_replace(is_array($editProtocol) ? $editProtocol : [], $draftProtocolFields);
    if (is_array($protocolDraftData['items'] ?? null)) {
        $editProtocol['items'] = $protocolDraftData['items'];
    }
}
$isEditingProtocol = is_array($editProtocol) && trim((string) ($editProtocol['id'] ?? '')) !== '';
$isSavedProtocolView = $requestedViewId !== '' && $editId === $requestedViewId && is_array($savedProtocolBaseline) && !$protocolDraftData;
$protocolSignatureFreshRequired = $isEditingProtocol && is_array($savedProtocolBaseline)
    ? portal_protocol_content_hash($editProtocol) !== portal_protocol_content_hash($savedProtocolBaseline)
    : false;
$protocolStoredMediaFile = static function (mixed $value): string {
    if (!is_string($value) || !preg_match('/\\Auploads\\/protocols\\/[a-f0-9]{32}\\.(?:png|jpe?g)\\z/i', $value)) {
        return '';
    }
    $uploadsRoot = dirname(__DIR__) . '/uploads';
    $protocolMediaRoot = $uploadsRoot . '/protocols';
    if (is_link($uploadsRoot) || is_link($protocolMediaRoot)) {
        return '';
    }
    $mediaRoot = realpath($protocolMediaRoot);
    $candidate = dirname(__DIR__) . '/' . $value;
    $realCandidate = realpath($candidate);
    if ($mediaRoot === false || $realCandidate === false || is_link($candidate) || dirname($realCandidate) !== $mediaRoot || !is_file($realCandidate)) {
        return '';
    }
    return $value;
};
$protocolMeterPhotoFile = $protocolStoredMediaFile($editProtocol['meter_photo_file'] ?? null);
$protocolSignatureFile = $protocolStoredMediaFile($editProtocol['signature_file'] ?? null);
$protocolNumber = app_legacy_text($editProtocol['nummer'] ?? get_next_protocol_number());
$items = is_array($editProtocol['items'] ?? null) ? array_values(array_filter(array_slice($editProtocol['items'], 0, 50), 'is_array')) : [];
foreach ($items as &$legacyProtocolItem) {
    foreach (['art', 'qty', 'spec'] as $legacyKey) {
        if (isset($legacyProtocolItem[$legacyKey])) { $legacyProtocolItem[$legacyKey] = app_legacy_text($legacyProtocolItem[$legacyKey]); }
    }
}
unset($legacyProtocolItem);
$rowCount = max(8, count($items));
?>
<style>
.pro-wrap{--p-ink:#142d36;--p-muted:#61757b;--p-line:#d9e5e3;max-width:1000px;margin:0 auto;color:var(--p-ink)}.pro-toolbar{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:16px}.pro-toolbar p{margin:0;color:var(--p-muted);font-size:.83rem}.pro-paper{padding:clamp(16px,3vw,28px);border:1px solid var(--p-line);border-radius:16px;background:#fff;box-shadow:0 6px 24px rgba(16,40,58,.05)}.pro-paper-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-end;padding-bottom:15px;border-bottom:3px solid #173b50;margin-bottom:17px}.pro-title-group{display:flex;align-items:center;gap:12px}.pro-title-icon{width:48px;height:48px;flex:0 0 auto;border-radius:13px}.pro-paper-head h2{margin:0;font-size:clamp(1.3rem,3vw,1.8rem);letter-spacing:-.04em}.pro-kicker{margin:4px 0 0;color:var(--p-muted);font-size:.77rem;font-weight:700}.pro-number{padding:9px 12px;border:1px solid var(--p-line);border-radius:10px;background:#f8fafc;text-align:right}.pro-number small{display:block;color:var(--p-muted);font-size:.65rem;font-weight:800;letter-spacing:.08em}.pro-number strong{font-size:.98rem}.pro-section{margin:0 0 16px;border:1px solid var(--p-line);border-radius:12px;overflow:hidden}.pro-section-title{padding:9px 13px;background:#f3f7fa;color:#26475a;font-size:.72rem;font-weight:850;letter-spacing:.08em;text-transform:uppercase}.pro-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:0}.pro-field{padding:11px 12px;border-top:1px solid #edf0f4;border-right:1px solid #edf0f4;min-width:0}.pro-field.span-2{grid-column:span 2}.pro-field.span-3{grid-column:span 3}.pro-field label{display:block;margin:0 0 5px;color:var(--p-muted);font-size:.67rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em}.pro-field input,.pro-field textarea,.pro-field select{width:100%;border:0;outline:0;background:transparent;color:var(--p-ink);font:inherit;font-size:.91rem;font-weight:650}.pro-field textarea{min-height:76px;resize:vertical;line-height:1.45}.pro-noprint{margin:14px 0;padding:13px;border:1px dashed #b7cbd5;border-radius:12px;background:#f5fafb}.pro-import{display:flex;gap:9px;align-items:end;flex-wrap:wrap}.pro-import>div{flex:1;min-width:190px}.pro-import select{width:100%;min-height:42px;padding:8px 10px;border:1px solid var(--p-line);border-radius:8px;background:#fff}.pro-table-wrap{overflow-x:auto}.pro-table{width:100%;min-width:600px;border-collapse:collapse}.pro-table th{padding:9px;background:#f7f9fb;color:#475467;text-align:left;font-size:.69rem;letter-spacing:.04em}.pro-table td{border-top:1px solid #e9edf2;padding:3px}.pro-table input{width:100%;min-height:38px;padding:7px;border:1px solid transparent;border-radius:6px;background:#fff;font:inherit;font-size:.82rem}.pro-table input:focus{border-color:#8bb8c5;outline:0;background:#f9fdff}.pro-add-row{margin-top:8px}.pro-sign{display:grid;grid-template-columns:1.5fr 1fr;gap:40px;margin:24px 0}.pro-sign div{padding-top:8px;border-top:1px solid #344054}.pro-sign small{color:var(--p-muted);font-size:.7rem;font-weight:750}.pro-actions{display:flex;justify-content:center;gap:10px;margin-top:20px}.pro-note{margin:10px 0 0;color:var(--p-muted);font-size:.74rem;text-align:center}
.pro-review{display:none}.pro-wizard-nav{display:none}.pro-wizard-nav[hidden]{display:none!important}.pro-progress{margin-bottom:10px}.pro-progress p{margin:0 0 7px;color:#315462;font-size:.76rem;font-weight:800}.pro-progress-track{height:7px;overflow:hidden;border-radius:999px;background:#dce9ed}.pro-progress-track span{display:block;width:33.333%;height:100%;border-radius:inherit;background:linear-gradient(90deg,#0b7a72,#22a594);transition:width .2s ease}.pro-step-buttons{display:flex;gap:8px}.pro-step-buttons .btn{flex:1;min-height:46px}.pro-wizard-review-list{margin:0;padding-left:20px}.pro-wizard-review-list li{margin:5px 0;overflow-wrap:anywhere}.pro-review-details{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin:0}.pro-review-details div{padding:9px;border:1px solid #e6edf0;border-radius:9px;background:#fbfdfe}.pro-review-details dt{color:#667085;font-size:.64rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase}.pro-review-details dd{margin:3px 0 0;font-size:.83rem;font-weight:700;overflow-wrap:anywhere}.pro-review-description{white-space:pre-wrap;overflow-wrap:anywhere}.pro-review-heading{margin:15px 0 8px;font-size:.83rem}.pro-wizard-nav{display:none}
@media(max-width:660px){.pro-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.pro-field.span-3{grid-column:span 2}.pro-paper-head{align-items:flex-start;flex-direction:column}.pro-number{text-align:left}.pro-actions{flex-direction:column}.pro-actions .btn{width:100%}.pro-wrap.protocol-wizard-active [data-protocol-step]{display:none}.pro-wrap.protocol-wizard-active [data-protocol-step].is-active{display:block}.pro-wrap.protocol-wizard-active .pro-actions[data-protocol-step].is-active{display:flex}.pro-wrap.protocol-wizard-active .pro-import[data-protocol-step].is-active{display:flex}.pro-wrap.protocol-wizard-active .pro-review[data-protocol-step].is-active{display:block}.pro-wrap.protocol-wizard-active .pro-wizard-nav{position:sticky;bottom:7px;z-index:15;display:block;margin-top:14px;padding:12px;border:1px solid #cbdce2;border-radius:14px;background:rgba(255,255,255,.97);box-shadow:0 -4px 18px rgba(16,40,58,.10);backdrop-filter:blur(10px);padding-bottom:calc(12px + env(safe-area-inset-bottom))}.pro-wrap.protocol-wizard-active .pro-step-buttons{display:flex}.pro-review-details{grid-template-columns:1fr 1fr}}
@media(max-width:660px){.pro-wrap.protocol-wizard-active{padding-bottom:calc(104px + env(safe-area-inset-bottom))}.pro-wrap.protocol-wizard-active .pro-wizard-nav{position:fixed;left:max(12px,env(safe-area-inset-left));right:max(12px,env(safe-area-inset-right));bottom:calc(6px + env(safe-area-inset-bottom));z-index:30;width:auto;max-width:1000px;margin:0 auto;padding:8px;border:1px solid #cbdce2;border-radius:12px;background:rgba(255,255,255,.97);box-shadow:0 -4px 18px rgba(16,40,58,.12);backdrop-filter:blur(10px)}.pro-wrap.protocol-wizard-active .pro-actions[data-protocol-step="3"]>.btn-main{display:none!important}.pro-wrap.protocol-wizard-active .pro-progress{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:8px;margin-bottom:0}.pro-wrap.protocol-wizard-active .pro-progress p{margin:0;font-size:.72rem;line-height:1.12;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.pro-wrap.protocol-wizard-active .pro-progress-track{width:110px;height:5px}.pro-wrap.protocol-wizard-active .pro-step-buttons{gap:6px}.pro-wrap.protocol-wizard-active .pro-step-buttons .btn{min-height:48px!important;padding:7px 8px;font-size:.78rem;line-height:1.12}}
@media(min-width:661px) and (min-height:561px){.pro-wizard-nav{display:none!important}}
@media(max-width:900px) and (max-height:560px) and (orientation:landscape){.pro-wrap.protocol-wizard-active{padding-bottom:calc(104px + env(safe-area-inset-bottom))}.pro-wrap.protocol-wizard-active [data-protocol-step]{display:none}.pro-wrap.protocol-wizard-active [data-protocol-step].is-active{display:block}.pro-wrap.protocol-wizard-active .pro-actions[data-protocol-step].is-active{display:flex}.pro-wrap.protocol-wizard-active .pro-import[data-protocol-step].is-active{display:flex}.pro-wrap.protocol-wizard-active .pro-review[data-protocol-step].is-active{display:block}.pro-wrap.protocol-wizard-active .pro-wizard-nav{position:fixed;left:max(12px,env(safe-area-inset-left));right:max(12px,env(safe-area-inset-right));bottom:calc(6px + env(safe-area-inset-bottom));z-index:30;display:block!important;width:auto;max-width:1000px;margin:0 auto;padding:8px;border:1px solid #cbdce2;border-radius:12px;background:rgba(255,255,255,.97);box-shadow:0 -4px 18px rgba(16,40,58,.12);backdrop-filter:blur(10px)}.pro-wrap.protocol-wizard-active .pro-actions[data-protocol-step="3"]>.btn-main{display:none!important}.pro-wrap.protocol-wizard-active .pro-progress{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:8px;margin-bottom:0}.pro-wrap.protocol-wizard-active .pro-progress p{margin:0;font-size:.72rem;line-height:1.12;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.pro-wrap.protocol-wizard-active .pro-progress-track{width:110px;height:5px}.pro-wrap.protocol-wizard-active .pro-step-buttons{gap:6px}.pro-wrap.protocol-wizard-active .pro-step-buttons .btn{min-height:48px!important;padding:7px 8px;font-size:.78rem;line-height:1.12}}
.pro-wrap [hidden]{display:none!important}
.pro-photo-control{margin:12px 0;padding:12px;border:1px dashed #b7cbd5;border-radius:10px;background:#f8fbfc}.pro-photo-control label{display:block;margin-bottom:6px;color:#315462;font-size:.76rem;font-weight:800}.pro-photo-control input[type=file]{display:block;width:100%;max-width:100%;font:inherit;font-size:.82rem}.pro-photo-status{margin:7px 0 0;color:var(--p-muted);font-size:.75rem}.pro-photo-preview{margin:12px 13px 15px}.pro-photo-preview img{display:block;max-width:min(100%,520px);max-height:320px;object-fit:contain;border:1px solid var(--p-line);border-radius:9px;background:#f8fafc}.pro-photo-preview p{margin:6px 0 0;color:var(--p-muted);font-size:.74rem}.pro-signature-capture{margin-top:14px;padding:12px;border:1px solid var(--p-line);border-radius:10px;background:#fbfdfe}.pro-signature-capture label{display:block;margin-bottom:7px;color:#315462;font-size:.76rem;font-weight:850}.pro-signature-canvas{display:block;width:100%;height:138px;border:1px solid #bccbd3;border-radius:8px;background:#fff;touch-action:none;user-select:none;-webkit-user-select:none;cursor:crosshair}.pro-signature-actions{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:7px}.pro-signature-status{margin:0;color:var(--p-muted);font-size:.74rem}.pro-signature-error{margin:7px 0 0;color:#b42318;font-size:.78rem;font-weight:750}.pro-signature-image{display:block;max-width:min(100%,280px);max-height:94px;object-fit:contain;object-position:left center}.pro-signature-placeholder{display:block;min-height:42px;padding-top:8px;color:#667085;font-size:.72rem;font-style:italic}.pro-signature-block{min-height:120px}
@media print{@page{size:A4 portrait;margin:12mm}.page-shell{padding:0!important}.pro-wrap{max-width:none}.pro-paper{padding:0;border:0;box-shadow:none}.pro-noprint,.pro-actions,.pro-wizard-nav,.pro-review,.invoice-session-bar,.app-header,.tabs-wrap,.page-heading,.app-footer{display:none!important}.pro-wrap.protocol-wizard-active [data-protocol-step]{display:block!important}.pro-wrap.protocol-wizard-active .pro-review,.pro-wrap.protocol-wizard-active .pro-actions{display:none!important}.pro-wrap.protocol-wizard-active .pro-sign{display:grid!important}.pro-section{break-inside:avoid}.pro-field input,.pro-field textarea{font-size:10pt}.pro-table{min-width:0}}
</style>
<style>
.pro-subnav{display:flex;gap:8px;overflow-x:auto;margin:8px 0 15px;padding-bottom:8px;border-bottom:1px solid #dce3ea}.pro-subnav a{flex:0 0 auto;padding:9px 13px;border:1px solid #dce3ea;border-radius:9px;background:#fff;color:#315462;text-decoration:none;font-size:.82rem;font-weight:750}.pro-subnav a[aria-current="page"]{border-color:#bfe1d5;background:#e8f5f0;color:#075c59;box-shadow:inset 0 -2px 0 #0b7a72}.pro-copy-notice{margin:-3px 0 14px;padding:11px 13px;border:1px solid #cce5dc;border-radius:12px;background:#f0f8f4;color:#28564e;font-size:.84rem;line-height:1.45}.pro-readonly{padding:10px 12px;border-top:1px solid #edf0f4;min-height:40px;white-space:pre-wrap;overflow-wrap:anywhere;font-size:.92rem;font-weight:650;line-height:1.45}.pro-view-media{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;padding:13px}.pro-view-media-card{min-width:0;padding:12px;border:1px solid #e4eaf0;border-radius:10px;background:#fff}.pro-view-media-card h3{margin:0 0 9px;color:#475467;font-size:.72rem;letter-spacing:.05em;text-transform:uppercase}.pro-view-media-card img{display:block;max-width:100%;max-height:300px;object-fit:contain;object-position:left center}.pro-view-email{margin:0 0 14px;padding:12px 14px;border:1px solid #cce5dc;border-radius:12px;background:#f0f8f4;color:#28564e;font-size:.84rem;line-height:1.45}.pro-view-actions{display:flex;justify-content:center;gap:10px;margin:16px 0}.pro-view-items{width:100%;border-collapse:collapse}.pro-view-items th,.pro-view-items td{padding:9px 10px;border-top:1px solid #e5ebf0;text-align:left;vertical-align:top;font-size:.84rem;overflow-wrap:anywhere}.pro-view-items th{background:#f7f9fb;color:#475467;font-size:.68rem;letter-spacing:.04em;text-transform:uppercase}
@media(max-width:660px){.pro-view-media{grid-template-columns:1fr}.pro-view-actions{flex-direction:column}.pro-view-actions .btn{width:100%}}
@media print{.pro-subnav,.pro-view-actions,.pro-saved-view .invoice-session-bar,.pro-saved-view .pro-toolbar,.pro-view-email{display:none!important}.pro-saved-view .pro-paper{padding:0;border:0;box-shadow:none}.pro-view-media-card{break-inside:avoid}.pro-view-media-card img{max-width:100%;max-height:85mm}.pro-view-items th,.pro-view-items td{font-size:9pt}}
.pro-paper-head{border-bottom-color:#15544f}.pro-number{border-color:#dbe8e3;background:#f4f8f5}.pro-section{border-color:#dce7e4;border-radius:14px}.pro-section-title{background:#eef6f1;color:#24554e}.pro-noprint{border-color:#c8dfd6;background:#f5faf7}.pro-progress-track{background:#deece6}.pro-progress p{color:#24554e}.pro-review-details div{border-color:#dce7e4;background:#f8fbf9}.pro-photo-control{border-color:#c8dfd6;background:#f5faf7}.pro-photo-control label,.pro-signature-capture label{color:#24554e}.pro-signature-capture{border-color:#d6e5df;background:#f8fbf9}.pro-signature-canvas{border-color:#b8cdc5;border-radius:10px}.pro-signature-canvas:focus-visible{border-color:#0b7a72;box-shadow:0 0 0 4px rgba(11,122,114,.15)}.pro-view-media-card{border-color:#dce7e4;border-radius:13px}.pro-view-items th{background:#f1f7f4;color:#38565a}.pro-readonly{border-top-color:#e8efec}.pro-view-actions{gap:12px}
@media(max-width:660px){.pro-paper{padding:15px 12px}.pro-section{margin-bottom:13px}.pro-field{padding:10px}.pro-photo-control,.pro-signature-capture{padding:11px}.pro-view-actions{gap:9px}}
</style>
<style>
.pro-field-help,.pro-row-help{display:block;margin:5px 0 0;color:#61757b;font-size:.72rem;line-height:1.42;font-weight:500}.pro-visually-hidden{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}
.pro-catalog-panel{display:grid;gap:11px;margin:10px 0 12px;padding:13px;border:1px solid #cfe1d9;border-radius:12px;background:#f8fbf9}
.pro-catalog-panel>p{margin:0;color:#526b69;font-size:.78rem;line-height:1.45}
.pro-catalog-tools{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:end;gap:9px}
.pro-catalog-tools label{display:block;margin:0 0 5px;color:#31554f;font-size:.74rem;font-weight:800}
.pro-catalog-tools select{width:100%;min-height:44px;padding:8px 10px;border:1px solid #c9dcd4;border-radius:9px;background:#fff;color:#173a38;font:inherit}
.pro-catalog-tools .btn{min-height:44px;white-space:nowrap}
.pro-catalog-status{grid-column:1/-1;min-height:1.2em;margin:0;color:#4c6663;font-size:.75rem;line-height:1.4}
.pro-table-wrap[tabindex="0"]:focus-visible{outline:3px solid rgba(11,122,114,.28);outline-offset:3px;border-radius:10px}
.pro-table th small{display:block;margin-top:3px;color:#6b7e80;font-size:.62rem;font-weight:550;line-height:1.35;letter-spacing:0;text-transform:none}
.pro-table td[data-label]::before{display:none}
@media(max-width:660px){
 .pro-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
 .pro-field.span-3{grid-column:1/-1}
 .pro-table-wrap{overflow:visible}
 .pro-table{display:block;min-width:0}
 .pro-table thead{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
 .pro-table tbody{display:grid;gap:10px}
 .pro-table tbody tr{display:grid;grid-template-columns:minmax(0,1fr) minmax(82px,.46fr);gap:9px 11px;padding:12px;border:1px solid #dce7e4;border-radius:13px;background:#fff;box-shadow:0 5px 15px rgba(18,54,58,.045)}
 .pro-table td{display:block;min-width:0;padding:0;border:0}
 .pro-table td[data-label]::before{display:block;margin:0 0 5px;color:#5f7377;content:attr(data-label);font-size:.65rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase}
 .pro-table td[data-label="Artikel / arbetsmoment"]{grid-column:1/-1}
 .pro-table td[data-label="Antal"]{grid-column:1}
 .pro-table td[data-label="Teknisk specifikation"]{grid-column:1/-1}
 .pro-table input{min-height:46px;padding:8px;border:1px solid #dce7e4;border-radius:8px;font-size:16px}
 .pro-catalog-tools{grid-template-columns:1fr}
 .pro-catalog-tools .btn{width:100%;white-space:normal}
}
@media(max-width:420px){.pro-grid{grid-template-columns:1fr}.pro-field.span-2,.pro-field.span-3{grid-column:1}.pro-paper-head{gap:10px}.pro-title-icon{width:40px;height:40px}}
</style>
<style>
.pro-print-logo{display:none}
@media print{
    .pro-title-icon{display:none!important}
    .pro-print-logo{display:block!important;width:80mm;max-width:100%;height:auto;flex:0 1 auto;object-fit:contain}
}
</style>
<?php if ($isSavedProtocolView && $isEditingProtocol && $editId === $requestedViewId): ?>
<section class="pro-wrap pro-saved-view" aria-label="Sparat serviceprotokoll">
    <div class="invoice-session-bar"><div class="invoice-session-person"><strong><?= h($protocolAuthUser['name'] ?? $protocolAuthUser['username'] ?? 'Inloggad') ?></strong><span class="invoice-session-role">Protokollkonto</span></div><form class="invoice-session-form" method="post" action="?tab=protocol"><input type="hidden" name="action" value="protocol_pin_logout"><?= csrf_field() ?><button class="btn btn-outline" type="submit">Logga ut från protokoll</button></form></div>
    <nav class="pro-subnav" aria-label="Serviceprotokollflikar"><a href="?tab=protocol">Nytt protokoll</a><a href="?tab=protocol&amp;protocol_view=records" aria-current="page">Sparade protokoll (<?= count($savedProtocols) ?>)</a></nav>
    <?php $viewEmailStatus = is_array($editProtocol['internal_email_status'] ?? null) ? $editProtocol['internal_email_status'] : []; ?>
    <?php if ($viewEmailStatus): ?>
        <div class="pro-view-email" role="status">
            <?php if (($viewEmailStatus['result'] ?? '') === 'accepted'): ?>PDF-mejlet accepterades av e-posttransporten till portalens primära interna mottagare (<?= h($viewEmailStatus['attempts'] ?? 1) ?> försök). Det bekräftar inte leverans till inkorgen. Ingen kundkopia skickades.
            <?php elseif (($viewEmailStatus['result'] ?? '') === 'pending'): ?>Status för PDF-mejlet kunde inte bekräftas. Kontrollera den interna inkorgen innan du försöker igen; protokollet är sparat. Ingen kundkopia skickades.
            <?php elseif (($viewEmailStatus['result'] ?? '') === 'not_configured'): ?>Ingen giltig primär intern mottagare är konfigurerad; PDF-mejl har inte skickats. Ingen kundkopia skickades.
            <?php else: ?>PDF-mejlet kunde inte accepteras av e-posttransporten. Protokollet är sparat och finns kvar i Historik. Ingen kundkopia skickades.
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="pro-toolbar"><div><p>Sparat serviceprotokoll · <?= h($editProtocol['updated_at'] ?? '') ?></p></div><a class="btn btn-outline" href="?tab=protocol&amp;protocol_view=records">Sparade protokoll</a></div>
    <div class="pro-paper">
        <div class="pro-paper-head"><div class="pro-title-group"><img class="pro-title-icon" src="?view_file=assets/tadega-icon.svg&amp;v=<?= (string) (@filemtime(__DIR__ . '/../assets/tadega-icon.svg') ?: '1') ?>" alt="" width="48" height="48"><img class="pro-print-logo" src="?view_file=assets/tadega-logo.svg&amp;v=<?= (string) (@filemtime(__DIR__ . '/../assets/tadega-logo.svg') ?: '1') ?>" alt="Tadega Dokumentportalen" width="1000" height="240"><div><h2>SERVICEPROTOKOLL</h2><p class="pro-kicker">TADEGA AB · BILSERVICE</p></div></div><div class="pro-number"><small>PROTOKOLL NR</small><strong><?= h($protocolNumber) ?></strong></div></div>
        <section class="pro-section"><div class="pro-section-title">Kund och fordon</div><div class="pro-grid">
            <div class="pro-field"><label>Registreringsnummer</label><div class="pro-readonly"><?= h($editProtocol['reg'] ?? $editProtocol['regnr'] ?? '—') ?></div></div>
            <div class="pro-field"><label>Datum</label><div class="pro-readonly"><?= h($editProtocol['date'] ?? '—') ?></div></div>
            <div class="pro-field"><label>Mätarställning (km)</label><div class="pro-readonly"><?= h($editProtocol['km'] ?? '—') ?></div></div>
            <div class="pro-field span-2"><label>Kund / företag</label><div class="pro-readonly"><?= h($editProtocol['customer'] ?? '—') ?></div></div>
            <div class="pro-field"><label>Tekniker</label><div class="pro-readonly"><?= h($editProtocol['tech'] ?? '—') ?></div></div>
        </div></section>
        <section class="pro-section"><div class="pro-section-title">Utfört arbete</div><div class="pro-readonly"><?= h($editProtocol['description'] ?? '—') ?></div></section>
        <section class="pro-section"><div class="pro-section-title">Material och arbetsmoment</div>
            <?php $viewItemsPresent = false; foreach ($items as $viewItem) { if (trim((string) ($viewItem['art'] ?? '') . (string) ($viewItem['qty'] ?? '') . (string) ($viewItem['spec'] ?? '')) !== '') { $viewItemsPresent = true; break; } } ?>
            <?php if ($viewItemsPresent): ?><div class="pro-table-wrap"><table class="pro-view-items"><thead><tr><th>Artikel / arbetsmoment</th><th>Antal</th><th>Teknisk specifikation</th></tr></thead><tbody><?php foreach ($items as $viewItem): if (trim((string) ($viewItem['art'] ?? '') . (string) ($viewItem['qty'] ?? '') . (string) ($viewItem['spec'] ?? '')) === '') { continue; } ?><tr><td><?= h($viewItem['art'] ?? '') ?></td><td><?= h($viewItem['qty'] ?? '') ?></td><td><?= h($viewItem['spec'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table></div>
            <?php else: ?><div class="pro-readonly">Inga material eller arbetsmoment angivna.</div><?php endif; ?>
        </section>
        <section class="pro-section"><div class="pro-section-title">Foto och signatur</div><div class="pro-view-media">
            <div class="pro-view-media-card"><h3>Mätarställningsfoto</h3><?php if ($protocolMeterPhotoFile !== ''): ?><img src="?tab=protocol&amp;view_file=<?= h(rawurlencode($protocolMeterPhotoFile)) ?>" alt="Foto av mätarställningen"><?php else: ?><p>Inget foto bifogat.</p><?php endif; ?></div>
            <div class="pro-view-media-card"><h3>Teknikerns handritade signatur</h3><?php if ($protocolSignatureFile !== ''): ?><img src="?tab=protocol&amp;view_file=<?= h(rawurlencode($protocolSignatureFile)) ?>" alt="Teknikerns handritade signatur"><?php else: ?><p>Ingen sparad signatur.</p><?php endif; ?><p><small><?= h($editProtocol['tech'] ?? '') ?></small></p></div>
        </div></section>
    </div>
    <div class="pro-view-actions pro-noprint"><button class="btn btn-main" type="button" id="protocol-saved-print">Skriv ut A4</button><a class="btn btn-outline" href="?tab=protocol&amp;edit_id=<?= h(rawurlencode((string) ($editProtocol['id'] ?? ''))) ?>">Redigera</a><a class="btn btn-outline" href="?tab=protocol">Nytt protokoll</a></div>
</section>
<script>(function(){var button=document.getElementById('protocol-saved-print');if(button)button.addEventListener('click',function(){window.print();});})();</script>
<?php return; endif; ?>
<section class="pro-wrap" aria-label="Serviceprotokoll">
    <div class="invoice-session-bar"><div class="invoice-session-person"><strong><?= h($protocolAuthUser['name'] ?? $protocolAuthUser['username'] ?? 'Inloggad') ?></strong><span class="invoice-session-role">Protokollkonto</span></div><form class="invoice-session-form" method="post" action="?tab=protocol"><input type="hidden" name="action" value="protocol_pin_logout"><?= csrf_field() ?><button class="btn btn-outline" type="submit">Logga ut från protokoll</button></form></div>
    <nav class="pro-subnav" aria-label="Serviceprotokollflikar"><a href="?tab=protocol" aria-current="page">Nytt protokoll</a><a href="?tab=protocol&amp;protocol_view=records">Sparade protokoll (<?= count($savedProtocols) ?>)</a></nav>
    <section class="pro-noprint" aria-labelledby="protocol-drafts-title" style="margin-top:14px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap"><div><h2 id="protocol-drafts-title" style="margin:0 0 4px;font-size:1rem">Utkast</h2><p style="margin:0;color:#667085;font-size:.82rem">Formulär sparas automatiskt i tre dagar. När du sparar protokollet tas utkastet bort.</p></div><a class="btn btn-outline" href="?tab=protocol">Nytt protokoll</a></div>
        <?php if ($protocolDrafts): ?>
            <ul style="list-style:none;margin:12px 0 0;padding:0;display:grid;gap:8px">
                <?php foreach ($protocolDrafts as $protocolDraft): $draftFields = is_array($protocolDraft['data']['p'] ?? null) ? $protocolDraft['data']['p'] : []; $draftLabel = trim((string) ($draftFields['customer'] ?? '')); if ($draftLabel === '') { $draftLabel = trim((string) ($draftFields['reg'] ?? '')); } if ($draftLabel === '') { $draftLabel = 'Nytt serviceprotokoll'; } ?>
                    <li style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:10px;border:1px solid #dce3ea;border-radius:9px;background:#fff"><span><strong><?= h($draftLabel) ?></strong><br><small style="color:#667085">Senast ändrat <?= h(date('Y-m-d H:i', (int) ($protocolDraft['updated_at'] ?? time()))) ?></small></span><span style="display:flex;gap:7px;align-items:center"><a class="btn btn-outline" href="?tab=protocol&amp;draft_id=<?= h($protocolDraft['id'] ?? '') ?>">Öppna</a><form method="post" action="?tab=protocol" onsubmit="return confirm('Radera det här utkastet?')"><input type="hidden" name="action" value="delete_autosaved_draft"><input type="hidden" name="draft_id" value="<?= h($protocolDraft['id'] ?? '') ?>"><?= csrf_field() ?><button class="btn btn-outline" type="submit">Radera</button></form></span></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p style="margin:12px 0 0;color:#667085;font-size:.82rem">Inga andra osparade utkast just nu. Det här formuläret autosparas när du börjar skriva; du kan alltid börja ett nytt protokoll.</p>
        <?php endif; ?>
    </section>
    <div class="pro-toolbar"><div><p>Registrera fordon, utfört arbete och material.</p></div><a class="btn btn-outline" href="?tab=protocol&amp;protocol_view=records">Sparade protokoll</a></div>
    <div class="pro-paper">
        <div class="pro-paper-head"><div class="pro-title-group"><img class="pro-title-icon" src="?view_file=assets/tadega-icon.svg&amp;v=<?= (string) (@filemtime(__DIR__ . '/../assets/tadega-icon.svg') ?: '1') ?>" alt="" width="48" height="48"><img class="pro-print-logo" src="?view_file=assets/tadega-logo.svg&amp;v=<?= (string) (@filemtime(__DIR__ . '/../assets/tadega-logo.svg') ?: '1') ?>" alt="Tadega Dokumentportalen" width="1000" height="240"><div><h2>SERVICEPROTOKOLL</h2><p class="pro-kicker">TADEGA AB · BILSERVICE</p></div></div><div class="pro-number"><small>PROTOKOLL NR</small><strong><?= h($protocolNumber) ?></strong></div></div>
        <?php if ($protocolCopySourceNumber !== ''): ?><div class="pro-copy-notice" role="status">Kopia från protokoll <?= h($protocolCopySourceNumber) ?>. Dagens datum och nytt protokollnummer används. Foto och tidigare signatur följer inte med; teknikern signerar den nya posten.</div><?php endif; ?>
        <form method="post" action="?tab=protocol" id="protocol-form" autocomplete="on" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save_protocol"><input type="hidden" name="p[id]" value="<?= h($editProtocol['id'] ?? '') ?>"><input type="hidden" name="draft_id" value="<?= h($protocolDraftId) ?>"><?= csrf_field() ?>
            <section class="pro-section" data-protocol-step="1"><div class="pro-section-title" id="protocol-step-1-title" tabindex="-1">Kund och fordon</div><div class="pro-grid">
                <div class="pro-field"><label for="p-reg">Registreringsnummer *</label><input id="p-reg" name="p[reg]" required maxlength="20" autocomplete="off" placeholder="ABC 123" aria-describedby="p-reg-help" value="<?= h($editProtocol['reg'] ?? '') ?>"><small class="pro-field-help" id="p-reg-help">Identifierar vilket fordon arbetet och protokollet gäller.</small></div>
                <div class="pro-field"><label for="p-date">Datum</label><input id="p-date" type="date" name="p[date]" aria-describedby="p-date-help" value="<?= h($editProtocol['date'] ?? date('Y-m-d')) ?>"><small class="pro-field-help" id="p-date-help">Visar när servicearbetet utfördes och hjälper till att hitta rätt händelse i historiken.</small></div>
                <div class="pro-field"><label for="p-km">Mätarställning (km)</label><input id="p-km" name="p[km]" inputmode="numeric" maxlength="20" placeholder="124 500" aria-describedby="p-km-help" value="<?= h($editProtocol['km'] ?? '') ?>"><small class="pro-field-help" id="p-km-help">Dokumenterar bilens körsträcka vid servicebesöket.</small></div>
                <div class="pro-field span-2"><label for="p-customer">Kund / företag</label><input id="p-customer" name="p[customer]" maxlength="180" autocomplete="organization" placeholder="Kundnamn" aria-describedby="p-customer-help" value="<?= h($editProtocol['customer'] ?? '') ?>"><small class="pro-field-help" id="p-customer-help">Kopplar arbetet till rätt kund och gör protokollet lättare att hitta.</small></div>
                <div class="pro-field"><label for="p-tech">Tekniker</label><input id="p-tech" name="p[tech]" maxlength="120" placeholder="Namn" aria-describedby="p-tech-help" value="<?= h($editProtocol['tech'] ?? '') ?>"><small class="pro-field-help" id="p-tech-help">Visar vem som utförde och signerar arbetet.</small></div>
            </div>
                <div class="pro-photo-control pro-noprint">
                    <label for="protocol-meter-photo">Foto av mätarställningen <span class="pro-kicker">(valfritt)</span></label>
                    <input id="protocol-meter-photo" name="protocol_meter_photo" type="file" accept="image/jpeg,image/png" capture="environment" aria-describedby="protocol-meter-photo-help protocol-meter-photo-status">
                    <p class="pro-photo-status" id="protocol-meter-photo-help">Fotot dokumenterar mätarställningen och gör avläsningen möjlig att kontrollera senare. Ta ett foto med mobilkameran; stora bilder optimeras på enheten när webbläsaren stöder det. Annars gäller gränsen 12 MB. Siffrorna läses inte av automatiskt.</p>
                    <button class="btn btn-outline" type="button" id="protocol-meter-photo-retake" aria-label="Ta om eller byt foto av mätarställningen">Ta om / byt foto</button>
                    <p class="pro-photo-status" id="protocol-meter-photo-status" role="status" aria-live="polite"><?= $protocolMeterPhotoFile !== '' ? 'Ett foto finns redan. Välj en ny bild om du vill ersätta det.' : 'Ingen bild vald. Fotot sparas när du sparar protokollet.' ?></p>
                </div>
            </section>
            <?php if ($customers): ?>
                <div class="pro-noprint pro-import" data-protocol-step="1"><div><label class="pro-kicker" for="p-customer-select">Snabbfyll kund från registret</label><select id="p-customer-select"><option value="">Välj kund…</option><?php foreach ($customers as $customer): ?><option data-name="<?= h(html_entity_decode((string) ($customer['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?>" data-ref="<?= h(html_entity_decode((string) ($customer['reference'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?>"><?= h(html_entity_decode((string) ($customer['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?></option><?php endforeach; ?></select></div><button class="btn btn-outline" type="button" id="p-import-customer">Fyll i kund</button></div>
            <?php endif; ?>
            <section class="pro-section" data-protocol-step="2"><div class="pro-section-title" id="protocol-step-2-title" tabindex="-1">Arbetsbeskrivning</div><div class="pro-field"><label for="p-description">Utfört arbete / anteckningar</label><textarea id="p-description" name="p[description]" maxlength="5000" placeholder="Beskriv arbetet och eventuella rekommendationer…" aria-describedby="p-description-help"><?= h($editProtocol['description'] ?? '') ?></textarea><small class="pro-field-help" id="p-description-help">Förklarar vad som gjordes och om kunden bör få en rekommendation eller återkoppling.</small></div></section>
            <section class="pro-section" data-protocol-step="2">
                <div class="pro-section-title">Material och detaljer</div>
                <p class="pro-row-help" id="protocol-material-help" style="margin:10px 13px 0">Artikel / arbetsmoment beskriver vad som användes eller utfördes; antal dokumenterar mängd eller arbetstid; teknisk specifikation hjälper att skilja delar och varianter åt. I en rad: tryck Enter för att gå vidare; från sista fältet fortsätter du till nästa rad.</p>
                <div class="pro-table-wrap" role="region" aria-label="Material- och arbetsrader. Tabbning går mellan fälten." tabindex="0">
                    <table class="pro-table" id="p-table" aria-describedby="protocol-material-help">
                        <caption class="pro-visually-hidden">Material och arbetsmoment i serviceprotokollet</caption>
                        <thead><tr><th scope="col">Artikel / arbetsmoment</th><th scope="col" style="width:120px">Antal</th><th scope="col">Teknisk specifikation</th></tr></thead>
                        <tbody>
                            <?php for ($rowIndex = 0; $rowIndex < $rowCount; $rowIndex++): $item = is_array($items[$rowIndex] ?? null) ? $items[$rowIndex] : []; ?>
                                <tr>
                                    <td data-label="Artikel / arbetsmoment"><input class="p-name" name="items[<?= $rowIndex ?>][art]" maxlength="200" value="<?= h($item['art'] ?? '') ?>" placeholder="Artikel / arbete" aria-label="Artikel eller arbetsmoment, rad <?= $rowIndex + 1 ?>" aria-describedby="protocol-material-help"></td>
                                    <td data-label="Antal"><input class="p-qty" name="items[<?= $rowIndex ?>][qty]" type="number" min="0" step="0.1" value="<?= h($item['qty'] ?? '') ?>" aria-label="Antal, rad <?= $rowIndex + 1 ?>" aria-describedby="protocol-material-help"></td>
                                    <td data-label="Teknisk specifikation"><input name="items[<?= $rowIndex ?>][spec]" maxlength="300" value="<?= h($item['spec'] ?? '') ?>" placeholder="Exempel: 5W-30 / märke" aria-label="Teknisk specifikation, rad <?= $rowIndex + 1 ?>" aria-describedby="protocol-material-help"></td>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pro-noprint" style="border:0;background:none;padding:0;margin:8px 13px 0"><button class="btn btn-outline pro-add-row" type="button" id="p-new-row">+ Lägg till tom rad</button><p class="pro-field-help" id="p-row-status" role="status" aria-live="polite"></p></div>
                <div class="pro-noprint pro-catalog-panel" data-protocol-step="2" aria-label="Återanvändbara artiklar för serviceprotokoll">
                    <p>Serviceprotokollets artikelkatalog är separat från fakturakatalogen. Medarbetare kan välja en sparad artikel och lägga in den i nästa tomma rad.</p>
                    <?php if (!$protocolCatalogStorageReady): ?>
                        <p role="status">Den privata serviceprotokollkatalogen kunde inte läsas. Fortsätt genom att skriva raden manuellt.</p>
                    <?php else: ?>
                        <div class="pro-catalog-tools">
                            <div><label for="p-catalog-article">Importera sparad serviceartikel</label><select id="p-catalog-article" aria-describedby="p-catalog-import-help"><option value="">Välj artikel…</option><?php foreach ($protocolCatalogArticles as $protocolArticle): ?><option value="<?= h($protocolArticle['id']) ?>" data-name="<?= h($protocolArticle['name']) ?>" data-spec="<?= h($protocolArticle['spec']) ?>"><?= h($protocolArticle['name']) ?><?= $protocolArticle['spec'] !== '' ? ' — ' . h($protocolArticle['spec']) : '' ?></option><?php endforeach; ?></select><small class="pro-field-help" id="p-catalog-import-help">Import fyller artikel och specifikation; ange antal i formuläret.</small></div>
                            <button class="btn btn-outline" type="button" id="p-catalog-import" <?= !$protocolCatalogArticles ? 'disabled' : '' ?>>Importera till nästa tomma rad</button>
                        </div>
                        <p class="pro-catalog-status" id="p-catalog-import-status" role="status" aria-live="polite"></p>
                        <?php if (!$protocolCatalogArticles): ?><p id="p-catalog-empty">Katalogen är tom ännu. En serviceprotokolladmin kan spara en ifylld rad här.</p><?php endif; ?>
                        <?php if ($protocolIsAdmin): ?>
                            <div class="pro-catalog-tools">
                                <div><label for="p-catalog-source-row">Spara en ifylld rad i katalogen</label><select id="p-catalog-source-row"><option value="">Välj material- eller arbetsrad…</option></select></div>
                                <button class="btn btn-main" type="button" id="p-catalog-save">Spara för medarbetarna</button>
                            </div>
                            <p class="pro-catalog-status" id="p-catalog-status" role="status" aria-live="polite">Sparade artiklar kan senare importeras av serviceprotokollanvändare.</p>
                        <?php else: ?>
                            <p>Endast serviceprotokolladmin kan spara artiklar i den gemensamma katalogen.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>
            <section class="pro-section pro-review" data-protocol-step="3" aria-labelledby="protocol-review-title">
                <div class="pro-section-title" id="protocol-review-title" tabindex="-1">Granska och spara</div>
                <div style="padding:13px">
                    <dl class="pro-review-details">
                        <div><dt>Protokollnummer</dt><dd><?= h($protocolNumber) ?></dd></div>
                        <div><dt>Registreringsnummer</dt><dd id="protocol-review-reg">—</dd></div>
                        <div><dt>Kund / företag</dt><dd id="protocol-review-customer">—</dd></div>
                        <div><dt>Datum</dt><dd id="protocol-review-date">—</dd></div>
                        <div><dt>Mätarställning</dt><dd id="protocol-review-km">—</dd></div>
                        <div><dt>Tekniker</dt><dd id="protocol-review-tech">—</dd></div>
                    </dl>
                    <h3 class="pro-review-heading">Arbetsbeskrivning</h3>
                    <p class="pro-review-description" id="protocol-review-description">Inte angivet</p>
                    <h3 class="pro-review-heading">Material och detaljer</h3>
                    <ul class="pro-wizard-review-list" id="protocol-review-items"><li>Inga materialrader angivna.</li></ul>
                    <p class="pro-note">Kontrollera uppgifterna. Du kan gå tillbaka och ändra innan du sparar.</p>
                </div>
            </section>
            <section class="pro-section" data-protocol-step="3" aria-labelledby="protocol-media-title">
                <div class="pro-section-title" id="protocol-media-title">Foto och signatur</div>
                <div class="pro-photo-preview">
                    <img id="protocol-meter-photo-preview" alt="Foto av mätarställningen" <?= $protocolMeterPhotoFile !== '' ? 'src="?tab=protocol&amp;view_file=' . h(rawurlencode($protocolMeterPhotoFile)) . '"' : 'hidden' ?> data-existing-src="<?= $protocolMeterPhotoFile !== '' ? h('?tab=protocol&view_file=' . rawurlencode($protocolMeterPhotoFile)) : '' ?>">
                    <p id="protocol-meter-photo-review-status"><?= $protocolMeterPhotoFile !== '' ? 'Fotot bifogas det sparade serviceprotokollet.' : 'Inget foto valt.' ?></p>
                </div>
                <div class="pro-signature-capture pro-noprint">
                    <label for="protocol-signature-canvas">Teknikerns handritade signatur <span class="pro-kicker">(obligatorisk)</span></label>
                    <p class="pro-photo-status" id="protocol-signature-help">Signaturen visar vem som har utfört och godkänt protokollet. Skriv i ett lugnt, sammanhängande drag med finger, penna eller mus. Ritytan jämnar ut små ryck och ger strecket naturlig avsmalning och lätt breddvariation. Signaturen följer med utskriften och sparas först när protokollet sparas, inte i utkastet.</p>
                    <canvas id="protocol-signature-canvas" class="pro-signature-canvas" width="1000" height="220" tabindex="0" role="img" aria-label="Rityta för teknikerns signatur" aria-describedby="protocol-signature-help"></canvas>
                    <input type="hidden" id="protocol-signature-data" name="p[signature_png]" value="" data-existing-signature="<?= $protocolSignatureFile !== '' ? '1' : '0' ?>" data-require-fresh-signature="<?= $protocolSignatureFreshRequired ? '1' : '0' ?>">
                    <input type="hidden" id="protocol-signature-remove" name="p[signature_remove]" value="0">
                    <div class="pro-signature-actions">
                        <p class="pro-signature-status" id="protocol-signature-status" role="status" aria-live="polite"><?= $protocolSignatureFreshRequired ? 'Utkastet ändrar sparat innehåll. En ny signatur krävs innan ändringarna kan sparas.' : ($protocolSignatureFile !== '' ? 'En sparad signatur finns. Rita en ny för att ersätta den.' : 'Rita signaturen innan du sparar.') ?></p>
                        <button class="btn btn-outline" type="button" id="protocol-signature-clear">Rensa</button>
                    </div>
                    <p class="pro-signature-error" id="protocol-signature-error" role="alert" hidden>Rita teknikerns signatur innan du sparar protokollet.</p>
                </div>
            </section>
            <div class="pro-sign" data-protocol-step="3"><div><small>Ort & datum</small><div style="font-weight:700;margin-top:5px">Västervik, <?= h(date('Y-m-d')) ?></div></div><div class="pro-signature-block"><small>Signatur tekniker</small><img id="protocol-signature-preview" class="pro-signature-image" alt="Teknikerns handritade signatur" <?= $protocolSignatureFile !== '' ? 'src="?tab=protocol&amp;view_file=' . h(rawurlencode($protocolSignatureFile)) . '"' : 'hidden' ?> data-existing-src="<?= $protocolSignatureFile !== '' ? h('?tab=protocol&view_file=' . rawurlencode($protocolSignatureFile)) : '' ?>"><span class="pro-signature-placeholder" id="protocol-signature-placeholder" <?= $protocolSignatureFile !== '' ? 'hidden' : '' ?>>Rita signaturen ovan</span></div></div>
            <div class="pro-actions" data-protocol-step="3"><button class="btn btn-main" type="submit"><?= $isEditingProtocol ? 'Spara ändringar' : 'Spara serviceprotokoll' ?></button><button class="btn btn-outline" type="button" id="protocol-print">Skriv ut A4</button><?php if ($isEditingProtocol): ?><a class="btn btn-outline" href="?tab=protocol">Avbryt</a><?php endif; ?></div>
            <p class="pro-note" data-protocol-step="3">Kontrollera uppgifterna innan du sparar eller skriver ut.</p>
            <p class="pro-note pro-noprint" id="protocol-autosave-status" role="status" aria-live="polite"><span id="protocol-autosave-message">Utkastet sparas automatiskt när du börjar skriva.</span> <a id="protocol-autosave-resume" href="?tab=protocol&amp;draft_id=<?= h($protocolDraftId) ?>"<?= is_array($protocolDraftData) ? '' : ' hidden' ?>>Öppna det autosparade utkastet</a></p>
            <nav class="pro-wizard-nav" id="protocol-wizard-nav" data-mobile-action-dock aria-label="Steg i serviceprotokollet">
                <div class="pro-progress">
                    <p id="protocol-step-status" aria-live="polite">Steg 1 av 3: Kund och fordon</p>
                    <div class="pro-progress-track" id="protocol-step-progress" role="progressbar" aria-label="Fyll i serviceprotokollet" aria-valuemin="1" aria-valuemax="3" aria-valuenow="1"><span></span></div>
                </div>
                <div class="pro-step-buttons">
                    <button class="btn btn-outline" type="button" id="protocol-step-back" hidden>← Tillbaka</button>
                    <button class="btn btn-main" type="button" id="protocol-step-next">Fortsätt till arbete och material →</button>
                    <button class="btn btn-main" type="submit" id="protocol-step-save" hidden><?= $isEditingProtocol ? 'Spara ändringar' : 'Spara serviceprotokoll' ?></button>
                </div>
            </nav>
        </form>
    </div>
</section>
<script>
(function(){
 'use strict';
 var form=document.getElementById('protocol-form');
 var wrap=document.querySelector('.pro-wrap');
 var table=document.getElementById('p-table');
 var rowStatus=document.getElementById('p-row-status');
 var nextIndex=table?table.tBodies[0].rows.length:0;
 var panels=form?Array.prototype.slice.call(form.querySelectorAll('[data-protocol-step]')):[];
 var media=window.matchMedia?window.matchMedia('(max-width:660px), (max-width:900px) and (max-height:560px) and (orientation:landscape)'):null;
 var currentStep=1;
 var stepNames=['Kund och fordon','Arbete och material','Granska och spara'];
 var status=document.getElementById('protocol-step-status');
 var progress=document.getElementById('protocol-step-progress');
 var back=document.getElementById('protocol-step-back');
 var next=document.getElementById('protocol-step-next');
 var save=document.getElementById('protocol-step-save');
 var autosaveMessage=document.getElementById('protocol-autosave-message');
 var autosaveResume=document.getElementById('protocol-autosave-resume');
 var draftIdField=form?form.querySelector('input[name="draft_id"]'):null;
 var autosaveTimer=null;
 var autosaveRequest=null;
 var changeVersion=0;
 var savedVersion=0;
 var draftExists=<?= is_array($protocolDraftData) ? 'true' : 'false' ?>;
 var finalSaving=false;
 var submitAfterAutosave=false;
 var deferredSubmitter=null;
 var photoInput=document.getElementById('protocol-meter-photo');
 var photoRetakeButton=document.getElementById('protocol-meter-photo-retake');
 var photoStatus=document.getElementById('protocol-meter-photo-status');
 var photoPreview=document.getElementById('protocol-meter-photo-preview');
 var photoReviewStatus=document.getElementById('protocol-meter-photo-review-status');
 var photoPreviewUrl='';
 var photoOptimizationPending=false;
 var photoSelectionVersion=0;
 var protocolCatalogSelect=document.getElementById('p-catalog-article');
 var protocolCatalogImportButton=document.getElementById('p-catalog-import');
 var protocolCatalogSourceSelect=document.getElementById('p-catalog-source-row');
 var protocolCatalogSaveButton=document.getElementById('p-catalog-save');
 var protocolCatalogStatus=document.getElementById('p-catalog-status');
 var protocolCatalogImportStatus=document.getElementById('p-catalog-import-status');
 var signatureCanvas=document.getElementById('protocol-signature-canvas');
 var signatureData=document.getElementById('protocol-signature-data');
 var signatureRemove=document.getElementById('protocol-signature-remove');
 var signatureStatus=document.getElementById('protocol-signature-status');
 var signatureError=document.getElementById('protocol-signature-error');
 var signatureImage=document.getElementById('protocol-signature-preview');
 var signaturePlaceholder=document.getElementById('protocol-signature-placeholder');
 var signatureExisting=!!(signatureData&&signatureData.getAttribute('data-existing-signature')==='1');
 var protocolContentChanged=!!(signatureData&&signatureData.getAttribute('data-require-fresh-signature')==='1');
 if(protocolContentChanged&&signatureExisting&&signatureRemove)signatureRemove.value='1';
 var signatureHasInk=false;
 var signaturePad=null;
 if(signatureCanvas&&window.TadegaSignaturePad&&typeof window.TadegaSignaturePad.attach==='function'){
  signaturePad=window.TadegaSignaturePad.attach(signatureCanvas,{
   color:'#172033',background:null,
   onStart:function(){
    signatureHasInk=true;
    if(signatureRemove)signatureRemove.value='0';
    if(signatureStatus)signatureStatus.textContent='Skriv signaturen i ett lugnt, sammanhängande drag.';
    if(signatureError)signatureError.hidden=true;
    signatureCanvas.removeAttribute('aria-invalid');
   },
   onEnd:function(){
    var signaturePng=signatureHasInk&&signaturePad?signaturePad.serializeCropped():'';
    if(signaturePng&&signatureData){
     signatureData.value=signaturePng;
     if(signatureStatus)signatureStatus.textContent='Signaturen är ritad. Den bifogas när protokollet sparas.';
    }else{
     signatureHasInk=false;
     if(signatureData)signatureData.value='';
     if(signatureStatus)signatureStatus.textContent='Signaturen kunde inte läsas. Rensa rutan och försök igen.';
    }
    updateSignaturePreview();
    signatureIsValid(false);
   }
  });
 }
 if(!signaturePad&&signatureCanvas&&signatureStatus)signatureStatus.textContent='Digital signering kunde inte starta. Ladda om sidan innan du sparar.';
 if(protocolContentChanged&&signatureStatus)signatureStatus.textContent='Utkastet ändrar sparat innehåll. Rita en ny signatur innan du sparar.';

 function setAutosaveMessage(message){if(autosaveMessage)autosaveMessage.textContent=message;}
 function markProtocolContentChanged(){
  protocolContentChanged=true;
  if(signatureHasInk){
   clearSignature();
  }else if(signatureExisting&&signatureRemove){
   signatureRemove.value='1';
   if(signatureStatus)signatureStatus.textContent='Protokollets innehåll har ändrats. Rita en ny signatur innan du sparar.';
   updateSignaturePreview();
   signatureIsValid(false);
  }
 }
 function isProtocolContentControl(target){
  if(!target||typeof target.name!=='string')return false;
  return ['p[reg]','p[date]','p[km]','p[customer]','p[tech]','p[description]','protocol_meter_photo'].indexOf(target.name)!==-1||target.name.indexOf('items[')===0;
 }
 function updateSignaturePreview(){

  if(!signatureImage)return;
  if(signatureHasInk&&signatureCanvas){
   var signaturePreviewData=signatureData&&signatureData.value?signatureData.value:(signaturePad?signaturePad.serializeCropped():'');
   if(signaturePreviewData)signatureImage.src=signaturePreviewData;
   signatureImage.hidden=false;
   if(signaturePlaceholder)signaturePlaceholder.hidden=true;
   return;
  }
  var existingSrc=signatureImage.getAttribute('data-existing-src')||'';
  if(signatureExisting&&!protocolContentChanged&&signatureRemove&&signatureRemove.value!=='1'&&existingSrc){
   if(signatureImage.getAttribute('src')!==existingSrc)signatureImage.src=existingSrc;
   signatureImage.hidden=false;
   if(signaturePlaceholder)signaturePlaceholder.hidden=true;
  }else{
   signatureImage.hidden=true;
   if(signaturePlaceholder)signaturePlaceholder.hidden=false;
  }
 }
 function signatureIsValid(showError){
  var valid=!!((signatureData&&signatureData.value.trim()!=='')||(signatureExisting&&!protocolContentChanged&&signatureRemove&&signatureRemove.value!=='1'));
  if(signatureError)signatureError.hidden=valid||!showError;
  if(signatureCanvas){if(valid)signatureCanvas.removeAttribute('aria-invalid');else signatureCanvas.setAttribute('aria-invalid','true');}
  return valid;
 }
 function clearSignature(){
  if(signaturePad)signaturePad.clear();
  signatureHasInk=false;
  if(signatureData)signatureData.value='';
  if(signatureRemove)signatureRemove.value=signatureExisting?'1':'0';
  if(signatureStatus)signatureStatus.textContent=signatureExisting?'Ritytan rensad. Rita en ny signatur för att ersätta den sparade.':'Rita signaturen innan du sparar protokollet.';
  if(signatureError)signatureError.hidden=true;
  updateSignaturePreview();
  signatureIsValid(false);
 }
 function restoreMeterPhoto(){
  if(photoPreview){
   if(photoPreviewUrl){URL.revokeObjectURL(photoPreviewUrl);photoPreviewUrl='';}
   var existingSrc=photoPreview.getAttribute('data-existing-src')||'';
   if(existingSrc){photoPreview.src=existingSrc;photoPreview.hidden=false;}else{photoPreview.removeAttribute('src');photoPreview.hidden=true;}
  }
  var hasExisting=!!(photoPreview&&photoPreview.getAttribute('data-existing-src'));
  if(photoStatus)photoStatus.textContent=hasExisting?'Ett foto finns redan. Välj en ny bild om du vill ersätta det.':'Ingen bild vald. Fotot sparas när du sparar protokollet.';
  if(photoReviewStatus)photoReviewStatus.textContent=hasExisting?'Fotot bifogas det sparade serviceprotokollet.':'Inget foto valt.';
 }
 function setPhotoMessage(message){if(photoStatus)photoStatus.textContent=message;if(photoReviewStatus)photoReviewStatus.textContent=message;}
 function setPhotoInputFile(file){
  if(!photoInput||typeof DataTransfer==='undefined')return false;
  try{var transfer=new DataTransfer();transfer.items.add(file);photoInput.files=transfer.files;return !!(photoInput.files&&photoInput.files[0]);}catch(e){return false;}
 }
 function optimizeMeterPhoto(file){
  if(!file||typeof window.createImageBitmap!=='function')return Promise.resolve(file);
  return window.createImageBitmap(file).then(function(bitmap){
   var width=bitmap.width||0,height=bitmap.height||0;
   if(width<1||height<1){if(bitmap.close)bitmap.close();return file;}
   if(Math.max(width,height)<=2400&&file.size<=2621440){if(bitmap.close)bitmap.close();return file;}
   var scale=Math.min(1,2400/Math.max(width,height));
   var canvas=document.createElement('canvas');
   canvas.width=Math.max(1,Math.round(width*scale));
   canvas.height=Math.max(1,Math.round(height*scale));
   var context=canvas.getContext('2d');
   if(!context){if(bitmap.close)bitmap.close();return file;}
   context.fillStyle='#fff';context.fillRect(0,0,canvas.width,canvas.height);
   context.drawImage(bitmap,0,0,canvas.width,canvas.height);
   return new Promise(function(resolve){
    canvas.toBlob(function(blob){
     if(bitmap.close)bitmap.close();
     if(!blob||blob.size<1||blob.size>=file.size){resolve(file);return;}
     try{resolve(new File([blob],'matarstallning.jpg',{type:'image/jpeg',lastModified:Date.now()}));}catch(e){resolve(file);}
    },'image/jpeg',0.84);
   });
  }).catch(function(){return file;});
 }
 function validateMeterPhoto(){
  if(photoOptimizationPending){setPhotoMessage('Vänta tills fotooptimeringen är klar innan du fortsätter.');return false;}
  var file=photoInput&&photoInput.files&&photoInput.files[0];
  if(!file)return true;
  var allowedTypes=['image/jpeg','image/png'];
  if(file.size>12582912||(file.type&&allowedTypes.indexOf(file.type)===-1)){
   var message=file.size>12582912?'Bilden är större än 12 MB efter optimering. Välj en mindre JPEG- eller PNG-bild.':'Välj en JPEG- eller PNG-bild som kan bifogas protokollet.';
   setPhotoMessage(message);
   if(photoInput)photoInput.focus();
   return false;
  }
  return true;
 }
 function handleMeterPhotoChange(){
  var file=photoInput&&photoInput.files&&photoInput.files[0];
  var selection=++photoSelectionVersion;
  photoOptimizationPending=false;
  if(!file){restoreMeterPhoto();return;}
  if(file.size>41943040){setPhotoMessage('Bilden är större än 40 MB och kan inte förberedas. Välj en mindre JPEG- eller PNG-bild.');return;}
  if(file.type&&['image/jpeg','image/png'].indexOf(file.type)===-1){setPhotoMessage('Välj en JPEG- eller PNG-bild som kan bifogas protokollet.');return;}
  photoOptimizationPending=true;
  setPhotoMessage('Förbereder och optimerar foto för mobiluppladdning och A4…');
  optimizeMeterPhoto(file).then(function(optimized){
   if(selection!==photoSelectionVersion)return;
   photoOptimizationPending=false;
   var selectedFile=file;
   var wasOptimized=optimized!==file&&setPhotoInputFile(optimized);
   if(wasOptimized)selectedFile=optimized;
   if(!validateMeterPhoto())return;
   if(photoPreviewUrl)URL.revokeObjectURL(photoPreviewUrl);
   photoPreviewUrl=URL.createObjectURL(selectedFile);
   if(photoPreview){photoPreview.src=photoPreviewUrl;photoPreview.hidden=false;}
   var sizeLabel=(selectedFile.size/1048576).toFixed(1)+' MB';
   var message=wasOptimized?'Foto optimerat till '+sizeLabel+'. Det bifogas när du sparar protokollet.':'Foto redo ('+sizeLabel+'). Det bifogas när du sparar protokollet.';
   setPhotoMessage(message);
  }).catch(function(){
   if(selection!==photoSelectionVersion)return;
   photoOptimizationPending=false;
   if(!validateMeterPhoto())return;
   if(photoPreviewUrl)URL.revokeObjectURL(photoPreviewUrl);
   photoPreviewUrl=URL.createObjectURL(file);
   if(photoPreview){photoPreview.src=photoPreviewUrl;photoPreview.hidden=false;}
   setPhotoMessage('Foto redo. Det bifogas när du sparar protokollet.');
  });
 }
 var signatureClearButton=document.getElementById('protocol-signature-clear');
 if(signatureClearButton)signatureClearButton.addEventListener('click',clearSignature);
 if(photoInput)photoInput.addEventListener('change',handleMeterPhotoChange);
 if(photoRetakeButton&&photoInput)photoRetakeButton.addEventListener('click',function(){
  photoSelectionVersion++;
  photoOptimizationPending=false;
  try{photoInput.value='';}catch(ignore){}
  if(photoPreviewUrl){URL.revokeObjectURL(photoPreviewUrl);photoPreviewUrl='';}
  restoreMeterPhoto();
  photoInput.click();
 });
 updateSignaturePreview();
 function formHasDraftContent(){
  if(['p-reg','p-km','p-customer','p-tech','p-description'].some(function(id){return fieldValue(id)!=='';}))return true;
  if(!table||!table.tBodies.length)return false;
  return Array.prototype.some.call(table.tBodies[0].rows,function(row){return Array.prototype.some.call(row.querySelectorAll('input'),function(input){return input.value.trim()!=='';});});
 }
 function sendAutosave(){
  if(!form||finalSaving)return;
  autosaveTimer=null;
  if(autosaveRequest)return;
  if(!formHasDraftContent()&&!draftExists){savedVersion=changeVersion;setAutosaveMessage('Skriv i formuläret för att skapa ett utkast.');return;}
  if(!draftIdField||!draftIdField.value){setAutosaveMessage('Autosparning är inte tillgänglig just nu. Spara protokollet manuellt.');return;}
  var sentVersion=changeVersion;
  var draftFormData=new FormData(form);
  draftFormData.delete('protocol_meter_photo');
  draftFormData.delete('p[signature_png]');
  draftFormData.delete('p[signature_remove]');
  var body=new URLSearchParams(draftFormData);
  body.set('action','autosave_draft');
  setAutosaveMessage('Sparar utkast…');
  autosaveRequest=fetch('?tab=protocol',{method:'POST',credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'},body:body})
   .then(function(response){return response.json().then(function(data){if(!response.ok||!data||data.ok!==true)throw new Error((data&&data.code)||'autosave_failed');return data;});})
   .then(function(data){
    savedVersion=sentVersion;
    if(data.deleted){draftExists=false;if(autosaveResume)autosaveResume.hidden=true;setAutosaveMessage('Utkastet har raderats eftersom formuläret är tomt.');return;}
    if(data.draft_id){draftExists=true;if(autosaveResume){autosaveResume.href='?tab=protocol&draft_id='+encodeURIComponent(data.draft_id);autosaveResume.hidden=false;}setAutosaveMessage('Utkastet har sparats automatiskt.');return;}
    setAutosaveMessage('Inga utkastuppgifter att spara ännu.');
   })
   .catch(function(){setAutosaveMessage('Autosparning kunde inte bekräftas. Spara protokollet innan du lämnar sidan.');})
   .finally(function(){
    autosaveRequest=null;
    if(submitAfterAutosave){
     submitAfterAutosave=false;
     finalSaving=true;
     if(window.TadegaPortalSubmitState)window.TadegaPortalSubmitState.markBusy(form,'Sparar serviceprotokollet…',deferredSubmitter);
     deferredSubmitter=null;
     HTMLFormElement.prototype.submit.call(form);
     return;
    }
    if(changeVersion>sentVersion&&!finalSaving){autosaveTimer=window.setTimeout(sendAutosave,400);}
   });
 }
 function scheduleAutosave(){
  if(!form||finalSaving)return;
  changeVersion++;
  if(autosaveTimer)window.clearTimeout(autosaveTimer);
  autosaveTimer=window.setTimeout(sendAutosave,700);
 }

 function fieldValue(id){var field=document.getElementById(id);return field&&typeof field.value==='string'?field.value.trim():'';}
 function setText(id,value,fallback){var node=document.getElementById(id);if(node)node.textContent=value||fallback;}
 function updateReview(){
  var km=fieldValue('p-km');
  setText('protocol-review-reg',fieldValue('p-reg'),'Inte angivet');
  setText('protocol-review-customer',fieldValue('p-customer'),'Inte angivet');
  setText('protocol-review-date',fieldValue('p-date'),'Inte angivet');
  setText('protocol-review-km',km?km+' km':'','Inte angivet');
  setText('protocol-review-tech',fieldValue('p-tech'),'Inte angivet');
  setText('protocol-review-description',fieldValue('p-description'),'Inte angivet');
  var list=document.getElementById('protocol-review-items');
  if(!list)return;
  while(list.firstChild)list.removeChild(list.firstChild);
  var count=0;
  if(table&&table.tBodies.length){
   Array.prototype.forEach.call(table.tBodies[0].rows,function(row){
    var nameField=row.querySelector('.p-name');
    var qtyField=row.querySelector('.p-qty');
    var specField=row.cells[2]?row.cells[2].querySelector('input'):null;
    var name=nameField?nameField.value.trim():'';
    var qty=qtyField?qtyField.value.trim():'';
    var spec=specField?specField.value.trim():'';
    if(!name&&!qty&&!spec)return;
    var parts=[name||'Rad utan artikel'];
    if(qty)parts.push('Antal: '+qty);
    if(spec)parts.push(spec);
    var item=document.createElement('li');
    item.textContent=parts.join(' · ');
    list.appendChild(item);
    count++;
   });
  }
  if(!count){var empty=document.createElement('li');empty.textContent='Inga materialrader angivna.';list.appendChild(empty);}
  updateProtocolCatalogRows();
 }
 function focusHeading(step){
  var ids=['protocol-step-1-title','protocol-step-2-title','protocol-review-title'];
  var heading=document.getElementById(ids[step-1]);
  if(!heading)return;
  try{heading.focus({preventScroll:true});}catch(e){heading.focus();}
  if(typeof heading.scrollIntoView==='function'){
   try{heading.scrollIntoView({behavior:'smooth',block:'start'});}catch(e){heading.scrollIntoView();}
  }
 }
 function activateStep(step,shouldFocus){
  currentStep=Math.max(1,Math.min(3,step));
  panels.forEach(function(panel){panel.classList.toggle('is-active',Number(panel.getAttribute('data-protocol-step'))===currentStep);});
  if(status)status.textContent='Steg '+currentStep+' av 3: '+stepNames[currentStep-1];
  if(progress){
   progress.setAttribute('aria-valuenow',String(currentStep));
   var fill=progress.querySelector('span');
   if(fill)fill.style.width=(currentStep/3*100)+'%';
  }
  if(back){back.hidden=currentStep===1;back.textContent=currentStep===3?'← Tillbaka till arbete och material':'← Tillbaka till kund och fordon';}
  if(next){next.hidden=currentStep===3;next.textContent=currentStep===1?'Fortsätt till arbete och material →':'Granska protokollet →';}
  if(save)save.hidden=currentStep!==3;
  updateReview();
  if(shouldFocus)focusHeading(currentStep);
 }
 function registrationIsValid(){
  var reg=document.getElementById('p-reg');
  if(!reg||reg.checkValidity())return true;
  if(typeof reg.reportValidity==='function')reg.reportValidity();else reg.focus();
  return false;
 }
 function addRow(){
  if(!table||!table.tBodies.length)return null;
  var body=table.tBodies[0];
  if(body.rows.length>=50){if(rowStatus)rowStatus.textContent='Högst 50 material- och arbetsrader får finnas i ett protokoll.';return null;}
  var index=nextIndex++,rowNumber=body.rows.length+1,row=body.insertRow();
  row.innerHTML='<td data-label="Artikel / arbetsmoment"><input class="p-name" maxlength="200" name="items['+index+'][art]" placeholder="Artikel / arbete" aria-label="Artikel eller arbetsmoment, rad '+rowNumber+'" aria-describedby="protocol-material-help"></td><td data-label="Antal"><input class="p-qty" type="number" min="0" step="0.1" name="items['+index+'][qty]" aria-label="Antal, rad '+rowNumber+'" aria-describedby="protocol-material-help"></td><td data-label="Teknisk specifikation"><input maxlength="300" name="items['+index+'][spec]" placeholder="Teknisk specifikation" aria-label="Teknisk specifikation, rad '+rowNumber+'" aria-describedby="protocol-material-help"></td>';
  if(rowStatus)rowStatus.textContent='';
  updateReview();
  return row;
 }
 function updateProtocolCatalogRows(){
  if(!protocolCatalogSourceSelect||!table||!table.tBodies.length)return;
  var previous=protocolCatalogSourceSelect.value;
  while(protocolCatalogSourceSelect.options.length>1)protocolCatalogSourceSelect.remove(1);
  Array.prototype.forEach.call(table.tBodies[0].rows,function(row,index){
   var nameField=row.querySelector('.p-name');
   var specField=row.cells[2]?row.cells[2].querySelector('input'):null;
   var name=nameField?nameField.value.trim():'';
   if(!name)return;
   var option=document.createElement('option');
   option.value=String(index);
   option.textContent='Rad '+(index+1)+': '+name;
   if(specField&&specField.value.trim())option.textContent+=' — '+specField.value.trim();
   protocolCatalogSourceSelect.appendChild(option);
  });
  if(Array.prototype.some.call(protocolCatalogSourceSelect.options,function(option){return option.value===previous;}))protocolCatalogSourceSelect.value=previous;
  else protocolCatalogSourceSelect.value='';
 }
 var add=document.getElementById('p-new-row');
 if(add)add.addEventListener('click',function(){var row=addRow();if(row){var name=row.querySelector('.p-name');if(name)name.focus();}});
 if(protocolCatalogImportButton)protocolCatalogImportButton.addEventListener('click',function(){
  var option=protocolCatalogSelect&&protocolCatalogSelect.options[protocolCatalogSelect.selectedIndex];
  var name=option?option.getAttribute('data-name')||'':'';
  var spec=option?option.getAttribute('data-spec')||'':'';
  if(!name){if(protocolCatalogImportStatus)protocolCatalogImportStatus.textContent='Välj en sparad serviceartikel först.';if(protocolCatalogSelect)protocolCatalogSelect.focus();return;}
  var targetRow=null;
  if(table&&table.tBodies.length)Array.prototype.some.call(table.tBodies[0].rows,function(row){
   var nameField=row.querySelector('.p-name'),qtyField=row.querySelector('.p-qty'),specField=row.cells[2]?row.cells[2].querySelector('input'):null;
   if(nameField&&!nameField.value.trim()&&qtyField&&!qtyField.value.trim()&&specField&&!specField.value.trim()){targetRow=row;return true;}return false;
  });
  if(!targetRow)targetRow=addRow();
  if(!targetRow){if(protocolCatalogImportStatus)protocolCatalogImportStatus.textContent='Det går inte att lägga till fler rader. Ta bort eller använd en befintlig tom rad.';return;}
  var nameField=targetRow.querySelector('.p-name'),specField=targetRow.cells[2]?targetRow.cells[2].querySelector('input'):null;
  if(nameField)nameField.value=name;
  if(specField)specField.value=spec;
  if(protocolCatalogSelect)protocolCatalogSelect.value='';
  markProtocolContentChanged();
  updateReview();
  scheduleAutosave();
  if(protocolCatalogImportStatus)protocolCatalogImportStatus.textContent='Artikeln har lagts in. Ange antal eller arbetstid i den nya raden.';
  var quantityField=targetRow.querySelector('.p-qty');if(quantityField)quantityField.focus();
 });
 if(protocolCatalogSaveButton)protocolCatalogSaveButton.addEventListener('click',function(){
  if(protocolCatalogSaveButton.disabled)return;
  var sourceValue=protocolCatalogSourceSelect?protocolCatalogSourceSelect.value:'';
  var selectedIndex=sourceValue!==''?Number(sourceValue):NaN;
  var row=table&&table.tBodies.length&&Number.isInteger(selectedIndex)?table.tBodies[0].rows[selectedIndex]:null;
  var nameField=row?row.querySelector('.p-name'):null;
  var specField=row&&row.cells[2]?row.cells[2].querySelector('input'):null;
  var name=nameField?nameField.value.trim():'';
  var spec=specField?specField.value.trim():'';
  if(!name){if(protocolCatalogStatus)protocolCatalogStatus.textContent='Välj en ifylld material- eller arbetsrad först.';if(protocolCatalogSourceSelect)protocolCatalogSourceSelect.focus();return;}
  var csrf=form.querySelector('input[name="csrf_token"]');
  if(!csrf||!csrf.value){if(protocolCatalogStatus)protocolCatalogStatus.textContent='Säkerhetskontrollen saknas. Ladda om sidan innan du sparar.';return;}
  var body=new URLSearchParams();
  body.set('action','protocol_catalog_quick_save');
  body.set('csrf_token',csrf.value);
  body.set('article[name]',name);
  body.set('article[spec]',spec);
  protocolCatalogSaveButton.disabled=true;
  if(protocolCatalogStatus)protocolCatalogStatus.textContent='Sparar artikeln i serviceprotokollkatalogen…';
  fetch(form.action||window.location.href,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-Requested-With':'XMLHttpRequest'},body:body.toString()})
   .then(function(response){return response.json().then(function(data){if(!response.ok||!data||data.ok!==true)throw new Error(data&&data.code?data.code:'save_error');return data;});})
   .then(function(data){
    if(protocolCatalogSelect&&data.id&&!Array.prototype.some.call(protocolCatalogSelect.options,function(existing){return existing.value===data.id;})){
     var savedOption=document.createElement('option');savedOption.value=data.id;savedOption.setAttribute('data-name',name);savedOption.setAttribute('data-spec',spec);savedOption.textContent=name+(spec?' — '+spec:'');protocolCatalogSelect.appendChild(savedOption);
    }
    if(protocolCatalogImportButton&&protocolCatalogSelect&&protocolCatalogSelect.options.length>1)protocolCatalogImportButton.disabled=false;
    var emptyMessage=document.getElementById('p-catalog-empty');if(emptyMessage)emptyMessage.remove();
    if(protocolCatalogStatus)protocolCatalogStatus.textContent=data.code==='exists'?'Artikeln fanns redan i den gemensamma katalogen. Medarbetare kan importera den.':'Artikeln är sparad. Medarbetare kan nu importera den.';
   })
   .catch(function(error){
    var message=error&&error.message==='admin_required'?'Endast serviceprotokolladmin kan spara artiklar.':error&&error.message==='storage_error'?'Katalogen kunde inte sparas. Kontrollera privat lagring och försök igen.':'Artikeln kunde inte sparas. Kontrollera fälten och försök igen.';
    if(protocolCatalogStatus)protocolCatalogStatus.textContent=message;
   })
   .finally(function(){protocolCatalogSaveButton.disabled=false;});
 });
 var customerSelect=document.getElementById('p-customer-select');
 var importButton=document.getElementById('p-import-customer');
 if(importButton)importButton.addEventListener('click',function(){
  if(!customerSelect||customerSelect.selectedIndex<1)return;
  var option=customerSelect.options[customerSelect.selectedIndex];
  var customerField=document.getElementById('p-customer');
  if(customerField){customerField.value=option.getAttribute('data-name')||'';markProtocolContentChanged();}
  customerSelect.value='';
  updateReview();
  scheduleAutosave();
 });
 if(table)table.addEventListener('keydown',function(event){
  if(event.key!=='Enter'||event.isComposing||event.ctrlKey||event.metaKey||event.altKey)return;
  var current=event.target;
  if(!current||String(current.tagName||'').toLowerCase()!=='input')return;
  var row=current.closest('tr');
  if(!row||!table.tBodies.length)return;
  var rows=Array.prototype.slice.call(table.tBodies[0].rows),rowIndex=rows.indexOf(row);
  var fields=Array.prototype.slice.call(row.querySelectorAll('input'));
  var fieldIndex=fields.indexOf(current);
  var nextField=null;
  if(event.shiftKey){
   if(fieldIndex>0)nextField=fields[fieldIndex-1];
   else if(rowIndex>0){var previousFields=rows[rowIndex-1].querySelectorAll('input');nextField=previousFields[previousFields.length-1]||null;}
  }else{
   if(fieldIndex>=0&&fieldIndex<fields.length-1)nextField=fields[fieldIndex+1];
   else if(rowIndex<rows.length-1)nextField=rows[rowIndex+1].querySelector('.p-name');
   else{var newRow=addRow();nextField=newRow?newRow.querySelector('.p-name'):null;}
  }
  if(!nextField){event.preventDefault();return;}
  event.preventDefault();
  try{nextField.focus({preventScroll:true});}catch(ignore){nextField.focus();}
  if(typeof nextField.scrollIntoView==='function'){try{nextField.scrollIntoView({block:'nearest',inline:'nearest'});}catch(ignore){}}
 });
 if(form){
  form.addEventListener('focusin',function(event){
   if(media&&media.matches)return;
   var panel=event.target&&event.target.closest?event.target.closest('[data-protocol-step]'):null;
   if(panel){var focusedStep=Number(panel.getAttribute('data-protocol-step'));if(focusedStep>=1&&focusedStep<=3)currentStep=focusedStep;}
  });
  form.addEventListener('input',function(event){if(isProtocolContentControl(event.target))markProtocolContentChanged();updateReview();scheduleAutosave();});
  form.addEventListener('change',function(event){if(isProtocolContentControl(event.target))markProtocolContentChanged();updateReview();scheduleAutosave();});
  form.addEventListener('submit',function(event){
   if(finalSaving){event.preventDefault();return;}
   if(media&&media.matches&&currentStep<3){
    event.preventDefault();
    if(currentStep===1&&!registrationIsValid())return;
    activateStep(currentStep+1,true);
    return;
   }
   if(!registrationIsValid()){
    event.preventDefault();
    if(media&&media.matches)activateStep(1,true);
    return;
   }
   if(!validateMeterPhoto()){
    event.preventDefault();
    if(media&&media.matches)activateStep(1,true);
    if(photoInput)photoInput.focus();
    return;
   }
   if(!signatureIsValid(true)){
    event.preventDefault();
    if(media&&media.matches)activateStep(3,true);
    if(signatureCanvas)signatureCanvas.focus();
    return;
   }
   if(autosaveTimer){window.clearTimeout(autosaveTimer);autosaveTimer=null;}
   if(autosaveRequest){
    event.preventDefault();
    finalSaving=true;
    submitAfterAutosave=true;
    deferredSubmitter=event.submitter||null;
    if(window.TadegaPortalSubmitState)window.TadegaPortalSubmitState.markBusy(form,'Sparar serviceprotokollet…',deferredSubmitter);
    setAutosaveMessage('Slutför autosparning innan protokollet sparas…');
    return;
   }
   finalSaving=true;
  });
 }
 var printButton=document.getElementById('protocol-print');
 if(printButton)printButton.addEventListener('click',function(){
  if(!registrationIsValid()){if(media&&media.matches)activateStep(1,true);return;}
  if(!validateMeterPhoto()){if(media&&media.matches)activateStep(1,true);if(photoInput)photoInput.focus();return;}
  if(!signatureIsValid(true)){if(media&&media.matches)activateStep(3,true);if(signatureCanvas)signatureCanvas.focus();return;}
  window.print();
 });
 if(back)back.addEventListener('click',function(){activateStep(currentStep-1,true);});
 if(next)next.addEventListener('click',function(){
  if(currentStep===1&&!registrationIsValid())return;
  activateStep(currentStep+1,true);
 });
 function syncViewport(){
  if(!wrap||!media)return;
  if(media.matches){
   var focusedPanel=document.activeElement&&document.activeElement.closest?document.activeElement.closest('[data-protocol-step]'):null;
   if(focusedPanel){var focusedStep=Number(focusedPanel.getAttribute('data-protocol-step'));if(focusedStep>=1&&focusedStep<=3)currentStep=focusedStep;}
   wrap.classList.add('protocol-wizard-active');
   activateStep(currentStep,false);
  }else{
   wrap.classList.remove('protocol-wizard-active');
   updateReview();
  }
 }
 if(media){
  if(typeof media.addEventListener==='function')media.addEventListener('change',syncViewport);
  else if(typeof media.addListener==='function')media.addListener(syncViewport);
 }
 updateReview();
 syncViewport();
 window.addEventListener('pageshow',function(){
  finalSaving=false;
  submitAfterAutosave=false;
  deferredSubmitter=null;
 });
})();
</script>
