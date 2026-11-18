<?php
declare(strict_types=1);

$customers = array_values(array_filter(load_data(CUSTOMERS_FILE, []), 'is_array'));
$invoiceCatalogRecords = portal_invoice_catalog_read();
$invoiceCatalogStorageReady = is_array($invoiceCatalogRecords);
$invoiceArticles = $invoiceCatalogStorageReady ? portal_invoice_catalog_normalize($invoiceCatalogRecords) : [];
$invoiceArticleUnits = portal_invoice_catalog_units();
$invoiceCustomerOptions = [];
foreach ($customers as $invoiceCustomerRecord) {
    $invoiceCustomerId = is_scalar($invoiceCustomerRecord['id'] ?? null) ? (string) $invoiceCustomerRecord['id'] : '';
    $invoiceCustomerName = trim(app_legacy_text($invoiceCustomerRecord['name'] ?? ''));
    if ($invoiceCustomerId === '' || $invoiceCustomerName === '') {
        continue;
    }
    $invoiceCustomerReferences = [];
    $rawInvoiceCustomerReferences = $invoiceCustomerRecord['references'] ?? [];
    if (is_array($rawInvoiceCustomerReferences)) {
        foreach (array_slice($rawInvoiceCustomerReferences, 0, 20) as $rawInvoiceCustomerReference) {
            if (!is_scalar($rawInvoiceCustomerReference)) {
                continue;
            }
            $invoiceCustomerReference = trim(app_legacy_text($rawInvoiceCustomerReference));
            if ($invoiceCustomerReference !== '' && !in_array($invoiceCustomerReference, $invoiceCustomerReferences, true)) {
                $invoiceCustomerReferences[] = $invoiceCustomerReference;
            }
        }
    }
    $legacyInvoiceCustomerReference = trim(app_legacy_text($invoiceCustomerRecord['reference'] ?? ''));
    if ($legacyInvoiceCustomerReference !== '' && !in_array($legacyInvoiceCustomerReference, $invoiceCustomerReferences, true)) {
        array_unshift($invoiceCustomerReferences, $legacyInvoiceCustomerReference);
    }
    $invoiceCustomerOptions[] = [
        'id' => $invoiceCustomerId,
        'name' => $invoiceCustomerName,
        'references' => array_slice(array_values($invoiceCustomerReferences), 0, 20),
    ];
}
$savedInvoices = array_values(array_filter(load_data(INVOICES_FILE, []), 'is_array'));
$invoiceListRecords = $savedInvoices;
usort($invoiceListRecords, static function (array $left, array $right): int {
    $leftDate = (string) ($left['updated_at'] ?? $left['date'] ?? '');
    $rightDate = (string) ($right['updated_at'] ?? $right['date'] ?? '');
    return strcmp($rightDate, $leftDate);
});
$invoiceProcessingRecords = array_values(array_filter($invoiceListRecords, static function (array $record): bool {
    $number = $record['fortnox_invoice_number'] ?? '';
    return !is_scalar($number) || trim((string) $number) === '';
}));
$invoiceHistoryRecords = array_values(array_filter($invoiceListRecords, static function (array $record): bool {
    $number = $record['fortnox_invoice_number'] ?? '';
    return is_scalar($number) && trim((string) $number) !== '';
}));
$invoiceRecordSearchText = static function (array $record) use ($invoiceArticleUnits): string {
    $searchParts = [];
    foreach (['nummer', 'fortnox_invoice_number', 'fortnox_invoice_date', 'date', 'customer', 'ref', 'description'] as $field) {
        $value = $record[$field] ?? '';
        if (is_scalar($value)) {
            $text = trim(app_legacy_text($value));
            if ($text !== '') {
                $searchParts[] = $text;
            }
        }
    }
    foreach (is_array($record['items'] ?? null) ? $record['items'] : [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        foreach (['art', 'qty', 'price'] as $field) {
            $value = $item[$field] ?? '';
            if (is_scalar($value)) {
                $text = trim(app_legacy_text($value));
                if ($text !== '') {
                    $searchParts[] = $text;
                }
            }
        }
        $unit = is_scalar($item['unit'] ?? null) ? (string) $item['unit'] : 'st';
        $searchParts[] = $invoiceArticleUnits[$unit] ?? ($invoiceArticleUnits['st'] ?? 'st');
    }
    return implode(' ', $searchParts);
};
$currentInvoiceUser = portal_invoice_auth_current_user();
$currentInvoiceUserId = is_array($currentInvoiceUser) ? (string) ($currentInvoiceUser['id'] ?? '') : '';
$isInvoiceAdmin = is_array($currentInvoiceUser) && ($currentInvoiceUser['role'] ?? '') === 'admin';
$isCentralAdminUser = is_array($currentInvoiceUser) && ($currentInvoiceUser['auth_source'] ?? '') === 'central_admin';
$invoiceDrafts = $currentInvoiceUserId !== '' ? portal_autosaved_drafts_list('invoice', $currentInvoiceUserId) : [];
$invoiceDraftId = portal_autosaved_drafts_new_id();
$invoiceDraftData = null;
$requestedDraftId = isset($_GET['draft_id']) && is_string($_GET['draft_id']) ? $_GET['draft_id'] : '';
if ($requestedDraftId !== '') {
    $loadedInvoiceDraft = portal_autosaved_drafts_get('invoice', $currentInvoiceUserId, $requestedDraftId);
    if (is_array($loadedInvoiceDraft)) {
        $invoiceDraftId = (string) $loadedInvoiceDraft['id'];
        $invoiceDraftData = is_array($loadedInvoiceDraft['data'] ?? null) ? $loadedInvoiceDraft['data'] : null;
    }
}
$editId = isset($_GET['edit_id']) && is_string($_GET['edit_id']) ? $_GET['edit_id'] : '';
if ($editId === '' && is_array($invoiceDraftData) && is_array($invoiceDraftData['i'] ?? null) && isset($invoiceDraftData['i']['id']) && is_string($invoiceDraftData['i']['id'])) {
    $editId = $invoiceDraftData['i']['id'];
}
$copyId = isset($_GET['copy_id']) && is_string($_GET['copy_id']) ? $_GET['copy_id'] : '';
$copyingInvoice = false;
$editInvoice = null;

foreach ($savedInvoices as $record) {
    if ($editId !== '' && (string) ($record['id'] ?? '') === $editId) {
        $editInvoice = $record;
        break;
    }
}
if ($editId === '' && !is_array($invoiceDraftData) && $copyId !== '') {
    foreach ($savedInvoices as $record) {
        if ((string) ($record['id'] ?? '') !== $copyId) {
            continue;
        }
        $sourceItems = is_array($record['items'] ?? null) ? array_slice($record['items'], 0, 100) : [];
        $copyItems = [];
        foreach ($sourceItems as $sourceItem) {
            if (!is_array($sourceItem)) {
                continue;
            }
            $sourceUnit = is_scalar($sourceItem['unit'] ?? null) ? (string) $sourceItem['unit'] : 'st';
            if (!array_key_exists($sourceUnit, $invoiceArticleUnits)) {
                $sourceUnit = 'st';
            }
            $copyItems[] = [
                'art' => app_legacy_text($sourceItem['art'] ?? ''),
                'unit' => $sourceUnit,
                'qty' => app_legacy_text($sourceItem['qty'] ?? ''),
                'price' => app_legacy_text($sourceItem['price'] ?? ''),
            ];
        }
        $sourceVatMode = (string) ($record['vat_mode'] ?? 'ex');
        $editInvoice = [
            'id' => '',
            'date' => date('Y-m-d'),
            'ref' => app_legacy_text($record['ref'] ?? ''),
            'customer' => app_legacy_text($record['customer'] ?? ''),
            'description' => app_legacy_text($record['description'] ?? ''),
            'vat_mode' => in_array($sourceVatMode, ['ex', 'inc'], true) ? $sourceVatMode : 'ex',
            'items' => $copyItems,
        ];
        $copyingInvoice = true;
        break;
    }
}

if (is_array($editInvoice)) {
    foreach (['nummer', 'fortnox_invoice_number', 'fortnox_invoice_date', 'date', 'ref', 'customer', 'description'] as $legacyKey) {
        if (isset($editInvoice[$legacyKey])) {
            $editInvoice[$legacyKey] = app_legacy_text($editInvoice[$legacyKey]);
        }
    }
}
if (is_array($invoiceDraftData)) {
    $draftInvoiceFields = is_array($invoiceDraftData['i'] ?? null) ? $invoiceDraftData['i'] : [];
    $editInvoice = array_replace(is_array($editInvoice) ? $editInvoice : [], $draftInvoiceFields);
    if (is_array($invoiceDraftData['items'] ?? null)) {
        $editInvoice['items'] = $invoiceDraftData['items'];
    }
}
$requestedCustomerId = isset($_GET['customer_id']) && is_string($_GET['customer_id']) ? $_GET['customer_id'] : '';
$requestedCustomerRefPresent = array_key_exists('customer_ref', $_GET) && is_string($_GET['customer_ref']);
$requestedCustomerRef = $requestedCustomerRefPresent ? trim((string) $_GET['customer_ref']) : '';
$selectedInvoiceCustomer = null;
foreach ($invoiceCustomerOptions as $invoiceCustomerOption) {
    if ($requestedCustomerId !== '' && $invoiceCustomerOption['id'] === $requestedCustomerId) {
        $selectedInvoiceCustomer = $invoiceCustomerOption;
        break;
    }
}
$invoiceCustomerPrefillMissing = $requestedCustomerId !== '' && !is_array($selectedInvoiceCustomer);
if (is_array($selectedInvoiceCustomer)) {
    $editInvoice = is_array($editInvoice) ? $editInvoice : [];
    if (trim((string) ($editInvoice['customer'] ?? '')) === '') {
        $editInvoice['customer'] = $selectedInvoiceCustomer['name'];
    }
    if (trim((string) ($editInvoice['ref'] ?? '')) === '') {
        $selectedReference = '';
        if ($requestedCustomerRefPresent) {
            if ($requestedCustomerRef !== '' && in_array($requestedCustomerRef, $selectedInvoiceCustomer['references'], true)) {
                $selectedReference = $requestedCustomerRef;
            }
        } else {
            $selectedReference = (string) ($selectedInvoiceCustomer['references'][0] ?? '');
        }
        $editInvoice['ref'] = $selectedReference;
    }
}
$isEditingInvoice = is_array($editInvoice) && trim((string) ($editInvoice['id'] ?? '')) !== '';

$invoiceItems = is_array($editInvoice['items'] ?? null)
    ? array_values(array_filter(array_slice($editInvoice['items'], 0, 100), 'is_array'))
    : [];
foreach ($invoiceItems as &$legacyInvoiceItem) {
    foreach (['art', 'qty', 'price'] as $legacyKey) {
        if (isset($legacyInvoiceItem[$legacyKey])) {
            $legacyInvoiceItem[$legacyKey] = app_legacy_text($legacyInvoiceItem[$legacyKey]);
        }
    }
}
unset($legacyInvoiceItem);

$rowCount = max(1, count($invoiceItems));
$vatModeCandidate = $editInvoice['vat_mode'] ?? 'ex';
$vatMode = in_array($vatModeCandidate, ['ex', 'inc'], true) ? $vatModeCandidate : 'ex';
$invoiceNumber = (string) ($editInvoice['nummer'] ?? '');
$fortnoxInvoiceNumber = (string) ($editInvoice['fortnox_invoice_number'] ?? '');
$fortnoxInvoiceDate = (string) ($editInvoice['fortnox_invoice_date'] ?? '');
$invoiceUsers = $isInvoiceAdmin ? portal_invoice_auth_admin_users() : [];
$protocolUsers = $isInvoiceAdmin && empty($portalIsScopedPwa) ? portal_protocol_auth_admin_users() : [];
$emailStatus = $isInvoiceAdmin ? portal_email_status_read() : null;
$emailTransportStatus = $isInvoiceAdmin ? portal_email_transport_status() : null;
$emailSettings = $isInvoiceAdmin ? load_settings() : [];
$emailInvoicePrimary = is_array($emailSettings) && is_string($emailSettings['notification_email'] ?? null) ? $emailSettings['notification_email'] : '';
$emailInvoiceRecipientConfigured = filter_var($emailInvoicePrimary, FILTER_VALIDATE_EMAIL) !== false;
$invoiceEmailEntry = is_array($emailStatus['invoice'] ?? null) ? $emailStatus['invoice'] : null;
$skadeEmailEntry = is_array($emailStatus['skadeblankett'] ?? null) ? $emailStatus['skadeblankett'] : null;
$initialLineTotals = [];
foreach ($invoiceItems as $item) {
    $quantity = is_numeric($item['qty'] ?? null) ? max(0, (float) $item['qty']) : 0.0;
    $price = is_numeric($item['price'] ?? null) ? max(0, (float) $item['price']) : 0.0;
    $initialLineTotals[] = round($quantity * $price, 2);
}
$requestedInvoiceView = isset($_GET['invoice_view']) && is_string($_GET['invoice_view']) ? $_GET['invoice_view'] : 'form';
$invoiceAdminViews = empty($portalIsScopedPwa) ? ['admin', 'email', 'debug'] : ['admin', 'email'];
$invoiceView = in_array($requestedInvoiceView, ['articles', 'customers', 'processing', 'invoice_history'], true)
    ? $requestedInvoiceView
    : ($isInvoiceAdmin && in_array($requestedInvoiceView, $invoiceAdminViews, true) ? $requestedInvoiceView : 'form');
$requestedArticleEditId = isset($_GET['edit_article']) && is_string($_GET['edit_article']) ? $_GET['edit_article'] : '';
$invoiceEditingArticle = null;
foreach ($invoiceArticles as $invoiceArticleRecord) {
    if ($requestedArticleEditId !== '' && $invoiceArticleRecord['id'] === $requestedArticleEditId) {
        $invoiceEditingArticle = $invoiceArticleRecord;
        break;
    }
}
$invoiceContextParams = [];
if ($invoiceDraftId !== '') {
    $invoiceContextParams['draft_id'] = $invoiceDraftId;
}
if ($editId !== '') {
    $invoiceContextParams['edit_id'] = $editId;
}
if ($copyingInvoice && $copyId !== '') {
    $invoiceContextParams['copy_id'] = $copyId;
}
$invoiceContextQuery = http_build_query($invoiceContextParams, '', '&', PHP_QUERY_RFC3986);
$invoiceContextHtml = $invoiceContextQuery !== '' ? '&amp;' . h($invoiceContextQuery) : '';
$invoiceCurrentCustomerName = trim(app_legacy_text($editInvoice['customer'] ?? ''));
$invoiceCurrentReference = trim(app_legacy_text($editInvoice['ref'] ?? ''));
$invoiceCustomerSelectionId = '';
if (is_array($selectedInvoiceCustomer) && $selectedInvoiceCustomer['name'] === $invoiceCurrentCustomerName) {
    $invoiceCustomerSelectionId = $selectedInvoiceCustomer['id'];
} else {
    foreach ($invoiceCustomerOptions as $invoiceCustomerOption) {
        if ($invoiceCurrentCustomerName !== '' && $invoiceCustomerOption['name'] === $invoiceCurrentCustomerName) {
            $invoiceCustomerSelectionId = $invoiceCustomerOption['id'];
            break;
        }
    }
}
$diagnosticEntries = $isInvoiceAdmin && $invoiceView === 'debug' ? TadegaPrivacyLogger::readDiagnostics(30, 200) : null;
?>
<style>
    .invoice-page {
        --inv-ink: #173344;
        --inv-muted: #637885;
        --inv-line: #dce7eb;
        --inv-brand: #11718a;
        --inv-brand-dark: #0c5e73;
        --inv-soft: #eef7f8;
        --inv-surface: #fff;
        max-width: 1040px;
        margin: 0 auto;
        color: var(--inv-ink);
    }
    .invoice-page .btn { min-height:48px; padding:11px 15px; font-size:.92rem; line-height:1.4; }
    .invoice-page a:focus-visible, .invoice-page button:focus-visible, .invoice-page summary:focus-visible { outline:3px solid #0c5e73; outline-offset:3px; }
    .invoice-drafts { margin:16px 0; padding:16px; border:1px solid var(--inv-line); border-radius:14px; background:#f8fbfc; }
    .invoice-drafts-heading { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }
    .invoice-drafts-heading h2 { margin:0 0 4px; font-size:1rem; }
    .invoice-drafts-heading p { margin:0; color:var(--inv-muted); font-size:.86rem; line-height:1.45; }
    .invoice-draft-list { display:grid; gap:8px; margin:12px 0 0; padding:0; list-style:none; }
    .invoice-draft-item { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:11px; border:1px solid var(--inv-line); border-radius:10px; background:#fff; }
    .invoice-draft-item small { color:var(--inv-muted); }
    .invoice-draft-actions { display:flex; gap:8px; align-items:center; }
    .invoice-draft-actions form { margin:0; }
    .invoice-autosave-status { min-height:1.3em; margin:9px 0 0; color:var(--inv-muted); font-size:.84rem; }
    .invoice-topbar {
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 18px;
        margin-bottom: 12px;
    }
    .invoice-topbar-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    .invoice-subtabs {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
        width: fit-content;
        max-width: 100%;
        margin: 0 0 19px;
        padding: 5px;
        border: 1px solid var(--inv-line);
        border-radius: 13px;
        background: rgba(255, 255, 255, .82);
    }
    .invoice-subtab {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        min-height: 46px;
        padding: 9px 14px;
        border: 1px solid transparent;
        border-radius: 9px;
        color: var(--inv-muted);
        font-size: .92rem;
        font-weight: 760;
        text-decoration: none;
        white-space: nowrap;
    }
    .invoice-subtab:hover {
        border-color: #dce9ec;
        background: #f7fbfc;
        color: var(--inv-brand-dark);
    }
    .invoice-subtab[aria-current="page"] {
        border-color: #cce3e8;
        background: var(--inv-soft);
        color: var(--inv-brand-dark);
        box-shadow: inset 0 -2px 0 var(--inv-brand);
    }
    .invoice-subtab-icon {
        width: 16px;
        height: 16px;
        fill: none;
        stroke: currentColor;
        stroke-width: 1.8;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    .invoice-article-picker { margin:0 0 14px; padding:12px 14px; border:1px solid var(--inv-line); border-radius:12px; background:#f8fbfc; }
    .invoice-article-picker > summary { color:var(--inv-brand-dark); font-weight:780; cursor:pointer; }
    .invoice-article-picker-body { margin-top:12px; color:var(--inv-muted); font-size:.9rem; line-height:1.5; }
    .invoice-article-picker-controls { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:8px 12px; align-items:end; }
    .invoice-article-picker-controls label { grid-column:1 / -1; color:var(--inv-ink); font-weight:720; }
    .invoice-article-picker-controls select { width:100%; min-height:48px; padding:10px 12px; border:1px solid #cbd9df; border-radius:9px; background:#fff; color:var(--inv-ink); font:inherit; }
    #invoice-article-add-status { grid-column:1 / -1; min-height:1.2em; color:var(--inv-muted); font-size:.85rem; }
    .invoice-catalog-note { margin:12px 0 0; color:var(--inv-muted); font-size:.88rem; line-height:1.5; }
    .invoice-catalog-editor { margin:18px 0; padding:17px; border:1px solid var(--inv-line); border-radius:13px; background:#f8fbfc; }
    .invoice-catalog-editor h3 { margin:0 0 13px; font-size:1.02rem; }
    .invoice-catalog-fields { display:grid; grid-template-columns:minmax(0,2fr) minmax(150px,1fr) minmax(190px,1fr); gap:12px; }
    .invoice-catalog-form-actions { display:flex; flex-wrap:wrap; gap:9px; margin-top:13px; }
    .invoice-catalog-toolbar { display:grid; grid-template-columns:minmax(150px,1fr) minmax(200px,2fr) auto; gap:8px 12px; align-items:end; margin:19px 0 12px; }
    .invoice-catalog-toolbar label { color:var(--inv-ink); font-size:.9rem; font-weight:740; }
    .invoice-catalog-toolbar input { grid-column:2; width:100%; min-height:46px; padding:10px 12px; border:1px solid #cbd9df; border-radius:9px; background:#fff; color:var(--inv-ink); font:inherit; }
    .invoice-catalog-toolbar span { grid-column:3; padding-bottom:10px; color:var(--inv-muted); font-size:.85rem; white-space:nowrap; }
    .invoice-catalog-list { display:grid; gap:9px; margin:0; padding:0; list-style:none; }
    .invoice-catalog-card, .invoice-customer-card { min-width:0; padding:15px; border:1px solid var(--inv-line); border-radius:12px; background:#fff; }
    .invoice-catalog-card[hidden], .invoice-customer-card[hidden] { display:none !important; }
    .invoice-catalog-card { display:flex; justify-content:space-between; align-items:center; gap:14px; }
    .invoice-catalog-card-details h3, .invoice-customer-card-heading h3 { margin:0 0 4px; color:var(--inv-ink); font-size:1rem; overflow-wrap:anywhere; }
    .invoice-catalog-card-details strong { color:var(--inv-brand-dark); font-size:.94rem; }
    .invoice-catalog-card-actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
    .invoice-catalog-card-actions form { margin:0; }
    .invoice-customer-list { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:11px; }
    .invoice-customer-card-heading { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; margin-bottom:12px; }
    .invoice-customer-card-heading span { color:var(--inv-muted); font-size:.82rem; white-space:nowrap; }
    .invoice-customer-select-form { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:10px; align-items:end; }
    .invoice-customer-select-form .invoice-field { margin:0; }
    .invoice-customer-select-form select { width:100%; min-height:46px; padding:9px 11px; border:1px solid #cbd9df; border-radius:9px; background:#fff; color:var(--inv-ink); font:inherit; }
    .invoice-catalog-empty { margin-top:16px; padding:18px; border:1px dashed #b7cbd2; border-radius:12px; background:#f8fbfc; color:var(--inv-muted); }
    .invoice-catalog-empty strong { color:var(--inv-ink); }
    .invoice-catalog-empty p { margin:6px 0 12px; }
    .invoice-catalog-no-results { margin:12px 0; color:var(--inv-muted); font-size:.92rem; }
    .invoice-catalog-inline-warning { padding:10px 12px; border:1px solid #f2c5c2; border-radius:8px; background:#fff5f4; color:#9c2e27; }
    .invoice-record-search { display:grid; gap:6px; width:min(100%,520px); margin:14px 0 12px; }
    .invoice-record-search label { color:#435e69; font-size:.82rem; font-weight:780; }
    .invoice-record-search input { width:100%; min-height:46px; padding:10px 12px; border:1px solid #cbd9df; border-radius:9px; background:#fff; color:var(--inv-ink); font:inherit; font-size:.94rem; }
    .invoice-record-search input:focus { border-color:#78b3bf; outline:0; box-shadow:0 0 0 3px rgba(17,113,138,.12); }
    .invoice-record-search-count { min-height:1.2em; margin:0; color:var(--inv-muted); font-size:.78rem; }
    .invoice-record-search-empty { margin:12px 0; padding:13px 15px; border:1px dashed #b7cbd2; border-radius:10px; background:#f8fbfc; color:var(--inv-muted); font-size:.88rem; }
    .invoice-record-search-empty[hidden] { display:none; }
    .invoice-record-table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; overscroll-behavior-x:contain; margin-top:16px; border:1px solid var(--inv-line); border-radius:12px; background:#fff; }
    .invoice-record-table-wrap:focus-visible { outline:3px solid #0c5e73; outline-offset:3px; }
    .invoice-record-table { width:100%; min-width:1100px; border-collapse:collapse; table-layout:auto; }
    .invoice-record-table th { padding:11px 10px; background:#f3f8f9; color:#435e69; font-size:.73rem; font-weight:820; letter-spacing:.035em; text-align:left; text-transform:uppercase; }
    .invoice-record-table td { padding:10px; border-top:1px solid #e9eff1; color:var(--inv-ink); font-size:.87rem; vertical-align:middle; }
    .invoice-record-table tbody tr:first-child td { border-top:0; }
    .invoice-record-primary { font-weight:780; overflow-wrap:anywhere; }
    .invoice-record-muted { display:block; margin-top:3px; color:var(--inv-muted); font-size:.8rem; }
    .invoice-record-total { font-weight:790; white-space:nowrap; }
    .invoice-record-email { min-width:260px; }
    .invoice-record-email form { margin-top:7px; }
    .invoice-record-email .btn { min-height:38px; padding:6px 9px; white-space:nowrap; }
    .invoice-record-form { display:grid; grid-template-columns:minmax(135px,1fr) minmax(150px,1fr) auto; min-width:520px; gap:7px; align-items:center; margin:0; }
    .invoice-record-form input { width:100%; min-width:0; min-height:43px; padding:8px 10px; border:1px solid #cbd9df; border-radius:8px; background:#fff; color:var(--inv-ink); font:inherit; }
    .invoice-record-form .btn { min-height:43px; padding:8px 10px; white-space:nowrap; }
    .invoice-record-actions { display:flex; flex-wrap:wrap; gap:6px; white-space:nowrap; }
    .invoice-external-icon {
        width: 16px;
        height: 16px;
        fill: none;
        stroke: currentColor;
        stroke-width: 1.8;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    .invoice-external-note {
        margin: 0 0 18px;
        color: var(--inv-muted);
        font-size: .88rem;
        line-height: 1.5;
    }
    .invoice-sheet {
        overflow: hidden;
        border: 1px solid var(--inv-line);
        border-radius: 18px;
        background: var(--inv-surface);
        box-shadow: 0 14px 38px rgba(16, 57, 73, .075);
    }
    .invoice-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 20px;
        padding: 24px clamp(18px, 3.5vw, 34px);
        border-bottom: 1px solid var(--inv-line);
        background: linear-gradient(120deg, #f2f9fa 0%, #fff 75%);
    }
    .invoice-brand {
        display: flex;
        align-items: center;
        gap: 14px;
        min-width: 0;
    }
    .invoice-logo {
        display: block;
        flex: 0 0 auto;
        width: 48px;
        height: 48px;
        border-radius: 13px;
        object-fit: contain;
        box-shadow: 0 3px 10px rgba(16, 57, 73, .12);
    }
    .invoice-print-logo { display: none; }
    .invoice-title {
        margin: 0;
        font-size: clamp(1.35rem, 3vw, 1.85rem);
        line-height: 1.12;
        letter-spacing: -.045em;
    }
    .invoice-overline {
        margin: 5px 0 0;
        color: var(--inv-muted);
        font-size: .72rem;
        font-weight: 800;
        letter-spacing: .11em;
        text-transform: uppercase;
    }
    .invoice-meta {
        color: var(--inv-muted);
        font-size: .9rem;
        line-height: 1.45;
        text-align: right;
        white-space: nowrap;
    }
    .invoice-meta strong {
        display: block;
        margin-top: 3px;
        color: var(--inv-ink);
        font-size: 1.05rem;
    }
    .invoice-content {
        padding: clamp(18px, 3.5vw, 34px);
    }
    .invoice-disclaimer {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        margin: 0 0 24px;
        padding: 15px 17px;
        border: 1px solid #cce4e8;
        border-radius: 12px;
        background: #f2fafb;
        color: #355c68;
        font-size: .98rem;
        line-height: 1.55;
    }
    .invoice-disclaimer strong { color: #174d5d; }
    .invoice-disclaimer-icon {
        display: grid;
        flex: 0 0 22px;
        width: 22px;
        height: 22px;
        place-items: center;
        border-radius: 50%;
        background: #dceff2;
        color: #17667a;
        font-size: .8rem;
        font-weight: 900;
    }
    .invoice-section { margin-top: 26px; }
    .invoice-section:first-of-type { margin-top: 0; }
    .invoice-section-heading {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 12px;
        margin-bottom: 12px;
    }
    .invoice-section-heading h3 {
        margin: 0;
        font-size: 1.1rem;
        letter-spacing: -.02em;
    }
    .invoice-section-heading p {
        margin: 5px 0 0;
        color: var(--inv-muted);
        font-size: .9rem;
        line-height: 1.5;
    }
    .invoice-form-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 15px 16px;
    }
    .invoice-field { min-width: 0; }
    .invoice-field--wide { grid-column: 1 / -1; }
    .invoice-field label {
        display: block;
        margin: 0 0 8px;
        color: #334f5b;
        font-size: .94rem;
        font-weight: 760;
        line-height: 1.4;
    }
    .invoice-field input,
    .invoice-field textarea,
    .invoice-field select,
    .invoice-register select,
    .invoice-smtp-settings .invoice-field select {
        display: block;
        width: 100%;
        min-height: 52px;
        padding: 12px 14px;
        border: 1px solid #cfdee3;
        border-radius: 10px;
        background: #fff;
        color: var(--inv-ink);
        font: inherit;
        font-size: 1rem;
        line-height: 1.4;
        transition: border-color .15s, box-shadow .15s;
    }
    .invoice-field input:focus,
    .invoice-field textarea:focus,
    .invoice-field select:focus,
    .invoice-register select:focus,
    .invoice-smtp-settings .invoice-field select:focus {
        border-color: #78b3bf;
        outline: 0;
        box-shadow: 0 0 0 3px rgba(17, 113, 138, .13);
    }
    .invoice-field textarea {
        min-height: 120px;
        resize: vertical;
    }
    .invoice-help {
        margin: 7px 0 0;
        color: #586f7b;
        font-size: .86rem;
        line-height: 1.5;
    }
    .invoice-help-inline { color: var(--inv-muted); font-weight: 550; }
    .invoice-readonly-value {
        display: flex;
        min-height: 52px;
        align-items: center;
        padding: 12px 14px;
        border: 1px dashed #c7d8dd;
        border-radius: 10px;
        background: #f5fafb;
        color: var(--inv-ink);
        font-size: 1rem;
    }
    .invoice-readonly-value strong { font-weight: 800; }
    .invoice-admin-panel {
        margin-top: 0;
        padding: clamp(17px, 3vw, 26px);
        border: 1px solid #cfe0e5;
        border-radius: 18px;
        background: linear-gradient(145deg, #f5fafb, #fff 75%);
        box-shadow: 0 12px 30px rgba(16, 57, 73, .065);
    }
    .invoice-admin-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 16px;
    }
    .invoice-admin-eyebrow {
        margin: 0 0 6px;
        color: var(--inv-brand);
        font-size: .8rem;
        font-weight: 850;
        letter-spacing: .12em;
    }
    .invoice-admin-header h2 {
        margin: 0;
        color: var(--inv-ink);
        font-size: clamp(1.2rem, 3vw, 1.5rem);
        letter-spacing: -.035em;
    }
    .invoice-admin-header > div > p:last-child {
        max-width: 720px;
        margin: 8px 0 0;
        color: var(--inv-muted);
        font-size: .95rem;
        line-height: 1.55;
    }
    .invoice-admin-badge {
        flex: 0 0 auto;
        padding: 7px 11px;
        border: 1px solid #b6dbe3;
        border-radius: 999px;
        background: #e8f6f8;
        color: #145a6a;
        font-size: .82rem;
        font-weight: 850;
    }
    .invoice-admin-create,
    .invoice-admin-users {
        margin-top: 20px;
        padding: 16px;
        border: 1px solid #e0eaed;
        border-radius: 14px;
        background: #fff;
    }
    .invoice-email-status-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; margin-top:20px; }
    .invoice-email-status-card { min-width:0; padding:17px; border:1px solid #e0eaed; border-radius:14px; background:#fff; }
    .invoice-email-status-card h3 { margin:0; font-size:1.08rem; letter-spacing:-.02em; }
    .invoice-email-status-card > p { margin:7px 0; color:var(--inv-muted); font-size:.88rem; line-height:1.45; overflow-wrap:anywhere; }
    .invoice-email-status-card dl { display:grid; grid-template-columns:1fr auto; gap:6px 12px; margin:14px 0; padding:12px; border-radius:10px; background:#f6fafb; font-size:.84rem; }
    .invoice-email-status-card dt { color:var(--inv-muted); }
    .invoice-email-status-card dd { margin:0; color:var(--inv-ink); font-weight:800; text-align:right; }
    .invoice-email-events { margin:10px 0 14px; padding-left:20px; color:var(--inv-muted); font-size:.8rem; }
    .invoice-email-events li { margin:7px 0; overflow-wrap:anywhere; }
    .invoice-email-test-form { margin-top:12px; }
    .invoice-email-test-form .btn { width:100%; }
    .invoice-email-warning { margin-top:14px; }
    .invoice-email-transport { margin-top:16px; padding:13px 15px; border:1px solid #b9d9df; border-radius:11px; background:#eff8f9; color:#294b56; font-size:.9rem; line-height:1.5; }
    .invoice-email-transport.is-warning { border-color:#e6c46c; background:#fff8e8; color:#59400d; }
    .invoice-email-transport strong { color:var(--inv-ink); }
    .invoice-smtp-settings { margin-top:16px; padding:17px; border:1px solid #d5e3e7; border-radius:14px; background:#fff; }
    .invoice-smtp-settings h3 { margin:0; font-size:1.08rem; letter-spacing:-.02em; }
    .invoice-smtp-settings > p { margin:7px 0; color:var(--inv-muted); font-size:.88rem; line-height:1.5; }
    .invoice-smtp-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px 16px; margin-top:15px; }
    .invoice-smtp-actions { display:flex; flex-wrap:wrap; gap:10px; margin-top:14px; }
    .invoice-smtp-note { margin-top:13px!important; padding:11px 13px; border-radius:10px; background:#f3f8f9; color:#39535f!important; }
    .invoice-smtp-note.is-warning { border:1px solid #e6c46c; background:#fff8e8; color:#59400d!important; }
    .invoice-smtp-lock-panel { margin-top:14px; padding:13px 14px; border:1px solid #b9d9df; border-radius:11px; background:#eff8f9; }
    .invoice-smtp-lock-panel p { margin:5px 0 10px; color:var(--inv-muted); font-size:.88rem; }
    .invoice-smtp-lock-panel .invoice-smtp-pin { max-width:230px; }
    .invoice-debug-list { display:grid; gap:12px; margin-top:18px; }
    .invoice-debug-card { min-width:0; padding:15px; border:1px solid #e0eaed; border-radius:14px; background:#fff; }
    .invoice-debug-card h3 { margin:0; font-size:1rem; }
    .invoice-debug-card-top { display:flex; flex-wrap:wrap; justify-content:space-between; align-items:flex-start; gap:8px 16px; }
    .invoice-debug-card time { color:var(--inv-muted); font-size:.84rem; }
    .invoice-debug-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:9px 16px; margin:13px 0 0; }
    .invoice-debug-grid div { min-width:0; }
    .invoice-debug-grid dt { color:var(--inv-muted); font-size:.76rem; font-weight:750; }
    .invoice-debug-grid dd { margin:3px 0 0; color:var(--inv-ink); font-size:.88rem; overflow-wrap:anywhere; }
    .invoice-debug-mono { font-family:ui-monospace,SFMono-Regular,Consolas,monospace; font-size:.82rem!important; }
    .invoice-debug-empty { margin:16px 0 0; padding:16px; border:1px dashed #c7d8dd; border-radius:12px; background:#fff; color:var(--inv-muted); }
    .invoice-debug-actions { display:flex; flex-wrap:wrap; align-items:center; gap:8px 12px; margin-top:12px; }
    .invoice-debug-copy-status { color:var(--inv-muted); font-size:.84rem; }
    .invoice-debug-guidance { margin-top:12px; padding:11px 13px; border:1px solid #d5e5e9; border-radius:11px; background:#f5fafb; color:#344957; }
    .invoice-debug-guidance summary { min-height:32px; cursor:pointer; color:#145f73; font-size:.88rem; font-weight:750; }
    .invoice-debug-guidance > div { margin-top:8px; font-size:.84rem; line-height:1.5; }
    .invoice-debug-guidance p { margin:2px 0 0; color:#506573; }
    @media (max-width:540px) {
        .invoice-smtp-grid, .invoice-debug-grid { grid-template-columns:1fr; }
        .invoice-smtp-actions { flex-direction:column; }
        .invoice-smtp-actions .btn { width:100%; }
        .invoice-smtp-settings { padding:14px; }
        .invoice-debug-card { padding:13px; }
    }
    .invoice-admin-subheading h3 {
        margin: 0;
        font-size: 1.08rem;
        letter-spacing: -.02em;
    }
    .invoice-admin-subheading p {
        margin: 5px 0 0;
        color: var(--inv-muted);
        font-size: .9rem;
        line-height: 1.5;
    }
    .invoice-admin-create-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
        align-items: end;
        margin-top: 14px;
    }
    .invoice-admin-create-grid .invoice-field select,
    .invoice-reset-form input {
        display: block;
        width: 100%;
        min-height: 52px;
        padding: 12px 14px;
        border: 1px solid #cfdee3;
        border-radius: 10px;
        background: #fff;
        color: var(--inv-ink);
        font: inherit;
        font-size: 1rem;
    }
    .invoice-admin-create-grid .invoice-field select:focus,
    .invoice-reset-form input:focus {
        border-color: #78b3bf;
        outline: 0;
        box-shadow: 0 0 0 3px rgba(17, 113, 138, .13);
    }
    .invoice-admin-create-submit { grid-column: 1 / -1; }
    .invoice-admin-create-submit .btn { min-height: 50px; font-size: .95rem; }
    .invoice-admin-empty {
        margin: 14px 0 0;
        padding: 15px;
        border: 1px dashed #cbdde2;
        border-radius: 11px;
        color: var(--inv-muted);
        font-size: .93rem;
        line-height: 1.5;
    }
    .invoice-user-list { display: grid; gap: 9px; margin-top: 14px; }
    .invoice-user-row {
        display: grid;
        grid-template-columns: minmax(0, .9fr) minmax(0, 1.1fr);
        align-items: center;
        gap: 14px;
        padding: 13px;
        border: 1px solid #e3ecee;
        border-radius: 12px;
        background: #fbfdfd;
    }
    .invoice-user-meta { display: grid; min-width: 0; gap: 3px; }
    .invoice-user-meta > strong { overflow-wrap: anywhere; font-size: .98rem; }
    .invoice-user-username { color: var(--inv-muted); font-size: .86rem; overflow-wrap: anywhere; }
    .invoice-user-badges { display: flex; flex-wrap: wrap; gap: 7px; margin-top: 5px; }
    .invoice-role-chip,
    .invoice-status-chip {
        padding: 5px 9px;
        border: 1px solid #dce7ea;
        border-radius: 999px;
        background: #f3f7f8;
        color: #526b76;
        font-size: .78rem;
        font-weight: 760;
    }
    .invoice-role-chip { border-color: #c4dfe5; background: #edf8fa; color: #205b6a; }
    .invoice-status-chip.is-active { border-color: #c7e5d6; background: #edf8f1; color: #286245; }
    .invoice-reset-form {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        gap: 8px;
    }
    .invoice-reset-form input { min-width: 0; min-height: 50px; font-size: .95rem; }
    .invoice-reset-form .btn { min-height: 48px; white-space: nowrap; }
    .invoice-user-inactive-note { margin: 0; color: #8a6a2f; font-size: .88rem; line-height: 1.45; }
    .invoice-admin-footnote { margin: 15px 0 0; color: var(--inv-muted); font-size: .84rem; line-height: 1.5; }
    .invoice-register {
        margin-top: 14px;
        border: 1px solid var(--inv-line);
        border-radius: 12px;
        background: #fbfdfd;
    }
    .invoice-register summary {
        min-height: 50px;
        padding: 13px 15px;
        color: #315968;
        cursor: pointer;
        font-size: .93rem;
        font-weight: 760;
        line-height: 1.45;
    }
    .invoice-register summary:hover { color: var(--inv-brand-dark); }
    .invoice-register-body {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
        padding: 0 14px 14px;
    }
    .invoice-register-help { margin:0; padding:0 14px 14px; }
    .invoice-register-empty { padding:14px; }
    .invoice-register-empty p { margin:0; color:var(--inv-muted); }
    .invoice-register-field label {
        display: block;
        margin: 0 0 8px;
        color: #334f5b;
        font-size: .94rem;
        font-weight: 760;
    }
    .invoice-register-action {
        display: flex;
        gap: 8px;
        margin-top: 7px;
    }
    .invoice-register-action .btn { min-height: 48px; padding: 10px 14px; font-size: .92rem; }
    .invoice-lines-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 16px;
        margin-bottom: 12px;
    }
    .invoice-lines-header h3 { margin: 0; font-size: 1.1rem; letter-spacing: -.02em; }
    .invoice-lines-header p { margin: 5px 0 0; color: var(--inv-muted); font-size: .9rem; line-height: 1.5; }
    .invoice-vat-control {
        display: inline-flex;
        flex: 0 0 auto;
        padding: 7px 11px;
        border: 1px solid #d6e4e8;
        border-radius: 11px;
        background: #f5f9fa;
    }
    .invoice-vat-control label {
        display: flex;
        align-items: center;
        gap: 10px;
        min-height: 48px;
        color: #244955;
        cursor: pointer;
    }
    .invoice-vat-control input[type="checkbox"] {
        width: 22px;
        height: 22px;
        margin: 0;
        accent-color: var(--inv-brand-dark);
        flex: 0 0 auto;
    }
    .invoice-vat-control strong { display:block; font-size:.9rem; line-height:1.25; }
    .invoice-vat-control small { display:block; margin-top:3px; color:var(--inv-muted); font-size:.76rem; line-height:1.25; }
    .invoice-table-wrap {
        overflow-x: auto;
        border: 1px solid var(--inv-line);
        border-radius: 12px;
    }
    .invoice-table {
        width: 100%;
        min-width: 660px;
        border-collapse: collapse;
        table-layout: fixed;
    }
    .invoice-table th {
        padding: 12px 10px;
        background: #f3f8f9;
        color: #435e69;
        font-size: .76rem;
        font-weight: 820;
        letter-spacing: .035em;
        text-align: left;
        text-transform: uppercase;
    }
    .invoice-table td {
        padding: 8px;
        border-top: 1px solid #e9eff1;
        vertical-align: middle;
    }
    .invoice-table tbody tr:first-child td { border-top: 0; }
    .invoice-table input, .invoice-table select {
        display: block;
        width: 100%;
        min-height: 52px;
        padding: 10px;
        border: 1px solid #dbe5e8;
        border-radius: 8px;
        background: #fff;
        color: var(--inv-ink);
        font: inherit;
        font-size: 1rem;
    }
    .invoice-table input:focus, .invoice-table select:focus {
        border-color: #78b3bf;
        outline: 0;
        box-shadow: 0 0 0 3px rgba(17, 113, 138, .12);
    }
    .invoice-table .invoice-line-total {
        color: var(--inv-ink);
        font-size: .95rem;
        font-weight: 790;
        text-align: right;
        white-space: nowrap;
    }
    .invoice-table .invoice-line-total-cell { text-align: right; white-space: nowrap; }
    .invoice-remove-cell { width: 1%; text-align: right; }
    .invoice-remove {
        min-height: 44px;
        padding: 8px 11px;
        border: 1px solid #d9e4e7;
        border-radius: 8px;
        background: #fff;
        color: #536c77;
        cursor: pointer;
        font: inherit;
        font-size: .86rem;
        font-weight: 700;
    }
    .invoice-remove:hover { border-color: #efc6c3; background: #fff7f6; color: #a5332b; }
    .invoice-line-controls {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        margin-top: 10px;
    }
    .invoice-limit-note { color: var(--inv-muted); font-size: .88rem; line-height: 1.45; }
    .invoice-table-wrap:focus-visible { outline:3px solid #0c5e73; outline-offset:3px; }
    .invoice-catalog-save-box { display:grid; grid-template-columns:minmax(0,1fr) auto; align-items:end; gap:8px 12px; margin-top:13px; padding:13px; border:1px solid #cfe1d9; border-radius:12px; background:#f7fbf8; }
    .invoice-catalog-save-box label { display:block; margin:0 0 5px; color:#31554f; font-size:.84rem; font-weight:800; }
    .invoice-catalog-save-box select { width:100%; min-height:48px; padding:9px 12px; border:1px solid #cbd9d5; border-radius:9px; background:#fff; color:var(--inv-ink); font:inherit; }
    .invoice-catalog-save-box > .btn { min-height:48px; white-space:nowrap; }
    .invoice-catalog-save-box > p { grid-column:1/-1; min-height:1.2em; margin:0; color:var(--inv-muted); font-size:.83rem; line-height:1.45; }
    .invoice-form-error {
        margin: 16px 0 0;
        padding: 14px 16px;
        border: 1px solid #f2c5c2;
        border-radius: 10px;
        background: #fff5f4;
        color: #9c2e27;
        font-size: .96rem;
        line-height: 1.5;
    }
    .invoice-actions {
        display: flex;
        justify-content: flex-end;
        flex-wrap: wrap;
        gap: 9px;
        margin-top: 22px;
        padding-top: 18px;
        border-top: 1px solid var(--inv-line);
    }
    .invoice-note {
        margin: 14px 0 0;
        color: #586f7b;
        font-size: .86rem;
        line-height: 1.5;
        text-align: right;
    }
    .invoice-sr-only {
        position: absolute !important;
        width: 1px !important;
        height: 1px !important;
        padding: 0 !important;
        margin: -1px !important;
        overflow: hidden !important;
        clip: rect(0, 0, 0, 0) !important;
        white-space: nowrap !important;
        border: 0 !important;
    }
    .invoice-mobile-review, .invoice-step-navigation { display:none; }
    .invoice-review-details { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; margin:0; }
    .invoice-review-details > div { min-width:0; padding:12px; border:1px solid var(--inv-line); border-radius:10px; background:#f8fbfc; }
    .invoice-review-details dt { margin:0 0 5px; color:var(--inv-muted); font-size:.84rem; font-weight:760; line-height:1.35; }
    .invoice-review-details dd { margin:0; color:var(--inv-ink); font-size:.96rem; line-height:1.45; overflow-wrap:anywhere; }
    .invoice-review-details .invoice-review-wide { grid-column:1 / -1; }
    .invoice-review-lines { margin-top:15px; padding:14px; border:1px solid var(--inv-line); border-radius:12px; background:#fff; }
    .invoice-review-lines h4 { margin:0 0 9px; color:var(--inv-ink); font-size:1rem; }
    .invoice-review-lines ul { display:grid; gap:8px; margin:0; padding:0; list-style:none; }
    .invoice-review-item { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:4px 12px; padding:10px; border:1px solid #e3ecee; border-radius:9px; background:#fbfdfd; }
    .invoice-review-item-name { color:var(--inv-ink); font-size:.94rem; font-weight:760; overflow-wrap:anywhere; }
    .invoice-review-item-calculation { color:var(--inv-muted); font-size:.86rem; line-height:1.4; }
    .invoice-review-item-total { grid-column:2; grid-row:1 / span 2; align-self:center; color:var(--inv-ink); font-size:.95rem; font-weight:800; white-space:nowrap; }
    .invoice-review-note { margin:12px 0 0; color:var(--inv-muted); font-size:.9rem; line-height:1.5; }
    .invoice-step-navigation { margin-top:16px; padding:14px; border:1px solid var(--inv-line); border-radius:12px; background:#f8fbfc; }
    .invoice-step-progress p { margin:0 0 9px; color:var(--inv-ink); font-size:.94rem; font-weight:760; }
    .invoice-step-progress-track { height:9px; overflow:hidden; border-radius:99px; background:#dce8eb; }
    .invoice-step-progress-track span { display:block; width:33.333%; height:100%; border-radius:inherit; background:var(--inv-brand); }
    .invoice-step-buttons { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:13px; }
    .invoice-step-buttons .btn { width:100%; min-height:52px; justify-content:center; }
    @media (max-width: 720px) {
        .invoice-email-status-grid { grid-template-columns:1fr; }
        .invoice-catalog-fields, .invoice-customer-select-form { grid-template-columns:1fr; }
        .invoice-catalog-toolbar { grid-template-columns:1fr; }
        .invoice-catalog-toolbar label, .invoice-catalog-toolbar input, .invoice-catalog-toolbar span { grid-column:1; }
        .invoice-catalog-toolbar span { padding:0; }
        .invoice-catalog-card { align-items:stretch; flex-direction:column; }
        .invoice-catalog-card-actions { display:grid; grid-template-columns:1fr 1fr; }
        .invoice-catalog-card-actions .btn { width:100%; justify-content:center; }
        .invoice-customer-list { grid-template-columns:1fr; }
        .invoice-customer-select-form .btn { width:100%; justify-content:center; }
        .invoice-catalog-save-box { grid-template-columns:1fr; }
        .invoice-catalog-save-box > .btn { width:100%; white-space:normal; }
        .invoice-article-picker-controls { grid-template-columns:1fr; }
        .invoice-article-picker-controls label, #invoice-article-add-status { grid-column:1; }
        .invoice-article-picker-controls .btn { width:100%; justify-content:center; }
        .invoice-draft-item { align-items:stretch; flex-direction:column; }
        .invoice-draft-actions { display:grid; grid-template-columns:1fr 1fr; }
        .invoice-draft-actions .btn { width:100%; justify-content:center; }
        .invoice-topbar { align-items: stretch; flex-direction: column; gap: 11px; }
        .invoice-topbar-actions { display: grid; grid-template-columns: 1fr; width: 100%; }
        .invoice-topbar-actions .btn { width: 100%; justify-content: center; }
        .invoice-subtabs { width: 100%; }
        .invoice-subtab { flex: 1 1 150px; justify-content: center; white-space: normal; text-align: center; }
        .invoice-header { align-items: flex-start; }
        .invoice-meta { white-space: normal; }
        .invoice-form-grid, .invoice-register-body { grid-template-columns: 1fr; }
        .invoice-admin-create-grid { grid-template-columns: 1fr; }
        .invoice-admin-create-submit { grid-column: auto; }
        .invoice-user-row { grid-template-columns: 1fr; }
        .invoice-reset-form { grid-template-columns: 1fr; }
        .invoice-reset-form .btn { width: 100%; }
        .invoice-admin-header { align-items: flex-start; flex-direction: column; }
        .invoice-lines-header { align-items: stretch; flex-direction: column; }
        .invoice-page.invoice-wizard-ready [data-invoice-step] { display:none; }
        .invoice-page.invoice-wizard-ready [data-invoice-step].is-active { display:block; }
        .invoice-page.invoice-wizard-ready .invoice-step-navigation { display:grid; gap:12px; }
        .invoice-page.invoice-wizard-ready .invoice-step-buttons { grid-template-columns:1fr 1fr; }
        .invoice-page.invoice-wizard-ready[data-current-step="1"] .invoice-step-buttons { grid-template-columns:1fr; }
        .invoice-page.invoice-wizard-ready[data-current-step="3"] #invoice-step-back { grid-column:1 / -1; }
        .invoice-page.invoice-wizard-ready .invoice-review-details { grid-template-columns:1fr; }
        .invoice-page.invoice-wizard-ready .invoice-review-details .invoice-review-wide { grid-column:1; }
        .invoice-table-wrap { overflow: visible; border: 0; }
        .invoice-table { display: block; min-width: 0; }
        .invoice-table thead { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
        .invoice-table tbody { display: grid; gap: 10px; }
        .invoice-table tbody tr {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px 12px;
            padding: 15px;
            border: 1px solid var(--inv-line);
            border-radius: 12px;
            background: #fff;
        }
        .invoice-table td { display: block; min-width: 0; padding: 0; border: 0; }
        .invoice-table td::before {
            display: block;
            margin: 0 0 7px;
            color: #435e69;
            content: attr(data-label);
            font-size: .9rem;
            font-weight: 780;
            line-height: 1.4;
        }
        .invoice-table td[data-label="Beskrivning"],
        .invoice-table td[data-label="Pris per enhet"] { grid-column: 1 / -1; }
        .invoice-table td[data-label="Radbelopp"] { grid-column: 1; align-self: center; padding: 9px 0; text-align: left; }
        .invoice-table td[data-label="Ta bort"] { grid-column: 2; align-self: center; text-align: right; }
        .invoice-remove { width: 100%; }
        .invoice-line-controls { align-items: flex-start; flex-direction: column; }
        .invoice-line-controls .btn { width: 100%; }
        .invoice-limit-note { min-height: 1em; }
        .invoice-actions { flex-direction: column-reverse; }
        .invoice-actions .btn { width: 100%; }
        .invoice-note { text-align: left; }
        .invoice-page.invoice-wizard-ready { padding-bottom: calc(104px + env(safe-area-inset-bottom)); }
        .invoice-page.invoice-wizard-ready .invoice-step-navigation {
            position: fixed;
            left: max(12px, env(safe-area-inset-left));
            right: max(12px, env(safe-area-inset-right));
            bottom: calc(6px + env(safe-area-inset-bottom));
            z-index: 30;
            width: auto;
            max-width: 1000px;
            margin: 0 auto;
            padding: 8px;
            border: 1px solid var(--inv-line);
            border-radius: 12px;
            background: rgba(255,255,255,.97);
            box-shadow: 0 -4px 18px rgba(16,40,58,.12);
            backdrop-filter: blur(10px);
            gap: 6px;
        }
        .invoice-page.invoice-wizard-ready .invoice-step-progress { display:grid; grid-template-columns:minmax(0,1fr) minmax(70px,110px); align-items:center; gap:8px; }
        .invoice-page.invoice-wizard-ready .invoice-step-progress p { margin:0; font-size:.78rem; line-height:1.15; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .invoice-page.invoice-wizard-ready .invoice-step-progress-track { height: 5px; }
        .invoice-page.invoice-wizard-ready .invoice-step-buttons { gap: 6px; margin-top: 0; }
        .invoice-page.invoice-wizard-ready .invoice-step-buttons .btn { min-height: 48px!important; padding: 7px 9px; font-size: .78rem; line-height: 1.12; }
        .invoice-page.invoice-wizard-ready[data-current-step="3"] .invoice-step-buttons { grid-template-columns: 1fr 1fr; }
        .invoice-page.invoice-wizard-ready[data-current-step="3"] #invoice-step-back { grid-column: auto; }
        .invoice-page.invoice-wizard-ready .invoice-actions > button[type="submit"] { display: none !important; }
    }
    @media (max-width: 440px) {
        .invoice-header { flex-direction: column; gap: 10px; }
        .invoice-meta { text-align: left; }
        .invoice-disclaimer { align-items: flex-start; font-size: .94rem; }
        .invoice-vat-control { width: 100%; }
        .invoice-vat-control label { width: 100%; }
    }
    @media (max-width:900px) and (max-height:560px) and (orientation:landscape) {
        .invoice-page.invoice-wizard-ready { padding-bottom:calc(104px + env(safe-area-inset-bottom)); }
        .invoice-page.invoice-wizard-ready [data-invoice-step] { display:none; }
        .invoice-page.invoice-wizard-ready [data-invoice-step].is-active { display:block; }
        .invoice-page.invoice-wizard-ready .invoice-step-navigation {
            position:fixed;
            left:max(12px,env(safe-area-inset-left));
            right:max(12px,env(safe-area-inset-right));
            bottom:calc(6px + env(safe-area-inset-bottom));
            z-index:30;
            display:grid;
            gap:6px;
            width:auto;
            max-width:1000px;
            margin:0 auto;
            padding:8px;
            border:1px solid var(--inv-line);
            border-radius:12px;
            background:rgba(255,255,255,.97);
            box-shadow:0 -4px 18px rgba(16,40,58,.12);
            backdrop-filter:blur(10px);
        }
        .invoice-page.invoice-wizard-ready .invoice-step-progress { display:grid; grid-template-columns:minmax(0,1fr) minmax(70px,110px); align-items:center; gap:8px; }
        .invoice-page.invoice-wizard-ready .invoice-step-progress p { margin:0; font-size:.78rem; line-height:1.15; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .invoice-page.invoice-wizard-ready .invoice-step-progress-track { height:5px; }
        .invoice-page.invoice-wizard-ready .invoice-step-buttons { grid-template-columns:1fr 1fr; gap:6px; margin-top:0; }
        .invoice-page.invoice-wizard-ready[data-current-step="1"] .invoice-step-buttons { grid-template-columns:1fr; }
        .invoice-page.invoice-wizard-ready .invoice-step-buttons .btn { min-height:48px!important; padding:7px 9px; font-size:.78rem; line-height:1.12; }
        .invoice-page.invoice-wizard-ready .invoice-actions > button[type="submit"] { display:none!important; }
    }
    @media print {
        @page { size A4 portrait; margin: 12mm; }
        body { background: #fff !important; color: #111 !important; }
        .page-shell { max-width: none !important; padding: 0 !important; }
        .page-heading, .app-header, .tabs-wrap, .app-footer,
        .invoice-subtabs, .invoice-topbar, .invoice-drafts, .invoice-register, .invoice-admin-panel, .invoice-vat-control, .invoice-article-picker,
        .invoice-line-controls, .invoice-remove-cell, .invoice-actions,
        .invoice-note, .invoice-autosave-status, .invoice-form-error, .invoice-step-navigation { display: none !important; }
        .invoice-page.invoice-wizard-ready [data-invoice-step] { display: block !important; }
        .invoice-page.invoice-wizard-ready .invoice-mobile-review { display: none !important; }
        .invoice-page { max-width: none; color: #111; }
        .invoice-sheet { overflow: visible; border: 0; border-radius: 0; box-shadow: none; }
        .invoice-header { padding: 0 0 12px; border-bottom: 2px solid #173344; background: #fff; }
        .invoice-logo { display: none !important; }
        .invoice-print-logo { display: block; flex: 0 1 auto; width: 80mm; max-width: 100%; height: auto; object-fit: contain; }
        .invoice-content { padding: 14px 0 0; }
        .invoice-disclaimer { margin-bottom: 16px; background: #f5f8f9 !important; }
        .invoice-section { margin-top: 18px; }
        .invoice-field input, .invoice-field textarea, .invoice-table input, .invoice-table select {
            min-height: auto;
            padding: 2px 0;
            border: 0;
            border-radius: 0;
            background: transparent !important;
            box-shadow: none !important;
            color: #111;
            font-size: 10pt;
        }
        .invoice-field textarea { min-height: 38px; }
        .invoice-table-wrap { overflow: visible; border: 1px solid #ccd6da; }
        .invoice-table { min-width: 0; }
        .invoice-table th { background: #eef2f3 !important; color: #263b43; }
        .invoice-table td { padding: 5px 7px; }
        .invoice-table tr, .invoice-disclaimer { break-inside: avoid; }
    }
    /* Refined shared Tadega finish for the invoice PWA. */
    .invoice-page {
        --inv-ink:var(--ink); --inv-muted:var(--muted); --inv-line:var(--line);
        --inv-brand:var(--brand); --inv-brand-dark:var(--brand-dark); --inv-soft:var(--brand-soft);
    }
    .invoice-sheet { border-color:#dce7e4; border-radius:21px; box-shadow:0 14px 36px rgba(18,54,58,.07); }
    .invoice-header { background:linear-gradient(120deg,#edf7f2 0%,#fff 74%); }
    .invoice-logo { border:1px solid #d7e8e3; }
    .invoice-subtabs { border-color:#d6e4e0; background:rgba(255,255,255,.92); box-shadow:0 5px 16px rgba(18,54,58,.04); }
    .invoice-subtab[aria-current="page"] { border-color:#c6e2d9; background:#e8f5f0; color:#075c59; box-shadow:inset 0 -3px 0 #0b7a72; }
    .invoice-section-heading h3 { color:#193a40; }
    .invoice-disclaimer { border-color:#cce5dc; background:#f0f8f4; color:#325c54; }
    .invoice-disclaimer strong { color:#155e52; }
    .invoice-disclaimer-icon { background:#d9eee5; color:#0b6b62; }
    .invoice-step-navigation { border-color:#cfe1dc; background:rgba(250,253,251,.98); box-shadow:0 -6px 22px rgba(18,54,58,.12); }
    .invoice-step-progress-track { background:#dcebe5; }
    @media (max-width:720px) {
        .invoice-page { max-width:100%; }
        .invoice-subtabs { flex-wrap:nowrap; overflow-x:auto; scrollbar-width:none; scroll-snap-type:x proximity; }
        .invoice-subtabs::-webkit-scrollbar { display:none; }
        .invoice-subtab { flex:0 0 auto; justify-content:center; white-space:nowrap; scroll-snap-align:center; }
        .invoice-sheet { border-radius:16px; }
        .invoice-content { padding:17px 13px; }
        .invoice-drafts { padding:13px; }
        .invoice-draft-item { border-radius:12px; }
        .invoice-field label { margin-bottom:6px; font-size:.9rem; }
        .invoice-section-heading { align-items:flex-start; }
        .invoice-record-table-wrap { overflow:visible; border:0; background:transparent; }
        .invoice-record-table { display:block; min-width:0; }
        .invoice-record-table thead { position:absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0; }
        .invoice-record-table tbody { display:grid; gap:11px; }
        .invoice-record-table tbody tr { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:9px 11px; padding:13px; border:1px solid #dce7e4; border-radius:15px; background:#fff; box-shadow:0 7px 20px rgba(18,54,58,.055); }
        .invoice-record-table td { display:block; min-width:0; padding:0; border:0; overflow-wrap:anywhere; }
        .invoice-record-table td::before { display:block; margin:0 0 5px; color:#6a7c7d; content:attr(data-label); font-size:.62rem; font-weight:850; letter-spacing:.07em; text-transform:uppercase; }
        .invoice-record-table td[data-label="Underlagsnr"],.invoice-record-table td[data-label="Kund och referens"],.invoice-record-table td[data-label="Intern e-postavisering"],.invoice-record-table td[data-label="Fakturadatum och Fortnox-nummer"],.invoice-record-table td[data-label="Åtgärder"] { grid-column:1/-1; }
        .invoice-record-table .invoice-record-email { min-width:0; }
        .invoice-record-form { min-width:0; grid-template-columns:1fr; }
        .invoice-record-form .btn,.invoice-record-email form .btn { width:100%; }
        .invoice-record-actions { display:grid; grid-template-columns:1fr 1fr; white-space:normal; }
        .invoice-record-actions .btn { width:100%; min-height:46px; }
        .invoice-record-table [data-invoice-record-row][hidden] { display:none!important; }
        .invoice-record-table tbody tr:hover td { background:transparent; }
    }
    @media (max-width:420px) {
        .invoice-content { padding:14px 11px; }
        .invoice-subtab { padding-inline:11px; font-size:.84rem; }
        .invoice-table tbody tr { padding:12px; gap:9px; }
    }
</style>

<section class="invoice-page" aria-labelledby="invoice-title">
    <nav class="invoice-subtabs" aria-label="Fakturaunderlagets undersidor">
        <a class="invoice-subtab" data-invoice-nav href="?tab=invoice<?= $invoiceContextHtml ?>" <?= $invoiceView === 'form' ? 'aria-current="page"' : '' ?>>Nytt fakturaunderlag</a>
        <a class="invoice-subtab" data-invoice-nav href="?tab=invoice&amp;invoice_view=processing<?= $invoiceContextHtml ?>" <?= $invoiceView === 'processing' ? 'aria-current="page"' : '' ?>>Under behandling</a>
        <a class="invoice-subtab" data-invoice-nav href="?tab=invoice&amp;invoice_view=invoice_history<?= $invoiceContextHtml ?>" <?= $invoiceView === 'invoice_history' ? 'aria-current="page"' : '' ?>>Historik</a>
        <a class="invoice-subtab" data-invoice-nav href="?tab=invoice&amp;invoice_view=articles<?= $invoiceContextHtml ?>" <?= $invoiceView === 'articles' ? 'aria-current="page"' : '' ?>>
            <svg class="invoice-subtab-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 4h14v16H5z"/><path d="M8 8h8M8 12h8M8 16h4"/></svg>
            Artiklar
        </a>
        <a class="invoice-subtab" data-invoice-nav href="?tab=invoice&amp;invoice_view=customers<?= $invoiceContextHtml ?>" <?= $invoiceView === 'customers' ? 'aria-current="page"' : '' ?>>
            <svg class="invoice-subtab-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="9" cy="8" r="3"/><path d="M3 19c.6-3.2 2.5-5 6-5s5.4 1.8 6 5M17 8a3 3 0 0 1 0 6M17 15c2.3.4 3.6 1.7 4 4"/></svg>
            Kunder
        </a>
        <?php if ($isInvoiceAdmin): ?>
            <a class="invoice-subtab" data-invoice-nav href="?tab=invoice&amp;invoice_view=admin<?= $invoiceContextHtml ?>" <?= $invoiceView === 'admin' ? 'aria-current="page"' : '' ?>>
                <svg class="invoice-subtab-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-1.8 3.1-.2-.1a1.7 1.7 0 0 0-1.9.1l-.2.1h-3.6l-.1-.2a1.7 1.7 0 0 0-1.7-1l-.2.1-3.1-1.8.1-.2a1.7 1.7 0 0 0-.1-1.9L7 15v-3.6l.2-.1a1.7 1.7 0 0 0 1-1.7l-.1-.2 1.8-3.1.2.1a1.7 1.7 0 0 0 1.9-.1l.2-.1h3.6l.1.2a1.7 1.7 0 0 0 1.7 1l.2-.1 3.1 1.8-.1.2a1.7 1.7 0 0 0 .1 1.9l.1.2V15z"/></svg>
                Administration
            </a>
            <a class="invoice-subtab" data-invoice-nav href="?tab=invoice&amp;invoice_view=email<?= $invoiceContextHtml ?>" <?= $invoiceView === 'email' ? 'aria-current="page"' : '' ?>>
                <svg class="invoice-subtab-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>
                E-poststatus
            </a>
            <?php if (empty($portalIsScopedPwa)): ?>
                <a class="invoice-subtab" data-invoice-nav href="?tab=invoice&amp;invoice_view=debug<?= $invoiceContextHtml ?>" <?= $invoiceView === 'debug' ? 'aria-current="page"' : '' ?>>
                    <svg class="invoice-subtab-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 8v4l2.5 1.5"/><circle cx="12" cy="12" r="9"/><path d="M5 4.5 3.5 3M19 4.5 20.5 3"/></svg>
                    Felsökning
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </nav>
    <?php if ($invoiceView === 'form'): ?>
    <div class="invoice-topbar">
        <div class="invoice-topbar-actions">
            <a class="btn btn-outline" data-invoice-nav href="?tab=invoice&amp;invoice_view=invoice_history">Visa fakturahistorik</a>
            <a class="btn btn-main" href="https://tadega.com/faktura/" target="_blank" rel="noopener noreferrer" aria-label="Öppna Tadegas separata fakturasystem i en ny flik">
                Öppna fakturasystemet
                <svg class="invoice-external-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M14 4h6v6M20 4l-9 9"/><path d="M18 13v6a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h6"/></svg>
            </a>
        </div>
    </div>
    <p class="invoice-external-note">Fakturasystemet har en separat inloggning. Underlaget som sparas här överförs inte automatiskt dit.</p>

    <section class="invoice-drafts" aria-labelledby="invoice-drafts-heading">
        <div class="invoice-drafts-heading"><div><h2 id="invoice-drafts-heading">Osparade utkast</h2><p>Ändringar autosparas i tre dagar. Utkastet tas bort när du sparar underlaget.</p></div><a class="btn btn-outline" href="?tab=invoice">Nytt underlag</a></div>
        <?php if ($invoiceDrafts): ?>
            <ul class="invoice-draft-list">
                <?php foreach ($invoiceDrafts as $invoiceDraft): $draftFields = is_array($invoiceDraft['data']['i'] ?? null) ? $invoiceDraft['data']['i'] : []; $draftLabel = trim((string) ($draftFields['customer'] ?? '')); if ($draftLabel === '') { $draftLabel = trim((string) ($draftFields['ref'] ?? '')); } if ($draftLabel === '') { $draftLabel = 'Nytt fakturaunderlag'; } ?>
                    <li class="invoice-draft-item"><span><strong><?= h($draftLabel) ?></strong><br><small>Senast ändrat <?= h(date('Y-m-d H:i', (int) ($invoiceDraft['updated_at'] ?? time()))) ?></small></span><span class="invoice-draft-actions"><a class="btn btn-outline" href="?tab=invoice&amp;draft_id=<?= h($invoiceDraft['id'] ?? '') ?>">Öppna</a><form method="post" action="?tab=invoice" onsubmit="return confirm('Radera det här utkastet?')"><input type="hidden" name="action" value="delete_autosaved_draft"><input type="hidden" name="draft_id" value="<?= h($invoiceDraft['id'] ?? '') ?>"><?= csrf_field() ?><button class="btn btn-outline" type="submit">Radera</button></form></span></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p style="margin:12px 0 0;color:var(--inv-muted);font-size:.88rem">Inga andra utkast just nu. Det här formuläret autosparas när du börjar skriva; du kan alltid skapa ett nytt underlag.</p>
        <?php endif; ?>
    </section>

    <article class="invoice-sheet">
        <header class="invoice-header">
            <div class="invoice-brand">
                <img class="invoice-logo" src="?view_file=assets/tadega-icon.svg&amp;v=<?= (string) (@filemtime(__DIR__ . '/../assets/tadega-icon.svg') ?: '1') ?>" alt="" width="48" height="48">
                <img class="invoice-print-logo" src="?view_file=assets/tadega-logo.svg&amp;v=<?= (string) (@filemtime(__DIR__ . '/../assets/tadega-logo.svg') ?: '1') ?>" alt="Tadega Dokumentportalen" width="1000" height="240">
                <div>
                    <h2 class="invoice-title" id="invoice-title">Fakturaunderlag</h2>
                    <p class="invoice-overline">Tadega AB · sammanställning</p>
                </div>
            </div>
            <?php if ($invoiceNumber !== ''): ?>
                <div class="invoice-meta">Underlagsnummer<strong><?= h($invoiceNumber) ?></strong></div>
            <?php else: ?>
                <div class="invoice-meta">Nytt underlag<strong>Skapas när du sparar</strong></div>
            <?php endif; ?>
        </header>

        <div class="invoice-content">
            <p class="invoice-disclaimer">
                <span class="invoice-disclaimer-icon" aria-hidden="true">i</span>
                <span><strong>Det här är ett fakturaunderlag – inte en faktura.</strong> Det sparas i portalen och skickas inte till kunden.</span>
            </p>
            <?php if ($copyingInvoice): ?>
                <div class="app-alert app-alert-warning" role="status">En ny arbetskopia har skapats. Datumet är satt till idag. Internt nummer, Fortnox-nummer och ändringshistorik kopieras inte. Granska uppgifterna och spara när du är klar.</div>
            <?php elseif ($copyId !== '' && $editId === '' && $requestedDraftId === '' && !is_array($editInvoice)): ?>
                <div class="app-alert app-alert-warning" role="status">Det valda fakturaunderlaget kunde inte kopieras. Du kan ändå börja ett nytt underlag.</div>
            <?php endif; ?>

            <form method="post" action="?tab=invoice" id="invoice-form" aria-describedby="invoice-form-note">
                <input type="hidden" name="action" value="save_invoice">
                <input type="hidden" name="i[id]" value="<?= h($editInvoice['id'] ?? '') ?>">
                <input type="hidden" name="draft_id" value="<?= h($invoiceDraftId) ?>">
                <input type="hidden" name="i[vat_mode]" id="invoice-vat-mode" value="<?= h($vatMode) ?>">
                <?= csrf_field() ?>
                <p class="invoice-autosave-status" id="invoice-autosave-status" role="status" aria-live="polite"><span id="invoice-autosave-message">Dina ändringar sparas automatiskt som ett utkast.</span> <a id="invoice-autosave-resume" href="?tab=invoice&amp;draft_id=<?= h($invoiceDraftId) ?>"<?= is_array($invoiceDraftData) ? '' : ' hidden' ?>>Öppna autosparat utkast</a></p>

                <section class="invoice-section" aria-labelledby="invoice-customer-heading" data-invoice-step="1">
                    <div class="invoice-section-heading">
                        <div>
                            <h3 id="invoice-customer-heading" tabindex="-1">Kund och uppdrag</h3>
                            <p>Fyll i vem underlaget gäller och beskriv vid behov i fritext vad arbetet, materialet eller uppdraget avser.</p>
                        </div>
                    </div>
                    <div class="invoice-form-grid">
                        <div class="invoice-field">
                            <label for="invoice-date">Datum</label>
                            <input id="invoice-date" type="date" name="i[date]" aria-describedby="invoice-date-help" value="<?= h($editInvoice['date'] ?? date('Y-m-d')) ?>">
                            <p class="invoice-help" id="invoice-date-help">Visar vilket datum uppdraget eller underlaget gäller och hjälper till att sortera historiken.</p>
                        </div>
                        <div class="invoice-field">
                            <label for="invoice-customer">Kund / företag</label>
                            <input id="invoice-customer" name="i[customer]" maxlength="180" autocomplete="organization" placeholder="Företags- eller kundnamn" aria-describedby="invoice-customer-help" value="<?= h($editInvoice['customer'] ?? '') ?>">
                            <p class="invoice-help" id="invoice-customer-help">Identifierar kunden som underlaget avser och som den manuella fakturan ska gälla.</p>
                        </div>
                        <div class="invoice-field invoice-field--wide">
                            <label for="invoice-reference">Kundens referens <span class="invoice-help-inline">(valfritt)</span></label>
                            <input id="invoice-reference" name="i[ref]" maxlength="160" placeholder="Namn, ordernummer eller märkning" aria-describedby="invoice-reference-help" value="<?= h($editInvoice['ref'] ?? '') ?>">
                            <p class="invoice-help" id="invoice-reference-help">Kopplar underlaget till kundens beställare, projekt eller ordernummer.</p>
                        </div>
                        <div class="invoice-field invoice-field--wide">
                            <label for="invoice-description">Vad gäller underlaget? <span class="invoice-help-inline">(fritext, valfritt)</span></label>
                            <textarea id="invoice-description" name="i[description]" maxlength="5000" placeholder="Skriv kort vad arbetet, materialet eller uppdraget gäller…" aria-describedby="invoice-description-help"><?= h($editInvoice['description'] ?? '') ?></textarea>
                            <p class="invoice-help" id="invoice-description-help">Förklarar omfattningen så administratören kan skapa rätt faktura; texten visas i granskningen, på utskriften och i den interna aviseringen.</p>
                        </div>
                        <div class="invoice-field invoice-field--wide invoice-fortnox-field">
                            <label for="invoice-fortnox-number">Fortnox fakturanummer</label>
                            <?php if ($isInvoiceAdmin): ?>
                                <input id="invoice-fortnox-number" type="text" name="i[fortnox_invoice_number]" maxlength="80" autocomplete="off" placeholder="Fyll i Fortnox-numret när fakturan är skapad" value="<?= h($fortnoxInvoiceNumber) ?>">
                                <p class="invoice-help">Numret hör till fakturan i Fortnox och är separat från underlagets interna nummer. Det förs inte över automatiskt.</p>
                                <label for="invoice-fortnox-date">Fakturadatum i Fortnox</label>
                                <input id="invoice-fortnox-date" type="date" name="i[fortnox_invoice_date]" value="<?= h($fortnoxInvoiceDate) ?>" aria-describedby="invoice-fortnox-date-help">
                                <p class="invoice-help" id="invoice-fortnox-date-help">Ange fakturans datum i Fortnox. Det kan skilja sig från underlagsdatumet och krävs när Fortnox-numret anges.</p>
                            <?php else: ?>
                                <div class="invoice-readonly-value" id="invoice-fortnox-number" role="status"><strong><?= $fortnoxInvoiceNumber !== '' ? h($fortnoxInvoiceNumber) : 'Inte registrerat ännu' ?></strong></div>
                                <p class="invoice-help">Fortnox-numret visas här. Endast admin kan ändra det. Det är separat från underlagets interna nummer.</p>
                                <p class="invoice-help">Fakturadatum i Fortnox: <strong id="invoice-fortnox-date"><?= $fortnoxInvoiceDate !== '' ? h($fortnoxInvoiceDate) : 'Inte registrerat ännu' ?></strong></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($invoiceCustomerOptions): ?>
                        <details class="invoice-register" open>
                            <summary>Välj kund och referens från Kundregistret (valfritt)</summary>
                            <div class="invoice-register-body">
                                <div class="invoice-register-field">
                                    <label for="invoice-customer-select">Välj kund</label>
                                    <select id="invoice-customer-select">
                                        <option value="">Välj kund…</option>
                                        <?php foreach ($invoiceCustomerOptions as $invoiceCustomerOption): ?>
                                            <?php $invoiceReferenceJson = json_encode($invoiceCustomerOption['references'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG); ?>
                                            <option value="<?= h($invoiceCustomerOption['id']) ?>" data-name="<?= h($invoiceCustomerOption['name']) ?>" data-references="<?= h(is_string($invoiceReferenceJson) ? $invoiceReferenceJson : '[]') ?>" <?= $invoiceCustomerOption['id'] === $invoiceCustomerSelectionId ? 'selected' : '' ?>><?= h($invoiceCustomerOption['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="invoice-register-field">
                                    <label for="invoice-customer-reference-select">Välj referens</label>
                                    <select id="invoice-customer-reference-select" data-current-reference="<?= h($invoiceCurrentReference) ?>" <?= $invoiceCustomerSelectionId === '' ? 'disabled' : '' ?>>
                                        <option value=""><?= $invoiceCustomerSelectionId === '' ? 'Välj kund först' : 'Utan referens' ?></option>
                                    </select>
                                </div>
                            </div>
                            <p class="invoice-help invoice-register-help">Välj först kunden; därefter visas kundens referenser. Valen fyller i kund- och referensfälten ovan. Du kan också skriva i fälten manuellt.</p>
                        </details>
                    <?php else: ?>
                        <div class="invoice-register invoice-register-empty">
                            <p>Inga kunder finns i Kundregistret ännu. <?= empty($portalIsScopedPwa) ? '<a href="?tab=customers">Öppna Kundregistret</a> för att lägga till en kund.' : 'Lägg till kunden i Kundregistret via portalens vanliga kundvy.' ?></p>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="invoice-section" aria-labelledby="invoice-lines-heading" data-invoice-step="2">
                    <div class="invoice-lines-header">
                        <div>
                            <h3 id="invoice-lines-heading" tabindex="-1">Arbete och material</h3>
                            <p>Lägg till en rad per arbete eller material. Ange beskrivning, antal, enhet och pris per enhet.</p>
                        </div>
                        <div class="invoice-vat-control">
                            <label for="invoice-vat-included">
                                <input id="invoice-vat-included" type="checkbox" aria-describedby="invoice-vat-help" <?= $vatMode === 'inc' ? 'checked' : '' ?>>
                                <span><strong>Priser inkl. moms</strong><small>Avmarkerad = exkl. moms</small></span>
                            </label>
                        </div>
                    </div>

                    <p class="invoice-help" style="margin:0 0 10px" id="invoice-vat-help">Välj om radpriserna du skriver in är exklusive eller inklusive moms. Momsvalet sparas med underlaget; portalen beräknar inte moms.</p>
                    <p class="invoice-help" id="invoice-line-field-help"><strong>Varje rad:</strong> beskrivningen identifierar arbete eller material; antal anger mängden; enhet visar hur mängden räknas; pris per enhet dokumenterar det angivna stycke-, tim- eller enhetspriset. Uppgifterna behövs för att administratören ska kunna skapa rätt faktura i Fortnox.</p>

                    <details class="invoice-article-picker">
                        <summary>Välj artikel ur katalogen (valfritt)</summary>
                        <div class="invoice-article-picker-body">
                            <?php if (!$invoiceCatalogStorageReady): ?>
                                <p class="invoice-catalog-inline-warning">Artikelkatalogen kunde inte läsas säkert. Skriv i stället in raden manuellt och kontakta administratören.</p>
                            <?php elseif (!$invoiceArticles): ?>
                                <p>Katalogen är tom. <a data-invoice-nav href="?tab=invoice&amp;invoice_view=articles<?= $invoiceContextHtml ?>">Lägg till en artikel</a> så kan den väljas här.</p>
                            <?php else: ?>
                                <div class="invoice-article-picker-controls">
                                    <label for="invoice-catalog-article">Artikel</label>
                                    <select id="invoice-catalog-article">
                                        <option value="">Välj artikel…</option>
                                        <?php foreach ($invoiceArticles as $invoiceArticle): ?>
                                            <option value="<?= h($invoiceArticle['id']) ?>" data-name="<?= h($invoiceArticle['name']) ?>" data-unit="<?= h($invoiceArticle['unit']) ?>" data-price="<?= h(number_format((float) $invoiceArticle['price'], 2, '.', '')) ?>"><?= h($invoiceArticle['name']) ?> — <?= number_format((float) $invoiceArticle['price'], 2, ',', ' ') ?> kr / <?= h($invoiceArticleUnits[$invoiceArticle['unit']] ?? 'st') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-outline" type="button" id="invoice-add-catalog-article">Lägg till på fakturarad</button>
                                    <span id="invoice-article-add-status" role="status" aria-live="polite"></span>
                                </div>
                                <p class="invoice-catalog-note">Artikelns pris förs in oförändrat. Valet <strong>exkl./inkl. moms</strong> sparas som information; portalen beräknar inte moms.</p>
                            <?php endif; ?>
                        </div>
                    </details>

                    <div class="invoice-table-wrap" role="region" aria-label="Fakturarader. Använd tabbtangenten för att gå mellan beskrivning, antal, enhet och pris." tabindex="0">
                        <table class="invoice-table" id="invoice-table">
                            <caption class="invoice-sr-only">Rader för arbete och material</caption>
                            <colgroup>
                                <col style="width:38%">
                                <col style="width:14%">
                                <col style="width:10%">
                                <col style="width:18%">
                                <col style="width:14%">
                                <col style="width:6%">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th scope="col">Beskrivning</th>
                                    <th scope="col">Antal</th>
                                    <th scope="col">Enhet</th>
                                    <th scope="col">Pris per enhet (<span class="invoice-price-mode"><?= $vatMode === 'ex' ? 'exkl.' : 'inkl.' ?></span>)</th>
                                    <th scope="col" style="text-align:right">Radbelopp</th>
                                    <th scope="col"><span class="invoice-sr-only">Ta bort rad</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for ($index = 0; $index < $rowCount; $index++): ?>
                                    <?php $item = is_array($invoiceItems[$index] ?? null) ? $invoiceItems[$index] : []; $itemUnit = is_scalar($item['unit'] ?? null) ? (string) $item['unit'] : 'st'; if (!array_key_exists($itemUnit, $invoiceArticleUnits)) { $itemUnit = 'st'; } ?>
                                    <tr>
                                        <td data-label="Beskrivning">
                                            <input class="invoice-item-name" name="items[<?= $index ?>][art]" maxlength="200" aria-label="Beskrivning, rad <?= $index + 1 ?>" aria-describedby="invoice-line-field-help" placeholder="Ex. arbete, reservdel eller resa" value="<?= h($item['art'] ?? '') ?>">
                                        </td>
                                        <td data-label="Antal">
                                            <input class="invoice-item-qty" type="number" min="0" max="100000" step="0.01" inputmode="decimal" name="items[<?= $index ?>][qty]" aria-label="Antal, rad <?= $index + 1 ?>" aria-describedby="invoice-line-field-help" value="<?= h($item['qty'] ?? '') ?>">
                                        </td>
                                        <td data-label="Enhet">
                                            <select class="invoice-item-unit" name="items[<?= $index ?>][unit]" aria-label="Enhet, rad <?= $index + 1 ?>" aria-describedby="invoice-line-field-help">
                                                <?php foreach ($invoiceArticleUnits as $invoiceUnitValue => $invoiceUnitLabel): ?>
                                                    <option value="<?= h($invoiceUnitValue) ?>" <?= $itemUnit === $invoiceUnitValue ? 'selected' : '' ?>><?= h($invoiceUnitLabel) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td data-label="Pris per enhet">
                                            <input class="invoice-item-price" type="number" min="0" max="10000000" step="0.01" inputmode="decimal" name="items[<?= $index ?>][price]" aria-label="Pris per enhet i kronor, rad <?= $index + 1 ?>" aria-describedby="invoice-line-field-help" value="<?= h($item['price'] ?? '') ?>">
                                        </td>
                                        <td class="invoice-line-total" data-label="Radbelopp"><?= number_format($initialLineTotals[$index] ?? 0, 2, ',', ' ') ?> kr</td>
                                        <td class="invoice-remove-cell" data-label="Ta bort">
                                            <button class="invoice-remove" type="button" data-remove-line aria-label="Ta bort rad <?= $index + 1 ?>">Ta bort</button>
                                        </td>
                                    </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="invoice-line-controls">
                        <button class="btn btn-outline" type="button" id="invoice-add-row">+ Lägg till rad</button>
                        <span class="invoice-limit-note" id="invoice-row-note" aria-live="polite"></span>
                    </div>

                    <?php if ($isInvoiceAdmin && $invoiceCatalogStorageReady): ?>
                        <div class="invoice-catalog-save-box" aria-labelledby="invoice-catalog-save-title">
                            <div>
                                <label id="invoice-catalog-save-title" for="invoice-catalog-source-row">Spara en fakturarad som återanvändbar artikel</label>
                                <select id="invoice-catalog-source-row" aria-describedby="invoice-catalog-save-help"><option value="">Välj en ifylld rad…</option></select>
                                <p class="invoice-help" id="invoice-catalog-save-help">Snabbsparning från formuläret är adminfunktion. Artiklarna hör till fakturaunderlagets katalog; enhet och pris förs med, momsval görs separat på varje underlag.</p>
                            </div>
                            <button class="btn btn-outline" type="button" id="invoice-save-catalog-row" disabled>Spara i artikelkatalogen</button>
                            <p id="invoice-catalog-save-status" role="status" aria-live="polite"></p>
                        </div>
                    <?php elseif ($isInvoiceAdmin): ?>
                        <p class="invoice-catalog-inline-warning" role="status">Snabbsparning är tillfälligt avstängd eftersom den privata artikelkatalogen inte kan läsas säkert.</p>
                    <?php endif; ?>

                    <div class="invoice-form-error" id="invoice-form-error" role="alert" tabindex="-1" hidden></div>
                </section>

                <section class="invoice-section invoice-mobile-review" data-invoice-step="3" aria-labelledby="invoice-mobile-review-heading">
                    <div class="invoice-section-heading">
                        <div>
                            <h3 id="invoice-mobile-review-heading" tabindex="-1">Granska underlaget</h3>
                            <p>Kontrollera kund, uppgifter och arbetsrader innan du sparar.</p>
                        </div>
                    </div>
                    <dl class="invoice-review-details">
                        <div><dt>Kund / företag</dt><dd id="invoice-review-customer">Inte angivet</dd></div>
                        <div><dt>Datum</dt><dd id="invoice-review-date">Inte angivet</dd></div>
                        <div><dt>Kundens referens</dt><dd id="invoice-review-reference">Inte angivet</dd></div>
                        <div><dt>Fortnox fakturanummer</dt><dd id="invoice-review-fortnox">Inte registrerat ännu</dd></div>
                        <div><dt>Fakturadatum i Fortnox</dt><dd id="invoice-review-fortnox-date">Inte registrerat ännu</dd></div>
                        <div class="invoice-review-wide"><dt>Vad gäller underlaget?</dt><dd id="invoice-review-description">Inte angivet</dd></div>
                    </dl>
                    <div class="invoice-review-lines">
                        <h4>Arbete och material</h4>
                        <ul id="invoice-review-items"></ul>
                    </div>
                    <p class="invoice-review-note">Det här är fortfarande ett fakturaunderlag, inte en faktura. Det skickas inte till kunden.</p>
                </section>

                <nav class="invoice-step-navigation" id="invoice-step-navigation" data-mobile-action-dock aria-label="Steg i fakturaunderlaget">
                    <div class="invoice-step-progress">
                        <p id="invoice-step-status" aria-live="polite">Steg 1 av 3: Kund och uppdrag</p>
                        <div class="invoice-step-progress-track" id="invoice-step-progress" role="progressbar" aria-label="Fyll i fakturaunderlaget" aria-valuemin="1" aria-valuemax="3" aria-valuenow="1"><span></span></div>
                    </div>
                    <div class="invoice-step-buttons">
                        <button class="btn btn-outline" type="button" id="invoice-step-back" hidden>← Tillbaka</button>
                        <button class="btn btn-main" type="button" id="invoice-step-next">Fortsätt till arbetsrader →</button>
                        <button class="btn btn-main" type="submit" id="invoice-step-save" hidden><?= $isEditingInvoice ? 'Spara ändringar' : 'Spara fakturaunderlag' ?></button>
                    </div>
                </nav>

                <div class="invoice-submit-step" data-invoice-step="3">
                    <div class="invoice-actions">
                        <?php if ($isEditingInvoice): ?>
                            <a class="btn btn-outline" href="?tab=invoice">Avbryt ändringar</a>
                        <?php endif; ?>
                        <button class="btn btn-outline" type="button" id="invoice-print">Skriv ut / spara som PDF</button>
                        <button class="btn btn-main" type="submit"><?= $isEditingInvoice ? 'Spara ändringar' : 'Spara fakturaunderlag' ?></button>
                    </div>
                    <p class="invoice-note" id="invoice-form-note">Underlaget räknas om och valideras igen när det sparas. En intern avisering skickas till portalens primära e-postmottagare; ingen kundkopia skickas.</p>
                </div>
            </form>
        </div>
    </article>
    <?php elseif ($invoiceView === 'processing'): ?>
        <section class="invoice-admin-panel invoice-record-panel" aria-labelledby="invoice-processing-heading">
            <header class="invoice-admin-header">
                <div>
                    <p class="invoice-admin-eyebrow">UNDER BEHANDLING</p>
                    <h2 id="invoice-processing-heading">Sparade underlag som inte har fakturerats</h2>
                    <p>Här visas underlag som saknar Fortnox-fakturanummer. Admin anger fakturadatum och nummer; då flyttas underlaget till Historik.</p>
                </div>
                <span class="invoice-admin-badge"><?= count($invoiceProcessingRecords) ?> underlag</span>
            </header>
            <?php if ($invoiceProcessingRecords): ?>
                <div class="invoice-record-search">
                    <label for="invoice-processing-search">Sök på kund, referens eller fritext</label>
                    <input id="invoice-processing-search" type="search" data-invoice-record-search aria-controls="invoice-processing-table" data-count-id="invoice-processing-search-count" placeholder="Kund, underlagsnr, beskrivning…" autocomplete="off">
                    <p id="invoice-processing-search-count" class="invoice-record-search-count" role="status" aria-live="polite"></p>
                </div>
                <div class="invoice-record-table-wrap" role="region" aria-label="Underlag som väntar på fakturering" tabindex="0">
                    <table class="invoice-record-table" id="invoice-processing-table">
                        <caption class="invoice-sr-only">Sparade fakturaunderlag som ännu saknar Fortnox-fakturanummer</caption>
                        <thead><tr><th scope="col">Underlagsnr</th><th scope="col">Underlagsdatum</th><th scope="col">Kund och referens</th><th scope="col">Sparad summa</th><th scope="col">Intern e-postavisering</th><th scope="col">Fakturadatum och Fortnox-nummer</th><th scope="col">Åtgärder</th></tr></thead>
                        <tbody>
                            <?php foreach ($invoiceProcessingRecords as $invoiceRecordIndex => $invoiceRecord): ?>
                                <?php
                                $invoiceRecordId = is_scalar($invoiceRecord['id'] ?? null) ? (string) $invoiceRecord['id'] : '';
                                $invoiceRecordCustomer = app_legacy_text($invoiceRecord['customer'] ?? '');
                                $invoiceRecordReference = app_legacy_text($invoiceRecord['ref'] ?? '');
                                $invoiceEmailStatus = is_array($invoiceRecord['internal_email_status'] ?? null) ? $invoiceRecord['internal_email_status'] : [];
                                $invoiceEmailResult = is_string($invoiceEmailStatus['result'] ?? null) ? $invoiceEmailStatus['result'] : 'untracked';
                                $invoiceEmailStatusLabel = match ($invoiceEmailResult) {
                                    'accepted' => 'E-posttransporten accepterade anropet; inkorgsleverans är inte bekräftad.',
                                    'failed' => 'E-posttransporten kunde inte acceptera aviseringen.',
                                    'not_configured' => 'Ingen giltig mottagaradress; inget utskick gjordes.',
                                    default => 'Ingen status registrerad för detta underlag.',
                                };
                                $invoiceEmailAttempts = max(0, (int) ($invoiceEmailStatus['attempts'] ?? 0));
                                $invoiceEmailLastAttempt = is_string($invoiceEmailStatus['last_attempt_at'] ?? null) ? substr($invoiceEmailStatus['last_attempt_at'], 0, 19) : '';
                                $invoiceRecordSearch = $invoiceRecordSearchText($invoiceRecord);
                                ?>
                                <tr data-invoice-record-row data-search="<?= h($invoiceRecordSearch) ?>">
                                    <td data-label="Underlagsnr"><span class="invoice-record-primary"><?= h($invoiceRecord['nummer'] ?? '—') ?></span></td>
                                    <td data-label="Underlagsdatum"><?= h($invoiceRecord['date'] ?? '') ?></td>
                                    <td data-label="Kund och referens"><span class="invoice-record-primary"><?= h($invoiceRecordCustomer !== '' ? $invoiceRecordCustomer : 'Kund saknas') ?></span><?php if ($invoiceRecordReference !== ''): ?><span class="invoice-record-muted">Ref: <?= h($invoiceRecordReference) ?></span><?php endif; ?></td>
                                    <td class="invoice-record-total" data-label="Sparad summa"><?= number_format((float) ($invoiceRecord['total_sum'] ?? 0), 2, ',', ' ') ?> kr</td>
                                    <td class="invoice-record-email" data-label="Intern e-postavisering">
                                        <span class="invoice-record-primary"><?= h($invoiceEmailStatusLabel) ?></span>
                                        <?php if ($invoiceEmailAttempts > 0): ?><span class="invoice-record-muted">Försök: <?= $invoiceEmailAttempts ?><?= $invoiceEmailLastAttempt !== '' ? ' · Senast ' . h($invoiceEmailLastAttempt) . ' UTC' : '' ?></span><?php endif; ?>
                                        <?php if ($isInvoiceAdmin): ?>
                                            <form method="post" action="?tab=invoice&amp;invoice_view=processing" onsubmit="return confirm('Försöka skicka den interna aviseringen igen? Ett tidigare försök kan redan ha accepterats och ett nytt mejl kan bli en dubblett. Transportens svar bekräftar inte leverans till inkorgen.')">
                                                <input type="hidden" name="action" value="retry_invoice_email">
                                                <input type="hidden" name="invoice_id" value="<?= h($invoiceRecordId) ?>">
                                                <?= csrf_field() ?>
                                                <button class="btn btn-outline" type="submit"><?= $invoiceEmailAttempts > 0 ? 'Försök igen' : 'Skicka avisering' ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Fakturadatum och Fortnox-nummer">
                                        <?php if ($isInvoiceAdmin): ?>
                                            <form class="invoice-record-form" method="post" action="?tab=invoice&amp;invoice_view=processing">
                                                <input type="hidden" name="action" value="invoice_admin_set_fortnox_number">
                                                <input type="hidden" name="invoice_id" value="<?= h($invoiceRecordId) ?>">
                                                <?= csrf_field() ?>
                                                <label class="invoice-sr-only" for="invoice-fortnox-date-entry-<?= (int) $invoiceRecordIndex ?>">Fakturadatum i Fortnox för <?= h($invoiceRecordCustomer) ?></label>
                                                <input id="invoice-fortnox-date-entry-<?= (int) $invoiceRecordIndex ?>" name="fortnox_invoice_date" type="date" required>
                                                <label class="invoice-sr-only" for="invoice-fortnox-entry-<?= (int) $invoiceRecordIndex ?>">Fortnox-fakturanummer för <?= h($invoiceRecordCustomer) ?></label>
                                                <input id="invoice-fortnox-entry-<?= (int) $invoiceRecordIndex ?>" name="fortnox_invoice_number" type="text" maxlength="80" autocomplete="off" placeholder="Ange fakturanummer" required>
                                                <button class="btn btn-main" type="submit">Spara &amp; flytta</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="invoice-record-muted">Väntar på adminens fakturanummer</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Åtgärder"><div class="invoice-record-actions"><a class="btn btn-outline" href="?tab=invoice&amp;edit_id=<?= rawurlencode($invoiceRecordId) ?>">Öppna</a><a class="btn btn-outline" href="?tab=invoice&amp;copy_id=<?= rawurlencode($invoiceRecordId) ?>">Kopiera</a></div></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="invoice-record-search-empty" data-invoice-search-empty-for="invoice-processing-table" hidden>Inga underlag matchar sökningen.</p>
            <?php else: ?>
                <div class="invoice-catalog-empty"><strong>Inga underlag väntar på fakturering.</strong><p>Skapa ett nytt underlag eller öppna Historik för redan fakturerade underlag.</p><a class="btn btn-main" href="?tab=invoice">Skapa nytt underlag</a></div>
            <?php endif; ?>
        </section>
    <?php elseif ($invoiceView === 'invoice_history'): ?>
        <section class="invoice-admin-panel invoice-record-panel" aria-labelledby="invoice-history-heading">
            <header class="invoice-admin-header">
                <div>
                    <p class="invoice-admin-eyebrow">HISTORIK</p>
                    <h2 id="invoice-history-heading">Fakturerade underlag</h2>
                    <p>Här samlas sparade underlag som har fått ett Fortnox-fakturanummer. Nummer och fakturadatum kan ändras av admin via Öppna.</p>
                </div>
                <span class="invoice-admin-badge"><?= count($invoiceHistoryRecords) ?> underlag</span>
            </header>
            <?php if ($invoiceHistoryRecords): ?>
                <div class="invoice-record-search">
                    <label for="invoice-history-search">Sök på kund, referens eller fritext</label>
                    <input id="invoice-history-search" type="search" data-invoice-record-search aria-controls="invoice-history-table" data-count-id="invoice-history-search-count" placeholder="Kund, Fortnox-nr, beskrivning…" autocomplete="off">
                    <p id="invoice-history-search-count" class="invoice-record-search-count" role="status" aria-live="polite"></p>
                </div>
                <div class="invoice-record-table-wrap" role="region" aria-label="Fakturerade underlag" tabindex="0">
                    <table class="invoice-record-table" id="invoice-history-table">
                        <caption class="invoice-sr-only">Fakturerade underlag med Fortnox-fakturanummer</caption>
                        <thead><tr><th scope="col">Underlagsnr</th><th scope="col">Fortnox-nr</th><th scope="col">Underlagsdatum</th><th scope="col">Fakturadatum</th><th scope="col">Kund och referens</th><th scope="col">Sparad summa</th><th scope="col">Intern e-postavisering</th><th scope="col">Åtgärder</th></tr></thead>
                        <tbody>
                            <?php foreach ($invoiceHistoryRecords as $invoiceRecord): ?>
                                <?php
                                $invoiceRecordId = is_scalar($invoiceRecord['id'] ?? null) ? (string) $invoiceRecord['id'] : '';
                                $invoiceRecordCustomer = app_legacy_text($invoiceRecord['customer'] ?? '');
                                $invoiceRecordReference = app_legacy_text($invoiceRecord['ref'] ?? '');
                                $invoiceEmailStatus = is_array($invoiceRecord['internal_email_status'] ?? null) ? $invoiceRecord['internal_email_status'] : [];
                                $invoiceEmailResult = is_string($invoiceEmailStatus['result'] ?? null) ? $invoiceEmailStatus['result'] : 'untracked';
                                $invoiceEmailStatusLabel = match ($invoiceEmailResult) {
                                    'accepted' => 'E-posttransporten accepterade anropet; inkorgsleverans är inte bekräftad.',
                                    'failed' => 'E-posttransporten kunde inte acceptera aviseringen.',
                                    'not_configured' => 'Ingen giltig mottagaradress; inget utskick gjordes.',
                                    default => 'Ingen status registrerad för detta underlag.',
                                };
                                $invoiceEmailAttempts = max(0, (int) ($invoiceEmailStatus['attempts'] ?? 0));
                                $invoiceEmailLastAttempt = is_string($invoiceEmailStatus['last_attempt_at'] ?? null) ? substr($invoiceEmailStatus['last_attempt_at'], 0, 19) : '';
                                $invoiceFortnoxNumber = is_scalar($invoiceRecord['fortnox_invoice_number'] ?? null) ? (string) $invoiceRecord['fortnox_invoice_number'] : '';
                                $invoiceFortnoxDate = is_scalar($invoiceRecord['fortnox_invoice_date'] ?? null) ? (string) $invoiceRecord['fortnox_invoice_date'] : '';
                                $invoiceRecordSearch = $invoiceRecordSearchText($invoiceRecord);
                                ?>
                                <tr data-invoice-record-row data-search="<?= h($invoiceRecordSearch) ?>">
                                    <td data-label="Underlagsnr"><span class="invoice-record-primary"><?= h($invoiceRecord['nummer'] ?? '—') ?></span></td>
                                    <td data-label="Fortnox-nr"><span class="invoice-record-primary"><?= h($invoiceFortnoxNumber) ?></span></td>
                                    <td data-label="Underlagsdatum"><?= h($invoiceRecord['date'] ?? '') ?></td>
                                    <td data-label="Fakturadatum"><?= h($invoiceFortnoxDate !== '' ? $invoiceFortnoxDate : '—') ?></td>
                                    <td data-label="Kund och referens"><span class="invoice-record-primary"><?= h($invoiceRecordCustomer !== '' ? $invoiceRecordCustomer : 'Kund saknas') ?></span><?php if ($invoiceRecordReference !== ''): ?><span class="invoice-record-muted">Ref: <?= h($invoiceRecordReference) ?></span><?php endif; ?></td>
                                    <td class="invoice-record-total" data-label="Sparad summa"><?= number_format((float) ($invoiceRecord['total_sum'] ?? 0), 2, ',', ' ') ?> kr</td>
                                    <td class="invoice-record-email" data-label="Intern e-postavisering">
                                        <span class="invoice-record-primary"><?= h($invoiceEmailStatusLabel) ?></span>
                                        <?php if ($invoiceEmailAttempts > 0): ?><span class="invoice-record-muted">Försök: <?= $invoiceEmailAttempts ?><?= $invoiceEmailLastAttempt !== '' ? ' · Senast ' . h($invoiceEmailLastAttempt) . ' UTC' : '' ?></span><?php endif; ?>
                                        <?php if ($isInvoiceAdmin): ?>
                                            <form method="post" action="?tab=invoice&amp;invoice_view=invoice_history" onsubmit="return confirm('Försöka skicka den interna aviseringen igen? Ett tidigare försök kan redan ha accepterats och ett nytt mejl kan bli en dubblett. Transportens svar bekräftar inte leverans till inkorgen.')">
                                                <input type="hidden" name="action" value="retry_invoice_email">
                                                <input type="hidden" name="invoice_id" value="<?= h($invoiceRecordId) ?>">
                                                <?= csrf_field() ?>
                                                <button class="btn btn-outline" type="submit"><?= $invoiceEmailAttempts > 0 ? 'Försök igen' : 'Skicka avisering' ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Åtgärder"><div class="invoice-record-actions"><a class="btn btn-outline" href="?tab=invoice&amp;edit_id=<?= rawurlencode($invoiceRecordId) ?>">Öppna</a><a class="btn btn-outline" href="?tab=invoice&amp;copy_id=<?= rawurlencode($invoiceRecordId) ?>">Kopiera</a></div></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="invoice-record-search-empty" data-invoice-search-empty-for="invoice-history-table" hidden>Inga underlag matchar sökningen.</p>
            <?php else: ?>
                <div class="invoice-catalog-empty"><strong>Historiken är tom.</strong><p>När admin sparar Fortnox-fakturanummer och fakturadatum under Under behandling visas underlaget här.</p></div>
            <?php endif; ?>
        </section>
    <?php elseif ($invoiceView === 'articles'): ?>
        <section class="invoice-admin-panel invoice-catalog-panel" aria-labelledby="invoice-articles-heading">
            <header class="invoice-admin-header">
                <div>
                    <p class="invoice-admin-eyebrow">ARTIKELKATALOG</p>
                    <h2 id="invoice-articles-heading">Artiklar</h2>
                    <p>En gemensam, manuellt underhållen lista. Alla fakturaanvändare kan lägga till, ändra och ta bort artiklar.</p>
                </div>
                <span class="invoice-admin-badge"><?= count($invoiceArticles) ?> artiklar</span>
            </header>
            <?php if (!$invoiceCatalogStorageReady): ?>
                <div class="app-alert app-alert-error invoice-email-warning" role="alert">Artikelkatalogen kunde inte läsas säkert. Inga ändringar har gjorts. Kontrollera privat lagring under storage.</div>
            <?php else: ?>
                <p class="invoice-catalog-note">Katalogen sparar beskrivning, enhet och pris per enhet. Momsvalet på fakturaunderlaget sparas endast som information; portalen beräknar inte moms. Artikelns enhet och pris förs över oförändrade till fakturaraden.</p>
                <section class="invoice-catalog-editor" id="invoice-article-editor" aria-labelledby="invoice-article-form-heading">
                    <h3 id="invoice-article-form-heading"><?= is_array($invoiceEditingArticle) ? 'Ändra artikel' : 'Lägg till artikel' ?></h3>
                    <form method="post" action="?tab=invoice&amp;invoice_view=articles<?= $invoiceContextHtml ?>" class="invoice-catalog-form" autocomplete="off">
                        <input type="hidden" name="action" value="invoice_catalog_save_article">
                        <input type="hidden" name="article[id]" value="<?= h($invoiceEditingArticle['id'] ?? '') ?>">
                        <?= csrf_field() ?>
                        <div class="invoice-catalog-fields">
                            <div class="invoice-field">
                                <label for="invoice-article-name">Beskrivning</label>
                                <input id="invoice-article-name" name="article[name]" maxlength="200" value="<?= h($invoiceEditingArticle['name'] ?? '') ?>" placeholder="Ex. Reservdel eller material" required>
                            </div>
                            <div class="invoice-field">
                                <label for="invoice-article-unit">Enhet</label>
                                <select id="invoice-article-unit" name="article[unit]" required>
                                    <?php foreach ($invoiceArticleUnits as $invoiceUnitValue => $invoiceUnitLabel): ?>
                                        <option value="<?= h($invoiceUnitValue) ?>" <?= ($invoiceEditingArticle['unit'] ?? 'st') === $invoiceUnitValue ? 'selected' : '' ?>><?= h($invoiceUnitLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="invoice-field">
                                <label for="invoice-article-price">Pris per enhet, kr</label>
                                <input id="invoice-article-price" name="article[price]" type="number" min="0" max="10000000" step="0.01" inputmode="decimal" value="<?= is_array($invoiceEditingArticle) ? h(number_format((float) $invoiceEditingArticle['price'], 2, '.', '')) : '' ?>" placeholder="0,00" required>
                            </div>
                        </div>
                        <div class="invoice-catalog-form-actions">
                            <button class="btn btn-main" type="submit"><?= is_array($invoiceEditingArticle) ? 'Spara ändring' : 'Lägg till i katalogen' ?></button>
                            <?php if (is_array($invoiceEditingArticle)): ?>
                                <a class="btn btn-outline" href="?tab=invoice&amp;invoice_view=articles<?= $invoiceContextHtml ?>">Avbryt ändring</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </section>
                <?php if ($invoiceArticles): ?>
                    <div class="invoice-catalog-toolbar">
                        <label for="invoice-article-search">Sök i katalogen</label>
                        <input id="invoice-article-search" type="search" placeholder="Skriv en beskrivning…" autocomplete="off">
                        <span><?= count($invoiceArticles) ?> artiklar</span>
                    </div>
                    <ul class="invoice-catalog-list" id="invoice-catalog-list">
                        <?php foreach ($invoiceArticles as $invoiceArticle): ?>
                            <li class="invoice-catalog-card" data-invoice-catalog-card data-search="<?= h($invoiceArticle['name'] . ' ' . ($invoiceArticleUnits[$invoiceArticle['unit']] ?? 'st')) ?>">
                                <div class="invoice-catalog-card-details">
                                    <h3><?= h($invoiceArticle['name']) ?></h3>
                                    <strong><?= number_format((float) $invoiceArticle['price'], 2, ',', ' ') ?> kr / <?= h($invoiceArticleUnits[$invoiceArticle['unit']] ?? 'st') ?></strong>
                                </div>
                                <div class="invoice-catalog-card-actions">
                                    <a class="btn btn-outline" href="?tab=invoice&amp;invoice_view=articles<?= $invoiceContextHtml ?>&amp;edit_article=<?= rawurlencode($invoiceArticle['id']) ?>#invoice-article-editor">Ändra</a>
                                    <form method="post" action="?tab=invoice&amp;invoice_view=articles<?= $invoiceContextHtml ?>" onsubmit="return confirm('Ta bort artikeln från katalogen?')">
                                        <input type="hidden" name="action" value="invoice_catalog_delete_article">
                                        <input type="hidden" name="article_id" value="<?= h($invoiceArticle['id']) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-outline" type="submit">Ta bort</button>
                                    </form>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="invoice-catalog-no-results" id="invoice-article-no-results" hidden>Inga artiklar matchar sökningen.</p>
                <?php else: ?>
                    <div class="invoice-catalog-empty"><strong>Katalogen är tom.</strong><p>Lägg till den första artikeln ovan. Den kan sedan väljas direkt på fakturaunderlaget.</p></div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php elseif ($invoiceView === 'customers'): ?>
        <section class="invoice-admin-panel invoice-customer-panel" aria-labelledby="invoice-customers-heading">
            <header class="invoice-admin-header">
                <div>
                    <p class="invoice-admin-eyebrow">BEFINTLIGT KUNDREGISTER</p>
                    <h2 id="invoice-customers-heading">Kunder</h2>
                    <p>Välj en kund att fylla in i fakturaunderlaget. Kunduppgifter hanteras i det vanliga Kundregistret.</p>
                </div>
                <?php if (empty($portalIsScopedPwa)): ?><a class="btn btn-outline" href="?tab=customers">Öppna Kundregistret</a><?php endif; ?>
            </header>
            <?php if (!$invoiceCustomerOptions): ?>
                <div class="invoice-catalog-empty"><strong>Inga kunder hittades.</strong><p>Lägg till kunder i Kundregistret och kom sedan tillbaka hit.</p><?php if (empty($portalIsScopedPwa)): ?><a class="btn btn-main" href="?tab=customers">Gå till Kundregistret</a><?php endif; ?></div>
            <?php else: ?>
                <div class="invoice-catalog-toolbar">
                    <label for="invoice-customer-search">Sök kunder</label>
                    <input id="invoice-customer-search" type="search" placeholder="Skriv ett kundnamn…" autocomplete="off">
                    <span><?= count($invoiceCustomerOptions) ?> kunder</span>
                </div>
                <div class="invoice-customer-list" id="invoice-customer-list">
                    <?php foreach ($invoiceCustomerOptions as $invoiceCustomerIndex => $invoiceCustomer): ?>
                        <article class="invoice-customer-card" data-invoice-customer-card data-search="<?= h($invoiceCustomer['name'] . ' ' . implode(' ', $invoiceCustomer['references'])) ?>">
                            <div class="invoice-customer-card-heading">
                                <h3><?= h($invoiceCustomer['name']) ?></h3>
                                <span><?= count($invoiceCustomer['references']) ?> referenser</span>
                            </div>
                            <form method="get" action="?tab=invoice" class="invoice-customer-select-form">
                                <input type="hidden" name="invoice_view" value="form">
                                <input type="hidden" name="customer_id" value="<?= h($invoiceCustomer['id']) ?>">
                                <?php foreach ($invoiceContextParams as $invoiceContextKey => $invoiceContextValue): ?>
                                    <input type="hidden" name="<?= h($invoiceContextKey) ?>" value="<?= h($invoiceContextValue) ?>">
                                <?php endforeach; ?>
                                <div class="invoice-field">
                                    <label for="invoice-customer-ref-<?= (int) $invoiceCustomerIndex ?>">Kundens referens</label>
                                    <select id="invoice-customer-ref-<?= (int) $invoiceCustomerIndex ?>" name="customer_ref">
                                        <option value="">Utan referens</option>
                                        <?php foreach ($invoiceCustomer['references'] as $invoiceCustomerReference): ?>
                                            <option value="<?= h($invoiceCustomerReference) ?>"><?= h($invoiceCustomerReference) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button class="btn btn-main" type="submit">Fyll i fakturaunderlaget</button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
                <p class="invoice-catalog-no-results" id="invoice-customer-no-results" hidden>Inga kunder matchar sökningen.</p>
            <?php endif; ?>
        </section>
    <?php elseif ($invoiceView === 'email'): ?>
        <section class="invoice-admin-panel" aria-labelledby="invoice-email-heading">
            <header class="invoice-admin-header">
                <div>
                    <p class="invoice-admin-eyebrow">ADMINPORTAL</p>
                    <h2 id="invoice-email-heading">E-poststatus, SMTP-inställningar och testutskick</h2>
                    <p>Översikten visar försök och svar från den aktiva e-posttransporten. Ett accepterat SMTP-/mail()-anrop betyder inte att mejlet nådde inkorgen. <?= empty($portalIsScopedPwa) ? 'Skadeblanketter skickas alltid bara till rl@tadega.com.' : 'Den här vyn gäller endast aviseringar för fakturaunderlag.' ?></p>
                </div>
                <span class="invoice-admin-badge">Admin</span>
            </header>
            <?php if (is_array($emailTransportStatus)): ?>
                <div class="invoice-email-transport<?= ($emailTransportStatus['transport'] ?? '') === 'unavailable' ? ' is-warning' : '' ?>">
                    <strong>Aktiv e-posttransport:</strong>
                    <?php if (($emailTransportStatus['transport'] ?? '') === 'smtp'): ?>
                        SMTP · <?= h((string) $emailTransportStatus['host']) ?>:<?= (int) $emailTransportStatus['port'] ?> · <?= h(strtoupper((string) $emailTransportStatus['security'])) ?>
                    <?php elseif (($emailTransportStatus['transport'] ?? '') === 'php_mail'): ?>
                        PHP mail() · SMTP-konfiguration saknas i privat storage; den gamla fallbacken används tills SMTP har satts upp.
                    <?php else: ?>
                        Otillgänglig · SMTP-konfigurationen är ogiltig eller kan inte läsas säkert. Utskick stoppas tills lagringen eller konfigurationen har rättats.
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if (!is_array($emailTransportStatus) || ($emailTransportStatus['transport'] ?? '') === 'unavailable'): ?>
                <section class="invoice-smtp-settings" aria-labelledby="invoice-smtp-heading">
                    <h3 id="invoice-smtp-heading">SMTP-inställningar</h3>
                    <p>SMTP-konfigurationen eller skyddet för privat lagring kan inte läsas säkert. Inga inloggningsuppgifter visas och redigering är avstängd. Administratören behöver kontrollera konfigurationsfilen och filrättigheterna via SFTP.</p>
                </section>
            <?php elseif (($emailTransportStatus['locked'] ?? false) === true): ?>
                <section class="invoice-smtp-settings" aria-labelledby="invoice-smtp-heading">
                    <h3 id="invoice-smtp-heading">SMTP-inställningar · låsta</h3>
                    <p>Server: <strong><?= h((string) $emailTransportStatus['host']) ?>:<?= (int) $emailTransportStatus['port'] ?> · <?= h(strtoupper((string) $emailTransportStatus['security'])) ?></strong>. Inloggning: <strong><?= h((string) $emailTransportStatus['username']) ?></strong>. Lösenordet visas aldrig.</p>
                    <p>Utskicken fortsätter medan inställningarna är låsta. Upplåsning kräver den inloggade adminens <?= $isCentralAdminUser ? 'centrala admin-PIN' : 'sexsiffriga PIN' ?>.</p>
                    <div class="invoice-smtp-lock-panel">
                        <form method="post" action="?tab=invoice&amp;invoice_view=email">
                            <input type="hidden" name="action" value="invoice_admin_unlock_email_transport">
                            <?= csrf_field() ?>
                            <div class="invoice-field invoice-smtp-pin">
                                <label for="smtp-unlock-admin-pin"><?= $isCentralAdminUser ? 'Central admin-PIN' : 'Admin-PIN' ?></label>
                                <input id="smtp-unlock-admin-pin" name="admin_pin" type="password" <?= $isCentralAdminUser ? 'minlength="12" maxlength="128" autocomplete="current-password"' : 'inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="off"' ?> required>
                            </div>
                            <div class="invoice-smtp-actions"><button class="btn btn-main" type="submit">Lås upp SMTP-inställningar</button></div>
                        </form>
                    </div>
                </section>
            <?php else: ?>
                <section class="invoice-smtp-settings" aria-labelledby="invoice-smtp-heading">
                    <h3 id="invoice-smtp-heading">SMTP-inställningar</h3>
                    <p>Fyll i avsändarens SMTP-konto. Lösenordet lagras i en privat fil med rättighet 0600 (inte krypterat), visas aldrig igen och fältet lämnas tomt efter sparning. Skadeblanketternas mottagare är fast och kan inte ändras här.</p>
                    <?php if (($emailTransportStatus['transport'] ?? '') === 'smtp' && ($emailTransportStatus['tested'] ?? false) === true): ?>
                        <p class="invoice-smtp-note">Ett manuellt SMTP-test accepterades. Kontrollera även att mejlet kom fram; appen kan bara bekräfta transportens svar.</p>
                        <form method="post" action="?tab=invoice&amp;invoice_view=email">
                            <input type="hidden" name="action" value="invoice_admin_lock_email_transport">
                            <?= csrf_field() ?>
                            <div class="invoice-smtp-actions"><button class="btn btn-outline" type="submit">Lås SMTP-inställningarna</button></div>
                        </form>
                    <?php else: ?>
                        <p class="invoice-smtp-note">
                            <?php if (($emailTransportStatus['transport'] ?? '') === 'smtp'): ?>
                                SMTP-konfigurationen är sparad men saknar ett accepterat test. Använd testknappen för Fakturaunderlag här nedan; transportens svar bekräftar inte leverans till inkorgen.
                            <?php else: ?>
                                När du har sparat, använd testknappen för Fakturaunderlag här nedan för ett manuellt test till den konfigurerade mottagaren.
                            <?php endif; ?>
                            Låsknappen visas först när SMTP-servern har accepterat testet; det är inte en leveransbekräftelse.
                        </p>
                    <?php endif; ?>
                    <form method="post" action="?tab=invoice&amp;invoice_view=email" autocomplete="off">
                        <input type="hidden" name="action" value="invoice_admin_save_email_transport">
                        <?= csrf_field() ?>
                        <div class="invoice-smtp-grid">
                            <div class="invoice-field">
                                <label for="smtp-host">SMTP-server</label>
                                <input id="smtp-host" name="smtp_host" type="text" maxlength="253" value="<?= h((string) ($emailTransportStatus['host'] ?? 'smtp.m101.websupport.se')) ?>" autocomplete="off" autocapitalize="none" spellcheck="false" required>
                            </div>
                            <div class="invoice-field">
                                <label for="smtp-profile">Anslutning</label>
                                <select id="smtp-profile" name="smtp_profile" required>
                                    <option value="ssl465"<?= (int) ($emailTransportStatus['port'] ?? 465) === 465 ? ' selected' : '' ?>>Port 465 · SSL</option>
                                    <option value="tls587"<?= (int) ($emailTransportStatus['port'] ?? 0) === 587 ? ' selected' : '' ?>>Port 587 · TLS</option>
                                </select>
                            </div>
                            <div class="invoice-field">
                                <label for="smtp-username">SMTP-användare / e-postadress</label>
                                <input id="smtp-username" name="smtp_username" type="email" maxlength="254" value="<?= h((string) ($emailTransportStatus['username'] ?? '')) ?>" autocomplete="off" autocapitalize="none" required>
                            </div>
                            <div class="invoice-field">
                                <label for="smtp-password">Brevlådans lösenord</label>
                                <input id="smtp-password" name="smtp_password" type="password" maxlength="360" autocomplete="off" <?= empty($emailTransportStatus['has_password']) ? 'required' : '' ?>>
                                <p class="invoice-help"><?= !empty($emailTransportStatus['has_password']) ? 'Lämna tomt för att behålla det sparade lösenordet; ange ett nytt endast vid byte.' : 'Krävs första gången SMTP konfigureras.' ?></p>
                            </div>
                            <div class="invoice-field">
                                <label for="smtp-from-email">Avsändaradress</label>
                                <input id="smtp-from-email" name="smtp_from_email" type="email" maxlength="254" value="<?= h((string) ($emailTransportStatus['from_email'] ?? '')) ?>" autocomplete="off" autocapitalize="none" required>
                                <p class="invoice-help">Använd en avsändaradress som brevlådan har behörighet att skicka från; vanligtvis samma adress som SMTP-användaren.</p>
                            </div>
                            <div class="invoice-field">
                                <label for="smtp-from-name">Avsändarnamn</label>
                                <input id="smtp-from-name" name="smtp_from_name" type="text" maxlength="120" value="<?= h((string) ($emailTransportStatus['from_name'] ?? 'Tadega Portal')) ?>" required>
                            </div>
                        </div>
                        <div class="invoice-smtp-actions"><button class="btn btn-main" type="submit">Spara SMTP-inställningar</button></div>
                    </form>
                </section>
            <?php endif; ?>
            <?php if (!is_array($emailStatus)): ?>

                <div class="app-alert app-alert-error invoice-email-warning" role="alert">E-poststatuslagringen kunde inte läsas säkert. Inga försökshistorik visas; kontrollera privat lagring under storage.</div>
            <?php else: ?>
                <div class="invoice-email-status-grid">
                    <article class="invoice-email-status-card" aria-labelledby="invoice-email-status-title">
                        <h3 id="invoice-email-status-title">Fakturaunderlag</h3>
                        <p>Mottagare: <strong><?= $emailInvoiceRecipientConfigured ? h($emailInvoicePrimary) : 'Ingen giltig primär mottagare konfigurerad' ?></strong></p>
                        <p>Automatiska aviseringar och testutskick går bara till den konfigurerade primära adressen. Ingen kundkopia skickas.</p>
                        <?php $invoiceEvents = is_array($invoiceEmailEntry['events'] ?? null) ? array_reverse($invoiceEmailEntry['events']) : []; $invoiceLatest = $invoiceEvents[0] ?? null; ?>
                        <dl><dt>Försök totalt</dt><dd><?= (int) ($invoiceEmailEntry['attempts'] ?? 0) ?></dd><dt>Transport accepterat</dt><dd><?= (int) ($invoiceEmailEntry['accepted'] ?? 0) ?></dd><dt>Misslyckade försök</dt><dd><?= (int) ($invoiceEmailEntry['failed'] ?? 0) ?></dd><dt>Ingen giltig adress</dt><dd><?= (int) ($invoiceEmailEntry['not_configured'] ?? 0) ?></dd></dl>
                        <?php if ($invoiceEvents): ?>
                            <p><strong>Senaste försök</strong></p><ol class="invoice-email-events"><?php foreach (array_slice($invoiceEvents, 0, 5) as $emailEvent): $eventAtRaw = is_string($emailEvent['at'] ?? null) ? $emailEvent['at'] : ''; $eventTimestamp = $eventAtRaw !== '' ? strtotime($eventAtRaw) : false; ?><li><strong><?= h(portal_email_status_kind_label((string) ($emailEvent['kind'] ?? ''))) ?></strong><br><?= h($eventTimestamp === false ? 'Okänd tid' : date('Y-m-d H:i', $eventTimestamp)) ?> · <?= h(portal_email_status_transport_label((string) ($emailEvent['transport'] ?? 'php_mail'))) ?> · <?= h(portal_email_status_result_label((string) ($emailEvent['result'] ?? ''))) ?> · <?= h(portal_email_status_code_label((string) ($emailEvent['code'] ?? 'legacy'))) ?></li><?php endforeach; ?></ol>
                        <?php else: ?><p>Inga loggade e-postförsök ännu.</p><?php endif; ?>
                        <form class="invoice-email-test-form" method="post" action="?tab=invoice&amp;invoice_view=email"><input type="hidden" name="action" value="invoice_admin_test_email"><?= csrf_field() ?><button class="btn btn-main" type="submit">Skicka testmejl för fakturaunderlag</button></form>
                        <p>Testmejlet innehåller inga kunduppgifter. Om adressen saknas registreras att inget utskick gjordes.</p>
                    </article>
                    <?php if (empty($portalIsScopedPwa)): ?>
                    <article class="invoice-email-status-card" aria-labelledby="skade-email-status-title">
                        <h3 id="skade-email-status-title">Skadeblanketter</h3>
                        <p>Mottagaren är alltid <strong>rl@tadega.com</strong>. Kundens e-postadress och övriga mottagare används inte.</p>
                        <p>Det manuella testmejlet innehåller inga kunduppgifter och ingen PDF-bilaga.</p>
                        <?php $skadeEvents = is_array($skadeEmailEntry['events'] ?? null) ? array_reverse($skadeEmailEntry['events']) : []; ?>
                        <dl><dt>Försök totalt</dt><dd><?= (int) ($skadeEmailEntry['attempts'] ?? 0) ?></dd><dt>Transport accepterat</dt><dd><?= (int) ($skadeEmailEntry['accepted'] ?? 0) ?></dd><dt>Misslyckade försök</dt><dd><?= (int) ($skadeEmailEntry['failed'] ?? 0) ?></dd><dt>Ingen giltig adress</dt><dd><?= (int) ($skadeEmailEntry['not_configured'] ?? 0) ?></dd></dl>
                        <?php if ($skadeEvents): ?>
                            <p><strong>Senaste försök</strong></p><ol class="invoice-email-events"><?php foreach (array_slice($skadeEvents, 0, 5) as $emailEvent): $eventAtRaw = is_string($emailEvent['at'] ?? null) ? $emailEvent['at'] : ''; $eventTimestamp = $eventAtRaw !== '' ? strtotime($eventAtRaw) : false; ?><li><strong><?= h(portal_email_status_kind_label((string) ($emailEvent['kind'] ?? ''))) ?></strong><br><?= h($eventTimestamp === false ? 'Okänd tid' : date('Y-m-d H:i', $eventTimestamp)) ?> · <?= h(portal_email_status_transport_label((string) ($emailEvent['transport'] ?? 'php_mail'))) ?> · <?= h(portal_email_status_result_label((string) ($emailEvent['result'] ?? ''))) ?> · <?= h(portal_email_status_code_label((string) ($emailEvent['code'] ?? 'legacy'))) ?></li><?php endforeach; ?></ol>
                        <?php else: ?><p>Inga loggade e-postförsök ännu.</p><?php endif; ?>
                        <form class="invoice-email-test-form" method="post" action="?tab=invoice&amp;invoice_view=email"><input type="hidden" name="action" value="skade_admin_test_email"><?= csrf_field() ?><button class="btn btn-main" type="submit">Skicka testmejl till rl@tadega.com</button></form>
                        <p>Efter varje utskick eller manuellt omförsök visas transportens svar; faktisk leverans kan inte bekräftas.</p>
                    </article>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php elseif ($invoiceView === 'debug'): ?>
        <section class="invoice-admin-panel" aria-labelledby="invoice-debug-heading">
            <header class="invoice-admin-header">
                <div>
                    <p class="invoice-admin-eyebrow">ADMINPORTAL</p>
                    <h2 id="invoice-debug-heading">Felsökning · tekniska fel</h2>
                    <p>Visar PHP-/JavaScriptfel och skadeblankettens validerings-, signatur-, PDF- och lagringsfel. Loggen innehåller bara säkra felkoder, fältidentifierare och tekniska metadata — aldrig formulärvärden, kunduppgifter, innehåll eller autentiserings-/CSRF-token. Konton identifieras med ett stabilt pseudonymiserat ID. Poster rensas efter 30 dagar; vyn visar de senaste 200. Skadeblankettens felkoder har en förklaring och föreslagna kontroller. Varje post visar releaseetikett och ett kort SHA-256-byggfingeravtryck; etiketten är ”oversionerad” tills TADEGA_PORTAL_RELEASE konfigureras. Ingen information skickas automatiskt till en AI-chatt. Kopiera vid behov en teknisk sammanfattning och granska den innan du delar den.</p>
                </div>
                <span class="invoice-admin-badge">Admin</span>
            </header>
            <?php if (!is_array($diagnosticEntries)): ?>
                <div class="app-alert app-alert-error invoice-email-warning" role="alert">Felsökningsloggen kunde inte läsas säkert. Kontrollera att logs-mappen och logs/.htaccess finns och att lagringen är åtkomlig.</div>
            <?php elseif (!$diagnosticEntries): ?>
                <p class="invoice-debug-empty">Inga tekniska fel har registrerats under de senaste 30 dagarna.</p>
            <?php else: ?>
                <?php
                $diagnosticTypeLabels = [
                    'php_error' => 'PHP-fel',
                    'php_fatal' => 'Allvarligt PHP-fel',
                    'uncaught_exception' => 'Ohanterat PHP-undantag',
                    'js_error' => 'JavaScriptfel',
                    'js_unhandled_rejection' => 'Ohanterat JavaScriptfel',
                    'app_error' => 'Skadeblankettfel',
                ];
                $diagnosticPageLabels = [
                    'protocol' => 'Serviceprotokoll', 'invoice' => 'Fakturaunderlag',
                    'skadeblankett' => 'Skadeblankett', 'files' => 'PDF-arkiv',
                    'archive' => 'Historik', 'customers' => 'Kundregister',
                    'settings' => 'Inställningar', 'portal' => 'Portal',
                ];
                $diagnosticRoleLabels = [
                    'admin' => 'Admin', 'staff' => 'Medarbetare',
                    'invoice_user' => 'Fakturaanvändare', 'protocol_user' => 'Protokollanvändare',
                ];
                $diagnosticCodeGuidance = [
                    'skade.form_missing' => ['meaning' => 'Formulärdata saknas i begäran till servern.', 'next' => 'Kontrollera formulärets namn/action och att webbläsaren skickar formulärfält. Ladda om sidan och försök igen.'],
                    'skade.client_validation_failed' => ['meaning' => 'Webbläsarens validering stoppade formuläret innan det skickades till servern.', 'next' => 'Använd endast fältidentifierarna nedan. Kontrollera formulärets valideringsregler och JavaScript; inga fältvärden loggas.'],
                    'skade.token_invalid' => ['meaning' => 'Inlämningens engångstoken saknas, har gått ut eller kunde inte hittas.', 'next' => 'Ladda om blanketten. Om det återkommer: kontrollera sessionen och lagringen för skadeblankettens åtgärdstoken.'],
                    'skade.duplicate_submission' => ['meaning' => 'Samma inlämning är redan under behandling.', 'next' => 'Skicka inte in igen direkt. Kontrollera om PDF/posten redan skapades och om första försöket avslutades.'],
                    'skade.token_state_invalid' => ['meaning' => 'Engångstoken har ett oväntat statusvärde.', 'next' => 'Kontrollera tokenlagringens JSON-format och eventuella samtidiga inlämningar.'],
                    'skade.token_processing_failed' => ['meaning' => 'Servern kunde inte markera inlämningstoken som pågående.', 'next' => 'Kontrollera tokenlagring, låsfil och skrivbehörighet; be kunden ladda om innan nytt försök.'],
                    'skade.field_invalid' => ['meaning' => 'Ett fält hade fel typ eller överskred tillåten längd.', 'next' => 'Kontrollera fältidentifierarna nedan och deras HTML-/servergränser; loggen innehåller inte inmatade värden.'],
                    'skade.required_fields_missing' => ['meaning' => 'Ett eller flera obligatoriska fält saknades.', 'next' => 'Kontrollera fältidentifierarna nedan mot den aktuella blankettversionen.'],
                    'skade.registration_invalid' => ['meaning' => 'Registreringsnumret klarade inte formatkontrollen.', 'next' => 'Kontrollera att normaliseringen och gränsen 2–12 tillåtna tecken stämmer med formuläret.'],
                    'skade.email_invalid' => ['meaning' => 'E-postfältet hade ett ogiltigt format trots att fältet är frivilligt.', 'next' => 'Kontrollera webbläsarens och serverns e-postvalidering. Loggen innehåller inte adressen.'],
                    'skade.phone_invalid' => ['meaning' => 'Telefonfältet innehöll färre än fem siffror trots att fältet är frivilligt.', 'next' => 'Kontrollera telefonvalideringen. Loggen innehåller inte numret.'],
                    'skade.accident_date_invalid' => ['meaning' => 'Skadedatumet kunde inte valideras som ett kalenderdatum.', 'next' => 'Kontrollera datumformat, tidszon och serverns datumkontroll.'],
                    'skade.signature_date_invalid' => ['meaning' => 'Signaturdatumet kunde inte valideras.', 'next' => 'Kontrollera datumfältets format och kalenderkontroll.'],
                    'skade.replacement_date_invalid' => ['meaning' => 'Bytesdatumet kunde inte valideras.', 'next' => 'Kontrollera datumfältets format och kalenderkontroll.'],
                    'skade.appointment_invalid' => ['meaning' => 'Den inbokade tiden hade ogiltigt datum- eller tidsformat.', 'next' => 'Kontrollera datetime-local-formatet och serverns datum-/klockslagsregler.'],
                    'skade.numeric_invalid' => ['meaning' => 'Ett antal, en tid eller ett belopp klarade inte talformatet.', 'next' => 'Kontrollera fältidentifierarna nedan, tillåtna decimaler och max antal siffror. Inga talvärden sparas i loggen.'],
                    'skade.integer_invalid' => ['meaning' => 'Ett fält som ska vara heltal innehöll decimaler.', 'next' => 'Kontrollera heltalsregeln för mätarställning eller antal stenskott.'],
                    'skade.choice_invalid' => ['meaning' => 'Ett val eller en markerad flervalsuppgift matchade inte tillåtna alternativ.', 'next' => 'Kontrollera fältidentifierarna och att HTML-värdena överensstämmer med serverns allowlist.'],
                    'skade.checkbox_invalid' => ['meaning' => 'En kryssruta hade ett oväntat format.', 'next' => 'Kontrollera checkboxarnas name/value och att formuläret skickar endast godkända markeringar.'],
                    'skade.declaration_unconfirmed' => ['meaning' => 'Intygandet var inte bekräftat vid inlämning.', 'next' => 'Kontrollera att den obligatoriska bekräftelserutan visas, går att markera och har rätt fältnamn.'],
                    'skade.damage_description_missing' => ['meaning' => 'Alternativet Annat valdes utan en skadebeskrivning.', 'next' => 'Kontrollera villkorsstyrningen mellan skadeorsak och beskrivningsfält.'],
                    'skade.signature_required' => ['meaning' => 'Signaturbild eller signaturbekräftelse saknades eller hade fel format.', 'next' => 'Kontrollera signaturcanvas, export till PNG och bekräftelserutan. Loggen innehåller inte signaturbilden.'],
                    'skade.signature_processor_unavailable' => ['meaning' => 'PHP saknar funktionen som behövs för att kontrollera signaturbilden.', 'next' => 'Kontrollera PHP-bildstödet och att getimagesizefromstring är tillgänglig på webbhotellet.'],
                    'skade.signature_data_invalid' => ['meaning' => 'Signaturens kodade data kunde inte läsas som en giltig PNG.', 'next' => 'Kontrollera PNG-exporten i webbläsaren, base64-formatet och storleksgränserna. Signaturdata loggas inte.'],
                    'skade.signature_image_invalid' => ['meaning' => 'Signaturbildens format eller dimensioner låg utanför tillåtna gränser.', 'next' => 'Kontrollera bildtyp, min-/maxmått och canvasens exportstorlek.'],
                    'skade.template_missing' => ['meaning' => 'En godkänd PDF- eller PNG-mall saknas, är oläsbar eller kunde inte väljas.', 'next' => 'Kontrollera mallfilerna under pdf/ och png/ samt filrättigheter och att filerna inte är symboliska länkar.'],
                    'skade.archive_path_invalid' => ['meaning' => 'PDF-arkivets sökväg klarade inte säkerhetskontrollen.', 'next' => 'Kontrollera pdf/-katalogen och att arkivkatalogerna inte är symboliska länkar.'],
                    'skade.pdf_directory_create_failed' => ['meaning' => 'Servern kunde inte skapa katalogen för PDF-arkivet.', 'next' => 'Kontrollera PHP-processens skrivbehörighet till pdf/ och dess ägare/rättigheter.'],
                    'skade.pdf_directory_unwritable' => ['meaning' => 'PDF-arkivets målkatalog finns men är inte skrivbar för PHP.', 'next' => 'Kontrollera katalogens ägare, rättigheter och Websupports PHP-användare.'],
                    'skade.pdf_output_path_invalid' => ['meaning' => 'PDF-sökvägen låg utanför godkänt arkiv eller klarade inte sökvägskontrollen.', 'next' => 'Kontrollera att målet ligger under pdf/ och att ingen del av sökvägen är en symbolisk länk.'],
                    'skade.pdf_output_unwritable' => ['meaning' => 'PDF-målfilen eller dess katalog är inte skrivbar.', 'next' => 'Kontrollera målväg, ägare och skrivbehörighet för PHP-processen.'],
                    'skade.secure_filename_failed' => ['meaning' => 'Servern kunde inte skapa ett säkert slumpmässigt PDF-filnamn.', 'next' => 'Kontrollera att PHP random_bytes fungerar och att webbhotellets kryptografiska slumptalskälla är tillgänglig.'],
                    'skade.pdf_library_missing' => ['meaning' => 'FPDF-biblioteket hittades inte där applikationen förväntar sig det.', 'next' => 'Kontrollera includes/fpdf/fpdf.php och att filen finns i den uppladdade versionen.'],
                    'skade.pdf_signature_temp_unavailable' => ['meaning' => 'Servern kunde inte skapa eller använda den privata temporärkatalogen för signaturen.', 'next' => 'Kontrollera storage/.htaccess, storage/tmp, katalogrättigheter och att storage/tmp inte är en symbolisk länk. Ta inte bort skyddsfilerna.'],
                    'skade.pdf_signature_write_failed' => ['meaning' => 'Servern kunde inte skriva signaturbilden till den privata temporärfilen.', 'next' => 'Kontrollera skrivbehörighet och ledigt utrymme under storage/tmp. Signaturinnehållet loggas inte.'],
                    'skade.pdf_signature_invalid' => ['meaning' => 'PDF-steget kunde inte läsa signaturens temporära bild som en giltig PNG.', 'next' => 'Kontrollera PHP-bildstödet och bildformatet; dela aldrig signaturfilen i felsökningschatten.'],
                    'skade.pdf_output_not_written' => ['meaning' => 'PDF-biblioteket avslutades utan att skapa en användbar PDF-fil.', 'next' => 'Kontrollera skrivbehörighet, diskutrymme och om FPDF rapporterar ett undantag i felklass/plats.'],
                    'skade.pdf_generation_failed' => ['meaning' => 'PDF-skapandet misslyckades av en annan eller oväntad orsak.', 'next' => 'Använd felklass, källfil/rad och förfrågnings-ID för att kontrollera FPDF, PHP-stöd och mallar; råa undantagsmeddelanden sparas inte.'],
                    'skade.record_id_failed' => ['meaning' => 'Servern kunde inte skapa ett säkert ID för skadeblankettposten.', 'next' => 'Kontrollera PHP random_bytes och webbhotellets slumptalskälla.'],
                    'skade.record_save_failed' => ['meaning' => 'PDF-filen skapades men skadeblankettposten kunde inte sparas i arkivets JSON-lagring.', 'next' => 'Kontrollera storage-/arkivfilens skrivbehörighet, låsfiler och om lagringen är full eller ogiltig.'],
                    'skade.unclassified_failure' => ['meaning' => 'Ett oväntat skadeblankettfel saknar en mer specifik klassificering.', 'next' => 'Sök på förfrågnings-ID och granska de sanerade tekniska uppgifterna; lägg sedan till en specifik allowlistad felkod.'],
                ];
                ?>
                <div class="invoice-debug-list" role="list" aria-label="Senaste tekniska fel">
                    <?php foreach ($diagnosticEntries as $diagnosticEntry): ?>
                        <?php
                        $diagnosticTimestamp = is_string($diagnosticEntry['timestamp_utc'] ?? null) ? $diagnosticEntry['timestamp_utc'] : '';
                        $diagnosticEpoch = $diagnosticTimestamp !== '' ? strtotime($diagnosticTimestamp) : false;
                        $diagnosticType = (string) ($diagnosticEntry['type'] ?? '');
                        $diagnosticTitle = $diagnosticTypeLabels[$diagnosticType] ?? 'Tekniskt fel';
                        $diagnosticCode = is_string($diagnosticEntry['code'] ?? null) ? (string) $diagnosticEntry['code'] : '';
                        $diagnosticGuidance = $diagnosticCodeGuidance[$diagnosticCode] ?? null;
                        $diagnosticMeaning = is_array($diagnosticGuidance) ? (string) ($diagnosticGuidance['meaning'] ?? '') : '';
                        $diagnosticNextCheck = is_array($diagnosticGuidance) ? (string) ($diagnosticGuidance['next'] ?? '') : '';
                        $diagnosticFieldKeys = is_array($diagnosticEntry['fields'] ?? null)
                            ? array_values(array_filter($diagnosticEntry['fields'], 'is_string'))
                            : [];
                        $diagnosticFile = (string) ($diagnosticEntry['file'] ?? 'unknown');
                        $diagnosticExceptionClass = is_string($diagnosticEntry['exception_class'] ?? null) ? (string) $diagnosticEntry['exception_class'] : '';
                        $diagnosticLine = (int) ($diagnosticEntry['line'] ?? 0);
                        $diagnosticLocation = $diagnosticType === 'app_error'
                            ? ($diagnosticCode === 'skade.client_validation_failed' ? 'Skadeblankettens webbläsarvalidering' : 'Skadeblankettens servervalidering/PDF')
                            : $diagnosticFile . ':' . $diagnosticLine;
                        if ($diagnosticType === 'app_error' && $diagnosticExceptionClass !== '' && $diagnosticLine > 0) {
                            $diagnosticLocation .= ' · ' . $diagnosticFile . ':' . $diagnosticLine;
                        } elseif ($diagnosticType !== 'app_error' && (int) ($diagnosticEntry['column'] ?? 0) > 0) {
                            $diagnosticLocation .= ':' . (int) $diagnosticEntry['column'];
                        }
                        $diagnosticPage = (string) ($diagnosticEntry['page'] ?? 'portal');
                        $diagnosticPageLabel = $diagnosticPageLabels[$diagnosticPage] ?? 'Portal';
                        $diagnosticRole = (string) ($diagnosticEntry['actor_role'] ?? '');
                        $diagnosticRelease = is_string($diagnosticEntry['release'] ?? null) ? (string) $diagnosticEntry['release'] : 'unknown';
                        $diagnosticReleaseLabel = $diagnosticRelease === 'unknown' ? 'Okänd' : $diagnosticRelease;
                        $diagnosticBuildId = is_string($diagnosticEntry['build_id'] ?? null) ? (string) $diagnosticEntry['build_id'] : 'unknown';
                        $diagnosticUtcLabel = $diagnosticEpoch === false ? 'Okänd tid' : gmdate('Y-m-d H:i:s', $diagnosticEpoch) . ' UTC';
                        $diagnosticSummary = implode("\n", [
                            'Tadega portal — teknisk felsökningssammanfattning',
                            'Feltyp: ' . $diagnosticTitle,
                            'Felkod: ' . ($diagnosticCode !== '' ? $diagnosticCode : '—'),
                            'Fältidentifierare: ' . ($diagnosticFieldKeys ? implode(', ', $diagnosticFieldKeys) : '—'),
                            $diagnosticMeaning !== '' ? 'Förklaring: ' . $diagnosticMeaning : '',
                            $diagnosticNextCheck !== '' ? 'Nästa kontroll: ' . $diagnosticNextCheck : '',
                            'Tid: ' . $diagnosticUtcLabel,
                            'Release: ' . $diagnosticRelease,
                            'Kodfingeravtryck (Build-ID): ' . $diagnosticBuildId,
                            'Portalområde: ' . $diagnosticPageLabel,
                            'Plats: ' . $diagnosticLocation,
                            'Felklass: ' . ((string) ($diagnosticEntry['exception_class'] ?? '') ?: '—'),
                            'Teknisk nivå: ' . ((string) ($diagnosticEntry['severity'] ?? '') ?: '—'),
                            'Förfrågnings-ID: ' . ((string) ($diagnosticEntry['request_id'] ?? '') ?: '—'),
                        ]);
                        ?>
                        <article class="invoice-debug-card" role="listitem">
                            <div class="invoice-debug-card-top">
                                <h3><?= h($diagnosticTitle) ?></h3>
                                <time datetime="<?= $diagnosticEpoch === false ? '' : h(date(DATE_ATOM, $diagnosticEpoch)) ?>"><?= $diagnosticEpoch === false ? 'Okänd tid' : h(date('Y-m-d H:i:s T', $diagnosticEpoch)) ?></time>
                            </div>
                            <dl class="invoice-debug-grid">
                                <?php if ($diagnosticType === 'app_error'): ?>
                                    <div><dt>Felkod</dt><dd class="invoice-debug-mono"><?= h($diagnosticCode) ?></dd></div>
                                    <div><dt>Fältidentifierare</dt><dd class="invoice-debug-mono"><?= $diagnosticFieldKeys ? h(implode(', ', $diagnosticFieldKeys)) : '—' ?></dd></div>
                                <?php endif; ?>
                                <div><dt>Plats</dt><dd class="invoice-debug-mono"><?= h($diagnosticLocation) ?></dd></div>
                                <div><dt>Portalområde</dt><dd><?= h($diagnosticPageLabel) ?></dd></div>
                                <div><dt>Felklass</dt><dd><?= h((string) ($diagnosticEntry['exception_class'] ?? '') ?: '—') ?></dd></div>
                                <div><dt>Teknisk nivå</dt><dd><?= h((string) ($diagnosticEntry['severity'] ?? '') ?: '—') ?></dd></div>
                                <div><dt>Pseudonymiserat konto-ID</dt><dd class="invoice-debug-mono"><?= ($diagnosticEntry['actor_hash'] ?? '') !== '' ? h((string) $diagnosticEntry['actor_hash']) : '—' ?></dd></div>
                                <div><dt>Roll</dt><dd><?= h($diagnosticRoleLabels[$diagnosticRole] ?? 'Ej inloggad / okänd') ?></dd></div>
                                <div><dt>Förfrågnings-ID</dt><dd class="invoice-debug-mono"><?= h((string) ($diagnosticEntry['request_id'] ?? '') ?: '—') ?></dd></div>
                                <div><dt>Releaseetikett</dt><dd class="invoice-debug-mono"><?= h($diagnosticReleaseLabel) ?></dd></div>
                                <div><dt>Kodfingeravtryck</dt><dd class="invoice-debug-mono"><?= h($diagnosticBuildId) ?></dd></div>
                            </dl>
                            <?php if ($diagnosticType === 'app_error' && is_array($diagnosticGuidance)): ?>
                                <details class="invoice-debug-guidance">
                                    <summary>Förklaring och nästa kontroll</summary>
                                    <div><strong>Vad det betyder</strong><p><?= h($diagnosticMeaning) ?></p></div>
                                    <div><strong>Nästa kontroll</strong><p><?= h($diagnosticNextCheck) ?></p></div>
                                </details>
                            <?php endif; ?>
                            <div class="invoice-debug-actions">
                                <button class="btn btn-main invoice-debug-copy" type="button" data-debug-summary="<?= h(rawurlencode($diagnosticSummary)) ?>">Kopiera felsökningssammanfattning</button>
                                <span class="invoice-debug-copy-status" role="status" aria-live="polite"></span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <script>
                (function () {
                    document.addEventListener('click', function (event) {
                        var button = event.target && event.target.closest ? event.target.closest('.invoice-debug-copy') : null;
                        if (!button) return;
                        var summary = '';
                        try { summary = decodeURIComponent(button.getAttribute('data-debug-summary') || ''); } catch (error) {}
                        var card = button.closest('.invoice-debug-card');
                        var status = card ? card.querySelector('.invoice-debug-copy-status') : null;
                        var showResult = function (copied) {
                            if (status) status.textContent = copied ? 'Sammanfattningen kopierades.' : 'Kopieringen misslyckades. Markera och kopiera texten manuellt.';
                        };
                        var copyFallback = function () {
                            var textarea = document.createElement('textarea');
                            textarea.value = summary;
                            textarea.setAttribute('readonly', 'readonly');
                            textarea.style.position = 'fixed';
                            textarea.style.opacity = '0';
                            document.body.appendChild(textarea);
                            textarea.select();
                            var copied = false;
                            try { copied = document.execCommand('copy') === true; } catch (error) { copied = false; }
                            document.body.removeChild(textarea);
                            return copied;
                        };
                        try {
                            if (navigator.clipboard && window.isSecureContext && typeof navigator.clipboard.writeText === 'function') {
                                navigator.clipboard.writeText(summary).then(function () { showResult(true); }, function () { showResult(copyFallback()); });
                                return;
                            }
                        } catch (error) {}
                        showResult(copyFallback());
                    });
                })();
                </script>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <section class="invoice-admin-panel" aria-labelledby="invoice-admin-heading">
            <header class="invoice-admin-header">
                <div>
                    <p class="invoice-admin-eyebrow">ADMINPORTAL</p>
                    <h2 id="invoice-admin-heading">Konton för fakturaunderlag</h2>
                    <p>Skapa personliga PIN-konton och återställ användares PIN. PIN-kontona gäller endast fakturaunderlaget; övriga flikar styrs av portalens proxyinloggning.</p>
                </div>
                <span class="invoice-admin-badge">Admin</span>
            </header>

            <div class="invoice-admin-create">
                <div class="invoice-admin-subheading">
                    <h3>Skapa användarkonto</h3>
                    <p>Välj en unik inloggning och en tillfällig eller överenskommen sexsiffrig PIN.</p>
                </div>
                <form method="post" action="?tab=invoice&amp;invoice_view=admin" class="invoice-admin-create-form">
                    <input type="hidden" name="action" value="invoice_admin_create_user">
                    <?= csrf_field() ?>
                    <div class="invoice-admin-create-grid">
                        <div class="invoice-field">
                            <label for="invoice-new-username">Användarnamn</label>
                            <input id="invoice-new-username" name="user[username]" maxlength="120" autocomplete="off" required>
                        </div>
                        <div class="invoice-field">
                            <label for="invoice-new-name">Visningsnamn</label>
                            <input id="invoice-new-name" name="user[name]" maxlength="120" autocomplete="name" required>
                        </div>
                        <div class="invoice-field">
                            <label for="invoice-new-pin">Sexsiffrig PIN</label>
                            <input id="invoice-new-pin" type="password" name="user[pin]" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="new-password" placeholder="6 siffror" required>
                        </div>
                        <div class="invoice-field">
                            <label for="invoice-new-role">Behörighet</label>
                            <select id="invoice-new-role" name="user[role]">
                                <option value="staff" selected>Medarbetare</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div class="invoice-admin-create-submit">
                            <button class="btn btn-main" type="submit">Skapa konto</button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="invoice-admin-users">
                <div class="invoice-admin-subheading">
                    <h3>Användarkonton</h3>
                    <p>En PIN-återställning avslutar användarens befintliga inloggningar.</p>
                </div>
                <?php if (!$invoiceUsers): ?>
                    <p class="invoice-admin-empty">Inga konton är registrerade.</p>
                <?php else: ?>
                    <div class="invoice-user-list">
                        <?php foreach ($invoiceUsers as $account): ?>
                            <?php
                            $accountId = (string) ($account['id'] ?? '');
                            $pinInputId = 'invoice-reset-pin-' . substr(hash('sha256', $accountId), 0, 12);
                            $accountIsAdmin = ($account['role'] ?? '') === 'admin';
                            ?>
                            <article class="invoice-user-row">
                                <div class="invoice-user-meta">
                                    <strong><?= h($account['name'] ?? $account['username'] ?? '') ?></strong>
                                    <span class="invoice-user-username">@<?= h($account['username'] ?? '') ?></span>
                                    <div class="invoice-user-badges">
                                        <span class="invoice-role-chip"><?= $accountIsAdmin ? 'Admin' : 'Medarbetare' ?></span>
                                        <?= portal_ui_badge(!empty($account['active']) ? 'Aktivt konto' : 'Inaktivt konto', !empty($account['active']) ? 'success' : 'muted') ?>
                                    </div>
                                </div>
                                <?php if (!empty($account['active']) && $accountId !== $currentInvoiceUserId): ?>
                                    <form method="post" action="?tab=invoice&amp;invoice_view=admin" class="invoice-reset-form">
                                        <input type="hidden" name="action" value="invoice_admin_reset_pin">
                                        <input type="hidden" name="user_id" value="<?= h($accountId) ?>">
                                        <?= csrf_field() ?>
                                        <label class="invoice-sr-only" for="<?= h($pinInputId) ?>">Ny sexsiffrig PIN för <?= h($account['name'] ?? $account['username'] ?? '') ?></label>
                                        <input id="<?= h($pinInputId) ?>" type="password" name="new_pin" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="new-password" placeholder="Ny PIN · 6 siffror" required>
                                        <button class="btn btn-outline" type="submit">Återställ PIN</button>
                                    </form>
                                <?php elseif ($accountId === $currentInvoiceUserId): ?>
                                    <p class="invoice-user-inactive-note">Ditt konto – be en annan admin återställa din PIN.</p>
                                <?php else: ?>
                                    <p class="invoice-user-inactive-note">Kontot är inaktivt.</p>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (empty($portalIsScopedPwa)): ?>
            <div class="invoice-admin-create">
                <div class="invoice-admin-subheading">
                    <h3>Serviceprotokollkonton</h3>
                    <p>Separata användarnamn och sexsiffriga PIN-koder för serviceprotokoll. Dessa konton och sessioner gäller inte Fakturaunderlaget.</p>
                </div>
                <form method="post" action="?tab=invoice&amp;invoice_view=admin" class="invoice-admin-create-form">
                    <input type="hidden" name="action" value="protocol_admin_create_user">
                    <?= csrf_field() ?>
                    <div class="invoice-admin-create-grid">
                        <div class="invoice-field">
                            <label for="protocol-new-username">Användarnamn</label>
                            <input id="protocol-new-username" name="protocol_user[username]" maxlength="120" autocomplete="off" required>
                        </div>
                        <div class="invoice-field">
                            <label for="protocol-new-name">Visningsnamn</label>
                            <input id="protocol-new-name" name="protocol_user[name]" maxlength="120" autocomplete="name" required>
                        </div>
                        <div class="invoice-field">
                            <label for="protocol-new-pin">Sexsiffrig PIN</label>
                            <input id="protocol-new-pin" type="password" name="protocol_user[pin]" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="new-password" placeholder="6 siffror" required>
                        </div>
                        <div class="invoice-field">
                            <label for="protocol-new-role">Protokollbehörighet</label>
                            <select id="protocol-new-role" name="protocol_user[role]">
                                <option value="staff" selected>Medarbetare</option>
                                <option value="admin">Admin – får spara protokollartiklar</option>
                            </select>
                        </div>
                        <div class="invoice-admin-create-submit">
                            <button class="btn btn-main" type="submit">Skapa protokollkonto</button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="invoice-admin-users">
                <div class="invoice-admin-subheading">
                    <h3>Protokollanvändare</h3>
                    <p>Återställning av PIN ogiltigförklarar tidigare protokollsessioner för den användaren.</p>
                </div>
                <?php if (!$protocolUsers): ?>
                    <p class="invoice-admin-empty">Inga protokollkonton är registrerade.</p>
                <?php else: ?>
                    <div class="invoice-user-list">
                        <?php foreach ($protocolUsers as $account): ?>
                            <?php
                            $accountId = (string) ($account['id'] ?? '');
                            $pinInputId = 'protocol-reset-pin-' . substr(hash('sha256', $accountId), 0, 12);
                            $roleSelectId = 'protocol-role-' . substr(hash('sha256', $accountId), 0, 12);
                            ?>
                            <article class="invoice-user-row">
                                <div class="invoice-user-meta">
                                    <strong><?= h($account['name'] ?? $account['username'] ?? '') ?></strong>
                                    <span class="invoice-user-username">@<?= h($account['username'] ?? '') ?></span>
                                    <div class="invoice-user-badges">
                                        <span class="invoice-role-chip">Serviceprotokoll</span>
                                        <?= portal_ui_badge(!empty($account['active']) ? 'Aktivt konto' : 'Inaktivt konto', !empty($account['active']) ? 'success' : 'muted') ?>
                                    </div>
                                </div>
                                <?php if (!empty($account['active'])): ?>
                                    <form method="post" action="?tab=invoice&amp;invoice_view=admin" class="invoice-reset-form">
                                        <input type="hidden" name="action" value="protocol_admin_set_role">
                                        <input type="hidden" name="protocol_user_id" value="<?= h($accountId) ?>">
                                        <?= csrf_field() ?>
                                        <label class="invoice-sr-only" for="<?= h($roleSelectId) ?>">Protokollbehörighet för <?= h($account['name'] ?? $account['username'] ?? '') ?></label>
                                        <select id="<?= h($roleSelectId) ?>" name="protocol_role">
                                            <option value="staff" <?= ($account['role'] ?? 'staff') === 'staff' ? 'selected' : '' ?>>Medarbetare</option>
                                            <option value="admin" <?= ($account['role'] ?? 'staff') === 'admin' ? 'selected' : '' ?>>Admin</option>
                                        </select>
                                        <button class="btn btn-outline" type="submit">Spara roll</button>
                                    </form>
                                    <form method="post" action="?tab=invoice&amp;invoice_view=admin" class="invoice-reset-form">
                                        <input type="hidden" name="action" value="protocol_admin_reset_pin">
                                        <input type="hidden" name="protocol_user_id" value="<?= h($accountId) ?>">
                                        <?= csrf_field() ?>
                                        <label class="invoice-sr-only" for="<?= h($pinInputId) ?>">Ny sexsiffrig protokoll-PIN för <?= h($account['name'] ?? $account['username'] ?? '') ?></label>
                                        <input id="<?= h($pinInputId) ?>" type="password" name="protocol_new_pin" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="new-password" placeholder="Ny PIN · 6 siffror" required>
                                        <button class="btn btn-outline" type="submit">Återställ PIN</button>
                                    </form>
                                <?php else: ?>
                                    <p class="invoice-user-inactive-note">Kontot är inaktivt.</p>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php endif; ?>
            <p class="invoice-admin-footnote">Första fakturaadminkontot skapas i portalens engångssetup när kontolistan är tom. <?= empty($portalIsScopedPwa) ? 'Här hanteras även separata protokollkonton.' : '' ?> Den här administrationsfliken visas bara för admins.</p>
        </section>
    <?php endif; ?>
</section>

<script>
(function () {
    'use strict';

    var form = document.getElementById('invoice-form');
    var table = document.getElementById('invoice-table');
    if (!form || !table) return;

    var body = table.tBodies[0];
    var vatMode = document.getElementById('invoice-vat-mode');
    var addRowButton = document.getElementById('invoice-add-row');
    var rowNote = document.getElementById('invoice-row-note');
    var errorBox = document.getElementById('invoice-form-error');
    var maxRows = 100;
    var nextIndex = body.rows.length;
    var invoiceUnitOptions = <?= json_encode($invoiceArticleUnits, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var formatter = typeof Intl !== 'undefined' && Intl.NumberFormat
        ? new Intl.NumberFormat('sv-SE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
        : null;
    var invoicePage = form.closest('.invoice-page');
    var stepNavigation = document.getElementById('invoice-step-navigation');
    var stepBackButton = document.getElementById('invoice-step-back');
    var stepNextButton = document.getElementById('invoice-step-next');
    var stepSaveButton = document.getElementById('invoice-step-save');
    var stepStatus = document.getElementById('invoice-step-status');
    var stepProgress = document.getElementById('invoice-step-progress');
    var mobileStepQuery = typeof window.matchMedia === 'function' ? window.matchMedia('(max-width: 720px), (max-width: 900px) and (max-height: 560px) and (orientation: landscape)') : null;
    var currentStep = 1;
    var stepNames = ['Kund och uppdrag', 'Arbete och material', 'Granska underlaget'];
    var autosaveMessage = document.getElementById('invoice-autosave-message');
    var autosaveResume = document.getElementById('invoice-autosave-resume');
    var draftIdField = form.querySelector('input[name="draft_id"]');
    var autosaveTimer = null;
    var autosaveRequest = null;
    var autosaveLastSucceeded = false;
    var subtabNavigationPending = false;
    var changeVersion = 0;
    var savedVersion = 0;
    var draftExists = <?= is_array($invoiceDraftData) ? 'true' : 'false' ?>;
    var finalSaving = false;
    var submitAfterAutosave = false;
    var deferredSubmitter = null;

    function setAutosaveMessage(message) {
        if (autosaveMessage) autosaveMessage.textContent = message;
    }

    function formHasDraftContent() {
        if (vatMode && vatMode.value !== 'ex') return true;
        var customer = document.getElementById('invoice-customer');
        var reference = document.getElementById('invoice-reference');
        var description = document.getElementById('invoice-description');
        if ([customer, reference, description].some(function (field) { return field && field.value.trim() !== ''; })) return true;
        return Array.prototype.some.call(body.rows, function (row) {
            return Array.prototype.some.call(row.querySelectorAll('input'), function (input) { return input.value.trim() !== ''; });
        });
    }

    function sendAutosave() {
        if (finalSaving) return Promise.resolve(false);
        autosaveTimer = null;
        if (autosaveRequest) return autosaveRequest;
        if (!formHasDraftContent() && !draftExists) {
            savedVersion = changeVersion;
            setAutosaveMessage('Skriv i formuläret för att skapa ett utkast.');
            return Promise.resolve(true);
        }
        if (!draftIdField || !draftIdField.value) {
            setAutosaveMessage('Autosparning är inte tillgänglig just nu. Spara underlaget manuellt.');
            autosaveLastSucceeded = false;
            return Promise.resolve(false);
        }
        var sentVersion = changeVersion;
        var requestBody = new URLSearchParams(new FormData(form));
        requestBody.set('action', 'autosave_draft');
        setAutosaveMessage('Sparar utkast…');
        autosaveLastSucceeded = false;
        autosaveRequest = fetch('?tab=invoice', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: requestBody
        }).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok || !data || data.ok !== true) throw new Error((data && data.code) || 'autosave_failed');
                return data;
            });
        }).then(function (data) {
            autosaveLastSucceeded = true;
            savedVersion = sentVersion;
            if (data.deleted) {
                draftExists = false;
                if (autosaveResume) autosaveResume.hidden = true;
                setAutosaveMessage('Utkastet har raderats eftersom formuläret är tomt.');
                return;
            }
            if (data.draft_id) {
                draftExists = true;
                if (autosaveResume) {
                    autosaveResume.href = '?tab=invoice&draft_id=' + encodeURIComponent(data.draft_id);
                    autosaveResume.hidden = false;
                }
                setAutosaveMessage('Utkastet har sparats automatiskt.');
                return;
            }
            setAutosaveMessage('Inga utkastuppgifter att spara ännu.');
        }).catch(function () {
            autosaveLastSucceeded = false;
            setAutosaveMessage('Autosparning kunde inte bekräftas. Spara underlaget innan du lämnar sidan.');
        }).finally(function () {
            autosaveRequest = null;
            if (submitAfterAutosave) {
                submitAfterAutosave = false;
                finalSaving = true;
                if (window.TadegaPortalSubmitState) {
                    window.TadegaPortalSubmitState.markBusy(form, 'Sparar underlaget…', deferredSubmitter);
                }
                deferredSubmitter = null;
                HTMLFormElement.prototype.submit.call(form);
                return;
            }
            if (changeVersion > sentVersion && !finalSaving) autosaveTimer = window.setTimeout(sendAutosave, 400);
        });
        return autosaveRequest;
    }

    function flushAutosaveForNavigation() {
        if (autosaveTimer) {
            window.clearTimeout(autosaveTimer);
            autosaveTimer = null;
        }
        if (finalSaving) return Promise.resolve(false);
        if (!formHasDraftContent() && !draftExists) return Promise.resolve(true);
        if (autosaveRequest) {
            var activeRequest = autosaveRequest;
            return activeRequest.then(function () {
                if (!autosaveLastSucceeded) return false;
                if (changeVersion > savedVersion) return flushAutosaveForNavigation();
                return true;
            });
        }
        if (draftExists && changeVersion <= savedVersion) return Promise.resolve(true);
        return sendAutosave().then(function () {
            if (!autosaveLastSucceeded) return false;
            if (changeVersion > savedVersion) return flushAutosaveForNavigation();
            return true;
        });
    }

    function scheduleAutosave() {
        if (finalSaving) return;
        changeVersion++;
        if (autosaveTimer) window.clearTimeout(autosaveTimer);
        autosaveTimer = window.setTimeout(sendAutosave, 700);
    }

    document.addEventListener('click', function (event) {
        var link = event.target && event.target.closest ? event.target.closest('[data-invoice-nav]') : null;
        if (!link || (!formHasDraftContent() && !draftExists)) return;
        event.preventDefault();
        if (subtabNavigationPending) return;
        subtabNavigationPending = true;
        flushAutosaveForNavigation().then(function (saved) {
            if (!saved || changeVersion > savedVersion) {
                var proceed = window.confirm('Autosparningen kunde inte bekräftas. Om du lämnar sidan nu kan ändringarna gå förlorade. Gå vidare ändå?');
                if (!proceed) {
                    subtabNavigationPending = false;
                    return;
                }
            }
            window.location.href = link.href;
        }).catch(function () {
            var proceed = window.confirm('Autosparningen kunde inte bekräftas. Om du lämnar sidan nu kan ändringarna gå förlorade. Gå vidare ändå?');
            if (proceed) {
                window.location.href = link.href;
            } else {
                subtabNavigationPending = false;
            }
        });
    });

    function money(value) {
        var amount = Number.isFinite(value) ? value : 0;
        return (formatter ? formatter.format(amount) : amount.toFixed(2).replace('.', ',')) + ' kr';
    }

    function readNumber(input) {
        var value = String(input.value || '').trim().replace(',', '.');
        if (value === '') return 0;
        var number = Number(value);
        return Number.isFinite(number) && number > 0 ? number : 0;
    }

    function roundCurrency(value) {
        return Math.round((value + Number.EPSILON) * 100) / 100;
    }

    function recalculate() {
        Array.prototype.forEach.call(body.rows, function (row) {
            var quantity = readNumber(row.querySelector('.invoice-item-qty'));
            var price = readNumber(row.querySelector('.invoice-item-price'));
            var rowTotal = roundCurrency(quantity * price);
            row.querySelector('.invoice-line-total').textContent = money(rowTotal);
        });

        Array.prototype.forEach.call(document.querySelectorAll('.invoice-price-mode'), function (element) {
            element.textContent = vatMode.value === 'ex' ? 'exkl.' : 'inkl.';
        });
        updateReview();
        updateCatalogSourceRows();
    }

    function setReviewText(id, value, fallback) {
        var element = document.getElementById(id);
        if (!element) return;
        var text = typeof value === 'string' ? value.trim() : '';
        element.textContent = text || fallback;
    }

    function formatReviewDate(value) {
        var parts = String(value || '').split('-');
        if (parts.length !== 3 || parts[0].length !== 4) return value || '';
        return parts[2] + '.' + parts[1] + '.' + parts[0];
    }

    function updateReview() {
        var list = document.getElementById('invoice-review-items');
        if (!list) return;

        setReviewText('invoice-review-customer', document.getElementById('invoice-customer').value, 'Inte angivet');
        setReviewText('invoice-review-date', formatReviewDate(document.getElementById('invoice-date').value), 'Inte angivet');
        setReviewText('invoice-review-reference', document.getElementById('invoice-reference').value, 'Inte angivet');
        setReviewText('invoice-review-description', document.getElementById('invoice-description').value, 'Inte angivet');

        var fortnoxField = document.getElementById('invoice-fortnox-number');
        var fortnoxValue = fortnoxField && typeof fortnoxField.value === 'string' ? fortnoxField.value : (fortnoxField ? fortnoxField.textContent : '');
        setReviewText('invoice-review-fortnox', fortnoxValue, 'Inte registrerat ännu');
        var fortnoxDateField = document.getElementById('invoice-fortnox-date');
        var fortnoxDateValue = fortnoxDateField && typeof fortnoxDateField.value === 'string' ? fortnoxDateField.value : (fortnoxDateField ? fortnoxDateField.textContent : '');
        setReviewText('invoice-review-fortnox-date', formatReviewDate(fortnoxDateValue), 'Inte registrerat ännu');

        while (list.firstChild) list.removeChild(list.firstChild);
        var visibleRows = 0;
        Array.prototype.forEach.call(body.rows, function (row) {
            var description = row.querySelector('.invoice-item-name').value.trim();
            var quantityInput = row.querySelector('.invoice-item-qty');
            var unitSelect = row.querySelector('.invoice-item-unit');
            var priceInput = row.querySelector('.invoice-item-price');
            var quantityText = quantityInput.value.trim();
            var unitText = unitSelect && unitSelect.selectedOptions.length ? unitSelect.selectedOptions[0].textContent : 'st';
            var priceText = priceInput.value.trim();
            if (!description && !quantityText && !priceText) return;

            var item = document.createElement('li');
            item.className = 'invoice-review-item';
            var name = document.createElement('span');
            name.className = 'invoice-review-item-name';
            name.textContent = description || 'Arbetsrad utan beskrivning';
            var calculation = document.createElement('span');
            calculation.className = 'invoice-review-item-calculation';
            calculation.textContent = (quantityText || '0') + ' ' + unitText + ' × ' + money(readNumber(priceInput)) + ' per enhet';
            var total = document.createElement('strong');
            total.className = 'invoice-review-item-total';
            total.textContent = row.querySelector('.invoice-line-total').textContent;
            item.appendChild(name);
            item.appendChild(calculation);
            item.appendChild(total);
            list.appendChild(item);
            visibleRows++;
        });
        if (visibleRows === 0) {
            var empty = document.createElement('li');
            empty.className = 'invoice-review-item-calculation';
            empty.textContent = 'Inga arbetsrader har lagts till.';
            list.appendChild(empty);
        }

    }

    function activateStep(step, moveFocus) {
        if (!invoicePage || !mobileStepQuery || !mobileStepQuery.matches) return;
        currentStep = Math.max(1, Math.min(3, step));
        invoicePage.classList.add('invoice-wizard-ready');
        invoicePage.setAttribute('data-current-step', String(currentStep));
        if (stepNavigation) stepNavigation.setAttribute('data-step', String(currentStep));

        Array.prototype.forEach.call(invoicePage.querySelectorAll('[data-invoice-step]'), function (panel) {
            panel.classList.toggle('is-active', Number(panel.getAttribute('data-invoice-step')) === currentStep);
        });
        if (stepBackButton) stepBackButton.hidden = currentStep === 1;
        if (stepNextButton) {
            stepNextButton.hidden = currentStep === 3;
            stepNextButton.textContent = currentStep === 1 ? 'Fortsätt till arbetsrader →' : 'Granska underlaget →';
        }
        if (stepSaveButton) stepSaveButton.hidden = currentStep !== 3;
        if (stepStatus) stepStatus.textContent = 'Steg ' + currentStep + ' av 3: ' + stepNames[currentStep - 1];
        if (stepProgress) {
            stepProgress.setAttribute('aria-valuenow', String(currentStep));
            var progressFill = stepProgress.querySelector('span');
            if (progressFill) progressFill.style.width = (currentStep / 3 * 100) + '%';
        }
        if (currentStep === 3) updateReview();

        if (moveFocus) {
            var headingIds = ['invoice-customer-heading', 'invoice-lines-heading', 'invoice-mobile-review-heading'];
            var heading = document.getElementById(headingIds[currentStep - 1]);
            if (heading) heading.focus();
        }
    }

    function syncMobileWizard() {
        if (!invoicePage || !mobileStepQuery) return;
        if (mobileStepQuery.matches) {
            invoicePage.classList.add('invoice-wizard-ready');
            activateStep(currentStep, false);
        } else {
            invoicePage.classList.remove('invoice-wizard-ready');
            invoicePage.removeAttribute('data-current-step');
            Array.prototype.forEach.call(invoicePage.querySelectorAll('[data-invoice-step]'), function (panel) {
                panel.classList.remove('is-active');
            });
        }
    }

    function updateRowControls() {
        var full = body.rows.length >= maxRows;
        addRowButton.disabled = full;
        rowNote.textContent = full ? 'Max 100 rader.' : '';
        Array.prototype.forEach.call(body.rows, function (row, index) {
            row.querySelector('.invoice-item-name').setAttribute('aria-label', 'Beskrivning, rad ' + (index + 1));
            row.querySelector('.invoice-item-qty').setAttribute('aria-label', 'Antal, rad ' + (index + 1));
            row.querySelector('.invoice-item-unit').setAttribute('aria-label', 'Enhet, rad ' + (index + 1));
            row.querySelector('.invoice-item-price').setAttribute('aria-label', 'Pris per enhet i kronor, rad ' + (index + 1));
            row.querySelector('[data-remove-line]').setAttribute('aria-label', 'Ta bort rad ' + (index + 1));
        });
    }

    function createCell(label, element, className) {
        var cell = document.createElement('td');
        cell.setAttribute('data-label', label);
        if (className) cell.className = className;
        cell.appendChild(element);
        return cell;
    }

    function createInput(className, type, name, ariaLabel, placeholder) {
        var input = document.createElement('input');
        input.className = className;
        input.type = type;
        input.name = name;
        input.setAttribute('aria-label', ariaLabel);
        input.setAttribute('aria-describedby', 'invoice-line-field-help');
        if (placeholder) input.placeholder = placeholder;
        if (type === 'number') {
            input.min = '0';
            input.step = '0.01';
            input.inputMode = 'decimal';
            input.max = className === 'invoice-item-qty' ? '100000' : '10000000';
        } else {
            input.maxLength = 200;
        }
        return input;
    }

    function createUnitSelect(name, ariaLabel, currentValue) {
        var select = document.createElement('select');
        select.className = 'invoice-item-unit';
        select.name = name;
        select.setAttribute('aria-label', ariaLabel);
        select.setAttribute('aria-describedby', 'invoice-line-field-help');
        Object.keys(invoiceUnitOptions || {}).forEach(function (unitValue) {
            var option = document.createElement('option');
            option.value = unitValue;
            option.textContent = invoiceUnitOptions[unitValue];
            select.appendChild(option);
        });
        select.value = Object.prototype.hasOwnProperty.call(invoiceUnitOptions || {}, currentValue) ? currentValue : 'st';
        return select;
    }

    function addRow(name, quantity, price, unit) {
        if (body.rows.length >= maxRows) {
            rowNote.textContent = 'Du kan lägga till högst 100 rader.';
            return null;
        }

        var index = nextIndex++;
        var rowNumber = body.rows.length + 1;
        var row = document.createElement('tr');
        var nameInput = createInput('invoice-item-name', 'text', 'items[' + index + '][art]', 'Beskrivning, rad ' + rowNumber, 'Ex. arbete, reservdel eller resa');
        var quantityInput = createInput('invoice-item-qty', 'number', 'items[' + index + '][qty]', 'Antal, rad ' + rowNumber, '');
        var unitSelect = createUnitSelect('items[' + index + '][unit]', 'Enhet, rad ' + rowNumber, unit || 'st');
        var priceInput = createInput('invoice-item-price', 'number', 'items[' + index + '][price]', 'Pris per enhet i kronor, rad ' + rowNumber, '');
        var total = document.createElement('span');
        total.className = 'invoice-line-total';
        total.textContent = money(0);
        var removeButton = document.createElement('button');
        removeButton.className = 'invoice-remove';
        removeButton.type = 'button';
        removeButton.setAttribute('data-remove-line', '');
        removeButton.setAttribute('aria-label', 'Ta bort rad ' + rowNumber);
        removeButton.textContent = 'Ta bort';

        if (name !== undefined && name !== null) nameInput.value = name;
        if (quantity !== undefined && quantity !== null && quantity !== '') quantityInput.value = quantity;
        if (price !== undefined && price !== null && price !== '') priceInput.value = price;

        row.appendChild(createCell('Beskrivning', nameInput));
        row.appendChild(createCell('Antal', quantityInput));
        row.appendChild(createCell('Enhet', unitSelect));
        row.appendChild(createCell('Pris per enhet', priceInput));
        row.appendChild(createCell('Radbelopp', total, 'invoice-line-total-cell'));
        row.appendChild(createCell('Ta bort', removeButton, 'invoice-remove-cell'));
        body.appendChild(row);
        updateRowControls();
        recalculate();
        return row;
    }

    var catalogArticleSelect = document.getElementById('invoice-catalog-article');
    var catalogArticleAddButton = document.getElementById('invoice-add-catalog-article');
    var catalogArticleStatus = document.getElementById('invoice-article-add-status');
    var catalogSourceRowSelect = document.getElementById('invoice-catalog-source-row');
    var catalogSaveRowButton = document.getElementById('invoice-save-catalog-row');
    var catalogSaveStatus = document.getElementById('invoice-catalog-save-status');
    var selectedCatalogSourceRow = null;
    var catalogSavePending = false;

    function readCatalogSourceArticle(row) {
        if (!row) return null;
        var nameField = row.querySelector('.invoice-item-name');
        var unitField = row.querySelector('.invoice-item-unit');
        var priceField = row.querySelector('.invoice-item-price');
        var name = nameField ? nameField.value.trim() : '';
        var unit = unitField ? unitField.value : '';
        var priceRaw = priceField ? priceField.value.trim() : '';
        if (
            name === '' || name.length > 200 ||
            !Object.prototype.hasOwnProperty.call(invoiceUnitOptions || {}, unit) ||
            !/^\d{1,10}(?:[.,]\d{1,2})?$/.test(priceRaw)
        ) return null;
        var price = Number(priceRaw.replace(',', '.'));
        if (!Number.isFinite(price) || price < 0 || price > 10000000) return null;
        return { name: name, unit: unit, price: price.toFixed(2) };
    }

    function updateCatalogSourceRows() {
        if (!catalogSourceRowSelect) return;
        var previousRow = selectedCatalogSourceRow;
        if (previousRow && previousRow.parentNode !== body) previousRow = null;
        while (catalogSourceRowSelect.options.length > 1) catalogSourceRowSelect.remove(1);

        var selectedOptionValue = '';
        Array.prototype.forEach.call(body.rows, function (row, index) {
            var article = readCatalogSourceArticle(row);
            if (!article) return;
            var option = document.createElement('option');
            option.value = String(index);
            var formattedPrice = formatter ? formatter.format(Number(article.price)) : article.price.replace('.', ',');
            option.textContent = 'Rad ' + (index + 1) + ': ' + article.name + ' · ' + (invoiceUnitOptions[article.unit] || article.unit) + ' · ' + formattedPrice + ' kr per enhet';
            catalogSourceRowSelect.appendChild(option);
            if (row === previousRow) selectedOptionValue = option.value;
        });

        if (selectedOptionValue !== '') {
            catalogSourceRowSelect.value = selectedOptionValue;
            selectedCatalogSourceRow = previousRow;
        } else {
            catalogSourceRowSelect.value = '';
            selectedCatalogSourceRow = null;
        }
        if (catalogSaveRowButton) catalogSaveRowButton.disabled = catalogSavePending || !selectedCatalogSourceRow;
    }

    if (catalogSourceRowSelect) {
        catalogSourceRowSelect.addEventListener('change', function () {
            var rowIndex = this.value === '' ? -1 : Number(this.value);
            selectedCatalogSourceRow = Number.isInteger(rowIndex) && rowIndex >= 0 ? (body.rows[rowIndex] || null) : null;
            if (catalogSaveRowButton) catalogSaveRowButton.disabled = catalogSavePending || !selectedCatalogSourceRow;
            if (catalogSaveStatus) catalogSaveStatus.textContent = '';
        });
    }

    if (catalogSourceRowSelect && catalogSaveRowButton) {
        catalogSaveRowButton.addEventListener('click', function () {
            if (catalogSavePending || finalSaving) return;
            var sourceRow = selectedCatalogSourceRow;
            var article = sourceRow && sourceRow.parentNode === body ? readCatalogSourceArticle(sourceRow) : null;
            if (!article) {
                if (catalogSaveStatus) catalogSaveStatus.textContent = 'Välj en rad med beskrivning och giltigt pris först.';
                updateCatalogSourceRows();
                catalogSourceRowSelect.focus();
                return;
            }
            var csrfInput = form.querySelector('input[name="csrf_token"]');
            if (!csrfInput || !csrfInput.value) {
                if (catalogSaveStatus) catalogSaveStatus.textContent = 'Säkerhetskontrollen saknas. Ladda om sidan och försök igen.';
                return;
            }

            var requestBody = new URLSearchParams();
            requestBody.set('action', 'invoice_catalog_quick_save');
            requestBody.set('csrf_token', csrfInput.value);
            requestBody.set('article[name]', article.name);
            requestBody.set('article[unit]', article.unit);
            requestBody.set('article[price]', article.price);
            catalogSavePending = true;
            catalogSaveRowButton.disabled = true;
            if (catalogSaveStatus) catalogSaveStatus.textContent = 'Sparar artikeln…';

            fetch('?tab=invoice', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: requestBody
            }).then(function (response) {
                return response.json().catch(function () { return null; }).then(function (data) {
                    if (!response.ok || !data || data.ok !== true) {
                        throw new Error(data && typeof data.code === 'string' ? data.code : 'request_failed');
                    }
                    return data;
                });
            }).then(function () {
                selectedCatalogSourceRow = null;
                catalogSourceRowSelect.value = '';
                if (catalogSaveStatus) catalogSaveStatus.textContent = 'Artikeln sparades. Den visas i artikelkatalogen efter omladdning eller när ett nytt underlag öppnas.';
            }).catch(function (error) {
                var messages = {
                    invalid_name: 'Artikelns beskrivning kan inte sparas. Kontrollera texten och försök igen.',
                    invalid_unit: 'Enheten är ogiltig. Välj en av de förvalda enheterna.',
                    invalid_price: 'Priset är ogiltigt. Ange ett belopp mellan 0 och 10 000 000 kr.',
                    catalog_full: 'Artikelkatalogen har nått sin gräns på 500 artiklar.',
                    storage_error: 'Artikelkatalogen kunde inte sparas. Försök igen senare.',
                    admin_required: 'Endast administratörer kan spara en ny artikel från formuläret.',
                    login_required: 'Inloggningen har gått ut. Logga in igen och försök.',
                    invalid_tab: 'Artikelkatalogen kunde inte nås från den här appvyn.'
                };
                if (catalogSaveStatus) catalogSaveStatus.textContent = messages[error && error.message] || 'Artikeln kunde inte sparas. Kontrollera anslutningen och försök igen.';
            }).finally(function () {
                catalogSavePending = false;
                updateCatalogSourceRows();
            });
        });
    }

    if (catalogArticleSelect && catalogArticleAddButton) {
        catalogArticleAddButton.addEventListener('click', function () {
            var option = catalogArticleSelect.options[catalogArticleSelect.selectedIndex];
            var name = option ? option.getAttribute('data-name') || '' : '';
            var unit = option ? option.getAttribute('data-unit') || 'st' : 'st';
            var price = option ? Number(option.getAttribute('data-price')) : NaN;
            if (!Object.prototype.hasOwnProperty.call(invoiceUnitOptions || {}, unit)) unit = 'st';
            if (!name || !Number.isFinite(price) || price < 0) {
                catalogArticleStatus.textContent = 'Välj en artikel först.';
                catalogArticleSelect.focus();
                return;
            }

            var targetRow = null;
            Array.prototype.some.call(body.rows, function (row) {
                var nameField = row.querySelector('.invoice-item-name');
                var quantityField = row.querySelector('.invoice-item-qty');
                var priceField = row.querySelector('.invoice-item-price');
                if (!nameField.value.trim() && !quantityField.value.trim() && !priceField.value.trim()) {
                    targetRow = row;
                    return true;
                }
                return false;
            });

            if (targetRow) {
                targetRow.querySelector('.invoice-item-name').value = name;
                targetRow.querySelector('.invoice-item-qty').value = '1';
                targetRow.querySelector('.invoice-item-unit').value = unit;
                targetRow.querySelector('.invoice-item-price').value = price.toFixed(2);
                updateRowControls();
                recalculate();
            } else {
                targetRow = addRow(name, '1', price.toFixed(2), unit);
            }
            if (!targetRow) return;
            catalogArticleStatus.textContent = 'Artikeln lades till med enhet ' + invoiceUnitOptions[unit] + ' och antal 1. Ändra vid behov.';
            catalogArticleSelect.value = '';
            targetRow.querySelector('.invoice-item-qty').focus();
            scheduleAutosave();
        });
    }

    table.addEventListener('input', recalculate);
    table.addEventListener('change', recalculate);
    table.addEventListener('click', function (event) {
        var button = event.target.closest('[data-remove-line]');
        if (!button) return;

        var row = button.closest('tr');
        if (body.rows.length > 1) {
            row.remove();
        } else {
            Array.prototype.forEach.call(row.querySelectorAll('input'), function (input) {
                input.value = '';
            });
            row.querySelector('.invoice-item-name').focus();
        }
        updateRowControls();
        recalculate();
        scheduleAutosave();
    });

    addRowButton.addEventListener('click', function () {
        var row = addRow('', '', '');
        if (row) row.querySelector('.invoice-item-name').focus();
    });

    var fortnoxNumberInput = document.getElementById('invoice-fortnox-number');
    var fortnoxDateInput = document.getElementById('invoice-fortnox-date');
    if (fortnoxNumberInput && fortnoxDateInput && typeof fortnoxNumberInput.value === 'string' && typeof fortnoxDateInput.value === 'string') {
        var syncFortnoxDateRequirement = function () {
            fortnoxDateInput.required = fortnoxNumberInput.value.trim() !== '';
        };
        syncFortnoxDateRequirement();
        fortnoxNumberInput.addEventListener('input', syncFortnoxDateRequirement);
    }

    var vatIncludedCheckbox = document.getElementById('invoice-vat-included');
    if (vatIncludedCheckbox) {
        vatIncludedCheckbox.addEventListener('change', function () {
            vatMode.value = this.checked ? 'inc' : 'ex';
            recalculate();
        });
    }

    var customerSelect = document.getElementById('invoice-customer-select');
    var customerReferenceSelect = document.getElementById('invoice-customer-reference-select');
    if (customerSelect && customerReferenceSelect) {
        var invoiceCustomerField = document.getElementById('invoice-customer');
        var invoiceReferenceField = document.getElementById('invoice-reference');

        function updateCustomerReferenceOptions(preserveCurrent) {
            while (customerReferenceSelect.firstChild) customerReferenceSelect.removeChild(customerReferenceSelect.firstChild);
            var customerOption = customerSelect.options[customerSelect.selectedIndex];
            if (!customerOption || !customerOption.value) {
                var promptOption = document.createElement('option');
                promptOption.value = '';
                promptOption.textContent = 'Välj kund först';
                customerReferenceSelect.appendChild(promptOption);
                customerReferenceSelect.disabled = true;
                return;
            }

            var references = [];
            try {
                references = JSON.parse(customerOption.getAttribute('data-references') || '[]');
            } catch (error) {
                references = [];
            }
            if (!Array.isArray(references)) references = [];

            var noReferenceOption = document.createElement('option');
            noReferenceOption.value = '';
            noReferenceOption.textContent = 'Utan referens';
            customerReferenceSelect.appendChild(noReferenceOption);
            references.forEach(function (reference) {
                if (typeof reference !== 'string' || !reference.trim()) return;
                var referenceOption = document.createElement('option');
                referenceOption.value = reference;
                referenceOption.textContent = reference;
                customerReferenceSelect.appendChild(referenceOption);
            });

            var currentReference = preserveCurrent ? customerReferenceSelect.getAttribute('data-current-reference') || '' : '';
            var referenceExists = Array.prototype.some.call(customerReferenceSelect.options, function (referenceOption) {
                return referenceOption.value === currentReference;
            });
            if (currentReference && !referenceExists) {
                var currentOption = document.createElement('option');
                currentOption.value = currentReference;
                currentOption.textContent = 'Behåll befintlig referens: ' + currentReference;
                customerReferenceSelect.appendChild(currentOption);
            }
            customerReferenceSelect.value = currentReference;
            customerReferenceSelect.disabled = false;
        }

        customerSelect.addEventListener('change', function () {
            var option = customerSelect.options[customerSelect.selectedIndex];
            if (!option || !option.value) {
                customerReferenceSelect.setAttribute('data-current-reference', '');
                updateCustomerReferenceOptions(false);
                invoiceCustomerField.value = '';
                invoiceReferenceField.value = '';
                return;
            }
            customerReferenceSelect.setAttribute('data-current-reference', '');
            updateCustomerReferenceOptions(false);
            invoiceCustomerField.value = option.getAttribute('data-name') || option.textContent || '';
            invoiceReferenceField.value = '';
        });

        customerReferenceSelect.addEventListener('change', function () {
            invoiceReferenceField.value = customerReferenceSelect.value || '';
        });

        updateCustomerReferenceOptions(true);
    }

    document.getElementById('invoice-print').addEventListener('click', function () {
        window.print();
    });

    function showFormError(message) {
        errorBox.textContent = message;
        errorBox.hidden = false;
        errorBox.focus();
    }

    function getInvoiceRowValidationMessage() {
        var completedRows = 0;
        var partialRow = false;
        Array.prototype.forEach.call(body.rows, function (row) {
            var description = row.querySelector('.invoice-item-name').value.trim();
            var quantity = row.querySelector('.invoice-item-qty').value.trim();
            var price = row.querySelector('.invoice-item-price').value.trim();
            if (!description && !quantity && !price) return;
            if (!description || quantity === '' || price === '') {
                partialRow = true;
                return;
            }
            completedRows++;
        });

        if (partialRow) return 'Fyll i beskrivning, antal och pris på varje påbörjad rad – eller töm raden helt.';
        if (completedRows === 0) return 'Lägg till minst en rad med arbete eller material innan du fortsätter.';
        return '';
    }

    if (stepNextButton) {
        stepNextButton.addEventListener('click', function () {
            if (!mobileStepQuery || !mobileStepQuery.matches) return;
            if (currentStep === 2) {
                var validationMessage = getInvoiceRowValidationMessage();
                if (validationMessage) {
                    showFormError(validationMessage);
                    return;
                }
            }
            errorBox.hidden = true;
            errorBox.textContent = '';
            activateStep(currentStep + 1, true);
        });
    }

    if (stepBackButton) {
        stepBackButton.addEventListener('click', function () {
            if (!mobileStepQuery || !mobileStepQuery.matches) return;
            activateStep(currentStep - 1, true);
        });
    }

    if (mobileStepQuery) {
        if (typeof mobileStepQuery.addEventListener === 'function') {
            mobileStepQuery.addEventListener('change', syncMobileWizard);
        } else if (typeof mobileStepQuery.addListener === 'function') {
            mobileStepQuery.addListener(syncMobileWizard);
        }
    }

    form.addEventListener('input', function () {
        errorBox.hidden = true;
        errorBox.textContent = '';
        updateReview();
        scheduleAutosave();
    });
    form.addEventListener('change', function () {
        updateReview();
        scheduleAutosave();
    });

    form.addEventListener('submit', function (event) {
        if (finalSaving) {
            event.preventDefault();
            return;
        }
        var validationMessage = getInvoiceRowValidationMessage();
        if (validationMessage) {
            event.preventDefault();
            if (mobileStepQuery && mobileStepQuery.matches && currentStep !== 2) {
                activateStep(2, true);
            }
            showFormError(validationMessage);
            return;
        }
        if (autosaveTimer) {
            window.clearTimeout(autosaveTimer);
            autosaveTimer = null;
        }
        if (autosaveRequest) {
            event.preventDefault();
            finalSaving = true;
            submitAfterAutosave = true;
            deferredSubmitter = event.submitter || null;
            if (window.TadegaPortalSubmitState) {
                window.TadegaPortalSubmitState.markBusy(form, 'Sparar underlaget…', deferredSubmitter);
            }
            setAutosaveMessage('Slutför autosparning innan underlaget sparas…');
            return;
        }
        finalSaving = true;
    });

    updateRowControls();
    recalculate();
    syncMobileWizard();
    window.addEventListener('pageshow', function () {
        finalSaving = false;
        submitAfterAutosave = false;
        deferredSubmitter = null;
    });
})();
</script>
<script>
(function () {
    'use strict';
    function normalize(value) {
        return String(value || '').toLocaleLowerCase('sv-SE').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }
    function bindFilter(inputId, cardSelector, emptyId) {
        var input = document.getElementById(inputId);
        if (!input) return;
        var cards = Array.prototype.slice.call(document.querySelectorAll(cardSelector));
        var empty = document.getElementById(emptyId);
        function applyFilter() {
            var query = normalize(input.value.trim());
            var visibleCount = 0;
            cards.forEach(function (card) {
                var content = normalize(card.getAttribute('data-search') || card.textContent || '');
                var visible = query === '' || content.indexOf(query) !== -1;
                card.hidden = !visible;
                if (visible) visibleCount++;
            });
            if (empty) empty.hidden = visibleCount > 0;
        }
        input.addEventListener('input', applyFilter);
        applyFilter();
    }
    bindFilter('invoice-article-search', '[data-invoice-catalog-card]', 'invoice-article-no-results');
    bindFilter('invoice-customer-search', '[data-invoice-customer-card]', 'invoice-customer-no-results');
})();
</script>
<script>
(function () {
    'use strict';
    function normalize(value) {
        return String(value || '').toLocaleLowerCase('sv-SE').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }
    Array.prototype.forEach.call(document.querySelectorAll('[data-invoice-record-search]'), function (input) {
        var table = document.getElementById(input.getAttribute('aria-controls') || '');
        if (!table) return;
        var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-search]'));
        var count = document.getElementById(input.getAttribute('data-count-id') || '');
        var empty = document.querySelector('[data-invoice-search-empty-for="' + table.id + '"]');
        function applyFilter() {
            var query = normalize(input.value.trim());
            var visibleCount = 0;
            rows.forEach(function (row) {
                var content = normalize(row.getAttribute('data-search') || '');
                var visible = query === '' || content.indexOf(query) !== -1;
                row.hidden = !visible;
                if (visible) visibleCount++;
            });
            if (count) {
                count.textContent = query === ''
                    ? visibleCount + ' underlag totalt.'
                    : 'Visar ' + visibleCount + ' av ' + rows.length + ' underlag.';
            }
            if (empty) empty.hidden = visibleCount > 0;
        }
        input.addEventListener('input', applyFilter);
        applyFilter();
    });
})();
</script>
