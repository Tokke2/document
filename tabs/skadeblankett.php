<?php
declare(strict_types=1);
$skadeCustomerMode = isset($isSkadeCustomerMode) && $isSkadeCustomerMode === true;
$skadeAdminCanOmitAccidentDate = !$skadeCustomerMode
    && function_exists('portal_skade_admin_can_omit_accident_date')
    && portal_skade_admin_can_omit_accident_date();
$skadeTextFitConfirmationRequired = !empty($portalSkadeTextFitConfirmationRequired);
$skadeEditAttempted = false;
$skadeEditRecord = null;
$skadeEditId = '';
$skadeEditSourceFile = '';
$skadeEditError = '';
$skadeEditSignatureDataUri = '';
$requestedSkadeEditId = '';
if (isset($_GET['edit_id']) && is_string($_GET['edit_id'])) {
    $requestedSkadeEditId = $_GET['edit_id'];
} elseif (isset($_POST['edit_id']) && is_string($_POST['edit_id'])) {
    $requestedSkadeEditId = $_POST['edit_id'];
}
if (!$skadeCustomerMode && $requestedSkadeEditId !== '') {
    $skadeEditAttempted = true;
    if (!preg_match('/\\A[a-f0-9]{32}\\z/', $requestedSkadeEditId) || !function_exists('portal_skade_editable_record_by_id')) {
        $skadeEditError = 'Redigeringslänken kunde inte verifieras. Öppna posten igen från Historik.';
    } else {
        $candidateEditRecord = portal_skade_editable_record_by_id($requestedSkadeEditId);
        if (!is_array($candidateEditRecord)) {
            $skadeEditError = 'Posten saknar sparade formuläruppgifter eller den tidigare PDF:en finns inte kvar. Äldre blanketter kan öppnas som PDF men inte fyllas i automatiskt.';
        } else {
            $skadeEditSignatureDataUri = portal_skade_signature_data_uri_from_record($candidateEditRecord);
            if ($skadeEditSignatureDataUri === null || (!empty($candidateEditRecord['signature_drawn']) && $skadeEditSignatureDataUri === '')) {
                $skadeEditError = 'Den sparade signaturen kunde inte läsas. Den tidigare PDF:en finns kvar; kontakta administratören innan redigering.';
            } else {
                $skadeEditRecord = $candidateEditRecord;
                $skadeEditId = $requestedSkadeEditId;
                $skadeEditSourceFile = (string) ($candidateEditRecord['file'] ?? '');
                if (isset($_POST['edit_source_file']) && is_string($_POST['edit_source_file'])) {
                    $skadeEditSourceFile = $_POST['edit_source_file'];
                }
            }
        }
    }
}
if ($requestedSkadeEditId !== '' && isset($portalPostError) && is_string($portalPostError) && str_starts_with($portalPostError, 'Posten har ändrats sedan du öppnade den.')) {
    $skadeEditError = 'Posten har ändrats i Historik sedan du öppnade den. Ladda om den från Historik innan du fortsätter.';
}
$skadeEditMode = is_array($skadeEditRecord) && $skadeEditError === '';
$skadeCustomerDone = $skadeCustomerMode && isset($_GET['done']) && is_string($_GET['done']) && $_GET['done'] === '1';
$skadeCustomerError = '';
if ($skadeCustomerMode) {
    if (!empty($portalCsrfFailed)) {
        $skadeCustomerError = 'Säkerhetskontrollen misslyckades. Ladda om sidan eller be personalen om hjälp.';
    } elseif (empty($csrfReady)) {
        $skadeCustomerError = 'Formuläret kan inte skickas just nu. Be personalen om hjälp.';
    } elseif (!$skadeTextFitConfirmationRequired && isset($portalPostError) && is_string($portalPostError) && $portalPostError !== '') {
        $skadeCustomerError = str_starts_with($portalPostError, 'Den här blanketten')
            ? 'Blanketten kan redan vara under behandling. Skicka inte in den igen; be personalen kontrollera statusen.'
            : $portalPostError;
    }
}
$skadeSubmissionBlocked = $skadeCustomerMode && isset($portalPostError) && is_string($portalPostError) && str_starts_with($portalPostError, 'Den här blanketten');
$skadeValidationError = isset($portalPostError) && is_string($portalPostError) && $portalPostError !== '';
$skadeSubmissionToken = function_exists('portal_skade_new_action_token')
    ? portal_skade_new_action_token($skadeEditMode ? 'edit' : 'submission', $skadeEditMode ? $skadeEditId : '')
    : '';
$skadeInput = isset($_POST['s']) && is_array($_POST['s'])
    ? $_POST['s']
    : (is_array($skadeEditRecord['form_data'] ?? null) ? $skadeEditRecord['form_data'] : []);
if ($skadeEditMode && !isset($_POST['s']) && $skadeEditSignatureDataUri !== '') {
    $skadeInput['signature_png'] = $skadeEditSignatureDataUri;
}
$skadeText = static function (string $key, string $fallback = '') use ($skadeInput): string {
    $value = $skadeInput[$key] ?? $fallback;
    return h(is_string($value) ? $value : $fallback);
};
$skadeChecked = static function (string $key, string $value = '1') use ($skadeInput): bool {
    $current = $skadeInput[$key] ?? null;
    return is_string($current) ? $current === $value : (is_array($current) && in_array($value, $current, true));
};
$skadeTemplateName = 'Bilrutan Skadeblankett.pdf';
$skadeTemplatePath = __DIR__ . '/../pdf/' . $skadeTemplateName;
if ((!is_file($skadeTemplatePath) || is_link($skadeTemplatePath)) && is_file(__DIR__ . '/../pdf/Bilrutan_Skadeblankett.pdf') && !is_link(__DIR__ . '/../pdf/Bilrutan_Skadeblankett.pdf')) {
    $skadeTemplateName = 'Bilrutan_Skadeblankett.pdf';
    $skadeTemplatePath = __DIR__ . '/../pdf/' . $skadeTemplateName;
}
$skadeViewOriginal = '?view_file=' . rawurlencode('pdf/' . $skadeTemplateName);
$skadeDownloadOriginal = '?download_file=' . rawurlencode('pdf/' . $skadeTemplateName);
$skadeTemplateExists = is_file($skadeTemplatePath) && !is_link($skadeTemplatePath);
$skadePdf = isset($_GET['pdf']) && is_string($_GET['pdf']) ? $_GET['pdf'] : '';
if ($skadePdf === '' && $skadeEditMode && is_string($skadeEditRecord['file'] ?? null)) {
    $skadePdf = $skadeEditRecord['file'];
}
$skadePdfValid = preg_match('~\\Apdf/[A-Z0-9-]{2,12}/Skadeblankett-Bilglas-[A-Z0-9-]{2,12}-\\d{8}-\\d{6}-[a-f0-9]{6}\\.pdf\\z~D', $skadePdf) === 1;
if ($skadePdfValid) {
    $skadePdfPath = __DIR__ . '/../' . $skadePdf;
    $skadePdfReal = realpath($skadePdfPath);
    $skadePdfRoot = realpath(__DIR__ . '/../pdf');
    $skadePdfValid = $skadePdfReal !== false && $skadePdfRoot !== false && !is_link($skadePdfPath) && is_file($skadePdfReal)
        && strpos($skadePdfReal, rtrim($skadePdfRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0;
}
$skadePreviewAvailable = $skadePdfValid || $skadeTemplateExists;
$skadePreviewUrl = $skadePdfValid ? '?view_file=' . rawurlencode($skadePdf) : $skadeViewOriginal;
$skadePreviewDownloadUrl = $skadePdfValid ? '?download_file=' . rawurlencode($skadePdf) : $skadeDownloadOriginal;
$skadePreviewTitle = $skadePdfValid ? 'Skapad PDF' : ($skadeTemplateExists ? 'Originalblankett' : 'Förhandsvisning saknas');
$skadePreviewDescription = $skadePdfValid
    ? 'Kontrollera kundunderlaget i PDF:en. Verkstadsuppgifterna ska kompletteras.'
    : ($skadeTemplateExists ? 'Bilrutan originalblankett · ifyllbar PDF.' : 'Varken skapad PDF eller originalmall kunde visas.');
$skadeMailStatus = isset($_GET['mail']) && is_string($_GET['mail']) && in_array($_GET['mail'], ['sent', 'failed', 'pending'], true) ? $_GET['mail'] : '';
if ($skadeMailStatus === '' && isset($_GET['email']) && is_string($_GET['email']) && $_GET['email'] === '1') {
    $skadeMailStatus = 'sent';
}
$skadeSignatureStatus = isset($_GET['sig']) && is_string($_GET['sig']) ? $_GET['sig'] : '';
$skadeWasUpdated = isset($_GET['updated']) && is_string($_GET['updated']) && $_GET['updated'] === '1';
$skadePdfTextFitWarning = isset($_GET['text_fit_warning']) && is_string($_GET['text_fit_warning']) && $_GET['text_fit_warning'] === '1';
$skadeEmailRetryResult = isset($_GET['email_retry']) && is_string($_GET['email_retry']) && in_array($_GET['email_retry'], ['sent', 'failed', 'pending'], true) ? $_GET['email_retry'] : '';
$skadeEmailRecordId = '';
$skadeEmailSent = false;
$skadeEmailRetryPending = false;
$skadeEmailRetryToken = '';
if (!$skadeCustomerMode && $skadePdfValid && function_exists('load_skadeblanketter')) {
    foreach (load_skadeblanketter() as $skadeRecord) {
        if (!is_array($skadeRecord) || ($skadeRecord['file'] ?? '') !== $skadePdf) {
            continue;
        }
        $candidateRecordId = isset($skadeRecord['id']) && is_string($skadeRecord['id']) ? $skadeRecord['id'] : '';
        if (!preg_match('/\\A[a-f0-9]{32}\\z/', $candidateRecordId)) {
            break;
        }
        $skadeEmailRecordId = $candidateRecordId;
        $storedMailStatus = $skadeRecord['email_status'] ?? '';
        if (!empty($skadeRecord['email_accepted']) || !empty($skadeRecord['email_sent']) || in_array($storedMailStatus, ['accepted', 'sent'], true)) {
            $skadeEmailSent = true;
            $skadeMailStatus = 'sent';
        } elseif (!empty($skadeRecord['email_retry_pending']) || in_array($storedMailStatus, ['sending', 'retrying'], true)) {
            $skadeEmailRetryPending = true;
            $skadeMailStatus = 'pending';
        } elseif ($storedMailStatus === 'failed') {
            $skadeMailStatus = 'failed';
        }
        break;
    }
}
if (!$skadeCustomerMode && $skadeMailStatus === 'failed' && $skadeEmailRecordId !== '' && !$skadeEmailSent && !$skadeEmailRetryPending && function_exists('portal_skade_new_action_token')) {
    $skadeEmailRetryToken = portal_skade_new_action_token('email_retry', $skadeEmailRecordId);
}
?>
<style>
    .skade-page { --sk-ink:#142b3a; --sk-muted:#667784; --sk-line:#dfe7eb; --sk-brand:#11718a; --sk-bg:#f7fafb; color:var(--sk-ink); }
    .skade-page * { box-sizing:border-box; }
    <?php if ($skadeCustomerMode): ?>
    /* Prevent horizontal scroll chaining / rubber-banding on the customer page.
       clip does not create an inner scroll container, so vertical scrolling
       remains on the page. Keep the fallback for older Safari versions. */
    html.skade-kiosk-root, body.skade-kiosk-mode { overflow-x:hidden; overscroll-behavior-x:none; }
    @supports (overflow-x:clip) { html.skade-kiosk-root, body.skade-kiosk-mode { overflow-x:clip; } }
    /* Installed customer app: permit vertical panning only at normal scale.
       A system/accessibility zoom that is already active remains navigable. */
    html.skade-kiosk-root.skade-kiosk-standalone:not(.skade-kiosk-zoomed) { touch-action:pan-y; }
    @media (display-mode:standalone) { html.skade-kiosk-root:not(.skade-kiosk-zoomed) { touch-action:pan-y; } }
    body.skade-kiosk-mode { width:100%; min-width:0; background:#f3f8fa; }
    body.skade-kiosk-mode #main-content { width:100%; max-width:1320px; min-height:100vh; margin:0 auto; padding:clamp(12px,2.6vw,28px) max(12px,env(safe-area-inset-right)) calc(18px + env(safe-area-inset-bottom)) max(12px,env(safe-area-inset-left)); }
    body.skade-kiosk-mode .page-content { width:100%; }
    body.skade-kiosk-mode .skade-page { width:100%; max-width:1260px; margin:0 auto; }
    body.skade-kiosk-mode .skade-hero { margin-bottom:15px; padding:clamp(18px,3vw,26px); }
    body.skade-kiosk-mode .skade-hero-kicker { margin-bottom:8px; }
    body.skade-kiosk-mode .skade-hero h2 { font-size:clamp(1.65rem,4.2vw,2.45rem); line-height:1.2; }
    body.skade-kiosk-mode .skade-hero p { max-width:none; color:#344957; font-size:1.12rem; line-height:1.55; }
    body.skade-kiosk-mode .skade-hero-kicker { font-size:.86rem; letter-spacing:.08em; }
    body.skade-kiosk-mode .skade-layout { display:flex; flex-direction:column; gap:18px; }
    body.skade-kiosk-mode .skade-main { width:100%; min-width:0; }
    body.skade-kiosk-mode .skade-hero-actions, body.skade-kiosk-mode .skade-preview, body.skade-kiosk-mode .skade-success { display:none!important; }
    body.skade-kiosk-mode .skade-card { padding:clamp(20px,3vw,30px); margin-bottom:18px; }
    body.skade-kiosk-mode .skade-grid, body.skade-kiosk-mode .skade-grid-three, body.skade-kiosk-mode .skade-checks, body.skade-kiosk-mode .skade-checks-three, body.skade-kiosk-mode .skade-payment-grid, body.skade-kiosk-mode .skade-inline { grid-template-columns:minmax(0,1fr); }
    body.skade-kiosk-mode .skade-card-head { gap:15px; margin-bottom:20px; }
    body.skade-kiosk-mode .skade-step { flex-basis:44px; width:44px; height:44px; font-size:1rem; }
    body.skade-kiosk-mode .skade-card-head h3 { font-size:1.2rem; }
    body.skade-kiosk-mode .skade-card-head p { font-size:1rem; line-height:1.5; }
    body.skade-kiosk-mode .skade-label { margin-bottom:8px; font-size:1rem; line-height:1.4; }
    body.skade-kiosk-mode .skade-label small { color:#526774; font-size:.95rem; font-weight:650; }
    body.skade-kiosk-mode .skade-field input:not([type=checkbox]):not([type=radio]), body.skade-kiosk-mode .skade-field select, body.skade-kiosk-mode .skade-field textarea { min-height:60px; padding:14px 15px; font-size:1.15rem!important; line-height:1.4; }
    body.skade-kiosk-mode .skade-field textarea { min-height:145px; }
    body.skade-kiosk-mode [data-skade-step][hidden], body.skade-kiosk-mode #skade-review-open[hidden], body.skade-kiosk-mode .skade-customer-tools[hidden], body.skade-kiosk-mode #skade-speech-stop[hidden], body.skade-kiosk-mode #skade-speech-note[hidden], body.skade-kiosk-mode #skade-sign-back[hidden] { display:none!important; }
    body.skade-kiosk-mode .skade-customer-tools { display:flex; flex-wrap:wrap; gap:10px; margin:0 0 16px; }
    body.skade-kiosk-mode .skade-customer-tools button, body.skade-kiosk-mode .skade-read-field { min-height:44px; }
    body.skade-kiosk-mode .skade-speech-note { width:100%; margin:0; font-size:.9rem; }
    body.skade-kiosk-mode .skade-read-field { margin:5px 0 8px; font-size:.9rem; }
    body.skade-kiosk-mode .skade-field-has-speech { flex-wrap:wrap; min-width:0; }
    body.skade-kiosk-mode .skade-read-field { max-width:100%; white-space:normal; }
    body.skade-kiosk-mode .skade-review-list { display:grid; gap:10px; margin:16px 0; }
    body.skade-kiosk-mode .skade-review-item { min-width:0; padding:12px; background:#f1f7f4; border:1px solid #cfe3dc; border-radius:10px; }
    body.skade-kiosk-mode .skade-review-item dt { font-weight:750; }
    body.skade-kiosk-mode .skade-review-item dd { margin:4px 0 8px; white-space:pre-wrap; overflow-wrap:anywhere; }
    body.skade-kiosk-mode .skade-review-more summary { cursor:pointer; min-height:44px; padding:12px 0; color:#075c59; font-weight:700; }
    body.skade-kiosk-mode .skade-review-actions { display:flex; flex-wrap:wrap; gap:10px; margin-top:14px; }
    body.skade-kiosk-mode .skade-review-actions .btn { min-height:54px; }
    body.skade-kiosk-mode :is(button,input,select,textarea,a,summary,canvas,#skade-review-heading,#skade-sign-heading):focus-visible { outline:3px solid #075c59; outline-offset:3px; }
    body.skade-kiosk-mode.skade-large-text .skade-page { font-size:1.25rem; line-height:1.65; }
    body.skade-kiosk-mode.skade-large-text :is(.skade-label,.skade-help,.skade-choice,.skade-card-head p,.skade-signature-copy,.skade-signature-disclaimer,.skade-customer-declaration,.skade-speech-note,.skade-read-field,.skade-review-list,.skade-form-actions p) { font-size:1.2rem; line-height:1.6; }
    body.skade-kiosk-mode.skade-large-text .skade-field :is(input:not([type=checkbox]):not([type=radio]),select,textarea) { font-size:1.3rem!important; }
    body.skade-kiosk-mode.skade-large-text :is(.skade-card-head h3,.skade-review-item dt) { font-size:1.35rem; }
    body.skade-kiosk-mode.skade-large-text :is(.skade-review-actions .btn,.skade-customer-tools button,.skade-submit,#skade-review-open) { font-size:1.15rem; }
    /* An unfocused multiline field must not consume a page-scroll gesture.
       Once focused, restore normal text editing and internal scrolling. */
    body.skade-kiosk-mode .skade-field textarea:not(:focus) { overflow:hidden; resize:none; }
    @supports (overflow:clip) { body.skade-kiosk-mode .skade-field textarea:not(:focus) { overflow:clip; } }
    body.skade-kiosk-mode .skade-field textarea:focus { overflow:auto; resize:vertical; }
    body.skade-kiosk-mode .skade-help { color:#526774; font-size:.95rem; line-height:1.5; }
    body.skade-kiosk-mode .skade-field-error { margin-top:8px; font-size:.95rem; line-height:1.45; }
    body.skade-kiosk-mode .skade-choice { min-height:60px; padding:14px 15px; gap:12px; font-size:1rem; line-height:1.5; }
    body.skade-kiosk-mode .skade-choice input { width:24px; height:24px; }
    body.skade-kiosk-mode .skade-progress { margin-bottom:18px; padding:15px; }
    body.skade-kiosk-mode .skade-progress-row { font-size:.95rem; line-height:1.4; }
    body.skade-kiosk-mode .skade-progress-track { height:10px; }
    body.skade-kiosk-mode .skade-signature { margin-top:20px; padding:18px; }
    body.skade-kiosk-mode .skade-signature-title { font-size:1.05rem; }
    body.skade-kiosk-mode .skade-signature-copy { font-size:1rem; line-height:1.5; }
    body.skade-kiosk-mode .skade-signature-canvas { height:190px; min-height:190px; }
    body.skade-kiosk-mode .skade-signature-tools .btn { min-height:58px; font-size:1rem; }
    body.skade-kiosk-mode .skade-signature-status { font-size:.95rem; line-height:1.4; }
    body.skade-kiosk-mode .skade-signature-disclaimer, body.skade-kiosk-mode .skade-sign-note { font-size:.9rem; line-height:1.5; }
    body.skade-kiosk-mode .skade-customer-declaration { padding:0; font-size:1.1rem; line-height:1.65; }
    body.skade-kiosk-mode .skade-customer-declaration h4 { padding:15px 18px; font-size:1.2rem; }
    body.skade-kiosk-mode .skade-declaration-content { padding:18px; }
    body.skade-kiosk-mode .skade-customer-declaration .skade-declaration-warning, body.skade-kiosk-mode .skade-customer-declaration .skade-declaration-payment { padding:14px 16px; }
    body.skade-kiosk-mode .skade-form-actions { display:grid; grid-template-columns:minmax(0,1fr); gap:14px; margin-top:22px; }
    body.skade-kiosk-mode .skade-form-actions p { font-size:.95rem; line-height:1.5; }
    body.skade-kiosk-mode .skade-submit { width:100%; min-height:66px; font-size:1.12rem; }
    .skade-kiosk-error { margin:0 0 16px; padding:15px 17px; border:1px solid #fecdca; border-left:5px solid #d92d20; border-radius:12px; background:#fff5f4; color:#912018; font-size:1rem; line-height:1.5; }
    .skade-kiosk-notice { margin:0 0 16px; padding:15px 17px; border:1px solid #a9e7c0; border-left:5px solid #079455; border-radius:12px; background:#effaf3; color:#105b36; }
    .skade-kiosk-notice strong { display:block; margin-bottom:4px; font-size:1.05rem; }
    .skade-kiosk-notice p { margin:0; color:#31724d; font-size:.95rem; line-height:1.5; }
    .skade-kiosk-notice.is-warning { border-color:#fedf89; border-left-color:#dc6803; background:#fffaeb; color:#93370d; }
    .skade-kiosk-notice.is-warning p { color:#93370d; }
    @media (max-width:680px) {
        body.skade-kiosk-mode #main-content { padding-top:12px; }
    }
    <?php endif; ?>
    .skade-hero { display:flex; align-items:flex-start; justify-content:space-between; gap:24px; margin:0 0 20px; padding:24px 26px; border:1px solid #d4e8ed; border-radius:20px; background:linear-gradient(125deg,#f2fbfd 0%,#fff 62%,#edf6f8 100%); box-shadow:0 12px 28px rgba(16,57,73,.06); }
    .skade-hero-kicker { display:flex; align-items:center; gap:8px; margin:0 0 9px; color:#0f6d85; font-size:.72rem; font-weight:850; letter-spacing:.12em; text-transform:uppercase; }
    .skade-hero h2 { max-width:720px; margin:0; color:#163343; font-size:clamp(1.35rem,2.7vw,2rem); letter-spacing:-.035em; line-height:1.16; }
    .skade-hero p { max-width:730px; margin:10px 0 0; color:#5f7180; font-size:.93rem; }
    .skade-device-instructions { display:block; max-width:730px; margin:8px 0 0; color:#657985; font-size:.75rem; line-height:1.5; }
    .skade-hero-actions { display:flex; flex-wrap:wrap; justify-content:flex-end; gap:9px; min-width:190px; }
    .skade-hero-actions .btn { min-height:42px; }
    .skade-layout { display:grid; grid-template-columns:minmax(0,1.35fr) minmax(310px,.8fr); align-items:start; gap:20px; }
    .skade-main { min-width:0; }
    .skade-card { margin:0 0 16px; padding:21px; border:1px solid var(--sk-line); border-radius:16px; background:#fff; box-shadow:0 7px 22px rgba(16,57,73,.045); }
    .skade-card-head { display:flex; align-items:flex-start; gap:13px; margin:0 0 18px; }
    .skade-step { display:grid; flex:0 0 34px; width:34px; height:34px; place-items:center; border-radius:11px; background:#eaf6f8; color:#0d6a80; font-size:.84rem; font-weight:850; }
    .skade-card-head h3 { margin:0; color:#183645; font-size:1.03rem; letter-spacing:-.015em; }
    .skade-card-head p { margin:3px 0 0; color:var(--sk-muted); font-size:.81rem; }
    .skade-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px 16px; }
    .skade-grid-three { grid-template-columns:repeat(3,minmax(0,1fr)); }
    .skade-field { min-width:0; }
    .skade-field-wide { grid-column:1/-1; }
    .skade-date-control { display:flex; align-items:stretch; gap:8px; }
    .skade-date-control > #sk-datum { flex:1 1 auto; min-width:0; }
    .skade-calendar-trigger { position:relative; display:grid; flex:0 0 48px; width:48px; min-height:43px; place-items:center; border:1px solid #ccd8de; border-radius:10px; background:#fff; color:#0b7068; cursor:pointer; }
    .skade-calendar-trigger svg { pointer-events:none; }
    .skade-calendar-trigger:focus-within { outline:3px solid rgba(17,113,138,.2); outline-offset:2px; }
    .skade-calendar-trigger .skade-calendar-input { position:absolute!important; inset:0!important; display:block; width:100%!important; height:100%!important; min-height:0!important; margin:0!important; padding:0!important; border:0!important; border-radius:10px!important; background:transparent!important; opacity:0; cursor:pointer; }
    .skade-calendar-trigger .skade-calendar-input:focus { outline:0!important; box-shadow:none!important; }
    .skade-label { display:block; margin:0 0 6px; color:#344957; font-size:.8rem; font-weight:760; }
    .skade-label small { color:#87949d; font-size:.72rem; font-weight:500; }
    .skade-field input:not([type=checkbox]):not([type=radio]), .skade-field select, .skade-field textarea { display:block; width:100%; min-height:43px; padding:10px 12px; border:1px solid #ccd8de; border-radius:10px; background:#fff; color:#172d3a; font:inherit; font-size:.9rem; box-shadow:inset 0 1px 1px rgba(16,57,73,.025); transition:border-color .15s,box-shadow .15s; }
    .skade-field textarea { min-height:92px; resize:vertical; line-height:1.45; }
    .skade-field input:focus, .skade-field select:focus, .skade-field textarea:focus { border-color:#38a0b7; outline:0; box-shadow:0 0 0 3px rgba(17,113,138,.12); }
    .skade-required { color:#b42318; }
    .skade-help { display:block; margin:6px 0 0; color:#75838c; font-size:.73rem; }
    .skade-field-error { display:block; margin:6px 0 0; color:#b42318; font-size:.74rem; font-weight:680; line-height:1.35; }
    .skade-field-error[hidden] { display:none!important; }
    .skade-field input[aria-invalid="true"], .skade-field select[aria-invalid="true"], .skade-field textarea[aria-invalid="true"] { border-color:#d92d20!important; background:#fff8f7!important; box-shadow:0 0 0 3px rgba(217,45,32,.1)!important; }
    .skade-choice input[aria-invalid="true"] { outline:2px solid #d92d20; outline-offset:2px; }
    .skade-signature.has-error { border-color:#d92d20; box-shadow:0 0 0 3px rgba(217,45,32,.1); }
    .skade-signature-canvas[aria-invalid="true"] { border-color:#d92d20; box-shadow:0 0 0 3px rgba(217,45,32,.12); }
    .skade-checks { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:9px; }
    .skade-checks-three { grid-template-columns:repeat(3,minmax(0,1fr)); }
    .skade-choice { display:flex; align-items:flex-start; gap:9px; min-width:0; padding:10px 11px; border:1px solid #e2e9ed; border-radius:11px; background:#fbfcfd; color:#344957; font-size:.82rem; line-height:1.35; cursor:pointer; }
    .skade-choice:hover { border-color:#b5d8e0; background:#f7fcfd; }
    .skade-choice input { flex:0 0 auto; width:19px; height:19px; margin:1px 0 0; accent-color:#11718a; }
    .skade-company-choice input { width:22px; height:22px; margin:0; }
    .skade-choice input:focus-visible { outline:3px solid rgba(17,113,138,.28); outline-offset:2px; }
    .skade-choice strong { font-weight:730; }
    .skade-inline { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; margin-top:13px; }
    .skade-inline .skade-field input { min-height:39px; }
    .skade-payment-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:9px; }
    .skade-progress { margin:0 0 15px; padding:12px 15px; border:1px solid #dce7eb; border-radius:12px; background:#fff; }
    .skade-progress-row { display:flex; justify-content:space-between; gap:12px; margin-bottom:8px; color:#526774; font-size:.75rem; font-weight:700; }
    .skade-progress-track { height:6px; overflow:hidden; border-radius:99px; background:#edf2f4; }
    .skade-progress-bar { width:0; height:100%; border-radius:inherit; background:linear-gradient(90deg,#16728a,#39a2b5); transition:width .18s; }
    .skade-consent { margin-top:17px; padding:14px; border:1px solid #d6e8ec; border-radius:12px; background:#f4fafb; }
    .skade-consent .skade-choice { border:0; background:transparent; padding:0; font-size:.83rem; }
    .skade-signature { margin-top:16px; padding:15px; border:1px solid #d6e5e9; border-radius:12px; background:#fbfdfe; }
    .skade-signature-title { display:block; margin:0 0 4px; color:#344957; font-size:.83rem; font-weight:780; }
    .skade-signature-copy { margin:0 0 10px; color:#687985; font-size:.75rem; }
    .skade-signature-canvas { display:block; width:100%; height:auto; min-height:70px; border:1px solid #bdcbd1; border-radius:9px; background:#fff; cursor:crosshair; touch-action:none; user-select:none; }
    .skade-signature-canvas:focus-visible { outline:3px solid rgba(17,113,138,.2); outline-offset:2px; }
    .skade-signature-tools { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-top:8px; }
    .skade-signature-status { color:#687985; font-size:.73rem; }
    .skade-signature-status[data-state="error"] { color:#b42318; font-weight:700; }
    .skade-signature-confirm { margin-top:14px; }
    .skade-signature-disclaimer { display:block; margin:10px 0 0 26px; color:#6e7d86; font-size:.72rem; }
    .skade-sign-note { margin:9px 0 0 26px; color:#6e7d86; font-size:.75rem; }
    .skade-form-actions { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px; margin:18px 0 0; }
    .skade-form-actions p { margin:0; color:#71818a; font-size:.75rem; }
    .skade-submit { min-height:48px; padding:12px 19px; border-radius:12px; background:#11718a; font-size:.9rem; box-shadow:0 7px 16px rgba(17,113,138,.17); }
    .skade-preview { position:sticky; top:82px; min-width:0; overflow:hidden; border:1px solid #dce5e9; border-radius:16px; background:#fff; box-shadow:0 7px 22px rgba(16,57,73,.045); }
    .skade-preview-details { min-width:0; }
    .skade-preview-head { display:flex; align-items:center; justify-content:space-between; gap:14px; padding:16px 18px; cursor:pointer; list-style:none; }
    .skade-preview-head::-webkit-details-marker { display:none; }
    .skade-preview-head::marker { content:""; }
    .skade-preview-head-copy { min-width:0; }
    .skade-preview-head h3 { margin:0; color:#173747; font-size:.99rem; }
    .skade-preview-head p { margin:4px 0 0; color:#71818a; font-size:.76rem; }
    .skade-preview-chevron { display:grid; flex:0 0 30px; width:30px; height:30px; place-items:center; border-radius:9px; background:#eff6f7; color:#176f85; font-size:1.2rem; line-height:1; }
    .skade-preview-chevron::before { content:"+"; }
    .skade-preview-details[open] .skade-preview-chevron::before { content:"−"; }
    .skade-preview-content { padding:0 14px 14px; }
    .skade-preview-links { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 12px; }
    .skade-preview-links .btn { flex:1 1 120px; min-width:0; min-height:40px; padding:8px 10px; font-size:.76rem; }
    .skade-pdf-frame { display:block; width:100%; height:min(72vh,820px); min-height:540px; border:1px solid #e3ebee; border-radius:10px; background:#eef2f4; }
    .skade-preview-empty { display:grid; min-height:160px; place-items:center; padding:24px; color:#71818a; text-align:center; font-size:.86rem; }
    .skade-preview-toggle[hidden] { display:none!important; }
    .skade-preview-toggle { min-height:42px; }
    .skade-success { display:flex; align-items:flex-start; gap:14px; margin:0 0 17px; padding:16px 18px; border:1px solid #a9e7c0; border-radius:14px; background:#effaf3; color:#105b36; }
    .skade-success-mark { display:grid; flex:0 0 31px; width:31px; height:31px; place-items:center; border-radius:50%; background:#d5f5df; font-weight:900; }
    .skade-success h3 { margin:1px 0 3px; font-size:.94rem; }
    .skade-success p { margin:0; color:#31724d; font-size:.82rem; }
    .skade-success-actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:11px; }
    .skade-text-fit-warning { margin:10px 0; padding:11px 13px; border:1px solid #fedf89; border-left:5px solid #dc6803; border-radius:10px; background:#fffaeb; color:#7a2e0e; font-size:.84rem; line-height:1.5; }
    .skade-text-fit-warning strong { display:block; margin-bottom:3px; color:#7a2e0e; }
    .skade-text-fit-warning p { margin:0; color:#7a2e0e; }
    .skade-mail-note { margin-top:9px!important; color:#8b6114!important; }
    .skade-email-retry { margin-top:10px; padding:12px; border:1px solid #eed99d; border-radius:11px; background:#fffbef; }
    .skade-email-retry p { margin:0 0 9px; color:#6d571f; font-size:.77rem; line-height:1.45; }
    .skade-email-retry .btn { min-height:44px; }
    .skade-customer-declaration { margin:20px 0 0; padding:0; overflow:hidden; border:2px solid #244653; border-radius:10px; background:#fff; color:#203b49; font-size:1rem; line-height:1.6; }
    .skade-customer-declaration h4 { margin:0; padding:12px 16px; border-bottom:1px solid #b9c9ce; background:#eaf4f6; color:#163847; font-size:1.05rem; font-weight:820; letter-spacing:0; }
    .skade-declaration-content { padding:15px 17px 17px; }
    .skade-customer-declaration p { margin:0; }
    .skade-customer-declaration p + p { margin-top:12px; }
    .skade-customer-declaration .skade-declaration-warning { padding:12px 14px; border:1px solid #e6c46c; border-radius:8px; background:#fff8e8; color:#59400d; }
    .skade-customer-declaration .skade-declaration-payment { padding:12px 14px; border:1px solid #9ebdc5; border-radius:8px; background:#f1f7f8; }
    .skade-customer-declaration strong { color:#142b3a; }
    .skade-original-note { display:flex; gap:10px; margin:13px 0 0; padding:11px 12px; border-radius:10px; background:#f3f7f8; color:#536974; font-size:.74rem; }
    .skade-mini-icon { flex:0 0 auto; color:#12718a; font-size:1rem; }
    @media (max-width:1050px) { .skade-layout { display:flex; flex-direction:column; gap:16px; } .skade-preview { position:static; order:-1; width:100%; } .skade-main { order:0; width:100%; } .skade-pdf-frame { height:min(64vh,680px); min-height:420px; } }
    @media (max-width:680px) { .skade-hero { flex-direction:column; padding:20px 17px; border-radius:15px; } .skade-hero-actions { justify-content:flex-start; } .skade-card { padding:17px 14px; } .skade-grid,.skade-payment-grid { grid-template-columns:1fr; } .skade-grid-three,.skade-checks-three { grid-template-columns:repeat(2,minmax(0,1fr)); } .skade-field-wide { grid-column:1; } .skade-form-actions { align-items:stretch; flex-direction:column; } .skade-form-actions .btn { width:100%; } .skade-preview-head { padding:13px 14px; } .skade-preview-content { padding:0 10px 10px; } .skade-pdf-frame { min-height:360px; height:62vh; } }
    @media (max-width:680px) {
        .skade-page { padding-bottom:calc(88px + env(safe-area-inset-bottom)); }
        .skade-page .skade-form-actions .skade-submit {
            position:fixed;
            left:max(13px,env(safe-area-inset-left));
            right:max(13px,env(safe-area-inset-right));
            bottom:calc(6px + env(safe-area-inset-bottom));
            z-index:35;
            width:auto;
            min-height:48px;
            margin:0;
            padding:8px 14px;
            font-size:.9rem;
            box-shadow:0 8px 24px rgba(17,113,138,.28);
        }
    }
    @media (max-width:900px) and (max-height:560px) and (orientation:landscape) {
        .skade-page { padding-bottom:calc(88px + env(safe-area-inset-bottom)); }
        .skade-page .skade-form-actions .skade-submit {
            position:fixed;
            left:max(13px,env(safe-area-inset-left));
            right:max(13px,env(safe-area-inset-right));
            bottom:calc(6px + env(safe-area-inset-bottom));
            z-index:35;
            width:auto;
            min-height:48px;
            margin:0;
            padding:8px 14px;
            font-size:.9rem;
            box-shadow:0 8px 24px rgba(17,113,138,.28);
        }
    }
    <?php if ($skadeCustomerMode): ?>
    /* A single centered column at every iPad orientation and phone width. Do
       not disable zoom: the layout should reflow rather than clip controls. */
    body.skade-kiosk-mode #main-content { max-width:760px; min-width:0; width:100%; }
    body.skade-kiosk-mode :is(.page-content,.skade-page,.skade-hero,.skade-hero > div,.skade-layout,.skade-main,.skade-card,.skade-signature,.skade-customer-declaration) { min-width:0; max-width:100%; }
    body.skade-kiosk-mode :is(.skade-page,.skade-card,.skade-hero,.skade-kiosk-notice,.skade-kiosk-error) { overflow-wrap:anywhere; }
    body.skade-kiosk-mode .skade-page { padding-bottom:0; }
    body.skade-kiosk-mode .skade-form-actions .skade-submit { position:static; width:100%; min-height:60px; margin:0; }
    body.skade-kiosk-mode .skade-signature-canvas { width:100%; max-width:100%; }
    body.skade-kiosk-mode .skade-install { margin:16px 0 0; font-size:.95rem; }
    body.skade-kiosk-mode .skade-install summary { cursor:pointer; color:#075c59; font-weight:750; min-height:44px; padding:10px 0; }
    body.skade-kiosk-mode .skade-install ol { margin:4px 0 8px; padding-left:1.5em; }
    body.skade-kiosk-mode .skade-install p { font-size:.9rem; margin:6px 0; }
    body.skade-kiosk-mode .skade-kiosk-notice .btn { margin-top:16px; min-height:54px; }
    @media (display-mode:standalone) { body.skade-kiosk-mode .skade-install { display:none; } }
    <?php endif; ?>
    @media print { .skade-hero-actions,.skade-progress,.skade-form-actions,.skade-preview,.skade-page form { display:none!important; } .skade-page { display:none!important; } }
    /* Shared Tadega mobile design system for staff and customer PWA modes. */
    .skade-page { --sk-ink:var(--ink); --sk-muted:var(--muted); --sk-line:var(--line); --sk-brand:var(--brand); --sk-bg:var(--canvas); }
    .skade-hero { border-color:#cfe3dc; background:linear-gradient(125deg,#eaf6f0 0%,#fff 58%,#f2f8f5 100%); box-shadow:0 12px 30px rgba(18,54,58,.065); }
    .skade-hero-kicker { color:#0b7068; }
    .skade-card,.skade-preview { border-color:#dce7e4; box-shadow:0 9px 26px rgba(18,54,58,.055); }
    .skade-step { background:#e7f4ee; color:#0b6d64; }
    .skade-field input:focus,.skade-field select:focus,.skade-field textarea:focus { border-color:#69b4a7; box-shadow:0 0 0 4px rgba(11,122,114,.13); }
    .skade-choice:hover { border-color:#acd5c9; background:#f3faf6; }
    .skade-choice input { accent-color:#0b7a72; }
    .skade-progress { border-color:#d6e6df; background:#fff; }
    .skade-progress-track { background:#deece6; }
    .skade-progress-bar { background:linear-gradient(90deg,#0b7a72,#27a28e); }
    .skade-signature { border-color:#d4e5dd; background:#f8fbf9; }
    .skade-signature-canvas { border-color:#b9cec6; }
    .skade-signature-canvas:focus-visible { outline-color:rgba(11,122,114,.28); }
    .skade-submit { border-color:#0b7770; background:linear-gradient(135deg,#0b7770,#169487); box-shadow:0 8px 20px rgba(11,122,114,.2); }
    .skade-submit:hover { background:linear-gradient(135deg,#075c59,#0b7770); }
    .skade-customer-declaration h4 { background:#eaf5ef; color:#164a43; }
    .skade-preview-chevron { background:#e7f4ee; color:#0b6d64; }
    .skade-original-note { background:#f0f6f3; }
    body.skade-kiosk-mode { background:linear-gradient(180deg,#f6faf7,#edf5f1); }
    body.skade-kiosk-mode .skade-hero { border-color:#cfe3dc; }
    body.skade-kiosk-mode .skade-signature-canvas { min-height:190px; }
    @media(max-width:680px) {
        .skade-hero { border-radius:18px; }
        .skade-card { border-radius:16px; }
        .skade-page .skade-form-actions .skade-submit { background:linear-gradient(135deg,#0b7770,#169487); }
        .skade-page .skade-form-actions .skade-submit:hover { background:linear-gradient(135deg,#075c59,#0b7770); }
    }
</style>

<div class="skade-page">
    <section class="skade-hero" aria-labelledby="skade-title">
        <div>
            <?php if ($skadeCustomerMode): ?>
                <p class="skade-hero-kicker"><span aria-hidden="true">◈</span> <?= ($portalLanguage ?? 'sv') === 'en' ? 'Auto glass · customer form' : 'Bilglas · kundformulär' ?></p>
                <h2 id="skade-title"><?= $skadeCustomerDone ? (($portalLanguage ?? 'sv') === 'en' ? 'Thank you!' : 'Tack!') : (($portalLanguage ?? 'sv') === 'en' ? 'Complete the damage form' : 'Fyll i skadeblanketten') ?></h2>
                <?php if (!$skadeCustomerDone): ?>
                <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'Fields marked * are required. Other fields are optional. In the final step, draw your signature.' : 'Fält märkta med * är obligatoriska. Övriga fält är frivilliga. I sista steget ritar du din signatur.' ?></p>
                <details class="skade-install" id="skade-install-help">
                    <summary><?= ($portalLanguage ?? 'sv') === 'en' ? 'Install on iPad' : 'Installera på iPad' ?></summary>
                    <ol><li>Öppna denna kundsida i Safari på iPad.</li><li>Tryck på <strong>Dela</strong> och välj <strong>Lägg till på hemskärmen</strong>.</li><li>Välj vid behov <strong>Öppna som webbapp</strong>, tryck på <strong>Lägg till</strong> och öppna ikonen.</li></ol>
                    <p>Det krävs internet för att fylla i och skicka. Om alternativet saknas, uppdatera iPadOS eller be personalen om hjälp.</p>
                </details>
                <?php endif; ?>
            <?php else: ?>
                <p class="skade-hero-kicker"><span aria-hidden="true">◈</span> <?= ($portalLanguage ?? 'sv') === 'en' ? 'Auto glass · customer record' : 'Bilglas · kundunderlag' ?></p>
                <?php if ($skadeEditMode): ?>
                    <h2 id="skade-title"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Edit damage form' : 'Redigera skadeblankett' ?></h2>
                    <p>Uppgifterna har hämtats från Historik. När du sparar skapas en ny PDF-version och den tidigare PDF:en behålls. Den sparade signaturen visas; bekräfta den igen eller rensa och rita om.</p>
                <?php elseif ($skadeEditError !== ''): ?>
                    <h2 id="skade-title"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Damage form cannot be edited' : 'Skadeblanketten kan inte redigeras' ?></h2>
                    <p><?= h($skadeEditError) ?></p>
                <?php else: ?>
                    <h2 id="skade-title"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Create customer record for damage form' : 'Skapa kundunderlag för skadeblanketten' ?></h2>
                    <p>Fyll i kund- och fordonsuppgifterna och låt kunden rita sin signatur på skärmen. Vi placerar dem på Bilrutans originalblankett. Skadedetaljer, tillägg, betalning och verkstadsdatum lämnas tomma så att ni kan komplettera dem.</p>
                    <small class="skade-device-instructions">Kundläget är responsivt för moderna telefoner och surfplattor. iPhone/iPad: Safari → Dela → Lägg till på hemskärmen; Android: Chrome → ⋮ → Installera app eller Lägg till på startskärmen. Starta från ikonen för fristående vy. Kiosklås: iOS/iPadOS har Guidad åtkomst; Android har skärmfästning eller enhetens kioskläge. Menyer och stöd varierar mellan webbläsare; annars kan sidan användas direkt i webbläsaren.</small>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php if (!$skadeCustomerMode): ?>
            <div class="skade-hero-actions">
                <?php if ($skadeEditAttempted): ?><a class="btn btn-outline" href="?tab=archive"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Back to History' : 'Tillbaka till Historik' ?></a><?php endif; ?>
                <a class="btn btn-main" href="./skadeblankett-kund.php" target="_blank" rel="noopener">Öppna kundläge på mobil eller surfplatta ↗</a>
                <?php if ($skadePreviewAvailable): ?>
                    <button class="btn btn-outline skade-preview-toggle" type="button" id="skade-preview-toggle" aria-controls="skade-preview-details" aria-expanded="true" hidden><?= ($portalLanguage ?? 'sv') === 'en' ? 'View PDF preview' : 'Visa PDF-förhandsvisning' ?></button>
                <?php endif; ?>
                <?php if ($skadeTemplateExists): ?>
                    <a class="btn btn-outline" href="<?= h($skadeViewOriginal) ?>" target="_blank" rel="noopener"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Open fillable PDF ↗' : 'Öppna ifyllbar PDF ↗' ?></a>
                    <a class="btn btn-outline" href="<?= h($skadeDownloadOriginal) ?>"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Download original' : 'Ladda ned original' ?></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($skadeCustomerMode && $skadeCustomerError !== ''): ?>
        <div class="skade-kiosk-error" role="alert" aria-live="assertive"><?= h($skadeCustomerError) ?></div>
    <?php endif; ?>

    <?php if ($skadeCustomerMode && $skadeCustomerDone): ?>
        <div class="skade-kiosk-notice<?= ($skadeMailStatus === 'failed' || $skadePdfTextFitWarning) ? ' is-warning' : '' ?>" id="skade-kiosk-notice" role="status" aria-live="polite">
            <strong><?= ($portalLanguage ?? 'sv') === 'en' ? 'The form has been submitted' : 'Blanketten är inskickad' ?></strong>
            <?php if ($skadePdfTextFitWarning): ?>
                <p>En eller flera textrutor i PDF:en använder text mindre än 5 pt. Be personalen kontrollera att den går att läsa innan den används.</p>
            <?php endif; ?>
            <?php if ($skadeMailStatus === 'failed'): ?>
                <p>Uppgifterna och PDF:en är sparade, men e-postaviseringen kunde inte skickas. Meddela personalen så att de kan kontrollera e-poststatusen och vid behov försöka igen.</p>
            <?php else: ?>
                <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'Tell the staff that you are finished.' : 'Meddela personalen att du är klar.' ?></p>
            <?php endif; ?>
            <a class="btn btn-outline" href="./skadeblankett-kund.php"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Start a new form' : 'Starta ny blankett' ?></a>
        </div>
    <?php elseif (!$skadeCustomerMode && !$skadeEditMode && $skadePdfValid): ?>
        <section class="skade-success" role="status" aria-live="polite">
            <div class="skade-success-mark" aria-hidden="true">✓</div>
            <div>
                <h3><?= $skadeWasUpdated ? 'Skadeblanketten är uppdaterad' : 'Kundunderlaget är klart' ?></h3>
                <?php if ($skadeWasUpdated): ?>
                    <p>En ny PDF-version har skapats. Den tidigare versionen finns kvar under äldre versioner i Historik.</p>
                <?php endif; ?>
                <?php if ($skadePdfTextFitWarning): ?>
                    <div class="skade-text-fit-warning" role="alert">
                        <strong>Kontrollera PDF:en — text under 5 pt</strong>
                        <p>Ett eller flera fält behövde mindre text för att rymmas. Kontrollera läsbarheten innan PDF:en används. Vid behov kan underlaget redigeras från Historik och en ny version skapas.</p>
                    </div>
                <?php endif; ?>
                <?php if ($skadeSignatureStatus === 'drawn'): ?>
                    <p>Kund- och fordonsuppgifterna samt kundens ritade signatur finns på en kopia av originalblanketten. Verkstadsuppgifterna återstår att fylla i.</p>
                <?php elseif ($skadeSignatureStatus === 'missing'): ?>
                    <p>Kund- och fordonsuppgifterna finns på en kopia av originalblanketten utan ritad signatur. Skriv ut PDF:en och låt kunden skriva under för hand innan blanketten används. Verkstadsuppgifterna återstår också att fylla i.</p>
                <?php else: ?>
                    <p>Kund- och fordonsuppgifterna finns på en kopia av originalblanketten. Kontrollera PDF:en och komplettera verkstadsuppgifterna innan den används.</p>
                <?php endif; ?>
                <div class="skade-success-actions">
                    <a class="btn btn-main" href="?view_file=<?= rawurlencode($skadePdf) ?>" target="_blank" rel="noopener"><?= ($portalLanguage ?? 'sv') === 'en' ? 'View completed PDF' : 'Visa ifylld PDF' ?></a>
                    <a class="btn btn-outline" href="?download_file=<?= rawurlencode($skadePdf) ?>"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Download PDF' : 'Ladda ned PDF' ?></a>
                </div>
                <?php if ($skadeMailStatus === 'sent'): ?>
                    <?php if ($skadeEmailRetryResult === 'sent'): ?>
                        <p style="margin-top:9px">E-posttransporten accepterade omförsöket till den av admin valda mottagaren. Det bekräftar inte vidarebefordran eller leverans till inkorgen.</p>
                    <?php else: ?>
                        <p style="margin-top:9px">E-posttransporten accepterade utskicket till den av admin valda mottagaren. Det bekräftar inte vidarebefordran eller leverans till inkorgen.</p>
                    <?php endif; ?>
                <?php elseif ($skadeMailStatus === 'failed'): ?>
                    <p class="skade-mail-note"><?php if ($skadeEmailRetryResult === 'failed'): ?>Omförsöket kunde inte skickas.<?php else: ?>PDF:en är sparad, men e-postaviseringen kunde inte skickas.<?php endif; ?> Kontrollera E-poststatus för transport och teknisk felkod. En ny knapptryckning gör ett nytt försök med samma PDF.</p>
                    <?php if ($skadeEmailRecordId !== '' && !$skadeEmailRetryPending): ?>
                        <form class="skade-email-retry" method="post" action="<?= h('?tab=skadeblankett&pdf=' . rawurlencode($skadePdf)) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="retry_skade_email">
                            <input type="hidden" name="record_id" value="<?= h($skadeEmailRecordId) ?>">
                            <input type="hidden" name="retry_token" value="<?= h($skadeEmailRetryToken) ?>">
                            <p>Skicka om den redan skapade PDF:en. Mottagaren är vald av admin; ingen kundkopia skickas. Varje sidvisning ger en engångsåtgärd, så dubbeltryck på samma knapp ger inte två nya försök.</p>
                            <button class="btn btn-main" type="submit" <?= empty($csrfReady) || $skadeEmailRetryToken === '' ? 'disabled' : '' ?>>Försök skicka igen till den av admin valda mottagaren</button>
                        </form>
                    <?php endif; ?>
                <?php elseif ($skadeMailStatus === 'pending'): ?>
                    <p class="skade-mail-note">Utskickets status kunde inte sparas säkert eller ett annat försök pågår. Kontrollera inkorgen och e-poststatusen innan ni försöker igen. Ingen faktisk leverans kan bekräftas av transportens svar.</p>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!$skadeCustomerDone): ?>
    <div class="skade-layout">
        <div class="skade-main">
            <?php if ($skadeTextFitConfirmationRequired): ?>
                <div class="skade-text-fit-warning" role="alert" aria-live="assertive">
                    <strong><?= ($portalLanguage ?? 'sv') === 'en' ? 'Confirm before creating or sending the PDF' : 'Bekräfta innan PDF skapas eller skickas' ?></strong>
                    <p><?= h(is_string($portalSkadeTextFitMessage ?? null) && $portalSkadeTextFitMessage !== '' ? $portalSkadeTextFitMessage : 'PDF-förhandskontrollen visar att text behöver vara mindre än 5 pt. Ingen PDF har sparats eller skickats.') ?> Uppgifterna är kvar i formuläret. Du kan ändra dem nu eller uttryckligen fortsätta med knappen nedan.</p>
                </div>
            <?php endif; ?>
            <?php if ($skadeCustomerMode): ?>
            <div class="skade-customer-tools" id="skade-customer-tools" hidden>
                <button type="button" class="btn btn-outline" id="skade-text-size" aria-pressed="false"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Larger text' : 'Större text' ?></button>
                <button type="button" class="btn btn-outline" id="skade-speech-stop" hidden><?= ($portalLanguage ?? 'sv') === 'en' ? 'Stop reading' : 'Stoppa uppläsning' ?></button>
                <p class="skade-speech-note" id="skade-speech-note" hidden><?= ($portalLanguage ?? 'sv') === 'en' ? 'Reading instructions does not read entered information. Your device or speech provider processes the spoken text; others nearby can hear it.' : 'Uppläsning läser instruktioner, inte det du har skrivit. Enheten eller dess taltjänst behandlar texten, och personer i närheten kan höra.' ?></p>
            </div>
            <?php endif; ?>
            <div class="skade-progress" aria-live="polite">
                <div class="skade-progress-row"><span><?= ($portalLanguage ?? 'sv') === 'en' ? 'Required information' : 'Obligatoriska uppgifter' ?></span><span id="skade-progress-text">0 %</span></div>
                <div class="skade-progress-track" id="skade-progress-track" role="progressbar" aria-label="Obligatoriska uppgifter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="skade-progress-bar" id="skade-progress-bar"></div></div>
                <?php if ($skadeCustomerMode): ?><small class="skade-help"><?= ($portalLanguage ?? 'sv') === 'en' ? '100% means required fields are filled in; the form is not sent until you press Send.' : '100 % betyder att de obligatoriska fälten är ifyllda. Blanketten skickas först när du trycker på Skicka.' ?></small><?php endif; ?>
            </div>

            <form id="skade-form" data-validation-error="<?= $skadeValidationError ? '1' : '0' ?>" data-signature-required="<?= $skadeCustomerMode ? '1' : '0' ?>" method="post" action="<?= h($skadeCustomerMode ? './skadeblankett-kund.php' : '?tab=skadeblankett') ?>" autocomplete="<?= $skadeCustomerMode ? 'off' : 'on' ?>">
                <input type="hidden" name="action" value="generate_skadeblankett">
                <input type="hidden" name="form_version" value="skade-customer-v3-signature">
                <input type="hidden" name="skade_submission_token" value="<?= h($skadeSubmissionToken) ?>">
                <?php if ($skadeEditMode): ?>
                    <input type="hidden" name="edit_id" value="<?= h($skadeEditId) ?>">
                    <input type="hidden" name="edit_source_file" value="<?= h($skadeEditSourceFile) ?>">
                <?php endif; ?>
                <?= csrf_field() ?>
                <?php if ($skadeTextFitConfirmationRequired): ?>
                    <input type="hidden" name="confirm_small_text" value="1">
                <?php endif; ?>

                <section class="skade-card" aria-labelledby="skade-kund-heading"<?= $skadeCustomerMode ? ' data-skade-step="entry"' : '' ?>>
                    <div class="skade-card-head"><span class="skade-step">1</span><div><h3 id="skade-kund-heading"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Customer details' : 'Kunduppgifter' ?></h3><p><?= ($portalLanguage ?? 'sv') === 'en' ? 'Enter the details exactly as they should appear on the form.' : 'Ange uppgifterna precis som de ska stå på blanketten.' ?></p></div></div>
                    <div class="skade-grid">
                        <div class="skade-field" style="display:flex;align-items:end;padding-bottom:3px">
                            <label class="skade-choice skade-company-choice" style="width:100%"><input id="sk-foretag" type="checkbox" name="s[foretag]" value="1" <?= $skadeChecked('foretag') ? 'checked' : '' ?>><span><strong><?= ($portalLanguage ?? 'sv') === 'en' ? 'Business customer' : 'Företagskund' ?></strong><br><small><?= ($portalLanguage ?? 'sv') === 'en' ? 'Select if the customer is a business.' : 'Markera om kunden är ett företag.' ?></small></span></label>
                        </div>
                        <div class="skade-field skade-field-wide">
                            <label class="skade-label" for="sk-namn"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Name, address, postal code and city' : 'Namn, adress, postnummer och ort' ?> <span class="skade-required">*</span></label>
                            <textarea id="sk-namn" name="s[namn_adress]"<?= $skadeCustomerMode ? ' aria-describedby="sk-namn-help"' : '' ?> maxlength="250" autocomplete="<?= $skadeCustomerMode ? 'off' : 'street-address' ?>" required><?= $skadeText('namn_adress') ?></textarea>
                            <?php if ($skadeCustomerMode): ?><small class="skade-help" id="sk-namn-help"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Enter your full name, street address, postal code and city.' : 'Skriv för- och efternamn, gatuadress, postnummer och ort.' ?></small><?php endif; ?>
                        </div>
                        <div class="skade-field">
                            <label class="skade-label" for="sk-personnr"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Personal / company identification number' : 'Personnummer / organisationsnummer' ?> <span class="skade-required">*</span></label>
                            <input id="sk-personnr" name="s[person_orgnr]" inputmode="text" maxlength="40" autocomplete="off" required value="<?= $skadeText('person_orgnr') ?>">
                            <small class="skade-help"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Enter the customer\'s personal identification number or the company\'s registration number.' : 'Ange kundens personnummer eller företagets organisationsnummer.' ?></small>
                        </div>
                        <div class="skade-field">
                            <label class="skade-label" for="sk-telefon">Telefonnummer <?php if ($skadeCustomerMode): ?><span class="skade-required">*</span><?php else: ?><small><?= ($portalLanguage ?? 'sv') === 'en' ? '(optional)' : '(frivilligt)' ?></small><?php endif; ?></label>
                            <input id="sk-telefon" name="s[telefon]" type="tel" inputmode="numeric" maxlength="40" autocomplete="<?= $skadeCustomerMode ? 'off' : 'tel' ?>" <?= $skadeCustomerMode ? 'required' : '' ?> value="<?= $skadeText('telefon') ?>">
                            <?php if ($skadeCustomerMode): ?><small class="skade-help"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Enter a phone number where we can reach you.' : 'Skriv ett telefonnummer där vi kan nå dig.' ?></small><?php endif; ?>
                        </div>
                        <div class="skade-field skade-field-wide">
                            <label class="skade-label" for="sk-epost"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Email address' : 'E-postadress' ?> <small><?= ($portalLanguage ?? 'sv') === 'en' ? '(optional)' : '(frivillig)' ?></small></label>
                            <input id="sk-epost" name="s[epost]" type="email" inputmode="email" maxlength="254" autocomplete="<?= $skadeCustomerMode ? 'off' : 'email' ?>" value="<?= $skadeText('epost') ?>">
                            <?php if ($skadeCustomerMode): ?>
                                <small class="skade-help"><?= ($portalLanguage ?? 'sv') === 'en' ? 'If you enter an email address, it is included on the form.' : 'Om du anger e-postadress skrivs den i blanketten.' ?></small>
                            <?php else: ?>
                                <small class="skade-help"><?= ($portalLanguage ?? 'sv') === 'en' ? 'If provided, the address is included on the form. If an administrator has configured a recipient, the PDF is sent only to that recipient. No email is sent without a recipient.' : 'Om den anges skrivs adressen i blanketten. Om admin har angett en mottagare skickas PDF:en endast dit. Utan mottagare skickas inget mejl.' ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <section class="skade-card" aria-labelledby="skade-fordon-heading"<?= $skadeCustomerMode ? ' data-skade-step="entry"' : '' ?>>
                    <div class="skade-card-head"><span class="skade-step">2</span><div><h3 id="skade-fordon-heading"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Vehicle and insurance' : 'Fordon och försäkring' ?></h3><p><?= ($portalLanguage ?? 'sv') === 'en' ? 'The details are copied to the vehicle fields on the Bilrutan form.' : 'Uppgifterna förs över till fordonets fält på Bilrutan-blanketten.' ?></p></div></div>
                    <div class="skade-grid">
                        <div class="skade-field">
                            <label class="skade-label" for="sk-regnr"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Registration number' : 'Registreringsnummer' ?> <span class="skade-required">*</span></label>
                            <input id="sk-regnr" name="s[regnr]" type="text" maxlength="12" pattern="[A-Za-z0-9 -]{2,12}" inputmode="text" autocapitalize="characters" autocomplete="off" autocorrect="off" spellcheck="false" aria-describedby="sk-regnr-help" required value="<?= $skadeText('regnr') ?>">
                            <small class="skade-help" id="sk-regnr-help"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Example: ABC123. Letters are capitalized and spaces are removed automatically.' : 'Exempel: ABC123. Bokstäver blir versaler och mellanslag tas bort automatiskt.' ?></small>
                        </div>
                        <div class="skade-field">
                            <label class="skade-label" for="sk-modell"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Make and model' : 'Fabrikat och modell' ?> <span class="skade-required">*</span></label>
                            <input id="sk-modell" name="s[modell]" maxlength="70" required value="<?= $skadeText('modell') ?>">
                            <small class="skade-help"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Enter the vehicle\'s make and model.' : 'Ange bilens märke och modell.' ?></small>
                        </div>
                        <div class="skade-field"><label class="skade-label" for="sk-matarstallning"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Current odometer reading in kilometers' : 'Aktuell mätarställning i kilometer' ?> <small><?= ($portalLanguage ?? 'sv') === 'en' ? '(optional)' : '(valfritt)' ?></small></label><input id="sk-matarstallning" name="s[matarstallning]" type="number" inputmode="numeric" min="0" max="99999999" step="1" value="<?= $skadeText('matarstallning') ?>"><?php if ($skadeCustomerMode): ?><small class="skade-help"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Enter the whole number of kilometers shown in the car, if known.' : 'Skriv antalet kilometer som bilen visar, om du vet.' ?></small><?php endif; ?></div>
                        <div class="skade-field"><label class="skade-label" for="sk-forsakring"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Insurance company' : 'Försäkringsbolag' ?> <span class="skade-required">*</span></label><input id="sk-forsakring" name="s[forsakringsbolag]" maxlength="70" required value="<?= $skadeText('forsakringsbolag') ?>"><?php if ($skadeCustomerMode): ?><small class="skade-help"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Enter the name of the vehicle insurance company.' : 'Skriv namnet på bilens försäkringsbolag.' ?></small><?php endif; ?></div>
                        <div class="skade-field">
                            <label class="skade-label" for="sk-datum">Skadedatum <?php if ($skadeAdminCanOmitAccidentDate): ?><small>(frivilligt för admin)</small><?php else: ?><span class="skade-required">*</span><?php endif; ?></label>
                            <div class="skade-date-control">
                                <input id="sk-datum" name="s[skadedatum]" type="text" inputmode="<?= $skadeCustomerMode ? 'numeric' : 'text' ?>" maxlength="32" autocomplete="off" placeholder="2026-03-21, 21.03.2026 eller 260321" aria-describedby="sk-datum-help" <?= $skadeAdminCanOmitAccidentDate ? '' : 'required' ?> value="<?= $skadeText('skadedatum') ?>">
                                <div class="skade-calendar-trigger">
                                    <svg viewBox="0 0 24 24" width="21" height="21" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"></path></svg>
                                    <input class="skade-calendar-input" id="sk-datum-picker" type="date" aria-label="Välj skadedatum i kalendern" tabindex="0" value="<?= $skadeText('skadedatum') ?>">
                                </div>
                            </div>
                            <small class="skade-help" id="sk-datum-help"><?php if ($skadeAdminCanOmitAccidentDate): ?>Skriv t.ex. 2026-03-21 eller 21.03.2026. Admin kan lämna fältet tomt när skadedatum saknas; PDF:en sparas och skickas som vanligt. Om datum anges måste det vara giltigt.<?php elseif ($skadeCustomerMode): ?>Skriv sex siffror, till exempel 260321 för 21 mars 2026, eller åtta siffror som 20260321. Du kan också välja i kalendern. År 00–49 tolkas som 2000–2049 och 50–99 som 1950–1999.<?php else: ?>Skriv t.ex. 2026-03-21 eller 21.03.2026. Sex siffror tolkas som ÅÅMMDD: 260321 blir 2026-03-21 (00–49 = 2000–2049, 50–99 = 1950–1999). Du kan även välja datum i kalendern.<?php endif; ?></small>
                        </div>
                        <div class="skade-field"><label class="skade-label" for="sk-plats"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Place of damage' : 'Skadeplats' ?> <small><?= ($portalLanguage ?? 'sv') === 'en' ? '(optional)' : '(frivillig)' ?></small></label><input id="sk-plats" name="s[skadeplats]" maxlength="80" value="<?= $skadeText('skadeplats') ?>"><?php if ($skadeCustomerMode): ?><small class="skade-help"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Enter where the damage happened, if known.' : 'Skriv var skadan inträffade, om du vet.' ?></small><?php endif; ?></div>
                        <div class="skade-field"><label class="skade-label" for="sk-skadenr"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Insurance / claim number' : 'Försäkrings- / skadenummer' ?> <small><?= ($portalLanguage ?? 'sv') === 'en' ? '(optional)' : '(valfritt)' ?></small></label><input id="sk-skadenr" name="s[skadenummer]" maxlength="80" value="<?= $skadeText('skadenummer') ?>"><?php if ($skadeCustomerMode): ?><small class="skade-help"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Enter the claim number from your insurer, if you have one.' : 'Skriv skadenumret från försäkringsbolaget om du har ett.' ?></small><?php endif; ?></div>
                        <div class="skade-field" style="display:flex;align-items:end;padding-bottom:3px"><label class="skade-choice" style="width:100%"><input id="sk-wiper-request" type="checkbox" name="s[nya_torkarblad_bestalls]" value="1" <?= $skadeChecked('nya_torkarblad_bestalls') ? 'checked' : '' ?>><span><strong><?= ($portalLanguage ?? 'sv') === 'en' ? 'Order new wiper blades' : 'Beställ nya torkarblad' ?></strong><br><small><?= ($portalLanguage ?? 'sv') === 'en' ? 'An additional charge applies if selected.' : 'Kostnad tillkommer om rutan markeras.' ?></small></span></label></div>
                    </div>
                </section>

                <?php if ($skadeCustomerMode): ?>
                <button class="btn btn-main" type="button" id="skade-review-open" hidden><?= ($portalLanguage ?? 'sv') === 'en' ? 'Review information' : 'Granska uppgifter' ?> →</button>
                <section class="skade-card" id="skade-review" data-skade-step="review" aria-labelledby="skade-review-heading" hidden>
                    <h3 id="skade-review-heading" tabindex="-1"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Review your information' : 'Granska dina uppgifter' ?></h3>
                    <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'A quick check before signing. You can also show the other fields.' : 'En snabb kontroll före signering. Du kan även visa övriga uppgifter.' ?></p>
                    <dl class="skade-review-list" id="skade-review-list"></dl>
                    <details class="skade-review-more" id="skade-review-more"><summary><?= ($portalLanguage ?? 'sv') === 'en' ? 'Show other information' : 'Visa övriga uppgifter' ?></summary><dl class="skade-review-list" id="skade-review-extra"></dl></details>
                    <div class="skade-review-actions"><button class="btn btn-outline" type="button" id="skade-review-back"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Back to fields' : 'Tillbaka till fälten' ?></button><button class="btn btn-main" type="button" id="skade-review-continue"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Continue to signature' : 'Fortsätt till signering' ?> →</button></div>
                </section>
                <?php endif; ?>
                <section class="skade-card"<?= $skadeCustomerMode ? ' id="skade-sign-stage" data-skade-step="signature"' : '' ?> aria-label="<?= h($skadeCustomerMode ? 'Bekräfta uppgifter' : 'Skapa kundunderlag') ?>">
                    <div class="skade-card-head"><span class="skade-step" aria-hidden="true">✓</span><div><h3<?= $skadeCustomerMode ? ' id="skade-sign-heading" tabindex="-1"' : '' ?>><?= $skadeCustomerMode ? 'Bekräfta uppgifterna' : 'Skapa kundunderlaget' ?></h3><p><?= $skadeCustomerMode ? 'Kontrollera att uppgifterna är riktiga. Rita din signatur och bekräfta innan du skickar.' : 'Skadedetaljer, åtgärd, utrustning, tilläggstjänster, betalning och datum lämnas utanför kundinmatningen och kompletteras av er.' ?></p></div></div>
                    <?php if ($skadeCustomerMode): ?><button class="btn btn-outline" type="button" id="skade-sign-back" hidden><?= ($portalLanguage ?? 'sv') === 'en' ? 'Back to review' : 'Tillbaka till granskning' ?></button><?php endif; ?>
                    <div class="skade-signature" aria-labelledby="skade-signature-title">
                        <p class="skade-signature-title" id="skade-signature-title"><?= $skadeCustomerMode ? 'Din digitala signatur' : 'Kundens digitala signatur' ?> <?php if ($skadeCustomerMode): ?><span class="skade-required">*</span><?php else: ?><small><?= ($portalLanguage ?? 'sv') === 'en' ? '(optional)' : '(frivillig)' ?></small><?php endif; ?></p>
                        <p class="skade-signature-copy" id="skade-signature-help"><?= $skadeCustomerMode ? 'Skriv din namnteckning med finger eller Apple Pencil och lämna lite luft till rutans kanter. Tryck på Rensa signatur om du vill börja om.' : 'Låt kunden skriva sin signatur med finger eller penna och lämna lite luft till rutans kanter. Ritytan jämnar ut små ryck och ger blått bläck med diskret breddvariation; signaturen anpassas till originalrutans storlek i PDF:en. Lämna rutan tom för utskrift och handskriven underskrift.' ?></p>
                        <canvas id="skade-signature-pad" class="skade-signature-canvas" width="1600" height="360" role="img" aria-label="<?= h($skadeCustomerMode ? 'Rita din signatur här' : 'Rita kundens signatur här') ?>" aria-describedby="skade-signature-help skade-signature-status" tabindex="0"></canvas>
                        <noscript><small class="skade-help"><?= $skadeCustomerMode ? 'Digital signering kräver JavaScript. Be personalen om hjälp.' : 'Ritning kräver JavaScript. Ni kan ändå skapa PDF:en utan digital signatur och låta kunden skriva under utskriften för hand.' ?></small></noscript>
                        <input type="hidden" name="s[signature_png]" id="skade-signature-png" value="<?= $skadeText('signature_png') ?>">
                        <div class="skade-signature-tools">
                            <button class="btn btn-outline" type="button" id="skade-signature-clear"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Clear signature' : 'Rensa signatur' ?></button>
                            <span class="skade-signature-status" id="skade-signature-status" aria-live="polite"><?= ($portalLanguage ?? 'sv') === 'en' ? 'No signature yet' : 'Ingen signatur ännu' ?></span>
                        </div>
                        <!-- Web-only copy of the declaration and payment terms; the original PDF remains unchanged. -->
                        <div class="skade-customer-declaration" role="note" aria-label="<?= ($portalLanguage ?? 'sv') === 'en' ? 'Declaration and payment terms' : 'Intygande och betalningsvillkor' ?>">
                            <h4><?= ($portalLanguage ?? 'sv') === 'en' ? 'Declaration and payment terms' : 'Intygande och betalningsvillkor' ?></h4>
                            <div class="skade-declaration-content">
                                <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'I solemnly declare that the information above is truthful and that the insurance was valid at the time of damage and covers the selected damage.' : 'Jag intygar härmed på heder och samvete att ovanstående uppgifter är med sanningen överensstämmande och att försäkringen gällde vid skadetillfället samt omfattar det valda skademomentet.' ?></p>
                                <p class="skade-declaration-warning"><strong><?= ($portalLanguage ?? 'sv') === 'en' ? 'NOTE:' : 'OBS:' ?></strong> <?= ($portalLanguage ?? 'sv') === 'en' ? 'If the insurer does not accept the claim, the vehicle owner remains responsible for payment.' : 'Godtar försäkringsbolaget inte skadan, är fordonsägaren alltid betalningsskyldig.' ?></p>
                                <p><strong><?= ($portalLanguage ?? 'sv') === 'en' ? 'Date and signature:' : 'Datum och underskrift:' ?></strong> <?= ($portalLanguage ?? 'sv') === 'en' ? 'The date is inserted automatically in the PDF when it is sent. The vehicle owner or driver signs in the signature area above.' : 'Datum sätts automatiskt i PDF:en när den skickas. Bilägarens/förarens underskrift görs i signaturrutan ovan.' ?></p>
                                <p class="skade-declaration-payment">Gällande betalningsvillkor är <strong><?= ($portalLanguage ?? 'sv') === 'en' ? 'payment on collection.' : 'betalning vid hämtning.' ?></strong></p>
                            </div>
                        </div>
                        <label class="skade-choice skade-signature-confirm" for="skade-signature-confirmed">
                            <input type="checkbox" name="s[signature_confirmed]" id="skade-signature-confirmed" value="1" <?= $skadeCustomerMode ? 'required' : '' ?> <?= $skadeChecked('signature_confirmed') ? 'checked' : '' ?>>
                            <span><?= $skadeCustomerMode ? 'Jag bekräftar att uppgifterna jag lämnar stämmer och att den ritade signaturen är min.' : ($skadeEditMode ? 'Jag bekräftar att den sparade signaturen tillhör kunden och att de ändrade uppgifterna är kontrollerade.' : 'Om du har ritat kundens signatur: bekräfta att uppgifterna stämmer och att signaturen är kundens.') ?> <?php if ($skadeCustomerMode): ?><span class="skade-required" aria-hidden="true">*</span><?php endif; ?></span>
                        </label>
                        <small class="skade-signature-disclaimer"><?= $skadeCustomerMode ? 'Formuläruppgifterna och signaturen sparas i portalens privata lagring så personalen kan redigera skadeblanketten. Gallringen körs vid en portalöppning (högst en kontroll per 24 timmar) när ett år har gått sedan senaste ändring, annars sedan skapandet; den körs inte när portalen är stängd. PDF-versionerna behålls. Säkerhetskopior på servern gallras också; nedladdade ZIP-kopior och redan skickade mejl/bilagor utanför portalen påverkas inte. Signaturen bäddas in i PDF:en; mejl skickas endast om admin har valt en mottagare. En ritad signatur verifierar inte identiteten via BankID.' : 'Formuläruppgifterna och eventuell signatur sparas i portalens privata lagring för redigering. Gallringen körs vid en portalöppning (högst en kontroll per 24 timmar) när ett år har gått sedan senaste ändring, annars sedan skapandet; den körs inte när portalen är stängd. PDF-versionerna behålls. Säkerhetskopior på servern gallras också; nedladdade ZIP-kopior och redan skickade mejl/bilagor utanför portalen påverkas inte. PDF:en skickas endast om admin har valt en mottagare. Lämnas rutan tom kan PDF:en skrivas ut för handskriven underskrift. En ritad signatur verifierar inte kundens identitet via BankID.' ?></small>
                    </div>
                    <?php if (!$skadeCustomerMode): ?>
                        <div class="skade-original-note"><span class="skade-mini-icon" aria-hidden="true">ⓘ</span><span>En ritad signatur placeras i originalets underskriftsruta. Lämna den tom om ni vill skriva ut blanketten utan underskrift; datumfältet och verkstadsuppgifterna kan kompletteras efteråt.</span></div>
                    <?php endif; ?>
                    <div class="skade-form-actions">
                        <p><?= $skadeCustomerMode ? 'Kontrollera att allt är rätt innan du skickar uppgifterna.' : 'PDF:en sparas privat i arkivet. Loggen innehåller inte kund-, fordons- eller försäkringsuppgifter.' ?></p>
                        <button class="btn btn-main skade-submit" type="submit" <?= $skadeCustomerMode ? '' : 'data-mobile-action-dock' ?> <?= empty($csrfReady) || $skadeSubmissionBlocked || $skadeEditError !== '' ? 'disabled' : '' ?>><?= $skadeTextFitConfirmationRequired ? ($skadeCustomerMode ? 'Skicka ändå — texten är liten' : 'Fortsätt ändå — skapa PDF med liten text') : ($skadeCustomerMode ? 'Skicka blanketten' : ($skadeEditMode ? 'Spara ändringar och skapa ny PDF' : 'Skapa PDF för utskrift')) ?> <span aria-hidden="true">→</span></button>
                    </div>
                </section>
            </form>
        </div>

        <?php if (!$skadeCustomerMode): ?>
        <aside class="skade-preview" aria-label="PDF-förhandsvisning">
            <details class="skade-preview-details" id="skade-preview-details" data-has-generated="<?= $skadePdfValid ? '1' : '0' ?>" open>
                <summary class="skade-preview-head">
                    <span class="skade-preview-head-copy">
                        <h3><?= h($skadePreviewTitle) ?></h3>
                        <p><?= h($skadePreviewDescription) ?></p>
                    </span>
                    <span class="skade-preview-chevron" aria-hidden="true"></span>
                </summary>
                <div class="skade-preview-content">
                    <?php if ($skadePreviewAvailable): ?>
                        <div class="skade-preview-links">
                            <a class="btn btn-main" href="<?= h($skadePreviewUrl) ?>" target="_blank" rel="noopener"><?= $skadePdfValid ? 'Öppna skapad PDF ↗' : 'Öppna original ↗' ?></a>
                            <a class="btn btn-outline" href="<?= h($skadePreviewDownloadUrl) ?>"><?= $skadePdfValid ? 'Ladda ned PDF' : 'Ladda ned original' ?></a>
                            <?php if ($skadePdfValid && $skadeTemplateExists): ?>
                                <a class="btn btn-outline" href="<?= h($skadeViewOriginal) ?>" target="_blank" rel="noopener"><?= ($portalLanguage ?? 'sv') === 'en' ? 'View original form' : 'Visa originalblankett' ?></a>
                            <?php endif; ?>
                        </div>
                        <iframe class="skade-pdf-frame" title="<?= h($skadePdfValid ? 'Förhandsvisning av skapad skadeblankett' : 'Förhandsvisning av Bilrutans ifyllbara originalblankett') ?>" src="<?= h($skadePreviewUrl) ?>#toolbar=0&navpanes=0&view=FitH" loading="lazy"></iframe>
                        <div class="skade-original-note">
                            <span class="skade-mini-icon" aria-hidden="true">ⓘ</span>
                            <span>
                                <?php if ($skadePdfValid): ?>
                                    Detta är den skapade kundkopian. Kontrollera uppgifterna och komplettera verkstadsfälten innan den används.
                                    <?php if ($skadeTemplateExists): ?><a href="<?= h($skadeViewOriginal) ?>" target="_blank" rel="noopener"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Open the original form.' : 'Öppna originalblanketten.' ?></a><?php endif; ?>
                                <?php else: ?>
                                    Originalblanketten visas före skapandet. Den skapade PDF:en visas här automatiskt när den har skapats.
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php else: ?>
                        <div class="skade-preview-empty"><?= ($portalLanguage ?? 'sv') === 'en' ? 'The original PDF was not found in the configured template folder.' : 'Original-PDF:en hittades inte i den konfigurerade mallmappen.' ?></div>
                    <?php endif; ?>
                </div>
            </details>
        </aside>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    <?php if ($skadeCustomerMode): ?>
    // Standalone gesture restrictions are scoped to the customer app. They do
    // not lock the operating system or replace Guided Access / kiosk mode.
    var standaloneQuery = typeof window.matchMedia === 'function' ? window.matchMedia('(display-mode: standalone)') : null;
    function isStandalone() { return window.navigator.standalone === true || !!(standaloneQuery && standaloneQuery.matches); }
    function zoomed() { return !!(window.visualViewport && window.visualViewport.scale > 1.01); }
    function syncGestureClasses() {
        document.documentElement.classList.toggle('skade-kiosk-standalone', isStandalone());
        document.documentElement.classList.toggle('skade-kiosk-zoomed', zoomed());
    }
    syncGestureClasses();
    if (standaloneQuery && typeof standaloneQuery.addEventListener === 'function') standaloneQuery.addEventListener('change', syncGestureClasses);
    else if (standaloneQuery && typeof standaloneQuery.addListener === 'function') standaloneQuery.addListener(syncGestureClasses);
    if (window.visualViewport && typeof window.visualViewport.addEventListener === 'function') window.visualViewport.addEventListener('resize', syncGestureClasses);
    var swipe = null;
    var signatureGesture = false;
    function inSignature(target) { return !!(target && target.closest && target.closest('#skade-signature-pad')); }
    function swipeExempt(target) {
        if (!target || !target.closest) return false;
        if (inSignature(target) || target.closest('[contenteditable="true"]')) return true;
        var editable = target.closest('input:not([type=checkbox]):not([type=radio]), textarea, select');
        // Normal browser mode leaves focused text-editing gestures untouched.
        return !isStandalone() && !!editable && document.activeElement === editable;
    }
    document.addEventListener('touchstart', function (event) {
        if (event.touches.length === 1) signatureGesture = inSignature(event.target);
        if (event.touches.length !== 1) {
            for (var i = 0; i < event.touches.length; i++) if (inSignature(event.touches[i].target)) signatureGesture = true;
            swipe = null;
            if (isStandalone() && !zoomed() && !signatureGesture && event.cancelable) event.preventDefault();
            return;
        }
        if (zoomed() || swipeExempt(event.target)) { swipe = null; return; }
        swipe = { x:event.touches[0].clientX, y:event.touches[0].clientY, axis:'' };
    }, { passive:false });
    document.addEventListener('touchmove', function (event) {
        if (event.touches.length !== 1) {
            swipe = null;
            if (isStandalone() && !zoomed() && !signatureGesture && event.cancelable) event.preventDefault();
            return;
        }
        if (!swipe || zoomed()) { swipe = null; return; }
        var dx = Math.abs(event.touches[0].clientX - swipe.x);
        var dy = Math.abs(event.touches[0].clientY - swipe.y);
        if (!swipe.axis && Math.max(dx, dy) >= 4) swipe.axis = dx > dy ? 'x' : 'y';
        if (swipe.axis === 'x' && event.cancelable) event.preventDefault();
    }, { passive:false });
    function endSwipe(event) { swipe = null; if (!event.touches || event.touches.length === 0) signatureGesture = false; }
    document.addEventListener('touchend', endSwipe, { passive:true });
    document.addEventListener('touchcancel', endSwipe, { passive:true });
    // Older iOS WebKit exposes separate pinch gesture events.
    ['gesturestart', 'gesturechange'].forEach(function (type) {
        document.addEventListener(type, function (event) {
            if (isStandalone() && !zoomed() && !signatureGesture && !inSignature(event.target) && event.cancelable) event.preventDefault();
        }, { passive:false });
    });
    <?php endif; ?>
    var kioskNotice = document.getElementById('skade-kiosk-notice');
    if (kioskNotice && window.history && typeof window.history.replaceState === 'function') {
        window.history.replaceState(null, '', window.location.pathname);
    }
    var installHelp = document.getElementById('skade-install-help');
    if (installHelp && (window.navigator.standalone === true || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches))) installHelp.hidden = true;
    var form = document.getElementById('skade-form');
    if (!form) return;
    form.noValidate = true;
    var signatureRequired = form.getAttribute('data-signature-required') === '1';
    var signatureConfirmedInput = document.getElementById('skade-signature-confirmed');
    var validationAttempted = false;
    var formSubmitting = false;
    var reportedClientValidationKeys = Object.create(null);
    var bar = document.getElementById('skade-progress-bar');
    var progressTrack = document.getElementById('skade-progress-track');
    var text = document.getElementById('skade-progress-text');
    var canvas = document.getElementById('skade-signature-pad');
    var signatureInput = document.getElementById('skade-signature-png');
    var signatureStatus = document.getElementById('skade-signature-status');
    var signatureClipped = false;
    var clippedSignatureMessage = <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? 'The signature reached the edge and may be cut off. Clear it and sign again with space around it.' : 'Signaturen nådde kanten och kan vara avhuggen. Rensa och skriv igen med luft runtom.', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var clearButton = document.getElementById('skade-signature-clear');
    var preview = document.getElementById('skade-preview-details');
    var previewToggle = document.getElementById('skade-preview-toggle');
    var regnrInput = document.getElementById('sk-regnr');
    var skadeDateField = document.getElementById('sk-datum');
    var skadeDatePicker = document.getElementById('sk-datum-picker');
    function normalizeSkadeDate(value) {
        function datePartsAreValid(year, month, day) {
            if (year < 1 || year > 9999 || month < 1 || month > 12 || day < 1) return false;
            var leapYear = year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0);
            var daysInMonth = [31, leapYear ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
            return day <= daysInMonth[month - 1];
        }
        var raw = String(value || '').trim();
        if (!raw) return '';
        var match = raw.match(/^(\d{4})(?:[.\s-]|\/)(\d{1,2})(?:[.\s-]|\/)(\d{1,2})$/);
        var year, month, day;
        if (match) {
            year = Number(match[1]);
            month = Number(match[2]);
            day = Number(match[3]);
        } else if ((match = raw.match(/^(\d{1,2})(?:[.\s-]|\/)(\d{1,2})(?:[.\s-]|\/)(\d{4})$/))) {
            day = Number(match[1]);
            month = Number(match[2]);
            year = Number(match[3]);
        } else if (/^\d{6}$/.test(raw)) {
            var shortYear = Number(raw.slice(0, 2));
            year = shortYear <= 49 ? 2000 + shortYear : 1900 + shortYear;
            month = Number(raw.slice(2, 4));
            day = Number(raw.slice(4, 6));
        } else if (/^\d{8}$/.test(raw)) {
            var compactYear = Number(raw.slice(0, 4));
            var compactMonth = Number(raw.slice(4, 6));
            var compactDay = Number(raw.slice(6, 8));
            if (compactYear >= 1000 && datePartsAreValid(compactYear, compactMonth, compactDay)) {
                year = compactYear;
                month = compactMonth;
                day = compactDay;
            } else {
                day = Number(raw.slice(0, 2));
                month = Number(raw.slice(2, 4));
                year = Number(raw.slice(4, 8));
            }
        } else {
            return '';
        }
        if (!datePartsAreValid(year, month, day)) return '';
        return String(year).padStart(4, '0') + '-' + String(month).padStart(2, '0') + '-' + String(day).padStart(2, '0');
    }
    function syncSkadeDatePicker() {
        if (!skadeDateField || !skadeDatePicker) return;
        var rawDate = String(skadeDateField.value || '').trim();
        var normalizedDate = normalizeSkadeDate(rawDate);
        skadeDatePicker.value = normalizedDate;
        if (normalizedDate && rawDate !== normalizedDate) {
            skadeDateField.value = normalizedDate;
        }
    }
    if (skadeDateField && skadeDatePicker) {
        skadeDateField.addEventListener('input', syncSkadeDatePicker);
        skadeDateField.addEventListener('change', syncSkadeDatePicker);
        skadeDatePicker.addEventListener('change', function () {
            if (!skadeDatePicker.value) return;
            skadeDateField.value = skadeDatePicker.value;
            skadeDateField.dispatchEvent(new Event('input', { bubbles:true }));
            skadeDateField.dispatchEvent(new Event('change', { bubbles:true }));
        });
        syncSkadeDatePicker();
    }
    function normalizeRegnr(value) {
        return String(value || '').toUpperCase().replace(/[^A-Z0-9-]/g, '');
    }
    function getValidationMessage(field) {
        if (!field || field === canvas || field.type === 'hidden' || field.disabled) return '';
        var currentValue = String(field.value || '').trim();
        if (field.required && field.type !== 'checkbox' && field.type !== 'radio' && !currentValue) return 'Fyll i det här fältet.';
        if (field.id === 'sk-epost' && currentValue === '') return '';
        if (field.id === 'sk-datum' && currentValue !== '' && !normalizeSkadeDate(currentValue)) {
            return 'Ange ett giltigt datum, t.ex. 2026-11-03 eller 03.11.2026.';
        }
        if (field.id === 'sk-telefon' && currentValue !== '') {
            var phoneDigits = String(field.value).replace(/\D/g, '');
            if (phoneDigits.length < 5) return field.required ? 'Ange minst fem siffror i telefonnumret.' : 'Ange minst fem siffror eller lämna telefonfältet tomt.';
        }
        var validity = field.validity;
        if (!validity || validity.valid) return '';
        if (validity.valueMissing) {
            if (field.type === 'checkbox') return 'Bekräfta uppgifterna genom att kryssa i rutan.';
            if (field.id === 'sk-datum') return 'Ange skadedatum eller välj det i kalendern.';
            return 'Fyll i det här fältet.';
        }
        if (validity.typeMismatch && field.type === 'email') return 'Skriv en giltig e-postadress.';
        if (validity.patternMismatch && field.id === 'sk-regnr') return 'Ange 2–12 tecken med bokstäver, siffror eller bindestreck.';
        if (validity.tooLong) return 'Värdet är för långt.';
        if (validity.rangeUnderflow || validity.rangeOverflow) return 'Ange ett värde inom tillåtet intervall.';
        if (validity.stepMismatch) return 'Ange ett giltigt heltal.';
        if (validity.badInput) return 'Ange ett giltigt värde.';
        return 'Kontrollera formatet på uppgiften.';
    }
    function validationContainer(field) {
        if (field.id === 'skade-signature-confirmed') return field.closest('.skade-signature');
        return field.closest('.skade-field') || field.closest('.skade-signature') || field.parentElement;
    }
    function showFieldError(field, message) {
        var container = validationContainer(field);
        if (!container) return;
        var errorId = 'skade-error-' + (field.id || 'field');
        var error = document.getElementById(errorId);
        if (!error) {
            error = document.createElement('small');
            error.id = errorId;
            error.className = 'skade-field-error';
            error.setAttribute('aria-live', 'polite');
            if (field.id === 'skade-signature-confirmed') {
                var choice = field.closest('.skade-choice');
                if (choice && choice.parentNode) choice.insertAdjacentElement('afterend', error);
                else container.appendChild(error);
            } else {
                container.appendChild(error);
            }
        }
        error.textContent = message;
        error.hidden = false;
        field.setAttribute('aria-invalid', 'true');
        var descriptions = (field.getAttribute('aria-describedby') || '').split(' ').filter(Boolean);
        if (descriptions.indexOf(errorId) === -1) descriptions.push(errorId);
        field.setAttribute('aria-describedby', descriptions.join(' '));
        container.classList.add('has-error');
    }
    function clearFieldError(field) {
        if (!field) return;
        var errorId = 'skade-error-' + (field.id || 'field');
        var error = document.getElementById(errorId);
        if (error) {
            error.hidden = true;
            error.textContent = '';
        }
        field.removeAttribute('aria-invalid');
        var descriptions = (field.getAttribute('aria-describedby') || '').split(' ').filter(function (id) { return id && id !== errorId; });
        if (descriptions.length) field.setAttribute('aria-describedby', descriptions.join(' '));
        else field.removeAttribute('aria-describedby');
        var container = validationContainer(field);
        if (container && !container.querySelector('[aria-invalid="true"]')) container.classList.remove('has-error');
    }
    function validateField(field) {
        if (field === canvas) {
            if (signatureClipped) { setSignatureStatus(clippedSignatureMessage, 'error'); return false; }
            if (signatureInput && signatureInput.value !== '') {
                setSignatureStatus(<?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "The signature is ready for the PDF." : "Signaturen är klar för PDF:en.", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
                return true;
            }
            if (!signatureRequired) {
                setSignatureStatus(<?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "No drawn signature. The PDF can be printed for a handwritten signature." : "Ingen ritad signatur. PDF:en kan skrivas ut för handskriven underskrift.", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
                return true;
            }
            setSignatureStatus(<?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "Draw the signature before submitting." : "Rita signaturen innan du skickar.", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, 'error');
            return false;
        }
        var message = getValidationMessage(field);
        if (message) {
            showFieldError(field, message);
            return false;
        }
        clearFieldError(field);
        return true;
    }
    function validateForm() {
        var firstInvalid = null;
        Array.prototype.forEach.call(form.querySelectorAll('input, textarea, select, canvas'), function (field) {
            if (field === canvas) {
                if (!validateField(field) && !firstInvalid) firstInvalid = field;
                return;
            }
            if (field.type === 'hidden') return;
            if (!validateField(field) && !firstInvalid) firstInvalid = field;
        });
        return firstInvalid;
    }
    function safeClientValidationField(field) {
        if (field === canvas) return 'signature_png';
        var name = field && typeof field.name === 'string' ? field.name : '';
        var match = /^s\[([a-z0-9_]+)\](?:\[\])?$/.exec(name);
        if (!match) return '';
        var allowed = [
            'regnr', 'namn_adress', 'person_orgnr', 'telefon', 'epost', 'modell', 'matarstallning',
            'forsakringsbolag', 'skadedatum', 'skadeplats', 'skadenummer', 'other_cause',
            'stone_count', 'mount_time', 'glass_price', 'notes', 'wiper_price', 'washer_liters',
            'solar_price', 'payment_amount', 'signature_date', 'replacement_date', 'signature_png',
            'signature_confirmed', 'damage_cause', 'payment_method', 'glass_locations', 'actions',
            'equipment', 'foretag', 'nya_torkarblad_bestalls', 'wiper_service', 'solar_film', 'consent'
        ];
        return allowed.indexOf(match[1]) !== -1 ? match[1] : '';
    }
    function reportClientValidationFailure() {
        if (typeof window.fetch !== 'function' || typeof window.URLSearchParams !== 'function') return;
        var invalidControls = form.querySelectorAll('[aria-invalid="true"]');
        var fieldKeys = [];
        Array.prototype.forEach.call(invalidControls, function (field) {
            var fieldKey = safeClientValidationField(field);
            if (fieldKey && fieldKeys.indexOf(fieldKey) === -1 && fieldKeys.length < 20) fieldKeys.push(fieldKey);
        });
        fieldKeys.sort();
        if (!fieldKeys.length) return;
        var reportKey = fieldKeys.join(',');
        if (reportedClientValidationKeys[reportKey]) return;
        reportedClientValidationKeys[reportKey] = true;
        var csrfInput = form.querySelector('input[name="csrf_token"]');
        var csrfToken = csrfInput && typeof csrfInput.value === 'string' ? csrfInput.value : '';
        if (!csrfToken) return;
        var endpoint = form.action || window.location.href;
        try {
            var endpointUrl = new URL(endpoint, window.location.href);
            if (endpointUrl.origin !== window.location.origin) return;
        } catch (error) { return; }
        var body = new URLSearchParams();
        body.set('action', 'client_diagnostic');
        body.set('csrf_token', csrfToken);
        body.set('diagnostic_type', 'client_validation');
        body.set('diagnostic_code', 'skade.client_validation_failed');
        fieldKeys.forEach(function (fieldKey) { body.append('diagnostic_fields[]', fieldKey); });
        window.fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: body.toString(),
            keepalive: true
        }).catch(function () {});
    }
    function focusInvalidField(field) {
        if (!field) return;
        try {
            if (typeof field.scrollIntoView === 'function') field.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } catch (error) {}
        try { field.focus({ preventScroll: true }); }
        catch (error) { try { field.focus(); } catch (ignored) {} }
    }
    <?php if ($skadeCustomerMode): ?>
    var customerStep = 'entry';
    var entrySections = Array.prototype.slice.call(form.querySelectorAll('[data-skade-step="entry"]'));
    var reviewSection = document.getElementById('skade-review');
    var reviewList = document.getElementById('skade-review-list');
    var reviewExtra = document.getElementById('skade-review-extra');
    var signStage = document.getElementById('skade-sign-stage');
    var reviewOpen = document.getElementById('skade-review-open');
    var reviewBack = document.getElementById('skade-review-back');
    var reviewContinue = document.getElementById('skade-review-continue');
    var signBack = document.getElementById('skade-sign-back');
    var customerLang = <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? 'en' : 'sv') ?>;
    function showCustomerStep(step) {
        customerStep = step;
        entrySections.forEach(function (section) { section.hidden = step !== 'entry'; });
        if (reviewOpen) reviewOpen.hidden = step !== 'entry';
        if (reviewSection) reviewSection.hidden = step !== 'review';
        if (signStage) signStage.hidden = step !== 'signature';
        if (step === 'signature' && signaturePad && typeof signaturePad.resize === 'function') {
            if (window.requestAnimationFrame) window.requestAnimationFrame(function () { signaturePad.resize(); });
            else signaturePad.resize();
        }
    }
    function entryValidation() {
        var first = null;
        entrySections.forEach(function (section) {
            Array.prototype.forEach.call(section.querySelectorAll('input,textarea,select'), function (field) {
                if (field.type !== 'hidden' && !validateField(field) && !first) first = field;
            });
        });
        return first;
    }
    function reviewLabel(field) {
        var label = field.id ? form.querySelector('label[for="' + field.id + '"]') : null;
        if (!label) label = field.closest('label');
        return label ? label.textContent.replace(/\s+/g, ' ').trim().replace(/\s*\*\s*$/, '') : '';
    }
    function buildCustomerReview() {
        if (!reviewList || !reviewExtra) return;
        while (reviewList.firstChild) reviewList.removeChild(reviewList.firstChild);
        while (reviewExtra.firstChild) reviewExtra.removeChild(reviewExtra.firstChild);
        ['sk-foretag','sk-namn','sk-personnr','sk-telefon','sk-epost','sk-regnr','sk-modell','sk-matarstallning','sk-forsakring','sk-datum','sk-plats','sk-skadenr','sk-wiper-request'].forEach(function (id) {
            var field = document.getElementById(id);
            if (!field) return;
            var label = reviewLabel(field);
            var item = document.createElement('div'); item.className = 'skade-review-item';
            var dt = document.createElement('dt'); dt.textContent = label;
            var dd = document.createElement('dd');
            dd.textContent = field.type === 'checkbox' ? (field.checked ? (customerLang === 'en' ? 'Yes' : 'Ja') : (customerLang === 'en' ? 'No' : 'Nej')) : (field.value.trim() || (customerLang === 'en' ? 'Not provided' : 'Inte angivet'));
            var edit = document.createElement('button'); edit.type = 'button'; edit.className = 'btn btn-outline';
            edit.textContent = customerLang === 'en' ? 'Change' : 'Ändra';
            edit.setAttribute('aria-label', (customerLang === 'en' ? 'Change ' : 'Ändra ') + label);
            edit.addEventListener('click', function () { showCustomerStep('entry'); focusInvalidField(field); });
            item.append(dt, dd, edit);
            (['sk-namn','sk-regnr','sk-datum'].indexOf(id) !== -1 ? reviewList : reviewExtra).appendChild(item);
        });
    }
    function openCustomerReview() {
        validationAttempted = true;
        var invalid = entryValidation();
        if (invalid) { showCustomerStep('entry'); reportClientValidationFailure(); focusInvalidField(invalid); return false; }
        buildCustomerReview(); showCustomerStep('review');
        focusInvalidField(document.getElementById('skade-review-heading'));
        return true;
    }
    if (reviewOpen && reviewSection && signStage) {
        reviewOpen.addEventListener('click', openCustomerReview);
        reviewBack.addEventListener('click', function () { showCustomerStep('entry'); focusInvalidField(reviewOpen); });
        reviewContinue.addEventListener('click', function () { showCustomerStep('signature'); focusInvalidField(document.getElementById('skade-sign-heading')); });
        signBack.hidden = false;
        signBack.addEventListener('click', function () { buildCustomerReview(); showCustomerStep('review'); focusInvalidField(document.getElementById('skade-review-heading')); });
        showCustomerStep('entry');
    }

    // Text size is temporary to this page and stores no customer information.
    var tools = document.getElementById('skade-customer-tools');
    var textSize = document.getElementById('skade-text-size');
    if (tools && textSize) {
        tools.hidden = false;
        textSize.addEventListener('click', function () {
            var large = document.body.classList.toggle('skade-large-text');
            textSize.setAttribute('aria-pressed', large ? 'true' : 'false');
            textSize.textContent = large ? (customerLang === 'en' ? 'Normal text' : 'Normal text') : (customerLang === 'en' ? 'Larger text' : 'Större text');
            if (signaturePad && typeof signaturePad.resize === 'function') signaturePad.resize();
        });
    }
    // Associate existing helper text with controls for screen-reader users.
    entrySections.forEach(function (section) {
        Array.prototype.forEach.call(section.querySelectorAll('.skade-field'), function (container) {
            var control = container.querySelector('input:not([type=hidden]),textarea,select');
            var help = container.querySelector('.skade-help');
            if (!control || !control.id || !help) return;
            if (!help.id) help.id = control.id + '-help';
            var ids = (control.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
            if (ids.indexOf(help.id) < 0) ids.push(help.id);
            control.setAttribute('aria-describedby', ids.join(' '));
        });
    });
    // Only static labels and help are spoken, never field contents.
    var speechStop = document.getElementById('skade-speech-stop');
    var speechNote = document.getElementById('skade-speech-note');
    if (window.speechSynthesis && typeof window.SpeechSynthesisUtterance === 'function' && speechStop && speechNote) {
        speechStop.hidden = false; speechNote.hidden = false;
        speechStop.addEventListener('click', function () { window.speechSynthesis.cancel(); });
        entrySections.forEach(function (section) {
            Array.prototype.forEach.call(section.querySelectorAll('.skade-field'), function (container) {
                var label = container.querySelector('label');
                if (!label) return;
                var help = container.querySelector('.skade-help');
                var labelText = label.textContent.replace(/\s+/g, ' ').trim();
                var spoken = labelText + (help ? '. ' + help.textContent.replace(/\s+/g, ' ').trim() : '');
                var button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-outline skade-read-field';
                button.textContent = customerLang === 'en' ? 'Read instructions' : 'Läs upp';
                button.setAttribute('aria-label', (customerLang === 'en' ? 'Read instructions for ' : 'Läs upp instruktion för ') + labelText);
                button.addEventListener('click', function () { window.speechSynthesis.cancel(); var utterance = new window.SpeechSynthesisUtterance(spoken); utterance.lang = customerLang === 'en' ? 'en-GB' : 'sv-SE'; window.speechSynthesis.speak(utterance); });
                container.classList.add('skade-field-has-speech');
                container.appendChild(button);
            });
        });
        var signHelp = document.getElementById('skade-signature-help');
        var declaration = form.querySelector('.skade-customer-declaration');
        [signHelp, declaration].forEach(function (content) {
            if (!content) return;
            var button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-outline skade-read-field';
            button.textContent = customerLang === 'en' ? 'Read instructions' : 'Läs upp';
            button.setAttribute('aria-label', content === signHelp ? (customerLang === 'en' ? 'Read signature instructions' : 'Läs upp signeringsinstruktioner') : (customerLang === 'en' ? 'Read declaration and payment terms' : 'Läs upp intygande och betalningsvillkor'));
            button.addEventListener('click', function () { window.speechSynthesis.cancel(); var utterance = new window.SpeechSynthesisUtterance(content.textContent.replace(/\s+/g, ' ').trim()); utterance.lang = customerLang === 'en' ? 'en-GB' : 'sv-SE'; window.speechSynthesis.speak(utterance); });
            content.insertAdjacentElement('afterend', button);
        });
        window.addEventListener('pagehide', function () { window.speechSynthesis.cancel(); });
    }
    // Customer pages do not have a mobile action dock; handle the visual
    // viewport separately and do not force horizontal scroll or disable zoom.
    var keyboardTimer = 0;
    function keepCustomerFieldVisible() {
        var active = document.activeElement;
        if (!active || !form.contains(active) || !active.matches('textarea, input:not([type=checkbox]):not([type=radio]):not([type=hidden]):not([type=date]), select')) return;
        var viewport = window.visualViewport;
        if (!viewport || !viewport.height || window.innerHeight - viewport.height < 100) return;
        var rect = active.getBoundingClientRect(), top = viewport.offsetTop + 12, bottom = viewport.offsetTop + viewport.height - 16;
        var delta = rect.top < top ? rect.top - top : (rect.bottom > bottom ? Math.min(rect.bottom - bottom, rect.top - top) : 0);
        if (Math.abs(delta) > 2) window.scrollBy({ top:delta, left:0, behavior:'auto' });
    }
    function scheduleCustomerVisibility() { window.clearTimeout(keyboardTimer); keyboardTimer = window.setTimeout(keepCustomerFieldVisible, 120); }
    form.addEventListener('focusin', scheduleCustomerVisibility);
    if (window.visualViewport && typeof window.visualViewport.addEventListener === 'function') window.visualViewport.addEventListener('resize', scheduleCustomerVisibility);
    window.addEventListener('resize', scheduleCustomerVisibility, {passive:true});
    <?php endif; ?>
    if (regnrInput) {
        var initialRegnr = normalizeRegnr(regnrInput.value);
        if (regnrInput.value !== initialRegnr) regnrInput.value = initialRegnr;
        regnrInput.addEventListener('input', function () {
            var original = regnrInput.value;
            var cursor = typeof regnrInput.selectionStart === 'number' ? regnrInput.selectionStart : original.length;
            var normalized = normalizeRegnr(original);
            if (original !== normalized) {
                var normalizedCursor = normalizeRegnr(original.slice(0, cursor)).length;
                regnrInput.value = normalized;
                try { regnrInput.setSelectionRange(normalizedCursor, normalizedCursor); } catch (error) {}
            }
            if (validationAttempted) validateField(regnrInput);
            updateProgress();
        });
    }
    if (preview && previewToggle) {
        var generatedPdf = preview.getAttribute('data-has-generated') === '1';
        var compactLayout = window.matchMedia && window.matchMedia('(max-width: 1050px)').matches;
        if (compactLayout && !generatedPdf) preview.open = false;
        function syncPreviewToggle() {
            previewToggle.hidden = false;
            previewToggle.textContent = preview.open ? <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "Hide PDF preview" : "Dölj PDF-förhandsvisning", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> : <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "Show PDF preview" : "Visa PDF-förhandsvisning", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            previewToggle.setAttribute('aria-expanded', preview.open ? 'true' : 'false');
        }
        preview.addEventListener('toggle', syncPreviewToggle);
        previewToggle.addEventListener('click', function () {
            preview.open = !preview.open;
            syncPreviewToggle();
            if (preview.open && typeof preview.scrollIntoView === 'function') {
                try { preview.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
                catch (error) { preview.scrollIntoView(); }
            }
        });
        syncPreviewToggle();
    }
    function updateProgress() {
        var required = Array.prototype.slice.call(form.querySelectorAll('[required]'));
        var radioGroups = Object.create(null);
        var complete = 0;
        var total = 0;
        required.forEach(function (field) {
            if (field.type === 'radio') {
                if (!radioGroups[field.name]) radioGroups[field.name] = { checked: false };
                if (field.checked) radioGroups[field.name].checked = true;
                return;
            }
            total++;
            if ((field.type === 'checkbox' ? field.checked : String(field.value || '').trim() !== '') && !getValidationMessage(field)) complete++;
        });
        Object.keys(radioGroups).forEach(function (name) {
            total++;
            if (radioGroups[name].checked) complete++;
        });
        if (signatureRequired && signatureInput) {
            total++;
            if (signatureInput.value !== '') complete++;
        }
        var percent = total ? Math.round(complete / total * 100) : 0;
        var invalidOptional = Array.prototype.some.call(form.querySelectorAll('input:not([type=hidden]),textarea,select'), function (field) {
            return !field.required && !field.disabled && !!getValidationMessage(field);
        });
        if (invalidOptional && percent === 100) percent = 99;
        if (bar) bar.style.width = percent + '%';
        if (progressTrack) progressTrack.setAttribute('aria-valuenow', String(percent));
        if (text) text.textContent = percent + ' % (' + complete + <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? " of " : " av ", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> + total + ')' + (invalidOptional ? <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? " · Check optional fields" : " · Kontrollera valfria fält", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> : '');
    }
    function setSignatureStatus(message, state) {
        if (signatureStatus) {
            signatureStatus.textContent = message;
            if (state) signatureStatus.setAttribute('data-state', state);
            else signatureStatus.removeAttribute('data-state');
        }
        if (canvas) {
            var signatureCard = canvas.closest('.skade-signature');
            if (state === 'error') {
                canvas.setAttribute('aria-invalid', 'true');
                if (signatureCard) signatureCard.classList.add('has-error');
            } else {
                canvas.removeAttribute('aria-invalid');
                if (signatureCard && !signatureCard.querySelector('[aria-invalid="true"]')) signatureCard.classList.remove('has-error');
            }
        }
    }
    function syncSignatureConfirmationRequirement() {
        if (!signatureConfirmedInput) return;
        var hasSignature = !!signatureInput && signatureInput.value !== '';
        signatureConfirmedInput.required = signatureRequired || hasSignature;
        if (!signatureRequired && !hasSignature) {
            signatureConfirmedInput.checked = false;
            if (validationAttempted) clearFieldError(signatureConfirmedInput);
        }
    }

    if (canvas && signatureInput) {
        var signaturePad = window.TadegaSignaturePad && typeof window.TadegaSignaturePad.attach === 'function'
            ? window.TadegaSignaturePad.attach(canvas, {
                color: '#0751a6',
                baseWidthCssPx: 2.6,
                background: null,
                onStart: function () {
                    setSignatureStatus(<?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "Draw the signature in a calm, continuous stroke." : "Skriv signaturen i ett lugnt, sammanhängande drag.", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
                },
                onEnd: function () {
                    exportSignature();
                }
            })
            : null;
        var context = signaturePad ? signaturePad.context : null;
        if (signaturePad && context) {
            if (signatureInput.value.indexOf('data:image/png;base64,') === 0) {
                var savedSignatureImage = new Image();
                savedSignatureImage.onload = function () {
                    signaturePad.setBaseImage(savedSignatureImage);
                    setSignatureStatus(<?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "The saved signature is displayed. Confirm it still belongs to the customer, or clear it and draw again." : "Sparad signatur visas. Bekräfta att den fortfarande tillhör kunden, eller rensa och rita om.", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
                    syncSignatureConfirmationRequirement();
                    updateProgress();
                };
                savedSignatureImage.onerror = function () {
                    signaturePad.clear();
                    signatureInput.value = '';
                    setSignatureStatus(<?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "The saved signature could not be displayed. Draw it again or ask staff to check the record." : "Den sparade signaturen kunde inte visas. Rita om den eller be personalen kontrollera posten.", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, 'error');
                    syncSignatureConfirmationRequirement();
                    updateProgress();
                };
                savedSignatureImage.src = signatureInput.value;
            }

            function exportSignature() {
                var pixels;
                try {
                    pixels = context.getImageData(0, 0, canvas.width, canvas.height).data;
                } catch (error) {
                    signatureInput.value = '';
                    syncSignatureConfirmationRequirement();
                    setSignatureStatus(<?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "The signature could not be read. Clear the area and try again." : "Signaturen kunde inte läsas. Rensa rutan och försök igen.", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, 'error');
                    updateProgress();
                    return;
                }
                var minX = canvas.width;
                var minY = canvas.height;
                var maxX = -1;
                var maxY = -1;
                for (var y = 0; y < canvas.height; y++) {
                    for (var x = 0; x < canvas.width; x++) {
                        var offset = (y * canvas.width + x) * 4;
                        if (pixels[offset + 3] > 0 && (pixels[offset] < 245 || pixels[offset + 1] < 245 || pixels[offset + 2] < 245)) {
                            if (x < minX) minX = x;
                            if (x > maxX) maxX = x;
                            if (y < minY) minY = y;
                            if (y > maxY) maxY = y;
                        }
                    }
                }
                if (maxX < minX || maxY < minY) {
                    signatureClipped = false;
                    signatureInput.value = '';
                    syncSignatureConfirmationRequirement();
                    setSignatureStatus(<?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "No signature yet" : "Ingen signatur ännu", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
                    updateProgress();
                    return;
                }
                // Pixels on the canvas boundary are already clipped: adding a margin
                // cannot reconstruct them. Keep the drawing visible and ask to redraw.
                signatureClipped = minX === 0 || minY === 0 || maxX === canvas.width - 1 || maxY === canvas.height - 1;
                if (signatureClipped) {
                    signatureInput.value = '';
                    syncSignatureConfirmationRequirement();
                    setSignatureStatus(clippedSignatureMessage, 'error');
                    updateProgress();
                    return;
                }
                var rect = canvas.getBoundingClientRect();
                var padding = Math.max(13, Math.round(6 * Math.sqrt((canvas.width / Math.max(1, rect.width)) * (canvas.height / Math.max(1, rect.height)))));
                var inkWidth = maxX - minX + 1;
                var inkHeight = maxY - minY + 1;
                var paddedWidth = inkWidth + 2 * padding;
                var paddedHeight = inkHeight + 2 * padding;
                // Stay within the server's PNG dimension/pixel limits on high-DPI tablets.
                var factor = Math.min(1, 1600 / paddedWidth, 800 / paddedHeight, Math.sqrt(1200000 / (paddedWidth * paddedHeight)));
                var output = document.createElement('canvas');
                output.width = Math.max(1, Math.floor(paddedWidth * factor));
                output.height = Math.max(1, Math.floor(paddedHeight * factor));
                var outputContext = output.getContext('2d');
                outputContext.imageSmoothingEnabled = true;
                if ('imageSmoothingQuality' in outputContext) outputContext.imageSmoothingQuality = 'high';
                outputContext.drawImage(canvas, minX, minY, inkWidth, inkHeight,
                    padding * output.width / paddedWidth, padding * output.height / paddedHeight,
                    inkWidth * output.width / paddedWidth, inkHeight * output.height / paddedHeight);
                var png = output.toDataURL('image/png');
                if (png.length > 550000) {
                    signatureInput.value = '';
                    syncSignatureConfirmationRequirement();
                    setSignatureStatus(<?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "The signature is too large. Clear the area and draw a simpler signature." : "Signaturen blev för stor. Rensa rutan och skriv en enklare signatur.", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, 'error');
                    updateProgress();
                    return;
                }
                signatureInput.value = png;
                syncSignatureConfirmationRequirement();
                setSignatureStatus(<?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "The signature is ready for the PDF." : "Signaturen är klar för PDF:en.", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
                updateProgress();
            }

            if (clearButton) {
                clearButton.addEventListener('click', function () {
                    signaturePad.clear();
                    signatureClipped = false;
                    signatureInput.value = '';
                    syncSignatureConfirmationRequirement();
                    setSignatureStatus(<?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "The signature was cleared." : "Signaturen har rensats.", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
                    if (validationAttempted) validateField(canvas);
                    updateProgress();
                    canvas.focus();
                });
            }
        } else {
            setSignatureStatus(<?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "Digital signing could not start. Reload the page or leave the area empty for printing." : "Digital signering kunde inte starta. Ladda om sidan eller lämna rutan tom för utskrift.", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, 'error');
        }
    }

    function handleFormChange(event) {
        <?php if ($skadeCustomerMode): ?>
        if (event.target.closest('[data-skade-step="entry"]') && signatureConfirmedInput && signatureConfirmedInput.checked) {
            signatureConfirmedInput.checked = false;
            setSignatureStatus(<?= json_encode(($portalLanguage ?? 'sv') === 'en' ? 'The information changed. Review it and confirm again.' : 'Uppgifterna har ändrats. Granska och bekräfta igen.', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
        }
        <?php endif; ?>
        updateProgress();
        if (validationAttempted && event.target !== signatureInput) validateField(event.target);
    }
    form.addEventListener('input', handleFormChange);
    form.addEventListener('change', handleFormChange);
    form.addEventListener('submit', function (event) {
        if (formSubmitting) {
            event.preventDefault();
            return;
        }
        <?php if ($skadeCustomerMode): ?>
        if (customerStep !== 'signature') { event.preventDefault(); openCustomerReview(); return; }
        <?php endif; ?>
        validationAttempted = true;
        var firstInvalid = validateForm();
        if (firstInvalid) {
            event.preventDefault();
            reportClientValidationFailure();
            <?php if ($skadeCustomerMode): ?>
            if (firstInvalid.closest('[data-skade-step="entry"]')) showCustomerStep('entry');
            <?php endif; ?>
            focusInvalidField(firstInvalid);
            return;
        }
        formSubmitting = true;
        if (window.TadegaPortalSubmitState) {
            window.TadegaPortalSubmitState.markBusy(form, null, event.submitter || null);
        }
    });
    window.addEventListener('pageshow', function () { formSubmitting = false; });
    syncSignatureConfirmationRequirement();
    updateProgress();
    if (form.getAttribute('data-validation-error') === '1') {
        validationAttempted = true;
        var serverFirstInvalid = validateForm();
        if (serverFirstInvalid) {
            <?php if ($skadeCustomerMode): ?>
            showCustomerStep(serverFirstInvalid.closest('[data-skade-step="entry"]') ? 'entry' : 'signature');
            <?php endif; ?>
            focusInvalidField(serverFirstInvalid);
        }
    }
}());
</script>
