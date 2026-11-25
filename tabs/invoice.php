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
require_once __DIR__ . '/../includes/invoice_price_history.php';
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
$canManageFixedPrices = $isInvoiceAdmin && !$isCentralAdminUser;
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
$invoicePriorPrices = portal_invoice_price_history($invoiceListRecords, $editId);
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

// Protocol data is available here only when both independent areas are authorised.
require_once __DIR__ . '/../includes/invoice_from_protocol.php';
$invoiceCanReadProtocols = empty($portalIsScopedPwa) && is_array($currentInvoiceUser)
    && function_exists('portal_search_protocol_auth_user') && is_array(portal_search_protocol_auth_user());
$invoiceProtocolOptions = [];
$invoiceSourceProtocol = null;
$requestedSourceProtocol = isset($_GET['source_protocol']) && is_string($_GET['source_protocol']) ? $_GET['source_protocol'] : '';
if ($invoiceCanReadProtocols) {
    $allProtocolOptions = array_values(array_filter(load_data(PROTOCOLS_FILE, []), 'is_array'));
    usort($allProtocolOptions, static fn(array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? $b['date'] ?? ''), (string) ($a['updated_at'] ?? $a['date'] ?? '')));
    foreach ($allProtocolOptions as $protocolOption) {
        $protocolId = is_string($protocolOption['id'] ?? null) ? $protocolOption['id'] : '';
        if (!preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $protocolId)) continue;
        if ($protocolId === $requestedSourceProtocol) $invoiceSourceProtocol = $protocolOption;
        if (count($invoiceProtocolOptions) < 100) $invoiceProtocolOptions[] = $protocolOption;
    }
}
$importingProtocol = $editId === '' && $copyId === '' && !is_array($invoiceDraftData)
    && isset($_GET['import_protocol']) && $_GET['import_protocol'] === '1' && is_array($invoiceSourceProtocol);
if ($importingProtocol) {
    $editInvoice = portal_invoice_from_protocol($invoiceSourceProtocol, $_GET['source_item'] ?? []);
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
// History is only computed and rendered for a personal invoice administrator.
$fixedPriceHistory = ($canManageFixedPrices && $invoiceView === 'articles' && is_array($invoiceCatalogRecords)) ? portal_invoice_catalog_price_history($invoiceCatalogRecords) : [];
$historyPageRaw = $_GET['history_page'] ?? '1';
$historyPage = is_string($historyPageRaw) && preg_match('/\A[1-9][0-9]{0,5}\z/D', $historyPageRaw) ? (int)$historyPageRaw : 1;
$historyCount = is_array($fixedPriceHistory) ? count($fixedPriceHistory) : 0;
$historyPages = max(1, (int)ceil($historyCount / 50));
$historyPage = min($historyPage, $historyPages);
$historyRows = is_array($fixedPriceHistory) ? array_slice(array_reverse($fixedPriceHistory), ($historyPage - 1) * 50, 50) : [];
$sharedCandidates = ($canManageFixedPrices && $invoiceView === 'articles' && $invoiceCatalogStorageReady)
    ? portal_voice_shared_candidates($currentInvoiceUser, portal_invoice_auth_read_users(), $invoiceArticles) : [];
$sharedSummary = ($canManageFixedPrices && $invoiceView === 'articles' && $invoiceCatalogStorageReady)
    ? portal_voice_shared_article_summary($currentInvoiceUser, portal_invoice_auth_read_users(), $invoiceArticles) : [];
$statsRaw = $_GET['shared_stats_page'] ?? '1';
$statsPage = is_string($statsRaw) && preg_match('/\A[1-9][0-9]{0,5}\z/D', $statsRaw) ? (int)$statsRaw : 1;
$statsPages = max(1, (int)ceil(count($sharedSummary) / 50));
$statsPage = min($statsPage, $statsPages);
$statsRows = array_slice($sharedSummary, ($statsPage - 1) * 50, 50);
$sharedPageRaw = $_GET['shared_page'] ?? '1';
$sharedPage = is_string($sharedPageRaw) && preg_match('/\A[1-9][0-9]{0,5}\z/D', $sharedPageRaw) ? (int)$sharedPageRaw : 1;
$sharedPages = max(1, (int)ceil(count($sharedCandidates) / 50));
$sharedPage = min($sharedPage, $sharedPages);
$sharedRows = array_slice($sharedCandidates, ($sharedPage - 1) * 50, 50);
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
$activeDiagnosticErrors = $isInvoiceAdmin && $invoiceView === 'debug' ? TadegaPrivacyLogger::readActiveErrors() : null;
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
    .invoice-voice [hidden] { display:none !important; }
    .invoice-mobile-voice-shortcut { display:none; }
    @media (max-width: 720px) { .invoice-mobile-voice-shortcut { display:inline-flex; min-height:48px; width:100%; justify-content:center; margin:8px 0; } .invoice-voice button { min-height:44px; } }
    .invoice-article-picker { margin:0 0 14px; padding:12px 14px; border:1px solid var(--inv-line); border-radius:12px; background:#f8fbfc; }
    .invoice-article-picker > summary { color:var(--inv-brand-dark); font-weight:780; cursor:pointer; }
    .invoice-article-picker-body { margin-top:12px; color:var(--inv-muted); font-size:.9rem; line-height:1.5; }
    .invoice-article-picker-controls { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:8px 12px; align-items:end; }
    .invoice-article-picker-controls label { grid-column:1 / -1; color:var(--inv-ink); font-weight:720; }
    .invoice-article-picker-controls select { width:100%; min-height:48px; padding:10px 12px; border:1px solid #cbd9df; border-radius:9px; background:#fff; color:var(--inv-ink); font:inherit; }
    #invoice-article-add-status { grid-column:1 / -1; min-height:1.2em; color:var(--inv-muted); font-size:.85rem; }
    .invoice-price-history { margin:18px 0; padding:16px; border:1px solid var(--inv-line); border-radius:13px; background:var(--inv-surface); }
    .invoice-price-history h3 { margin:0; }
    .invoice-price-history-list { list-style:none; display:grid; gap:8px; padding:0; margin:12px 0; }
    .invoice-price-history-list li { padding:10px 12px; border:1px solid var(--inv-line); border-radius:9px; overflow-wrap:anywhere; }
    .invoice-price-history-list small { color:var(--inv-muted); }
    .invoice-price-history-pages { display:flex; flex-wrap:wrap; align-items:center; gap:10px; }
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
    .invoice-debug-active { margin:16px 0 0; padding:16px; border:1px solid #c7d8dd; border-radius:12px; background:#f7fbfc; }
    .invoice-debug-active h3 { margin:0; color:var(--inv-ink); font-size:.98rem; letter-spacing:-.01em; }
    .invoice-debug-active-intro { margin:6px 0 0; color:var(--inv-muted); font-size:.78rem; line-height:1.45; }
    .invoice-debug-active-item { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:10px; margin-top:9px; padding:10px 12px; border:1px solid #e2ecef; border-radius:10px; background:#fff; }
    .invoice-debug-active-item strong { display:block; color:var(--inv-ink); font-size:.85rem; }
    .invoice-debug-active-item small { display:block; margin-top:3px; color:var(--inv-muted); font-size:.72rem; overflow-wrap:anywhere; }
    .invoice-debug-active-item form { flex:0 0 auto; }
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
            background: #fff;
            box-shadow: 0 -4px 18px rgba(16,40,58,.12);
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
            background:#fff;
            box-shadow:0 -4px 18px rgba(16,40,58,.12);
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
        .invoice-note, .invoice-autosave-status, .invoice-form-error, .invoice-step-navigation, .invoice-voice, .invoice-mobile-voice-shortcut { display: none !important; }
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
    .invoice-step-navigation { border-color:#cfe1dc; background:#fafdfb; box-shadow:0 -6px 22px rgba(18,54,58,.12); }
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
    /* The invoice PWA shares the shell palette without changing print output. */
    @media screen {
        :root[data-width="wide"] .invoice-page { max-width: 1280px; }
        .invoice-page { --inv-surface: var(--color-surface); }
        .invoice-sheet { border-color:var(--inv-line); border-radius:18px; background:var(--inv-surface); }
        .invoice-header { background:#f6faf8; }
        .invoice-subtabs { width:100%; border-radius:12px; }
        .invoice-subtab { font-size:.875rem; }
        .invoice-section-heading h3 { letter-spacing:-.025em; }
        .invoice-voice > summary { display:flex; align-items:center; gap:9px; }
        .invoice-voice > summary::after { content:""; width:8px; height:8px; margin-left:auto; border-right:2px solid currentColor; border-bottom:2px solid currentColor; transform:rotate(45deg) translateY(-2px); }
        .invoice-voice[open] > summary::after { transform:rotate(225deg) translateY(-2px); }
        .invoice-voice-icon { width:19px; height:19px; flex:none; fill:none; stroke:currentColor; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }
        .voice-uncertainty { margin:10px 0; padding:12px 14px; border:1px solid #d8a43b; border-left:4px solid #a75a00; border-radius:10px; background:#fff8e7; color:#663700; }
        .voice-uncertainty[hidden] { display:none; } .voice-uncertainty ul { margin:5px 0; padding-left:20px; }
        #voice-row-preview .voice-needs-review { border:2px solid #aa5b00; }
        #voice-dictionary-list { display:grid; gap:10px; margin:10px 0; }
        .voice-dictionary-entry { display:flex; align-items:end; flex-wrap:wrap; gap:8px; padding:12px; border:1px solid var(--inv-line); border-radius:10px; }
        .voice-dictionary-entry label { display:grid; gap:4px; flex:1 1 200px; min-width:0; }
        html[data-theme="dark"] .voice-uncertainty { background:#382b19; color:#ffe1a6; border-color:#d89e44; }
        #voice-article-options { display:flex; flex-wrap:wrap; gap:8px; margin:8px 0; }
        #voice-article-options button { max-width:100%; white-space:normal; text-align:left; }
        html[data-theme="dark"] .invoice-page { color:var(--color-ink); }
        html[data-theme="dark"] .invoice-sheet,
        html[data-theme="dark"] .invoice-header,
        html[data-theme="dark"] .invoice-subtabs,
        html[data-theme="dark"] .invoice-page :is(.invoice-draft-item,.invoice-catalog-card,.invoice-customer-card,.invoice-catalog-editor,.invoice-catalog-empty,.invoice-article-picker,.invoice-mobile-review,.invoice-review-details > div,.invoice-review-lines,.invoice-review-item) { background:#1b2d2c!important; border-color:#3b5450!important; color:#e6efed!important; }
        html[data-theme="dark"] .invoice-page :is(.invoice-field label,.invoice-meta,.invoice-section-heading p,.invoice-help,.invoice-catalog-note,.invoice-drafts-heading p,.invoice-overline,.invoice-external-note) { color:#bdceca!important; }
        html[data-theme="dark"] .invoice-page :is(.invoice-section-heading h3,.invoice-meta strong,.invoice-catalog-card-details h3) { color:#e6efed!important; }
        html[data-theme="dark"] .invoice-page :is(.invoice-disclaimer,.invoice-step-navigation,.invoice-vat-control,.invoice-catalog-save-box) { background:#203a35!important; border-color:#40655a!important; color:#d5e9e1!important; }
        html[data-theme="dark"] .invoice-page :is(.invoice-vat-control label,.invoice-vat-control small,.invoice-catalog-save-box label,.invoice-register summary,.invoice-note) { color:#d5e9e1!important; }
        html[data-theme="dark"] .invoice-page .invoice-remove { background:#1a2b2d; border-color:#3a5252; color:#d8e8e4; }
        html[data-theme="dark"] .invoice-page .invoice-disclaimer strong { color:#d5e9e1!important; }
        html[data-theme="dark"] .invoice-page .invoice-subtab[aria-current="page"] { background:#23453c!important; color:#def7ee!important; border-color:#477365!important; }
        html[data-theme="dark"] .invoice-page :is(.invoice-table tbody tr,.invoice-record-table tbody tr) { background:#1b2d2c; border-color:#3b5450; }
        html[data-theme="dark"] .invoice-page :is(.invoice-table td::before,.invoice-record-table td::before) { color:#bdceca; }
        html[data-theme="dark"] .invoice-page :is(.invoice-line-total,.invoice-total-value) { color:#e6efed; }
        @media (max-width:720px) {
            .invoice-subtabs { width:100%; scroll-padding-inline:8px; }
            .invoice-voice .invoice-register-body { padding:14px; }
            .invoice-voice .btn { margin:4px 4px 4px 0; }
            .invoice-mobile-voice-shortcut { justify-content:center; }
            .invoice-draft-item { flex-wrap:wrap; }
            .invoice-draft-actions { flex-wrap:wrap; }
        }
    }
</style>

<section class="invoice-page" aria-labelledby="invoice-title">
    <nav class="invoice-subtabs" aria-label="Fakturaunderlagets undersidor">
        <a class="invoice-subtab" data-invoice-nav href="?tab=invoice<?= $invoiceContextHtml ?>" <?= $invoiceView === 'form' ? 'aria-current="page"' : '' ?>><svg class="invoice-subtab-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 4h14v16H5zM12 8v8M8 12h8"/></svg><?= ($portalLanguage ?? 'sv') === 'en' ? 'New invoice record' : 'Nytt fakturaunderlag' ?></a>
        <a class="invoice-subtab" data-invoice-nav href="?tab=invoice&amp;invoice_view=processing<?= $invoiceContextHtml ?>" <?= $invoiceView === 'processing' ? 'aria-current="page"' : '' ?>><svg class="invoice-subtab-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="8.5"/><path d="M12 7v5l3 2"/></svg><?= ($portalLanguage ?? 'sv') === 'en' ? 'In progress' : 'Under behandling' ?></a>
        <a class="invoice-subtab" data-invoice-nav href="?tab=invoice&amp;invoice_view=invoice_history<?= $invoiceContextHtml ?>" <?= $invoiceView === 'invoice_history' ? 'aria-current="page"' : '' ?>><svg class="invoice-subtab-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 6h16v4H4zM6 10v9h12v-9M10 14h4"/></svg><?= ($portalLanguage ?? 'sv') === 'en' ? 'History' : 'Historik' ?></a>
        <a class="invoice-subtab" data-invoice-nav href="?tab=invoice&amp;invoice_view=articles<?= $invoiceContextHtml ?>" <?= $invoiceView === 'articles' ? 'aria-current="page"' : '' ?>>
            <svg class="invoice-subtab-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 4h14v16H5z"/><path d="M8 8h8M8 12h8M8 16h4"/></svg>
            <?= ($portalLanguage ?? 'sv') === 'en' ? 'Items' : 'Artiklar' ?>
        </a>
        <a class="invoice-subtab" data-invoice-nav href="?tab=invoice&amp;invoice_view=customers<?= $invoiceContextHtml ?>" <?= $invoiceView === 'customers' ? 'aria-current="page"' : '' ?>>
            <svg class="invoice-subtab-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="9" cy="8" r="3"/><path d="M3 19c.6-3.2 2.5-5 6-5s5.4 1.8 6 5M17 8a3 3 0 0 1 0 6M17 15c2.3.4 3.6 1.7 4 4"/></svg>
            <?= ($portalLanguage ?? 'sv') === 'en' ? 'Customers' : 'Kunder' ?>
        </a>
        <?php if ($isInvoiceAdmin): ?>
            <a class="invoice-subtab" data-invoice-nav href="?tab=invoice&amp;invoice_view=admin<?= $invoiceContextHtml ?>" <?= $invoiceView === 'admin' ? 'aria-current="page"' : '' ?>>
                <svg class="invoice-subtab-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-1.8 3.1-.2-.1a1.7 1.7 0 0 0-1.9.1l-.2.1h-3.6l-.1-.2a1.7 1.7 0 0 0-1.7-1l-.2.1-3.1-1.8.1-.2a1.7 1.7 0 0 0-.1-1.9L7 15v-3.6l.2-.1a1.7 1.7 0 0 0 1-1.7l-.1-.2 1.8-3.1.2.1a1.7 1.7 0 0 0 1.9-.1l.2-.1h3.6l.1.2a1.7 1.7 0 0 0 1.7 1l.2-.1 3.1 1.8-.1.2a1.7 1.7 0 0 0 .1 1.9l.1.2V15z"/></svg>
                <?= ($portalLanguage ?? 'sv') === 'en' ? 'Administration' : 'Administration' ?>
            </a>
            <a class="invoice-subtab" data-invoice-nav href="?tab=invoice&amp;invoice_view=email<?= $invoiceContextHtml ?>" <?= $invoiceView === 'email' ? 'aria-current="page"' : '' ?>>
                <svg class="invoice-subtab-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>
                <?= ($portalLanguage ?? 'sv') === 'en' ? 'Email status' : 'E-poststatus' ?>
            </a>
            <?php if (empty($portalIsScopedPwa)): ?>
                <a class="invoice-subtab" data-invoice-nav href="?tab=invoice&amp;invoice_view=debug<?= $invoiceContextHtml ?>" <?= $invoiceView === 'debug' ? 'aria-current="page"' : '' ?>>
                    <svg class="invoice-subtab-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 8v4l2.5 1.5"/><circle cx="12" cy="12" r="9"/><path d="M5 4.5 3.5 3M19 4.5 20.5 3"/></svg>
                    <?= ($portalLanguage ?? 'sv') === 'en' ? 'Troubleshooting' : 'Felsökning' ?>
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </nav>
    <?php if ($invoiceView === 'form'): ?>
    <div class="invoice-topbar">
        <div class="invoice-topbar-actions">
            <a class="btn btn-outline" data-invoice-nav href="?tab=invoice&amp;invoice_view=invoice_history"><?= ($portalLanguage ?? 'sv') === 'en' ? 'View invoice history' : 'Visa fakturahistorik' ?></a>
        </div>
    </div>
    <p class="invoice-external-note"><?= ($portalLanguage ?? 'sv') === 'en' ? 'The invoicing system has a separate login. Records saved here are not automatically transferred to it.' : 'Fakturasystemet har en separat inloggning. Underlaget som sparas här överförs inte automatiskt dit.' ?></p>

    <section class="invoice-drafts" aria-labelledby="invoice-drafts-heading">
        <div class="invoice-drafts-heading"><div><h2 id="invoice-drafts-heading"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Unsaved drafts' : 'Osparade utkast' ?></h2><p><?= ($portalLanguage ?? 'sv') === 'en' ? 'Changes are autosaved for three days. The draft is removed when you save the record.' : 'Ändringar autosparas i tre dagar. Utkastet tas bort när du sparar underlaget.' ?></p></div><a class="btn btn-outline" href="?tab=invoice"><?= ($portalLanguage ?? 'sv') === 'en' ? 'New record' : 'Nytt underlag' ?></a></div>
        <?php if ($invoiceDrafts): ?>
            <ul class="invoice-draft-list">
                <?php foreach ($invoiceDrafts as $invoiceDraft): $draftFields = is_array($invoiceDraft['data']['i'] ?? null) ? $invoiceDraft['data']['i'] : []; $draftLabel = trim((string) ($draftFields['customer'] ?? '')); if ($draftLabel === '') { $draftLabel = trim((string) ($draftFields['ref'] ?? '')); } if ($draftLabel === '') { $draftLabel = 'Nytt fakturaunderlag'; } ?>
                    <li class="invoice-draft-item"><span><strong><?= h($draftLabel) ?></strong><br><small>Senast ändrat <?= h(date('Y-m-d H:i', (int) ($invoiceDraft['updated_at'] ?? time()))) ?></small></span><span class="invoice-draft-actions"><a class="btn btn-outline" href="?tab=invoice&amp;draft_id=<?= h($invoiceDraft['id'] ?? '') ?>"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Open' : 'Öppna' ?></a><form method="post" action="?tab=invoice" onsubmit="return confirm('Radera det här utkastet?')"><input type="hidden" name="action" value="delete_autosaved_draft"><input type="hidden" name="draft_id" value="<?= h($invoiceDraft['id'] ?? '') ?>"><?= csrf_field() ?><button class="btn btn-outline" type="submit"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Delete' : 'Radera' ?></button></form></span></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p style="margin:12px 0 0;color:var(--inv-muted);font-size:.88rem">Inga andra utkast just nu. Det här formuläret autosparas när du börjar skriva; du kan alltid skapa ett nytt underlag.</p>
        <?php endif; ?>
    </section>

    <?php if ($invoiceCanReadProtocols && !$isEditingInvoice && !is_array($invoiceDraftData) && !$copyingInvoice): ?>
    <details class="invoice-register" <?= $requestedSourceProtocol !== '' ? 'open' : '' ?>>
        <summary><?= ($portalLanguage ?? 'sv') === 'en' ? 'Create from saved service report' : 'Skapa från sparat serviceprotokoll' ?></summary>
        <div class="invoice-register-body" style="display:block">
            <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'Only users signed in to both invoice records and service reports can select a source. Choose which lines to copy; enter prices and review the record before saving. Photos and signatures are not copied. This opens a new form and replaces unsaved changes in this view.' : 'Endast personer inloggade i både faktura och protokoll kan välja källa. Välj vilka rader som ska följa med; priser behöver anges och underlaget granskas innan sparning. Foto och signatur kopieras inte. Valet öppnar ett nytt formulär och ersätter eventuella osparade ändringar i den här vyn.' ?></p>
            <form method="get" action="?">
                <input type="hidden" name="tab" value="invoice">
                <label for="invoice-protocol-source"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Service reports' : 'Serviceprotokoll' ?></label>
                <select id="invoice-protocol-source" name="source_protocol" required>
                    <option value=""><?= ($portalLanguage ?? 'sv') === 'en' ? 'Select report…' : 'Välj protokoll…' ?></option>
                    <?php foreach ($invoiceProtocolOptions as $protocolOption): ?><option value="<?= h($protocolOption['id']) ?>" <?= $requestedSourceProtocol === $protocolOption['id'] ? 'selected' : '' ?>><?= h($protocolOption['nummer'] ?? '') ?> · <?= h($protocolOption['date'] ?? '') ?> · <?= h($protocolOption['customer'] ?? '') ?></option><?php endforeach; ?>
                </select>
                <button class="btn btn-outline" type="submit"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Select rows' : 'Välj rader' ?></button>
            </form>
            <?php if (is_array($invoiceSourceProtocol)): ?>
                <form method="get" action="?">
                    <input type="hidden" name="tab" value="invoice"><input type="hidden" name="source_protocol" value="<?= h($requestedSourceProtocol) ?>"><input type="hidden" name="import_protocol" value="1">
                    <fieldset><legend><?= ($portalLanguage ?? 'sv') === 'en' ? 'Select work and material rows' : 'Välj arbets- och materialrader' ?></legend>
                    <?php foreach (array_slice(is_array($invoiceSourceProtocol['items'] ?? null) ? array_values($invoiceSourceProtocol['items']) : [], 0, 100) as $sourceIndex => $sourceItem): if (!is_array($sourceItem) || trim(app_legacy_text($sourceItem['art'] ?? '')) === '') continue; ?>
                        <label style="display:block"><input type="checkbox" name="source_item[]" value="<?= (int) $sourceIndex ?>"> <?= h(app_legacy_text($sourceItem['art'] ?? '')) ?> · antal <?= h(app_legacy_text($sourceItem['qty'] ?? '')) ?><?= !empty($sourceItem['spec']) ? ' · ' . h(app_legacy_text($sourceItem['spec'])) : '' ?></label>
                    <?php endforeach; ?></fieldset>
                    <button class="btn btn-main" type="submit"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Create draft for review' : 'Skapa granskningsutkast' ?></button>
                </form>
            <?php elseif ($requestedSourceProtocol !== ''): ?><p><?= ($portalLanguage ?? 'sv') === 'en' ? 'Report not found.' : 'Protokollet kunde inte hittas.' ?></p><?php endif; ?>
        </div>
    </details>
    <?php endif; ?>
    <article class="invoice-sheet">
        <header class="invoice-header">
            <div class="invoice-brand">


                <div>
                    <h2 class="invoice-title" id="invoice-title"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Invoice records' : 'Fakturaunderlag' ?></h2>
                    <p class="invoice-overline"><?= h($portalCompanyName ?? 'Dokumentportalen') ?> · sammanställning</p>
                </div>
            </div>
            <?php if ($invoiceNumber !== ''): ?>
                <div class="invoice-meta"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Record number' : 'Underlagsnummer' ?><strong><?= h($invoiceNumber) ?></strong></div>
            <?php else: ?>
                <div class="invoice-meta"><?= ($portalLanguage ?? 'sv') === 'en' ? 'New record' : 'Nytt underlag' ?><strong><?= ($portalLanguage ?? 'sv') === 'en' ? 'Created when you save' : 'Skapas när du sparar' ?></strong></div>
            <?php endif; ?>
        </header>

        <div class="invoice-content">
            <p class="invoice-disclaimer">
                <span class="invoice-disclaimer-icon" aria-hidden="true">i</span>
                <span><strong><?= ($portalLanguage ?? 'sv') === 'en' ? 'This is an invoice record, not an invoice.' : 'Det här är ett fakturaunderlag – inte en faktura.' ?></strong> <?= ($portalLanguage ?? 'sv') === 'en' ? 'It is saved in the portal and is not sent to the customer.' : 'Det sparas i portalen och skickas inte till kunden.' ?></span>
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
                <input type="hidden" name="i[source_protocol_id]" value="<?= h($editInvoice['source_protocol_id'] ?? '') ?>">
                <?php if (!empty($editInvoice['source_protocol_id'])): ?><p class="invoice-help">Källa: sparat serviceprotokoll. <?php if ($invoiceCanReadProtocols): ?><a href="?tab=protocol&amp;view_id=<?= rawurlencode((string) $editInvoice['source_protocol_id']) ?>"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Open original report' : 'Öppna originalprotokollet' ?></a><?php else: ?>Logga in i protokollområdet för att öppna originalet.<?php endif; ?></p><?php endif; ?>
                <input type="hidden" name="i[vat_mode]" id="invoice-vat-mode" value="<?= h($vatMode) ?>">
                <?= csrf_field() ?>
                <p class="invoice-autosave-status" id="invoice-autosave-status" role="status" aria-live="polite"><span id="invoice-autosave-message"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Your changes are automatically saved as a draft.' : 'Dina ändringar sparas automatiskt som ett utkast.' ?></span> <a id="invoice-autosave-resume" href="?tab=invoice&amp;draft_id=<?= h($invoiceDraftId) ?>"<?= is_array($invoiceDraftData) ? '' : ' hidden' ?>><?= ($portalLanguage ?? 'sv') === 'en' ? 'Open autosaved draft' : 'Öppna autosparat utkast' ?></a></p>

                <button type="button" class="btn btn-outline invoice-mobile-voice-shortcut" id="invoice-open-voice" aria-controls="invoice-voice-panel" aria-expanded="false"><svg class="invoice-voice-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21M9 21h6"/></svg><?= ($portalLanguage ?? 'sv') === 'en' ? 'Open voice input' : 'Öppna röstinmatning' ?></button>
                <details class="invoice-voice invoice-register" id="invoice-voice-panel">
                    <summary><svg class="invoice-voice-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21M9 21h6"/></svg><?= ($portalLanguage ?? 'sv') === 'en' ? 'Voice and quick entry' : 'Röst och snabb inmatning' ?></summary>
                    <div class="invoice-register-body" style="display:block">
                        <p class="invoice-help"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Voice recognition requires microphone permission and browser support. The browser provider may process audio externally. Do not use voice for sensitive information without your organization\'s approval. Always check the result before applying it. Voice input and commands currently use Swedish.' : 'Röstigenkänning kräver mikrofontillstånd och stöd i webbläsaren. Webbläsarens leverantör kan behandla ljudet externt. Använd inte röst för känsliga uppgifter utan organisationens godkännande. Kontrollera alltid resultatet innan det används.' ?></p>
                        <details id="voice-learning-panel">
                            <summary><?= ($portalLanguage ?? 'sv') === 'en' ? 'Personal learning and automatic entry' : 'Personligt lärande och automatisk inmatning' ?></summary>
                            <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'Optional. The account stores corrections of item descriptions, not recordings, voice profiles or full transcripts. Numeric learning requires separate consent below. Up to 200 description pairs are retained; older pairs are replaced. Descriptions may contain sensitive information. Server administrators can access the data. The browser speech provider may process audio externally.' : 'Valfritt. Kontot sparar korrigeringar av artikelbeskrivningar, inte ljud, röstprofiler eller hela dikteringar. Lärande av antal och priser kräver ett separat val nedan. Högst 200 beskrivningspar sparas; äldre par ersätts. Beskrivningar kan innehålla känsliga uppgifter. Serveradministratörer har åtkomst. Webbläsarens röstleverantör kan behandla ljud externt.' ?></p>
                            <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'After 50 manually reviewed voice rows, you can opt in to automatic entry of complete proposals. No number guarantees accuracy. Check every quantity, price and unit before saving. Automatic rows are not counted as verified examples; later description edits to your automatically entered rows continue to update learning, including after reopening a saved record or draft. The encrypted link is stored with the row and is erased by resetting learning.' : 'Efter 50 manuellt granskade röstrader kan du själv aktivera automatiskt införande av fullständiga förslag. Ingen mängd exempel garanterar rätt tolkning. Kontrollera alltid antal, enhet och pris före sparning. Automatiska rader räknas inte som granskade exempel; senare beskrivningsändringar av dina automatiskt införda rader uppdaterar lärandet, även när du öppnar ett sparat underlag eller utkast igen. En krypterad koppling sparas med raden och bryts när lärandet raderas.' ?></p>
                            <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'Optional numeric learning stores up to 200 pairs of previously recognized and corrected quantities or unit prices with the item description and unit in your personal profile. New automatic rows carry an encrypted link with those numbers. Older links cannot be reconstructed. When a previous correction matches, automatic entry stops and the alternative is shown for manual review only; prices may change. Pausing retains examples; erase all learning to delete them and invalidate links.' : 'Valfritt lärande av antal och priser sparar högst 200 par av tidigare igenkända och rättade antal eller enhetspriser tillsammans med artikelbeskrivning och enhet i din personliga profil. Nya automatiska rader får en krypterad koppling med dessa tal. Äldre kopplingar kan inte återskapas. Vid en matchning stoppas automatisk inmatning och alternativet visas enbart för manuell granskning; priser kan ändras. Paus behåller exemplen; radera allt lärande för att radera dem och bryta kopplingarna.' ?></p>
                            <p id="voice-learning-state" role="status" aria-live="polite"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Loading personal settings…' : 'Läser personliga inställningar…' ?></p>
                            <button type="button" class="btn btn-outline" id="voice-learning-toggle" disabled><?= ($portalLanguage ?? 'sv') === 'en' ? 'Start learning' : 'Starta lärande' ?></button>
                            <p class="invoice-help"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Sharing is a separate choice. When enabled, invoice administrators can see your account name and your existing and future recognized and corrected item descriptions. These may contain sensitive information. Separate quantity and price fields, recordings and complete transcripts are not shared, but descriptions can contain any of that text. An administrator may add an approved alternative name to the shared catalog; it remains there even if you later stop sharing or erase learning. Pausing personal learning also stops sharing.' : 'Delning är ett separat val. När det är på kan fakturaadmin se ditt kontonamn och dina befintliga och framtida igenkända och rättade artikelbeskrivningar. De kan innehålla känsliga uppgifter. Separata antal- och prisfält, inspelningar och hela dikteringar delas inte, men beskrivningar kan innehålla sådana uppgifter. Admin kan lägga till ett godkänt alternativnamn i den gemensamma katalogen; det ligger kvar även om du senare slutar dela eller raderar lärandet. När du pausar personligt lärande stoppas även delningen.' ?></p>
                            <button type="button" class="btn btn-outline" id="voice-share-toggle" disabled><?= ($portalLanguage ?? 'sv') === 'en' ? 'Share corrections for administrator review' : 'Dela rättningar för admingranskning' ?></button>
                            <button type="button" class="btn btn-outline" id="voice-numeric-toggle" disabled><?= ($portalLanguage ?? 'sv') === 'en' ? 'Enable learning of quantities and prices' : 'Aktivera lärande av antal och priser' ?></button>
                            <button type="button" class="btn btn-outline" id="voice-auto-toggle" disabled><?= ($portalLanguage ?? 'sv') === 'en' ? 'Enable automatic entry' : 'Aktivera automatisk inmatning' ?></button>
                            <button type="button" class="btn btn-outline" id="voice-learning-forget" disabled><?= ($portalLanguage ?? 'sv') === 'en' ? 'Erase learning' : 'Radera lärande' ?></button>
                            <details id="voice-dictionary"><summary><?= ($portalLanguage ?? 'sv') === 'en' ? 'My learned descriptions' : 'Min inlärda ordlista' ?></summary>
                                <p class="invoice-help"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Edit or delete your saved description corrections, including while learning is paused. Changes do not remove already approved catalog aliases.' : 'Ändra eller ta bort dina sparade beskrivningsrättningar, även när lärandet är pausat. Ändringar tar inte bort redan godkända katalogalias.' ?></p>
                                <p id="voice-dictionary-status" role="status" aria-live="polite"></p><div id="voice-dictionary-list"></div>
                            </details>
                            <button type="button" class="btn btn-outline" id="voice-learning-retry" hidden><?= ($portalLanguage ?? 'sv') === 'en' ? 'Retry learning sync' : 'Försök synkronisera lärandet igen' ?></button>
                        </details>
                        <label for="voice-destination"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Destination row for proposal' : 'Rad för förslaget' ?></label>
                        <select id="voice-destination"><option value="auto"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Next empty row' : 'Nästa tomma rad' ?></option></select>
                        <small class="invoice-help"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Select a row or focus one in the invoice table. Replacing existing values requires confirmation.' : 'Välj en rad här eller markera en rad i fakturatabellen. Befintliga uppgifter ersätts bara efter bekräftelse.' ?></small>
                        <label for="voice-target"><?= ($portalLanguage ?? 'sv') === 'en' ? 'What would you like to enter?' : 'Vad vill du ange?' ?></label>
                        <select id="voice-target"><option value="row"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Invoice line' : 'Fakturarad' ?></option><option value="customer"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Customer' : 'Kund' ?></option><option value="reference"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Reference' : 'Referens' ?></option><option value="catalog"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Search item' : 'Sök artikel' ?></option></select>
                        <button class="btn btn-outline" type="button" id="voice-start"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Start voice input' : 'Starta röstinmatning' ?></button>
                        <button class="btn btn-outline" type="button" id="voice-stop" hidden><?= ($portalLanguage ?? 'sv') === 'en' ? 'Stop and review' : 'Stoppa och granska' ?></button>
                        <button class="btn btn-outline" type="button" id="voice-cancel" hidden><?= ($portalLanguage ?? 'sv') === 'en' ? 'Cancel recording' : 'Avbryt inspelning' ?></button>
                        <label for="voice-transcript"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Recognized text (editable)' : 'Uppfattad text (kan redigeras)' ?></label>
                        <textarea id="voice-transcript" maxlength="1000" placeholder="Exempel: 2st Glasreparationer 750 kr st"></textarea>
                        <div class="invoice-form-grid" id="voice-row-preview">
                            <label><?= ($portalLanguage ?? 'sv') === 'en' ? 'Description' : 'Beskrivning' ?> <input id="voice-name" maxlength="200" aria-describedby="voice-uncertainty"></label>
                            <label><?= ($portalLanguage ?? 'sv') === 'en' ? 'Quantity' : 'Antal' ?> <input id="voice-qty" aria-describedby="voice-uncertainty" type="text" inputmode="decimal"></label>
                            <label><?= ($portalLanguage ?? 'sv') === 'en' ? 'Unit' : 'Enhet' ?> <select id="voice-unit" aria-describedby="voice-uncertainty"><option value=""><?= ($portalLanguage ?? 'sv') === 'en' ? 'Select unit…' : 'Välj enhet…' ?></option><?php foreach ($invoiceArticleUnits as $unitKey => $unitLabel): ?><option value="<?= h($unitKey) ?>"><?= h($unitLabel) ?></option><?php endforeach; ?></select></label>
                            <label><?= ($portalLanguage ?? 'sv') === 'en' ? 'Price per unit' : 'Pris per enhet' ?> <input id="voice-price" aria-describedby="voice-uncertainty" type="text" inputmode="decimal"></label>
                        </div>
                        <div id="voice-uncertainty" class="voice-uncertainty" role="status" aria-live="polite" hidden><strong><?= ($portalLanguage ?? 'sv') === 'en' ? 'Needs review' : 'Behöver granskas' ?></strong><ul id="voice-uncertainty-list"></ul><small><?= ($portalLanguage ?? 'sv') === 'en' ? 'These are rule-based warnings, not a confidence score. Even unmarked fields can be wrong.' : 'Varningarna bygger på regler, inte en säkerhetsprocent. Även omarkerade fält kan vara fel.' ?></small></div>
                        <p id="voice-match-reason" class="invoice-help" role="status" aria-live="polite" hidden></p>
                        <div id="voice-article-choices" hidden aria-live="polite">
                            <p id="voice-article-message"></p>
                            <div id="voice-article-options"></div>
                            <button type="button" class="btn btn-outline" id="voice-article-free"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Use as free text instead' : 'Använd som fritext i stället' ?></button>
                        </div>
                        <div id="voice-numeric-suggestions" hidden aria-live="polite">
                            <p id="voice-numeric-message"></p>
                            <button type="button" class="btn btn-outline" id="voice-numeric-qty" hidden></button>
                            <button type="button" class="btn btn-outline" id="voice-numeric-price" hidden></button>
                        </div>
                        <p class="invoice-help"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Speak in Swedish, for example: windscreen replacement, one and a half hours, 850 kronor per hour. The price must be per unit, not a total. Review and complete every field before applying. Dictation and commands are in Swedish.' : 'Säg till exempel: byte av vindruta, en och en halv timme, 850 kronor per timme. Ange pris per enhet, inte totalsumma. Granska och komplettera alla fält innan du använder förslaget.' ?></p>
                        <details><summary><?= ($portalLanguage ?? 'sv') === 'en' ? 'Swedish voice commands' : 'Svenska röstkommandon' ?></summary><p class="invoice-help"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Say “nästa”, “punkt” or “nästa rad” to move to the next row. Apply or clear the current proposal first. Ending a dictated row with “punkt” moves to the next row after you approve the proposal. Say “till kund”, “till referens”, “till beskrivning”, “till antal”, “till enhet”, “till pris” or “rad 2” to navigate. Say “ny rad” or “läs upp underlaget”. Commands never save or send the record. Say “ersätt med …” or “lägg till …” to edit the dictated proposal. “Ångra senaste” immediately undoes the last applied suggestion when it has not been edited since.' : 'Säg ”nästa”, ”punkt” eller ”nästa rad” för att gå till nästa rad. Använd eller rensa först det aktuella förslaget. Om du avslutar en dikterad rad med ordet ”punkt” går appen vidare när du har godkänt förslaget. Säg ”till kund”, ”till referens”, ”till beskrivning”, ”till antal”, ”till enhet”, ”till pris” eller ”rad 2” för att navigera. Säg ”ny rad” eller ”läs upp underlaget”. Kommandon sparar eller skickar aldrig underlaget. Säg ”ersätt med …” eller ”lägg till …” för att ändra den dikterade texten. ”Ångra senaste” ångrar direkt senast använda förslaget om det inte har ändrats efteråt.' ?></p></details>
                        <button type="button" class="btn btn-outline" id="voice-parse"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Interpret text' : 'Tolka text' ?></button>
                        <button type="button" class="btn btn-main" id="voice-apply"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Apply suggestion' : 'Använd förslaget' ?></button>
                        <button type="button" class="btn btn-outline" id="voice-clear"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Clear proposal' : 'Rensa förslag' ?></button>
                        <button type="button" class="btn btn-outline" id="voice-undo"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Undo last application' : 'Ångra senaste användning' ?></button>
                        <button type="button" class="btn btn-outline" id="voice-read"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Read record aloud' : 'Läs upp underlaget' ?></button>
                        <button type="button" class="btn btn-outline" id="voice-read-stop" hidden><?= ($portalLanguage ?? 'sv') === 'en' ? 'Stop reading' : 'Stoppa uppläsning' ?></button>
                        <p class="invoice-help"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Reading includes the customer, reference, assignment and entered lines, not an unapplied voice proposal. Anyone nearby may hear it. The device speech service may process the text.' : 'Uppläsning omfattar kund, referens, uppdrag och inmatade rader, inte ett oanvänt röstförslag. Personer i närheten kan höra. Enhetens taltjänst kan behandla texten.' ?></p>
                        <p id="voice-status" role="status" aria-live="polite"></p>
                    </div>
                </details>
                <section class="invoice-section" aria-labelledby="invoice-customer-heading" data-invoice-step="1">
                    <div class="invoice-section-heading">
                        <div>
                            <h3 id="invoice-customer-heading" tabindex="-1"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Customer and assignment' : 'Kund och uppdrag' ?></h3>
                            <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'Enter the customer and, if needed, describe the work, materials or assignment in free text.' : 'Fyll i vem underlaget gäller och beskriv vid behov i fritext vad arbetet, materialet eller uppdraget avser.' ?></p>
                        </div>
                    </div>
                    <div class="invoice-form-grid">
                        <div class="invoice-field">
                            <label for="invoice-date"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Date' : 'Datum' ?></label>
                            <input id="invoice-date" type="date" name="i[date]" aria-describedby="invoice-date-help" value="<?= h($editInvoice['date'] ?? date('Y-m-d')) ?>">
                            <p class="invoice-help" id="invoice-date-help">Visar vilket datum uppdraget eller underlaget gäller och hjälper till att sortera historiken.</p>
                        </div>
                        <div class="invoice-field">
                            <label for="invoice-customer"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Customer / company' : 'Kund / företag' ?></label>
                            <input id="invoice-customer" name="i[customer]" maxlength="180" autocomplete="organization" placeholder="Företags- eller kundnamn" aria-describedby="invoice-customer-help" value="<?= h($editInvoice['customer'] ?? '') ?>">
                            <p class="invoice-help" id="invoice-customer-help">Identifierar kunden som underlaget avser och som den manuella fakturan ska gälla.</p>
                        </div>
                        <div class="invoice-field invoice-field--wide">
                            <label for="invoice-reference"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Customer reference' : 'Kundens referens' ?> <span class="invoice-help-inline"><?= ($portalLanguage ?? 'sv') === 'en' ? '(optional)' : '(valfritt)' ?></span></label>
                            <input id="invoice-reference" name="i[ref]" maxlength="160" placeholder="Namn, ordernummer eller märkning" aria-describedby="invoice-reference-help" value="<?= h($editInvoice['ref'] ?? '') ?>">
                            <p class="invoice-help" id="invoice-reference-help">Kopplar underlaget till kundens beställare, projekt eller ordernummer.</p>
                        </div>
                        <div class="invoice-field invoice-field--wide">
                            <label for="invoice-description"><?= ($portalLanguage ?? 'sv') === 'en' ? 'What is the record for?' : 'Vad gäller underlaget?' ?> <span class="invoice-help-inline">(fritext, valfritt)</span></label>
                            <textarea id="invoice-description" name="i[description]" maxlength="5000" placeholder="Skriv kort vad arbetet, materialet eller uppdraget gäller…" aria-describedby="invoice-description-help"><?= h($editInvoice['description'] ?? '') ?></textarea>
                            <p class="invoice-help" id="invoice-description-help">Förklarar omfattningen så administratören kan skapa rätt faktura; texten visas i granskningen, på utskriften och i den interna aviseringen.</p>
                        </div>
                        <div class="invoice-field invoice-field--wide invoice-fortnox-field">
                            <label for="invoice-fortnox-number"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Fortnox invoice number' : 'Fortnox fakturanummer' ?></label>
                            <?php if ($isInvoiceAdmin): ?>
                                <input id="invoice-fortnox-number" type="text" name="i[fortnox_invoice_number]" maxlength="80" autocomplete="off" placeholder="Fyll i Fortnox-numret när fakturan är skapad" value="<?= h($fortnoxInvoiceNumber) ?>">
                                <p class="invoice-help">Numret hör till fakturan i Fortnox och är separat från underlagets interna nummer. Det förs inte över automatiskt.</p>
                                <label for="invoice-fortnox-date"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Fortnox invoice date' : 'Fakturadatum i Fortnox' ?></label>
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
                                    <label for="invoice-customer-select"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Select customer' : 'Välj kund' ?></label>
                                    <select id="invoice-customer-select">
                                        <option value=""><?= ($portalLanguage ?? 'sv') === 'en' ? 'Select customer…' : 'Välj kund…' ?></option>
                                        <?php foreach ($invoiceCustomerOptions as $invoiceCustomerOption): ?>
                                            <?php $invoiceReferenceJson = json_encode($invoiceCustomerOption['references'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG); ?>
                                            <option value="<?= h($invoiceCustomerOption['id']) ?>" data-name="<?= h($invoiceCustomerOption['name']) ?>" data-references="<?= h(is_string($invoiceReferenceJson) ? $invoiceReferenceJson : '[]') ?>" <?= $invoiceCustomerOption['id'] === $invoiceCustomerSelectionId ? 'selected' : '' ?>><?= h($invoiceCustomerOption['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="invoice-register-field">
                                    <label for="invoice-customer-reference-select"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Select reference' : 'Välj referens' ?></label>
                                    <select id="invoice-customer-reference-select" data-current-reference="<?= h($invoiceCurrentReference) ?>" <?= $invoiceCustomerSelectionId === '' ? 'disabled' : '' ?>>
                                        <option value=""><?= $invoiceCustomerSelectionId === '' ? ($portalLanguage ?? 'sv') === 'en' ? 'Choose a customer first' : 'Välj kund först' : 'Utan referens' ?></option>
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
                            <h3 id="invoice-lines-heading" tabindex="-1"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Work and materials' : 'Arbete och material' ?></h3>
                            <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'Add one line per work item or material. Enter description, quantity, unit and price per unit.' : 'Lägg till en rad per arbete eller material. Ange beskrivning, antal, enhet och pris per enhet.' ?></p>
                        </div>
                        <div class="invoice-vat-control">
                            <label for="invoice-vat-included">
                                <input id="invoice-vat-included" type="checkbox" aria-describedby="invoice-vat-help" <?= $vatMode === 'inc' ? 'checked' : '' ?>>
                                <span><strong><?= ($portalLanguage ?? 'sv') === 'en' ? 'Prices including VAT' : 'Priser inkl. moms' ?></strong><small><?= ($portalLanguage ?? 'sv') === 'en' ? 'Unchecked = excluding VAT' : 'Avmarkerad = exkl. moms' ?></small></span>
                            </label>
                        </div>
                    </div>

                    <p class="invoice-help" style="margin:0 0 10px" id="invoice-vat-help"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Choose whether the line prices you enter exclude or include VAT. The selection is saved with the record; the portal does not calculate VAT.' : 'Välj om radpriserna du skriver in är exklusive eller inklusive moms. Momsvalet sparas med underlaget; portalen beräknar inte moms.' ?></p>
                    <p class="invoice-help" id="invoice-line-field-help"><strong>Varje rad:</strong> beskrivningen identifierar arbete eller material; antal anger mängden; enhet visar hur mängden räknas; pris per enhet dokumenterar det angivna stycke-, tim- eller enhetspriset. Uppgifterna behövs för att administratören ska kunna skapa rätt faktura i Fortnox.</p>

                    <details class="invoice-article-picker">
                        <summary>Välj artikel ur katalogen (valfritt)</summary>
                        <div class="invoice-article-picker-body">
                            <?php if (!$invoiceCatalogStorageReady): ?>
                                <p class="invoice-catalog-inline-warning"><?= ($portalLanguage ?? 'sv') === 'en' ? 'The item catalog could not be read safely. Saving records and applying voice proposals is blocked until the administrator restores it.' : 'Artikelkatalogen kunde inte läsas säkert. Sparning och införande av röstförslag är stoppade tills administratören återställer katalogen.' ?></p>
                            <?php elseif (!$invoiceArticles): ?>
                                <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'The catalog is empty.' : 'Katalogen är tom.' ?> <a data-invoice-nav href="?tab=invoice&amp;invoice_view=articles<?= $invoiceContextHtml ?>"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Add an item' : 'Lägg till en artikel' ?></a> så kan den väljas här.</p>
                            <?php else: ?>
                                <div class="invoice-article-picker-controls">
                                    <label for="invoice-catalog-article"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Item' : 'Artikel' ?></label>
                                    <select id="invoice-catalog-article">
                                        <option value=""><?= ($portalLanguage ?? 'sv') === 'en' ? 'Select item…' : 'Välj artikel…' ?></option>
                                        <?php foreach ($invoiceArticles as $invoiceArticle): ?>
                                            <option value="<?= h($invoiceArticle['id']) ?>" data-name="<?= h($invoiceArticle['name']) ?>" data-unit="<?= h($invoiceArticle['unit']) ?>" data-price="<?= h(number_format((float) $invoiceArticle['price'], 2, '.', '')) ?>"><?= h($invoiceArticle['name']) ?><?= !empty($invoiceArticle['fixed_price']) ? (($portalLanguage ?? 'sv') === 'en' ? ' (fixed price)' : ' (fast pris)') : '' ?> — <?= number_format((float) $invoiceArticle['price'], 2, ',', ' ') ?> kr / <?= h($invoiceArticleUnits[$invoiceArticle['unit']] ?? 'st') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-outline" type="button" id="invoice-add-catalog-article"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Add to invoice line' : 'Lägg till på fakturarad' ?></button>
                                    <span id="invoice-article-add-status" role="status" aria-live="polite"></span>
                                </div>
                                <p class="invoice-catalog-note">Artikelns pris förs in oförändrat. Valet <strong>exkl./inkl. moms</strong> sparas som information; portalen beräknar inte moms.</p>
                            <?php endif; ?>
                        </div>
                    </details>

                    <div class="invoice-table-wrap" role="region" aria-label="Fakturarader. Använd tabbtangenten för att gå mellan beskrivning, antal, enhet och pris." tabindex="0">
                        <table class="invoice-table" id="invoice-table">
                            <caption class="invoice-sr-only"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Work and material lines' : 'Rader för arbete och material' ?></caption>
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
                                    <th scope="col"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Description' : 'Beskrivning' ?></th>
                                    <th scope="col"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Quantity' : 'Antal' ?></th>
                                    <th scope="col"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Unit' : 'Enhet' ?></th>
                                    <th scope="col">Pris per enhet (<span class="invoice-price-mode"><?= $vatMode === 'ex' ? 'exkl.' : 'inkl.' ?></span>)</th>
                                    <th scope="col" style="text-align:right"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Line amount' : 'Radbelopp' ?></th>
                                    <th scope="col"><span class="invoice-sr-only"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Remove line' : 'Ta bort rad' ?></span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for ($index = 0; $index < $rowCount; $index++): ?>
                                    <?php $item = is_array($invoiceItems[$index] ?? null) ? $invoiceItems[$index] : []; $itemUnit = is_scalar($item['unit'] ?? null) ? (string) $item['unit'] : 'st'; if (!array_key_exists($itemUnit, $invoiceArticleUnits)) { $itemUnit = 'st'; } ?>
                                    <tr>
                                        <td data-label="Beskrivning">
                                            <input type="hidden" class="invoice-fixed-lock" name="items[<?= $index ?>][fixed_lock]" value="<?= h(is_string($item['fixed_lock'] ?? null) && preg_match('/\A[a-f0-9]{32}\z/D', $item['fixed_lock']) === 1 ? $item['fixed_lock'] : '') ?>">
                                            <input type="hidden" class="invoice-voice-token" name="items[<?= $index ?>][voice_token]" value="<?= h(is_string($item['voice_token'] ?? null) && strlen($item['voice_token']) <= 4096 && preg_match('/\A[a-f0-9]{32}\.[A-Za-z0-9_-]+\z/D', $item['voice_token']) === 1 ? $item['voice_token'] : '') ?>">
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
                                            <button class="invoice-remove" type="button" data-remove-line aria-label="Ta bort rad <?= $index + 1 ?>"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Remove' : 'Ta bort' ?></button>
                                        </td>
                                    </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="invoice-line-controls">
                        <button class="btn btn-outline" type="button" id="invoice-add-row"><?= ($portalLanguage ?? 'sv') === 'en' ? '+ Add line' : '+ Lägg till rad' ?></button>
                        <span class="invoice-limit-note" id="invoice-row-note" aria-live="polite"></span>
                    </div>

                    <?php if ($isInvoiceAdmin && $invoiceCatalogStorageReady): ?>
                        <div class="invoice-catalog-save-box" aria-labelledby="invoice-catalog-save-title">
                            <div>
                                <label id="invoice-catalog-save-title" for="invoice-catalog-source-row"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Save an invoice line as a reusable item' : 'Spara en fakturarad som återanvändbar artikel' ?></label>
                                <select id="invoice-catalog-source-row" aria-describedby="invoice-catalog-save-help"><option value=""><?= ($portalLanguage ?? 'sv') === 'en' ? 'Select a completed line…' : 'Välj en ifylld rad…' ?></option></select>
                                <p class="invoice-help" id="invoice-catalog-save-help">Snabbsparning från formuläret är adminfunktion. Artiklarna hör till fakturaunderlagets katalog; enhet och pris förs med, momsval görs separat på varje underlag.</p>
                            </div>
                            <button class="btn btn-outline" type="button" id="invoice-save-catalog-row" disabled><?= ($portalLanguage ?? 'sv') === 'en' ? 'Save to item catalog' : 'Spara i artikelkatalogen' ?></button>
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
                            <h3 id="invoice-mobile-review-heading" tabindex="-1"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Review record' : 'Granska underlaget' ?></h3>
                            <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'Check the customer, details and work lines before saving.' : 'Kontrollera kund, uppgifter och arbetsrader innan du sparar.' ?></p>
                        </div>
                    </div>
                    <dl class="invoice-review-details">
                        <div><dt><?= ($portalLanguage ?? 'sv') === 'en' ? 'Customer / company' : 'Kund / företag' ?></dt><dd id="invoice-review-customer"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Not specified' : 'Inte angivet' ?></dd></div>
                        <div><dt><?= ($portalLanguage ?? 'sv') === 'en' ? 'Date' : 'Datum' ?></dt><dd id="invoice-review-date"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Not specified' : 'Inte angivet' ?></dd></div>
                        <div><dt><?= ($portalLanguage ?? 'sv') === 'en' ? 'Customer reference' : 'Kundens referens' ?></dt><dd id="invoice-review-reference"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Not specified' : 'Inte angivet' ?></dd></div>
                        <div><dt><?= ($portalLanguage ?? 'sv') === 'en' ? 'Fortnox invoice number' : 'Fortnox fakturanummer' ?></dt><dd id="invoice-review-fortnox"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Not recorded yet' : 'Inte registrerat ännu' ?></dd></div>
                        <div><dt><?= ($portalLanguage ?? 'sv') === 'en' ? 'Fortnox invoice date' : 'Fakturadatum i Fortnox' ?></dt><dd id="invoice-review-fortnox-date"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Not recorded yet' : 'Inte registrerat ännu' ?></dd></div>
                        <div class="invoice-review-wide"><dt><?= ($portalLanguage ?? 'sv') === 'en' ? 'What is the record for?' : 'Vad gäller underlaget?' ?></dt><dd id="invoice-review-description"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Not specified' : 'Inte angivet' ?></dd></div>
                    </dl>
                    <div class="invoice-review-lines">
                        <h4><?= ($portalLanguage ?? 'sv') === 'en' ? 'Work and materials' : 'Arbete och material' ?></h4>
                        <ul id="invoice-review-items"></ul>
                    </div>
                    <p class="invoice-review-note">Det här är fortfarande ett fakturaunderlag, inte en faktura. Det skickas inte till kunden.</p>
                </section>

                <nav class="invoice-step-navigation" id="invoice-step-navigation" data-mobile-action-dock aria-label="Steg i fakturaunderlaget">
                    <div class="invoice-step-progress">
                        <p id="invoice-step-status" aria-live="polite">Steg 1 av 3: Kund och uppdrag</p>
                        <div class="invoice-step-progress-track" id="invoice-step-progress" role="progressbar" aria-label="<?= ($portalLanguage ?? 'sv') === 'en' ? 'Fill in invoice record' : 'Fyll i fakturaunderlaget' ?>" aria-valuemin="1" aria-valuemax="3" aria-valuenow="1"><span></span></div>
                    </div>
                    <div class="invoice-step-buttons">
                        <button class="btn btn-outline" type="button" id="invoice-step-back" hidden><?= ($portalLanguage ?? 'sv') === 'en' ? '← Back' : '← Tillbaka' ?></button>
                        <button class="btn btn-main" type="button" id="invoice-step-next"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Continue to work lines →' : 'Fortsätt till arbetsrader →' ?></button>
                        <button class="btn btn-main" type="submit" id="invoice-step-save" hidden><?= $isEditingInvoice ? 'Spara ändringar' : 'Spara fakturaunderlag' ?></button>
                    </div>
                </nav>

                <div class="invoice-submit-step" data-invoice-step="3">
                    <div class="invoice-actions">
                        <?php if ($isEditingInvoice): ?>
                            <a class="btn btn-outline" href="?tab=invoice"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Cancel changes' : 'Avbryt ändringar' ?></a>
                        <?php endif; ?>
                        <button class="btn btn-outline" type="button" id="invoice-print"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Print / save as PDF' : 'Skriv ut / spara som PDF' ?></button>
                        <button class="btn btn-main" type="submit"><?= $isEditingInvoice ? 'Spara ändringar' : 'Spara fakturaunderlag' ?></button>
                    </div>
                    <p class="invoice-note" id="invoice-form-note"><?= ($portalLanguage ?? 'sv') === 'en' ? 'The record is recalculated and validated again when saved. An internal notification is sent to the portal\'s primary email recipient; no customer copy is sent.' : 'Underlaget räknas om och valideras igen när det sparas. En intern avisering skickas till portalens primära e-postmottagare; ingen kundkopia skickas.' ?></p>
                </div>
            </form>
        </div>
    </article>
    <?php elseif ($invoiceView === 'processing'): ?>
        <section class="invoice-admin-panel invoice-record-panel" aria-labelledby="invoice-processing-heading">
            <header class="invoice-admin-header">
                <div>
                    <p class="invoice-admin-eyebrow"><?= ($portalLanguage ?? 'sv') === 'en' ? 'IN PROGRESS' : 'UNDER BEHANDLING' ?></p>
                    <h2 id="invoice-processing-heading"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Saved records not yet invoiced' : 'Sparade underlag som inte har fakturerats' ?></h2>
                    <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'Records without a Fortnox invoice number appear here. An administrator enters the invoice date and number, moving the record to History.' : 'Här visas underlag som saknar Fortnox-fakturanummer. Admin anger fakturadatum och nummer; då flyttas underlaget till Historik.' ?></p>
                </div>
                <span class="invoice-admin-badge"><?= count($invoiceProcessingRecords) ?> underlag</span>
            </header>
            <?php if ($invoiceProcessingRecords): ?>
                <div class="invoice-record-search">
                    <label for="invoice-processing-search"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Search customer, reference or text' : 'Sök på kund, referens eller fritext' ?></label>
                    <input id="invoice-processing-search" type="search" data-invoice-record-search aria-controls="invoice-processing-table" data-count-id="invoice-processing-search-count" placeholder="Kund, underlagsnr, beskrivning…" autocomplete="off">
                    <p id="invoice-processing-search-count" class="invoice-record-search-count" role="status" aria-live="polite"></p>
                </div>
                <div class="invoice-record-table-wrap" role="region" aria-label="Underlag som väntar på fakturering" tabindex="0">
                    <table class="invoice-record-table" id="invoice-processing-table">
                        <caption class="invoice-sr-only">Sparade fakturaunderlag som ännu saknar Fortnox-fakturanummer</caption>
                        <thead><tr><th scope="col">Underlagsnr</th><th scope="col"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Record date' : 'Underlagsdatum' ?></th><th scope="col"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Customer and reference' : 'Kund och referens' ?></th><th scope="col"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Saved total' : 'Sparad summa' ?></th><th scope="col"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Internal email notification' : 'Intern e-postavisering' ?></th><th scope="col"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Invoice date and Fortnox number' : 'Fakturadatum och Fortnox-nummer' ?></th><th scope="col"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Actions' : 'Åtgärder' ?></th></tr></thead>
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
                                                <button class="btn btn-main" type="submit"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Save &amp; move' : 'Spara &amp; flytta' ?></button>
                                            </form>
                                        <?php else: ?>
                                            <span class="invoice-record-muted"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Waiting for administrator\'s invoice number' : 'Väntar på adminens fakturanummer' ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Åtgärder"><div class="invoice-record-actions"><a class="btn btn-outline" href="?tab=invoice&amp;edit_id=<?= rawurlencode($invoiceRecordId) ?>"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Open' : 'Öppna' ?></a><a class="btn btn-outline" href="?tab=invoice&amp;copy_id=<?= rawurlencode($invoiceRecordId) ?>"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Copy' : 'Kopiera' ?></a></div></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="invoice-record-search-empty" data-invoice-search-empty-for="invoice-processing-table" hidden><?= ($portalLanguage ?? 'sv') === 'en' ? 'No records match the search.' : 'Inga underlag matchar sökningen.' ?></p>
            <?php else: ?>
                <div class="invoice-catalog-empty"><strong><?= ($portalLanguage ?? 'sv') === 'en' ? 'No records are awaiting invoicing.' : 'Inga underlag väntar på fakturering.' ?></strong><p>Skapa ett nytt underlag eller öppna Historik för redan fakturerade underlag.</p><a class="btn btn-main" href="?tab=invoice"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Create new record' : 'Skapa nytt underlag' ?></a></div>
            <?php endif; ?>
        </section>
    <?php elseif ($invoiceView === 'invoice_history'): ?>
        <section class="invoice-admin-panel invoice-record-panel" aria-labelledby="invoice-history-heading">
            <header class="invoice-admin-header">
                <div>
                    <p class="invoice-admin-eyebrow"><?= ($portalLanguage ?? 'sv') === 'en' ? 'HISTORY' : 'HISTORIK' ?></p>
                    <h2 id="invoice-history-heading"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Invoiced records' : 'Fakturerade underlag' ?></h2>
                    <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'Saved records with a Fortnox invoice number appear here. An administrator can change the number and invoice date via Open.' : 'Här samlas sparade underlag som har fått ett Fortnox-fakturanummer. Nummer och fakturadatum kan ändras av admin via Öppna.' ?></p>
                </div>
                <span class="invoice-admin-badge"><?= count($invoiceHistoryRecords) ?> underlag</span>
            </header>
            <?php if ($invoiceHistoryRecords): ?>
                <div class="invoice-record-search">
                    <label for="invoice-history-search"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Search customer, reference or text' : 'Sök på kund, referens eller fritext' ?></label>
                    <input id="invoice-history-search" type="search" data-invoice-record-search aria-controls="invoice-history-table" data-count-id="invoice-history-search-count" placeholder="Kund, Fortnox-nr, beskrivning…" autocomplete="off">
                    <p id="invoice-history-search-count" class="invoice-record-search-count" role="status" aria-live="polite"></p>
                </div>
                <div class="invoice-record-table-wrap" role="region" aria-label="<?= ($portalLanguage ?? 'sv') === 'en' ? 'Invoiced records' : 'Fakturerade underlag' ?>" tabindex="0">
                    <table class="invoice-record-table" id="invoice-history-table">
                        <caption class="invoice-sr-only">Fakturerade underlag med Fortnox-fakturanummer</caption>
                        <thead><tr><th scope="col">Underlagsnr</th><th scope="col">Fortnox-nr</th><th scope="col"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Record date' : 'Underlagsdatum' ?></th><th scope="col"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Invoice date' : 'Fakturadatum' ?></th><th scope="col"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Customer and reference' : 'Kund och referens' ?></th><th scope="col"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Saved total' : 'Sparad summa' ?></th><th scope="col"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Internal email notification' : 'Intern e-postavisering' ?></th><th scope="col"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Actions' : 'Åtgärder' ?></th></tr></thead>
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
                                    <td data-label="Åtgärder"><div class="invoice-record-actions"><a class="btn btn-outline" href="?tab=invoice&amp;edit_id=<?= rawurlencode($invoiceRecordId) ?>"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Open' : 'Öppna' ?></a><a class="btn btn-outline" href="?tab=invoice&amp;copy_id=<?= rawurlencode($invoiceRecordId) ?>"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Copy' : 'Kopiera' ?></a></div></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="invoice-record-search-empty" data-invoice-search-empty-for="invoice-history-table" hidden><?= ($portalLanguage ?? 'sv') === 'en' ? 'No records match the search.' : 'Inga underlag matchar sökningen.' ?></p>
            <?php else: ?>
                <div class="invoice-catalog-empty"><strong><?= ($portalLanguage ?? 'sv') === 'en' ? 'History is empty.' : 'Historiken är tom.' ?></strong><p>När admin sparar Fortnox-fakturanummer och fakturadatum under Under behandling visas underlaget här.</p></div>
            <?php endif; ?>
        </section>
    <?php elseif ($invoiceView === 'articles'): ?>
        <section class="invoice-admin-panel invoice-catalog-panel" aria-labelledby="invoice-articles-heading">
            <header class="invoice-admin-header">
                <div>
                    <p class="invoice-admin-eyebrow"><?= ($portalLanguage ?? 'sv') === 'en' ? 'ITEM CATALOG' : 'ARTIKELKATALOG' ?></p>
                    <h2 id="invoice-articles-heading"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Items' : 'Artiklar' ?></h2>
                    <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'A shared list. Invoice administrators control fixed-price items; other items can be managed by all invoice users.' : 'En gemensam lista. Fakturaadmin styr fastprisartiklar; övriga artiklar kan hanteras av alla fakturaanvändare.' ?></p>
                </div>
                <span class="invoice-admin-badge"><?= count($invoiceArticles) ?> artiklar</span>
            </header>
            <?php if (!$invoiceCatalogStorageReady): ?>
                <div class="app-alert app-alert-error invoice-email-warning" role="alert">Artikelkatalogen kunde inte läsas säkert. Inga ändringar har gjorts. Kontrollera privat lagring under storage.</div>
            <?php else: ?>
                <p class="invoice-catalog-note">Katalogen sparar beskrivning, enhet och pris per enhet. Momsvalet på fakturaunderlaget sparas endast som information; portalen beräknar inte moms. Artikelns enhet och pris förs över oförändrade till fakturaraden.</p>
                <section class="invoice-catalog-editor" id="invoice-article-editor" aria-labelledby="invoice-article-form-heading">
                    <h3 id="invoice-article-form-heading"><?= is_array($invoiceEditingArticle) ? 'Ändra artikel' : 'Lägg till artikel' ?></h3>
                    <?php if (is_array($invoiceEditingArticle) && !empty($invoiceEditingArticle['fixed_price']) && !$canManageFixedPrices): ?>
                        <p role="alert"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Only an invoice administrator can change a fixed-price item.' : 'Endast fakturaadmin kan ändra en fastprisartikel.' ?></p>
                    <?php else: ?>
                    <form method="post" action="?tab=invoice&amp;invoice_view=articles<?= $invoiceContextHtml ?>" class="invoice-catalog-form" autocomplete="off">
                        <input type="hidden" name="action" value="invoice_catalog_save_article">
                        <input type="hidden" name="article[id]" value="<?= h($invoiceEditingArticle['id'] ?? '') ?>">
                        <?= csrf_field() ?>
                        <div class="invoice-catalog-fields">
                            <div class="invoice-field">
                                <label for="invoice-article-name"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Description' : 'Beskrivning' ?></label>
                                <input id="invoice-article-name" name="article[name]" maxlength="200" value="<?= h($invoiceEditingArticle['name'] ?? '') ?>" placeholder="Ex. Reservdel eller material" required>
                            </div>
                            <div class="invoice-field">
                                <label for="invoice-article-unit"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Unit' : 'Enhet' ?></label>
                                <select id="invoice-article-unit" name="article[unit]" required>
                                    <?php foreach ($invoiceArticleUnits as $invoiceUnitValue => $invoiceUnitLabel): ?>
                                        <option value="<?= h($invoiceUnitValue) ?>" <?= ($invoiceEditingArticle['unit'] ?? 'st') === $invoiceUnitValue ? 'selected' : '' ?>><?= h($invoiceUnitLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="invoice-field">
                                <label for="invoice-article-price"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Price per unit, SEK' : 'Pris per enhet, kr' ?></label>
                                <input id="invoice-article-price" name="article[price]" type="number" min="0" max="10000000" step="0.01" inputmode="decimal" value="<?= is_array($invoiceEditingArticle) ? h(number_format((float) $invoiceEditingArticle['price'], 2, '.', '')) : '' ?>" placeholder="0,00" required>
                            </div>
                        </div>
                        <?php if ($canManageFixedPrices): ?>
                            <label for="invoice-article-aliases"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Alternative names for voice input, one per line' : 'Alternativa namn för röstinmatning, ett per rad' ?></label>
                            <textarea id="invoice-article-aliases" name="article[aliases]" maxlength="16000" rows="3" placeholder="Rutbyte&#10;Vindrutebyte"><?= h(implode("\n", is_array($invoiceEditingArticle['aliases'] ?? null) ? $invoiceEditingArticle['aliases'] : [])) ?></textarea>
                            <p class="invoice-catalog-note"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Up to 20 unique names. Exact matches identify the item; uncertain matches must be reviewed.' : 'Högst 20 unika namn. Exakta träffar identifierar artikeln; osäkra träffar måste granskas.' ?></p>
                            <label><input type="checkbox" name="article[fixed_price]" value="1" <?= !empty($invoiceEditingArticle['fixed_price']) ? 'checked' : '' ?>> <?= ($portalLanguage ?? 'sv') === 'en' ? 'Fixed price: lock this unit price on invoice lines' : 'Fast pris: lås enhetspriset på fakturarader' ?></label>
                            <p class="invoice-catalog-note"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Only invoice administrators can change or remove this item. Saved records retain their historical price.' : 'Endast fakturaadmin kan ändra eller ta bort artikeln. Sparade underlag behåller sitt historiska pris.' ?></p>
                        <?php endif; ?>
                        <div class="invoice-catalog-form-actions">
                            <button class="btn btn-main" type="submit"><?= is_array($invoiceEditingArticle) ? 'Spara ändring' : 'Lägg till i katalogen' ?></button>
                            <?php if (is_array($invoiceEditingArticle)): ?>
                                <a class="btn btn-outline" href="?tab=invoice&amp;invoice_view=articles<?= $invoiceContextHtml ?>"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Cancel edit' : 'Avbryt ändring' ?></a>
                            <?php endif; ?>
                        </div>
                    </form>
                    <?php endif; ?>
                </section>
                <?php if ($canManageFixedPrices): ?>
                    <section class="invoice-price-history" id="invoice-shared-learning" aria-labelledby="invoice-shared-heading">
                        <h3 id="invoice-shared-heading"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Shared voice corrections for review' : 'Delade rösträttningar att granska' ?></h3>
                        <p class="invoice-catalog-note"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Only users who explicitly share are shown. Review the text carefully and choose the correct item. Approval adds the recognized text as a shared alternative name; prices are never changed.' : 'Här visas endast användare som uttryckligen delar. Kontrollera texten och välj rätt artikel. Godkännande lägger till den igenkända texten som gemensamt alternativnamn; priser ändras aldrig.' ?></p>
                        <?php if (!$sharedRows): ?><p><?= ($portalLanguage ?? 'sv') === 'en' ? 'No shared corrections to review.' : 'Inga delade rättningar att granska.' ?></p>
                        <?php else: ?><ul class="invoice-price-history-list">
                            <?php foreach ($sharedRows as $sharedRow): ?>
                            <li>
                                <p><strong><?= ($portalLanguage ?? 'sv') === 'en' ? 'Recognized:' : 'Igenkänt:' ?></strong> <?= h($sharedRow['heard']) ?><br>
                                <strong><?= ($portalLanguage ?? 'sv') === 'en' ? 'Corrected:' : 'Rättat:' ?></strong> <?= h($sharedRow['approved']) ?><br>
                                <small><?= ($portalLanguage ?? 'sv') === 'en' ? 'Shared by:' : 'Delat av:' ?> <?= h($sharedRow['source_name']) ?></small></p>
                                <form method="post" action="?tab=invoice&amp;invoice_view=articles#invoice-shared-learning">
                                    <input type="hidden" name="action" value="invoice_shared_approve">
                                    <input type="hidden" name="shared_user" value="<?= h($sharedRow['source_id']) ?>">
                                    <input type="hidden" name="shared_heard" value="<?= h($sharedRow['heard']) ?>">
                                    <input type="hidden" name="shared_approved" value="<?= h($sharedRow['approved']) ?>">
                                    <?= csrf_field() ?>
                                    <label><?= ($portalLanguage ?? 'sv') === 'en' ? 'Add as an alternative name for' : 'Lägg till som alternativnamn för' ?>
                                        <select name="shared_article" required><option value=""><?= ($portalLanguage ?? 'sv') === 'en' ? 'Choose item…' : 'Välj artikel…' ?></option>
                                            <?php foreach ($invoiceArticles as $sharedArticle): ?><option value="<?= h($sharedArticle['id']) ?>"><?= h($sharedArticle['name']) ?> · <?= h($invoiceArticleUnits[$sharedArticle['unit']] ?? $sharedArticle['unit']) ?><?= $sharedArticle['fixed_price'] ? (($portalLanguage ?? 'sv') === 'en' ? ' · fixed price' : ' · fast pris') : '' ?></option><?php endforeach; ?>
                                        </select>
                                    </label>
                                    <button class="btn btn-outline" type="submit"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Approve alternative name' : 'Godkänn alternativnamn' ?></button>
                                </form>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if ($sharedPages > 1): ?><nav class="invoice-price-history-pages" aria-label="<?= ($portalLanguage ?? 'sv') === 'en' ? 'Shared correction pages' : 'Sidor för delade rättningar' ?>">
                            <?php if ($sharedPage > 1): ?><a class="btn btn-outline" href="?tab=invoice&amp;invoice_view=articles&amp;shared_page=<?= $sharedPage - 1 ?>#invoice-shared-learning"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Previous' : 'Föregående' ?></a><?php endif; ?>
                            <span><?= ($portalLanguage ?? 'sv') === 'en' ? 'Page' : 'Sida' ?> <?= $sharedPage ?> / <?= $sharedPages ?></span>
                            <?php if ($sharedPage < $sharedPages): ?><a class="btn btn-outline" href="?tab=invoice&amp;invoice_view=articles&amp;shared_page=<?= $sharedPage + 1 ?>#invoice-shared-learning"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Next' : 'Nästa' ?></a><?php endif; ?>
                        </nav><?php endif; ?>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
                <?php if ($canManageFixedPrices): ?>
                    <section class="invoice-price-history" id="invoice-shared-summary" aria-labelledby="invoice-shared-summary-heading">
                        <h3 id="invoice-shared-summary-heading"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Shared corrections by item' : 'Delade rösträttningar per artikel' ?></h3>
                        <p class="invoice-catalog-note"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Number of currently sharing active accounts with each saved correction, not the number of times speech was recognized. Only unambiguous matches are included; opting out removes the contribution.' : 'Antal aktiva, delande konton med varje sparad rättning, inte antal gånger talet har känts igen. Bara entydiga artikelträffar räknas; bidraget försvinner vid stoppad delning.' ?></p>
                        <?php if (!$statsRows): ?><p><?= ($portalLanguage ?? 'sv') === 'en' ? 'No matching shared corrections.' : 'Inga matchande delade rättningar.' ?></p><?php else: ?><ul class="invoice-price-history-list">
                        <?php foreach ($statsRows as $stat): ?><li><strong><?= h($stat['article_name']) ?></strong> · <?= ($portalLanguage ?? 'sv') === 'en' ? 'Recognized:' : 'Igenkänt:' ?> <?= h($stat['heard']) ?> · <?= (int)$stat['contributors'] ?> <?= ($portalLanguage ?? 'sv') === 'en' ? 'sharing accounts' : 'delande konton' ?></li><?php endforeach; ?>
                        </ul><?php if ($statsPages > 1): ?><nav class="invoice-price-history-pages" aria-label="<?= ($portalLanguage ?? 'sv') === 'en' ? 'Correction summary pages' : 'Sidor för rättningsöversikten' ?>">
                        <?php if ($statsPage > 1): ?><a class="btn btn-outline" href="?tab=invoice&amp;invoice_view=articles&amp;shared_stats_page=<?= $statsPage - 1 ?>#invoice-shared-summary"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Previous' : 'Föregående' ?></a><?php endif; ?>
                        <span><?= ($portalLanguage ?? 'sv') === 'en' ? 'Page' : 'Sida' ?> <?= $statsPage ?> / <?= $statsPages ?></span>
                        <?php if ($statsPage < $statsPages): ?><a class="btn btn-outline" href="?tab=invoice&amp;invoice_view=articles&amp;shared_stats_page=<?= $statsPage + 1 ?>#invoice-shared-summary"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Next' : 'Nästa' ?></a><?php endif; ?></nav><?php endif; ?><?php endif; ?>
                    </section>
                <?php endif; ?>
                <?php if ($canManageFixedPrices): ?>
                    <section class="invoice-price-history" id="invoice-price-history" aria-labelledby="invoice-price-history-heading">
                        <h3 id="invoice-price-history-heading"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Fixed-price history' : 'Fastprishistorik' ?></h3>
                        <p class="invoice-catalog-note"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Changes are recorded from the introduction of this feature. Times are UTC. Saved invoice lines retain their historical prices.' : 'Ändringar registreras från och med att funktionen infördes. Tider visas i UTC. Sparade fakturarader behåller sina historiska priser.' ?></p>
                        <?php if ($fixedPriceHistory === null): ?>
                            <p role="alert"><?= ($portalLanguage ?? 'sv') === 'en' ? 'History could not be read safely; fixed-price changes are stopped until it is repaired.' : 'Historiken kunde inte läsas säkert; ändringar av fasta priser stoppas tills den har återställts.' ?></p>
                        <?php elseif (!$historyRows): ?>
                            <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'No recorded changes yet.' : 'Inga ändringar har registrerats ännu.' ?></p>
                        <?php else: ?>
                            <ol class="invoice-price-history-list">
                                <?php foreach ($historyRows as $historyEvent):
                                    $historyLabels = ($portalLanguage ?? 'sv') === 'en' ? ['created'=>'Created fixed item','enabled'=>'Fixed price enabled','changed'=>'Fixed price changed','disabled'=>'Fixed price disabled','deleted'=>'Fixed item removed'] : ['created'=>'Fastprisartikel skapad','enabled'=>'Fast pris aktiverat','changed'=>'Fast pris ändrat','disabled'=>'Fast pris avaktiverat','deleted'=>'Fastprisartikel borttagen'];
                                    $historyOld = $historyEvent['old_price'] === null ? '–' : number_format((float)$historyEvent['old_price'], 2, ',', ' ') . ' kr / ' . ($invoiceArticleUnits[$historyEvent['old_unit']] ?? $historyEvent['old_unit']);
                                    $historyNew = $historyEvent['new_price'] === null ? '–' : number_format((float)$historyEvent['new_price'], 2, ',', ' ') . ' kr / ' . ($invoiceArticleUnits[$historyEvent['new_unit']] ?? $historyEvent['new_unit']);
                                ?>
                                    <li><div><strong><?= h($historyEvent['article_name']) ?></strong> · <?= h($historyLabels[$historyEvent['action']] ?? '') ?></div>
                                        <div><?= h($historyOld) ?> → <?= h($historyNew) ?></div>
                                        <small><time datetime="<?= h($historyEvent['at']) ?>"><?= h(str_replace(['T','Z'], [' ',' UTC'], $historyEvent['at'])) ?></time> · <?= h($historyEvent['actor_name']) ?> (<?= h($historyEvent['actor_id']) ?>)</small>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                            <?php if ($historyPages > 1): ?><nav class="invoice-price-history-pages" aria-label="<?= ($portalLanguage ?? 'sv') === 'en' ? 'Price history pages' : 'Sidor i prishistoriken' ?>">
                                <?php if ($historyPage > 1): ?><a class="btn btn-outline" href="?tab=invoice&amp;invoice_view=articles&amp;history_page=<?= $historyPage - 1 ?>#invoice-price-history"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Newer' : 'Nyare' ?></a><?php endif; ?>
                                <span><?= ($portalLanguage ?? 'sv') === 'en' ? 'Page' : 'Sida' ?> <?= $historyPage ?> / <?= $historyPages ?></span>
                                <?php if ($historyPage < $historyPages): ?><a class="btn btn-outline" href="?tab=invoice&amp;invoice_view=articles&amp;history_page=<?= $historyPage + 1 ?>#invoice-price-history"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Older' : 'Äldre' ?></a><?php endif; ?>
                            </nav><?php endif; ?>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
                <?php if ($invoiceArticles): ?>
                    <div class="invoice-catalog-toolbar">
                        <label for="invoice-article-search"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Search catalog' : 'Sök i katalogen' ?></label>
                        <input id="invoice-article-search" type="search" placeholder="Skriv en beskrivning…" autocomplete="off">
                        <span><?= count($invoiceArticles) ?> artiklar</span>
                    </div>
                    <ul class="invoice-catalog-list" id="invoice-catalog-list">
                        <?php foreach ($invoiceArticles as $invoiceArticle): ?>
                            <li class="invoice-catalog-card" data-invoice-catalog-card data-search="<?= h($invoiceArticle['name'] . ' ' . ($invoiceArticleUnits[$invoiceArticle['unit']] ?? 'st')) ?>">
                                <div class="invoice-catalog-card-details">
                                    <h3><?= h($invoiceArticle['name']) ?> <?php if (!empty($invoiceArticle['fixed_price'])): ?><small><?= ($portalLanguage ?? 'sv') === 'en' ? '(fixed price)' : '(fast pris)' ?></small><?php endif; ?></h3>
                                    <strong><?= number_format((float) $invoiceArticle['price'], 2, ',', ' ') ?> kr / <?= h($invoiceArticleUnits[$invoiceArticle['unit']] ?? 'st') ?></strong>
                                </div>
                                <div class="invoice-catalog-card-actions">
                                    <?php if (empty($invoiceArticle['fixed_price']) || $canManageFixedPrices): ?>
                                    <a class="btn btn-outline" href="?tab=invoice&amp;invoice_view=articles<?= $invoiceContextHtml ?>&amp;edit_article=<?= rawurlencode($invoiceArticle['id']) ?>#invoice-article-editor"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Edit' : 'Ändra' ?></a>
                                    <form method="post" action="?tab=invoice&amp;invoice_view=articles<?= $invoiceContextHtml ?>" onsubmit="return confirm('Ta bort artikeln från katalogen?')">
                                        <input type="hidden" name="action" value="invoice_catalog_delete_article">
                                        <input type="hidden" name="article_id" value="<?= h($invoiceArticle['id']) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-outline" type="submit"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Remove' : 'Ta bort' ?></button>
                                    </form>
                                    <?php else: ?><span><?= ($portalLanguage ?? 'sv') === 'en' ? 'Managed by invoice administrator' : 'Hanteras av fakturaadmin' ?></span><?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="invoice-catalog-no-results" id="invoice-article-no-results" hidden><?= ($portalLanguage ?? 'sv') === 'en' ? 'No items match the search.' : 'Inga artiklar matchar sökningen.' ?></p>
                <?php else: ?>
                    <div class="invoice-catalog-empty"><strong><?= ($portalLanguage ?? 'sv') === 'en' ? 'The catalog is empty.' : 'Katalogen är tom.' ?></strong><p>Lägg till den första artikeln ovan. Den kan sedan väljas direkt på fakturaunderlaget.</p></div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php elseif ($invoiceView === 'customers'): ?>
        <section class="invoice-admin-panel invoice-customer-panel" aria-labelledby="invoice-customers-heading">
            <header class="invoice-admin-header">
                <div>
                    <p class="invoice-admin-eyebrow"><?= ($portalLanguage ?? 'sv') === 'en' ? 'EXISTING CUSTOMER REGISTER' : 'BEFINTLIGT KUNDREGISTER' ?></p>
                    <h2 id="invoice-customers-heading"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Customers' : 'Kunder' ?></h2>
                    <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'Select a customer to fill into the invoice record. Customer details are managed in the customer register.' : 'Välj en kund att fylla in i fakturaunderlaget. Kunduppgifter hanteras i det vanliga Kundregistret.' ?></p>
                </div>
                <?php if (empty($portalIsScopedPwa)): ?><a class="btn btn-outline" href="?tab=customers"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Open customer register' : 'Öppna Kundregistret' ?></a><?php endif; ?>
            </header>
            <?php if (!$invoiceCustomerOptions): ?>
                <div class="invoice-catalog-empty"><strong><?= ($portalLanguage ?? 'sv') === 'en' ? 'No customers found.' : 'Inga kunder hittades.' ?></strong><p>Lägg till kunder i Kundregistret och kom sedan tillbaka hit.</p><?php if (empty($portalIsScopedPwa)): ?><a class="btn btn-main" href="?tab=customers"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Go to customer register' : 'Gå till Kundregistret' ?></a><?php endif; ?></div>
            <?php else: ?>
                <div class="invoice-catalog-toolbar">
                    <label for="invoice-customer-search"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Search customers' : 'Sök kunder' ?></label>
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
                                    <label for="invoice-customer-ref-<?= (int) $invoiceCustomerIndex ?>"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Customer reference' : 'Kundens referens' ?></label>
                                    <select id="invoice-customer-ref-<?= (int) $invoiceCustomerIndex ?>" name="customer_ref">
                                        <option value=""><?= ($portalLanguage ?? 'sv') === 'en' ? 'Without reference' : 'Utan referens' ?></option>
                                        <?php foreach ($invoiceCustomer['references'] as $invoiceCustomerReference): ?>
                                            <option value="<?= h($invoiceCustomerReference) ?>"><?= h($invoiceCustomerReference) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button class="btn btn-main" type="submit"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Fill in invoice record' : 'Fyll i fakturaunderlaget' ?></button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
                <p class="invoice-catalog-no-results" id="invoice-customer-no-results" hidden><?= ($portalLanguage ?? 'sv') === 'en' ? 'No customers match the search.' : 'Inga kunder matchar sökningen.' ?></p>
            <?php endif; ?>
        </section>
    <?php elseif ($invoiceView === 'email'): ?>
        <section class="invoice-admin-panel" aria-labelledby="invoice-email-heading">
            <header class="invoice-admin-header">
                <div>
                    <p class="invoice-admin-eyebrow">ADMINPORTAL</p>
                    <h2 id="invoice-email-heading">E-poststatus, SMTP-inställningar och testutskick</h2>
                    <p>Översikten visar försök och svar från den aktiva e-posttransporten. Ett accepterat SMTP-/mail()-anrop betyder inte att mejlet nådde inkorgen. <?= empty($portalIsScopedPwa) ? 'Skadeblanketter skickas alltid bara till den av admin valda mottagaren.' : 'Den här vyn gäller endast aviseringar för fakturaunderlag.' ?></p>
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
                    <h3 id="invoice-smtp-heading"><?= ($portalLanguage ?? 'sv') === 'en' ? 'SMTP settings' : 'SMTP-inställningar' ?></h3>
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
                            <div class="invoice-smtp-actions"><button class="btn btn-main" type="submit"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Unlock SMTP settings' : 'Lås upp SMTP-inställningar' ?></button></div>
                        </form>
                    </div>
                </section>
            <?php else: ?>
                <section class="invoice-smtp-settings" aria-labelledby="invoice-smtp-heading">
                    <h3 id="invoice-smtp-heading"><?= ($portalLanguage ?? 'sv') === 'en' ? 'SMTP settings' : 'SMTP-inställningar' ?></h3>
                    <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'Enter the sender SMTP account. The password is stored in a private file with permissions 0600 (not encrypted), is never displayed again, and the field is left blank after saving. The damage form recipient is chosen by central administration, not here.' : 'Fyll i avsändarens SMTP-konto. Lösenordet lagras i en privat fil med rättighet 0600 (inte krypterat), visas aldrig igen och fältet lämnas tomt efter sparning. Skadeblanketternas mottagare väljs av central admin, inte här.' ?></p>
                    <?php if (($emailTransportStatus['transport'] ?? '') === 'smtp' && ($emailTransportStatus['tested'] ?? false) === true): ?>
                        <p class="invoice-smtp-note">Ett manuellt SMTP-test accepterades. Kontrollera även att mejlet kom fram; appen kan bara bekräfta transportens svar.</p>
                        <form method="post" action="?tab=invoice&amp;invoice_view=email">
                            <input type="hidden" name="action" value="invoice_admin_lock_email_transport">
                            <?= csrf_field() ?>
                            <div class="invoice-smtp-actions"><button class="btn btn-outline" type="submit"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Lock SMTP settings' : 'Lås SMTP-inställningarna' ?></button></div>
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
                                <label for="smtp-host"><?= ($portalLanguage ?? 'sv') === 'en' ? 'SMTP server' : 'SMTP-server' ?></label>
                                <input id="smtp-host" name="smtp_host" type="text" maxlength="253" value="<?= h((string) ($emailTransportStatus['host'] ?? 'smtp.m101.websupport.se')) ?>" autocomplete="off" autocapitalize="none" spellcheck="false" required>
                            </div>
                            <div class="invoice-field">
                                <label for="smtp-profile"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Connection' : 'Anslutning' ?></label>
                                <select id="smtp-profile" name="smtp_profile" required>
                                    <option value="ssl465"<?= (int) ($emailTransportStatus['port'] ?? 465) === 465 ? ' selected' : '' ?>>Port 465 · SSL</option>
                                    <option value="tls587"<?= (int) ($emailTransportStatus['port'] ?? 0) === 587 ? ' selected' : '' ?>>Port 587 · TLS</option>
                                </select>
                            </div>
                            <div class="invoice-field">
                                <label for="smtp-username"><?= ($portalLanguage ?? 'sv') === 'en' ? 'SMTP username / email address' : 'SMTP-användare / e-postadress' ?></label>
                                <input id="smtp-username" name="smtp_username" type="email" maxlength="254" value="<?= h((string) ($emailTransportStatus['username'] ?? '')) ?>" autocomplete="off" autocapitalize="none" required>
                            </div>
                            <div class="invoice-field">
                                <label for="smtp-password"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Mailbox password' : 'Brevlådans lösenord' ?></label>
                                <input id="smtp-password" name="smtp_password" type="password" maxlength="360" autocomplete="off" <?= empty($emailTransportStatus['has_password']) ? 'required' : '' ?>>
                                <p class="invoice-help"><?= !empty($emailTransportStatus['has_password']) ? 'Lämna tomt för att behålla det sparade lösenordet; ange ett nytt endast vid byte.' : 'Krävs första gången SMTP konfigureras.' ?></p>
                            </div>
                            <div class="invoice-field">
                                <label for="smtp-from-email"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Sender address' : 'Avsändaradress' ?></label>
                                <input id="smtp-from-email" name="smtp_from_email" type="email" maxlength="254" value="<?= h((string) ($emailTransportStatus['from_email'] ?? '')) ?>" autocomplete="off" autocapitalize="none" required>
                                <p class="invoice-help">Använd en avsändaradress som brevlådan har behörighet att skicka från; vanligtvis samma adress som SMTP-användaren.</p>
                            </div>
                            <div class="invoice-field">
                                <label for="smtp-from-name"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Sender name' : 'Avsändarnamn' ?></label>
                                <input id="smtp-from-name" name="smtp_from_name" type="text" maxlength="120" value="<?= h((string) ($emailTransportStatus['from_name'] ?? 'Dokumentportalen')) ?>" required>
                            </div>
                        </div>
                        <div class="invoice-smtp-actions"><button class="btn btn-main" type="submit"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Save SMTP settings' : 'Spara SMTP-inställningar' ?></button></div>
                    </form>
                </section>
            <?php endif; ?>
            <?php if (!is_array($emailStatus)): ?>

                <div class="app-alert app-alert-error invoice-email-warning" role="alert">E-poststatuslagringen kunde inte läsas säkert. Inga försökshistorik visas; kontrollera privat lagring under storage.</div>
            <?php else: ?>
                <div class="invoice-email-status-grid">
                    <article class="invoice-email-status-card" aria-labelledby="invoice-email-status-title">
                        <h3 id="invoice-email-status-title"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Invoice records' : 'Fakturaunderlag' ?></h3>
                        <p>Mottagare: <strong><?= $emailInvoiceRecipientConfigured ? h($emailInvoicePrimary) : 'Ingen giltig primär mottagare konfigurerad' ?></strong></p>
                        <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'Automatic notifications and test messages are sent only to the configured primary address. No customer copy is sent.' : 'Automatiska aviseringar och testutskick går bara till den konfigurerade primära adressen. Ingen kundkopia skickas.' ?></p>
                        <?php $invoiceEvents = is_array($invoiceEmailEntry['events'] ?? null) ? array_reverse($invoiceEmailEntry['events']) : []; $invoiceLatest = $invoiceEvents[0] ?? null; ?>
                        <dl><dt><?= ($portalLanguage ?? 'sv') === 'en' ? 'Total attempts' : 'Försök totalt' ?></dt><dd><?= (int) ($invoiceEmailEntry['attempts'] ?? 0) ?></dd><dt><?= ($portalLanguage ?? 'sv') === 'en' ? 'Accepted by transport' : 'Transport accepterat' ?></dt><dd><?= (int) ($invoiceEmailEntry['accepted'] ?? 0) ?></dd><dt><?= ($portalLanguage ?? 'sv') === 'en' ? 'Failed attempts' : 'Misslyckade försök' ?></dt><dd><?= (int) ($invoiceEmailEntry['failed'] ?? 0) ?></dd><dt><?= ($portalLanguage ?? 'sv') === 'en' ? 'No valid address' : 'Ingen giltig adress' ?></dt><dd><?= (int) ($invoiceEmailEntry['not_configured'] ?? 0) ?></dd></dl>
                        <?php if ($invoiceEvents): ?>
                            <p><strong><?= ($portalLanguage ?? 'sv') === 'en' ? 'Latest attempt' : 'Senaste försök' ?></strong></p><ol class="invoice-email-events"><?php foreach (array_slice($invoiceEvents, 0, 5) as $emailEvent): $eventAtRaw = is_string($emailEvent['at'] ?? null) ? $emailEvent['at'] : ''; $eventTimestamp = $eventAtRaw !== '' ? strtotime($eventAtRaw) : false; ?><li><strong><?= h(portal_email_status_kind_label((string) ($emailEvent['kind'] ?? ''))) ?></strong><br><?= h($eventTimestamp === false ? 'Okänd tid' : date('Y-m-d H:i', $eventTimestamp)) ?> · <?= h(portal_email_status_transport_label((string) ($emailEvent['transport'] ?? 'php_mail'))) ?> · <?= h(portal_email_status_result_label((string) ($emailEvent['result'] ?? ''))) ?> · <?= h(portal_email_status_code_label((string) ($emailEvent['code'] ?? 'legacy'))) ?></li><?php endforeach; ?></ol>
                        <?php else: ?><p><?= ($portalLanguage ?? 'sv') === 'en' ? 'No email attempts logged yet.' : 'Inga loggade e-postförsök ännu.' ?></p><?php endif; ?>
                        <form class="invoice-email-test-form" method="post" action="?tab=invoice&amp;invoice_view=email"><input type="hidden" name="action" value="invoice_admin_test_email"><?= csrf_field() ?><button class="btn btn-main" type="submit"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Send invoice record test email' : 'Skicka testmejl för fakturaunderlag' ?></button></form>
                        <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'The test email contains no customer data. If the address is missing, the system records that nothing was sent.' : 'Testmejlet innehåller inga kunduppgifter. Om adressen saknas registreras att inget utskick gjordes.' ?></p>
                    </article>
                    <?php if (empty($portalIsScopedPwa)): ?>
                    <article class="invoice-email-status-card" aria-labelledby="skade-email-status-title">
                        <h3 id="skade-email-status-title"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Damage forms' : 'Skadeblanketter' ?></h3>
                        <p>Mottagaren är alltid <strong>vald av admin</strong>. Kundens e-postadress och övriga mottagare används inte.</p>
                        <p>Det manuella testmejlet innehåller inga kunduppgifter och ingen PDF-bilaga.</p>
                        <?php $skadeEvents = is_array($skadeEmailEntry['events'] ?? null) ? array_reverse($skadeEmailEntry['events']) : []; ?>
                        <dl><dt><?= ($portalLanguage ?? 'sv') === 'en' ? 'Total attempts' : 'Försök totalt' ?></dt><dd><?= (int) ($skadeEmailEntry['attempts'] ?? 0) ?></dd><dt><?= ($portalLanguage ?? 'sv') === 'en' ? 'Accepted by transport' : 'Transport accepterat' ?></dt><dd><?= (int) ($skadeEmailEntry['accepted'] ?? 0) ?></dd><dt><?= ($portalLanguage ?? 'sv') === 'en' ? 'Failed attempts' : 'Misslyckade försök' ?></dt><dd><?= (int) ($skadeEmailEntry['failed'] ?? 0) ?></dd><dt><?= ($portalLanguage ?? 'sv') === 'en' ? 'No valid address' : 'Ingen giltig adress' ?></dt><dd><?= (int) ($skadeEmailEntry['not_configured'] ?? 0) ?></dd></dl>
                        <?php if ($skadeEvents): ?>
                            <p><strong><?= ($portalLanguage ?? 'sv') === 'en' ? 'Latest attempt' : 'Senaste försök' ?></strong></p><ol class="invoice-email-events"><?php foreach (array_slice($skadeEvents, 0, 5) as $emailEvent): $eventAtRaw = is_string($emailEvent['at'] ?? null) ? $emailEvent['at'] : ''; $eventTimestamp = $eventAtRaw !== '' ? strtotime($eventAtRaw) : false; ?><li><strong><?= h(portal_email_status_kind_label((string) ($emailEvent['kind'] ?? ''))) ?></strong><br><?= h($eventTimestamp === false ? 'Okänd tid' : date('Y-m-d H:i', $eventTimestamp)) ?> · <?= h(portal_email_status_transport_label((string) ($emailEvent['transport'] ?? 'php_mail'))) ?> · <?= h(portal_email_status_result_label((string) ($emailEvent['result'] ?? ''))) ?> · <?= h(portal_email_status_code_label((string) ($emailEvent['code'] ?? 'legacy'))) ?></li><?php endforeach; ?></ol>
                        <?php else: ?><p><?= ($portalLanguage ?? 'sv') === 'en' ? 'No email attempts logged yet.' : 'Inga loggade e-postförsök ännu.' ?></p><?php endif; ?>
                        <form class="invoice-email-test-form" method="post" action="?tab=invoice&amp;invoice_view=email"><input type="hidden" name="action" value="skade_admin_test_email"><?= csrf_field() ?><button class="btn btn-main" type="submit">Skicka testmejl till den av admin valda mottagaren</button></form>
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
                    <p>Visar PHP-/JavaScriptfel och skadeblankettens validerings-, signatur-, PDF- och lagringsfel. Loggen innehåller bara säkra felkoder, fältidentifierare och tekniska metadata — aldrig formulärvärden, kunduppgifter, innehåll eller autentiserings-/CSRF-token. Konton identifieras med ett stabilt pseudonymiserat ID. <strong>Aktiva fel</strong> listas först och bevaras tills de markeras som åtgärdade; då tas de bort ur felloggen. Råposter arkiveras dagligen, arkiven rensas efter 7 dagar och vyn visar de senaste 200. Skadeblankettens felkoder har en förklaring och föreslagna kontroller. Varje post visar releaseetikett och ett kort SHA-256-byggfingeravtryck; etiketten är ”oversionerad” tills releaseetiketten konfigureras. Ingen information skickas automatiskt till en AI-chatt. Kopiera vid behov en teknisk sammanfattning och granska den innan du delar den.</p>
                </div>
                <span class="invoice-admin-badge">Admin</span>
            </header>
            <?php if (is_array($activeDiagnosticErrors)): ?>
                <section class="invoice-debug-active" aria-label="Aktiva fel">
                    <h3>Aktiva fel</h3>
                    <?php if ($activeDiagnosticErrors === []): ?>
                        <p class="invoice-debug-empty">Inga aktiva fel. Nya fel dyker upp här automatiskt och tas bort när de markeras som åtgärdade.</p>
                    <?php else: ?>
                        <p class="invoice-debug-active-intro">Komplett fellogg över fel som ännu inte åtgärdats. När felet är åtgärdat trycker du på <strong>Markera som åtgärdad</strong> – då försvinner det ur både den aktiva listan och råloggen.</p>
                        <?php foreach ($activeDiagnosticErrors as $activeError): ?>
                            <?php
                            $activeType = (string) ($activeError['type'] ?? '');
                            $activeTitle = match ($activeType) {
                                'php_error' => 'PHP-fel',
                                'php_fatal' => 'Allvarligt PHP-fel',
                                'uncaught_exception' => 'Ohanterat PHP-undantag',
                                'js_error' => 'JavaScriptfel',
                                'js_unhandled_rejection' => 'Ohanterat JavaScriptfel',
                                'app_error' => 'Skadeblankettfel',
                                default => 'Tekniskt fel',
                            };
                            $activeCode = (string) ($activeError['code'] ?? '');
                            $activeFile = (string) ($activeError['file'] ?? 'unknown');
                            $activeLine = (int) ($activeError['line'] ?? 0);
                            $activeCount = (int) ($activeError['count'] ?? 1);
                            $activeLast = strtotime((string) ($activeError['last_seen'] ?? ''));
                            ?>
                            <div class="invoice-debug-active-item">
                                <div>
                                    <strong><?= h($activeTitle) ?><?= $activeCode !== '' ? ' · ' . h($activeCode) : '' ?></strong>
                                    <small><?= h($activeFile) ?><?= $activeLine > 0 ? ':' . $activeLine : '' ?> · <?= $activeCount ?> förekomst<?= $activeCount === 1 ? '' : 'er' ?><?= $activeLast !== false ? ' · senast ' . h(date('Y-m-d H:i', $activeLast)) : '' ?></small>
                                </div>
                                <form method="post" action="?tab=invoice&amp;invoice_view=debug" onsubmit="return confirm('Ta bort detta fel ur felloggen? Det kan dyka upp igen om felet inte är åtgärdat.')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="resolve_diagnostic">
                                    <input type="hidden" name="signature" value="<?= h((string) ($activeError['signature'] ?? '')) ?>">
                                    <button class="btn btn-outline" type="submit">Markera som åtgärdad</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
            <?php if (!is_array($diagnosticEntries)): ?>
                <div class="app-alert app-alert-error invoice-email-warning" role="alert">Felsökningsloggen kunde inte läsas säkert. Kontrollera att logs-mappen och logs/.htaccess finns och att lagringen är åtkomlig.</div>
            <?php elseif (!$diagnosticEntries): ?>
                <p class="invoice-debug-empty">Inga nya tekniska fel i råloggen de senaste 30 dagarna.</p>
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
                            'Dokumentportalen — teknisk felsökningssammanfattning',
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
    var invLang = <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? 'en' : 'sv') ?>;
    function invMsg(sv, en) { return invLang === 'en' ? en : sv; }
                (function () {
                    document.addEventListener('click', function (event) {
                        var button = event.target && event.target.closest ? event.target.closest('.invoice-debug-copy') : null;
                        if (!button) return;
                        var summary = '';
                        try { summary = decodeURIComponent(button.getAttribute('data-debug-summary') || ''); } catch (error) {}
                        var card = button.closest('.invoice-debug-card');
                        var status = card ? card.querySelector('.invoice-debug-copy-status') : null;
                        var showResult = function (copied) {
                            if (status) status.textContent = copied ? 'Sammanfattningen kopierades.' : invMsg('Kopieringen misslyckades. Markera och kopiera texten manuellt.', 'Copying failed. Select and copy the text manually.');
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
                    <h2 id="invoice-admin-heading"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Invoice record accounts' : 'Konton för fakturaunderlag' ?></h2>
                    <p>Skapa personliga PIN-konton och återställ användares PIN. PIN-kontona gäller endast fakturaunderlaget; övriga flikar styrs av portalens proxyinloggning.</p>
                </div>
                <span class="invoice-admin-badge">Admin</span>
            </header>

            <div class="invoice-admin-create">
                <div class="invoice-admin-subheading">
                    <h3><?= ($portalLanguage ?? 'sv') === 'en' ? 'Create user account' : 'Skapa användarkonto' ?></h3>
                    <p>Välj en unik inloggning och en tillfällig eller överenskommen sexsiffrig PIN.</p>
                </div>
                <form method="post" action="?tab=invoice&amp;invoice_view=admin" class="invoice-admin-create-form">
                    <input type="hidden" name="action" value="invoice_admin_create_user">
                    <?= csrf_field() ?>
                    <div class="invoice-admin-create-grid">
                        <div class="invoice-field">
                            <label for="invoice-new-username"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Username' : 'Användarnamn' ?></label>
                            <input id="invoice-new-username" name="user[username]" maxlength="120" autocomplete="off" required>
                        </div>
                        <div class="invoice-field">
                            <label for="invoice-new-name"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Display name' : 'Visningsnamn' ?></label>
                            <input id="invoice-new-name" name="user[name]" maxlength="120" autocomplete="name" required>
                        </div>
                        <div class="invoice-field">
                            <label for="invoice-new-pin"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Six-digit PIN' : 'Sexsiffrig PIN' ?></label>
                            <input id="invoice-new-pin" type="password" name="user[pin]" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="new-password" placeholder="6 siffror" required>
                        </div>
                        <div class="invoice-field">
                            <label for="invoice-new-role"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Access' : 'Behörighet' ?></label>
                            <select id="invoice-new-role" name="user[role]">
                                <option value="staff" selected><?= ($portalLanguage ?? 'sv') === 'en' ? 'Staff' : 'Medarbetare' ?></option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div class="invoice-admin-create-submit">
                            <button class="btn btn-main" type="submit"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Create account' : 'Skapa konto' ?></button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="invoice-admin-users">
                <div class="invoice-admin-subheading">
                    <h3><?= ($portalLanguage ?? 'sv') === 'en' ? 'User accounts' : 'Användarkonton' ?></h3>
                    <p>En PIN-återställning avslutar användarens befintliga inloggningar.</p>
                </div>
                <?php if (!$invoiceUsers): ?>
                    <p class="invoice-admin-empty"><?= ($portalLanguage ?? 'sv') === 'en' ? 'No accounts registered.' : 'Inga konton är registrerade.' ?></p>
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
                                        <button class="btn btn-outline" type="submit"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Reset PIN' : 'Återställ PIN' ?></button>
                                    </form>
                                <?php elseif ($accountId === $currentInvoiceUserId): ?>
                                    <p class="invoice-user-inactive-note">Ditt konto – be en annan admin återställa din PIN.</p>
                                <?php else: ?>
                                    <p class="invoice-user-inactive-note"><?= ($portalLanguage ?? 'sv') === 'en' ? 'The account is inactive.' : 'Kontot är inaktivt.' ?></p>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (empty($portalIsScopedPwa)): ?>
            <div class="invoice-admin-create">
                <div class="invoice-admin-subheading">
                    <h3><?= ($portalLanguage ?? 'sv') === 'en' ? 'Service report accounts' : 'Serviceprotokollkonton' ?></h3>
                    <p>Separata användarnamn och sexsiffriga PIN-koder för serviceprotokoll. Dessa konton och sessioner gäller inte Fakturaunderlaget.</p>
                </div>
                <form method="post" action="?tab=invoice&amp;invoice_view=admin" class="invoice-admin-create-form">
                    <input type="hidden" name="action" value="protocol_admin_create_user">
                    <?= csrf_field() ?>
                    <div class="invoice-admin-create-grid">
                        <div class="invoice-field">
                            <label for="protocol-new-username"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Username' : 'Användarnamn' ?></label>
                            <input id="protocol-new-username" name="protocol_user[username]" maxlength="120" autocomplete="off" required>
                        </div>
                        <div class="invoice-field">
                            <label for="protocol-new-name"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Display name' : 'Visningsnamn' ?></label>
                            <input id="protocol-new-name" name="protocol_user[name]" maxlength="120" autocomplete="name" required>
                        </div>
                        <div class="invoice-field">
                            <label for="protocol-new-pin"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Six-digit PIN' : 'Sexsiffrig PIN' ?></label>
                            <input id="protocol-new-pin" type="password" name="protocol_user[pin]" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="new-password" placeholder="6 siffror" required>
                        </div>
                        <div class="invoice-field">
                            <label for="protocol-new-role"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Service report access' : 'Protokollbehörighet' ?></label>
                            <select id="protocol-new-role" name="protocol_user[role]">
                                <option value="staff" selected><?= ($portalLanguage ?? 'sv') === 'en' ? 'Staff' : 'Medarbetare' ?></option>
                                <option value="admin">Admin – får spara protokollartiklar</option>
                            </select>
                        </div>
                        <div class="invoice-admin-create-submit">
                            <button class="btn btn-main" type="submit"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Create service report account' : 'Skapa protokollkonto' ?></button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="invoice-admin-users">
                <div class="invoice-admin-subheading">
                    <h3><?= ($portalLanguage ?? 'sv') === 'en' ? 'Service report users' : 'Protokollanvändare' ?></h3>
                    <p>Återställning av PIN ogiltigförklarar tidigare protokollsessioner för den användaren.</p>
                </div>
                <?php if (!$protocolUsers): ?>
                    <p class="invoice-admin-empty"><?= ($portalLanguage ?? 'sv') === 'en' ? 'No service report accounts registered.' : 'Inga protokollkonton är registrerade.' ?></p>
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
                                        <span class="invoice-role-chip"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Service reports' : 'Serviceprotokoll' ?></span>
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
                                            <option value="staff" <?= ($account['role'] ?? 'staff') === 'staff' ? 'selected' : '' ?>><?= ($portalLanguage ?? 'sv') === 'en' ? 'Staff' : 'Medarbetare' ?></option>
                                            <option value="admin" <?= ($account['role'] ?? 'staff') === 'admin' ? 'selected' : '' ?>>Admin</option>
                                        </select>
                                        <button class="btn btn-outline" type="submit"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Save role' : 'Spara roll' ?></button>
                                    </form>
                                    <form method="post" action="?tab=invoice&amp;invoice_view=admin" class="invoice-reset-form">
                                        <input type="hidden" name="action" value="protocol_admin_reset_pin">
                                        <input type="hidden" name="protocol_user_id" value="<?= h($accountId) ?>">
                                        <?= csrf_field() ?>
                                        <label class="invoice-sr-only" for="<?= h($pinInputId) ?>">Ny sexsiffrig protokoll-PIN för <?= h($account['name'] ?? $account['username'] ?? '') ?></label>
                                        <input id="<?= h($pinInputId) ?>" type="password" name="protocol_new_pin" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="new-password" placeholder="Ny PIN · 6 siffror" required>
                                        <button class="btn btn-outline" type="submit"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Reset PIN' : 'Återställ PIN' ?></button>
                                    </form>
                                <?php else: ?>
                                    <p class="invoice-user-inactive-note"><?= ($portalLanguage ?? 'sv') === 'en' ? 'The account is inactive.' : 'Kontot är inaktivt.' ?></p>
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

<script src="<?= h($portalAssetEntry ?? './index.php') ?>?view_file=assets/invoice_voice.js&amp;v=<?= (string) (@filemtime(__DIR__ . '/../assets/invoice_voice.js') ?: '1') ?>"></script>
<script>
(function () {
    'use strict';
    var invLang = <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? 'en' : 'sv') ?>;
    function invMsg(sv, en) { return invLang === 'en' ? en : sv; }

    var form = document.getElementById('invoice-form');
    var table = document.getElementById('invoice-table');
    if (!form || !table) return;

    var body = table.tBodies[0];
    var vatMode = document.getElementById('invoice-vat-mode');
    var addRowButton = document.getElementById('invoice-add-row');
    var rowNote = document.getElementById('invoice-row-note');
    var errorBox = document.getElementById('invoice-form-error');
    var maxRows = 100;
    var priorPrices = <?= json_encode($invoicePriorPrices, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
    var nextIndex = body.rows.length;
    var fixedCatalogAvailable = <?= $invoiceCatalogStorageReady && is_array(portal_invoice_catalog_fixed_rules($invoiceCatalogRecords)) ? 'true' : 'false' ?>;
    var fixedCatalog = <?= json_encode($invoiceArticles, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
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
    var stepNames = [<?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "Customer and assignment" : "Kund och uppdrag", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "Work and materials" : "Arbete och material", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "Review record" : "Granska underlaget", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>];
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
            setAutosaveMessage(invMsg('Autosparning är inte tillgänglig just nu. Spara underlaget manuellt.', 'Autosave is not available right now. Save the record manually.'));
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
                setAutosaveMessage(invMsg('Utkastet har raderats eftersom formuläret är tomt.', 'The draft was deleted because the form is empty.'));
                return;
            }
            if (data.draft_id) {
                draftExists = true;
                if (autosaveResume) {
                    autosaveResume.href = '?tab=invoice&draft_id=' + encodeURIComponent(data.draft_id);
                    autosaveResume.hidden = false;
                }
                setAutosaveMessage(invMsg('Utkastet har sparats automatiskt.', 'The draft has been saved automatically.'));
                return;
            }
            setAutosaveMessage('Inga utkastuppgifter att spara ännu.');
        }).catch(function () {
            autosaveLastSucceeded = false;
            setAutosaveMessage(invMsg('Autosparning kunde inte bekräftas. Spara underlaget innan du lämnar sidan.', 'Autosave could not be confirmed. Save the record before you leave the page.'));
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
                var proceed = window.confirm(<?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "Autosave could not be confirmed. Your changes may be lost if you leave now. Continue anyway?" : "Autosparningen kunde inte bekräftas. Om du lämnar sidan nu kan ändringarna gå förlorade. Gå vidare ändå?", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
                if (!proceed) {
                    subtabNavigationPending = false;
                    return;
                }
            }
            window.location.href = link.href;
        }).catch(function () {
            var proceed = window.confirm(<?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "Autosave could not be confirmed. Your changes may be lost if you leave now. Continue anyway?" : "Autosparningen kunde inte bekräftas. Om du lämnar sidan nu kan ändringarna gå förlorade. Gå vidare ändå?", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
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
            empty.textContent = <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "No work lines have been added." : "Inga arbetsrader har lagts till.", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
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
            stepNextButton.textContent = currentStep === 1 ? <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "Continue to work lines →" : "Fortsätt till arbetsrader →", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> : <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "Review record →" : "Granska underlaget →", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        }
        if (stepSaveButton) stepSaveButton.hidden = currentStep !== 3;
        if (stepStatus) stepStatus.textContent = <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "Step " : "Steg ", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> + currentStep + <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? " of 3: " : " av 3: ", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> + stepNames[currentStep - 1];
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
            row.querySelector('.invoice-item-name').setAttribute('aria-label', invMsg('Beskrivning, rad ', 'Description, row ') + (index + 1));
            row.querySelector('.invoice-item-qty').setAttribute('aria-label', invMsg('Antal, rad ', 'Quantity, row ') + (index + 1));
            row.querySelector('.invoice-item-unit').setAttribute('aria-label', invMsg('Enhet, rad ', 'Unit, row ') + (index + 1));
            row.querySelector('.invoice-item-price').setAttribute('aria-label', invMsg('Pris per enhet i kronor, rad ', 'Price per unit in kronor, row ') + (index + 1));
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

    function fixedNameMatches(value) {
        var key = String(value || '').trim().replace(/\s+/g, ' ').toLocaleLowerCase('sv-SE');
        return fixedCatalog.filter(function (article) { return key && [article.name].concat(Array.isArray(article.aliases) ? article.aliases : []).some(function (label) { return label.trim().replace(/\s+/g, ' ').toLocaleLowerCase('sv-SE') === key; }); });
    }
    function fixedArticle(value) {
        var matches = fixedNameMatches(value);
        if (!matches.some(function (article) { return article.fixed_price === true; })) return null;
        return matches.length === 1 && matches[0].fixed_price === true ? matches[0] : { ambiguous: true };
    }
    function updateFixedRow(row, fillEmpty) {
        if (!row || !row.isConnected) return;
        var nameField = row.querySelector('.invoice-item-name'), unitField = row.querySelector('.invoice-item-unit'), priceField = row.querySelector('.invoice-item-price');
        var storedLock = row.querySelector('.invoice-fixed-lock');
        var rule = fixedArticle(nameField.value), fixed = (storedLock && storedLock.value !== '') || (rule && !rule.ambiguous && unitField.value === rule.unit);
        var cell = priceField.parentNode, note = cell.querySelector('.invoice-fixed-note');
        if (!note) { note = document.createElement('small'); note.className = 'invoice-fixed-note'; note.setAttribute('role', 'status'); cell.appendChild(note); }
        var mirror = row.querySelector('.invoice-fixed-unit-mirror');
        if (fixed) {
            if (fillEmpty && !priceField.value.trim() && rule && !rule.ambiguous) priceField.value = Number(rule.price).toFixed(2);
            priceField.readOnly = true; nameField.readOnly = true; unitField.disabled = true;
            if (!mirror) { mirror = document.createElement('input'); mirror.type = 'hidden'; mirror.className = 'invoice-fixed-unit-mirror'; unitField.parentNode.appendChild(mirror); }
            mirror.name = unitField.name; mirror.value = unitField.value; mirror.disabled = false;
            note.textContent = <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? 'Fixed price — locked. Saved records may retain their historical price.' : 'Fast pris – låst. Sparade underlag kan behålla sitt historiska pris.', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        } else {
            priceField.readOnly = false; nameField.readOnly = false; unitField.disabled = false;
            if (mirror) mirror.disabled = true;
            note.textContent = rule ? <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? 'Fixed item: select the correct catalog unit, or ask the administrator to resolve duplicate names.' : 'Fastprisartikel: välj rätt katalogenhet, eller be admin lösa dubbletter i katalogen.', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> : '';
        }
    }
    function addRow(name, quantity, price, unit) {
        if (body.rows.length >= maxRows) {
            rowNote.textContent = 'Du kan lägga till högst 100 rader.';
            return null;
        }

        var index = nextIndex++;
        var rowNumber = body.rows.length + 1;
        var row = document.createElement('tr');
        var nameInput = createInput('invoice-item-name', 'text', 'items[' + index + '][art]', invMsg('Beskrivning, rad ', 'Description, row ') + rowNumber, 'Ex. arbete, reservdel eller resa');
        var quantityInput = createInput('invoice-item-qty', 'number', 'items[' + index + '][qty]', invMsg('Antal, rad ', 'Quantity, row ') + rowNumber, '');
        var unitSelect = createUnitSelect('items[' + index + '][unit]', invMsg('Enhet, rad ', 'Unit, row ') + rowNumber, unit || 'st');
        var priceInput = createInput('invoice-item-price', 'number', 'items[' + index + '][price]', invMsg('Pris per enhet i kronor, rad ', 'Price per unit in kronor, row ') + rowNumber, '');
        var total = document.createElement('span');
        total.className = 'invoice-line-total';
        total.textContent = money(0);
        var removeButton = document.createElement('button');
        removeButton.className = 'invoice-remove';
        removeButton.type = 'button';
        removeButton.setAttribute('data-remove-line', '');
        removeButton.setAttribute('aria-label', 'Ta bort rad ' + rowNumber);
        removeButton.textContent = <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "Remove" : "Ta bort", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        if (name !== undefined && name !== null) nameInput.value = name;
        if (quantity !== undefined && quantity !== null && quantity !== '') quantityInput.value = quantity;
        if (price !== undefined && price !== null && price !== '') priceInput.value = price;

        row.appendChild(createCell(<?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "Description" : "Beskrivning", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, nameInput));
        var fixedLock = document.createElement('input'); fixedLock.type = 'hidden'; fixedLock.className = 'invoice-fixed-lock'; fixedLock.name = 'items[' + index + '][fixed_lock]'; row.cells[0].appendChild(fixedLock);
        var voiceToken = document.createElement('input'); voiceToken.type = 'hidden'; voiceToken.className = 'invoice-voice-token'; voiceToken.name = 'items[' + index + '][voice_token]'; row.cells[0].appendChild(voiceToken);
        row.appendChild(createCell(<?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "Quantity" : "Antal", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, quantityInput));
        row.appendChild(createCell(<?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "Unit" : "Enhet", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, unitSelect));
        row.appendChild(createCell(<?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "Price per unit" : "Pris per enhet", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, priceInput));
        row.appendChild(createCell(<?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "Line amount" : "Radbelopp", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, total, 'invoice-line-total-cell'));
        row.appendChild(createCell(<?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "Remove" : "Ta bort", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, removeButton, 'invoice-remove-cell'));
        body.appendChild(row);
        updateRowControls();
        recalculate();
        return row;
    }

    var openVoice = document.getElementById('invoice-open-voice'), voicePanel = document.getElementById('invoice-voice-panel');
    if (openVoice && voicePanel) {
        openVoice.addEventListener('click', function () { voicePanel.open = true; openVoice.setAttribute('aria-expanded', 'true'); voicePanel.scrollIntoView && voicePanel.scrollIntoView({ block:'start' }); });
        voicePanel.addEventListener('toggle', function () { openVoice.setAttribute('aria-expanded', voicePanel.open ? 'true' : 'false'); });
    }

    // Dictation is opt-in; recognised text stays a proposal until explicitly applied.
    (function setupVoice() {
        var transcript = document.getElementById('voice-transcript');
        var target = document.getElementById('voice-target');
        var status = document.getElementById('voice-status');
        var start = document.getElementById('voice-start');
        var stop = document.getElementById('voice-stop');
        var cancel = document.getElementById('voice-cancel');
        var voiceEnglish = <?= ($portalLanguage ?? 'sv') === 'en' ? 'true' : 'false' ?>;
        var canLearnVoice = <?= !$isCentralAdminUser && preg_match('/\A[a-f0-9]{32}\z/D', $currentInvoiceUserId) === 1 ? 'true' : 'false' ?>;
        var personalVoiceId = <?= json_encode(!$isCentralAdminUser ? $currentInvoiceUserId : '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        function voiceMessage(sv, en) { return voiceEnglish ? en : sv; }
        var name = document.getElementById('voice-name');
        var qty = document.getElementById('voice-qty');
        var unit = document.getElementById('voice-unit');
        var price = document.getElementById('voice-price');
        if (!transcript || !target || !status || !start || !window.InvoiceVoice) return;
        var voiceProfile = null;
        var voiceObservation = null;
        var autoRowObservations = new WeakMap();
        var autoRowSyncing = new WeakMap();
        var learningBusy = false;
        var profileQueue = Promise.resolve();
        var learningState = document.getElementById('voice-learning-state');
        var learningToggle = document.getElementById('voice-learning-toggle');
        var autoToggle = document.getElementById('voice-auto-toggle');
        var numericToggle = document.getElementById('voice-numeric-toggle');
        var shareToggle = document.getElementById('voice-share-toggle');
        var numericSuggestions = document.getElementById('voice-numeric-suggestions');
        var numericMessage = document.getElementById('voice-numeric-message');
        var numericQtyButton = document.getElementById('voice-numeric-qty');
        var numericPriceButton = document.getElementById('voice-numeric-price');
        var articleReason = document.getElementById('voice-match-reason');
        var articleChoices = document.getElementById('voice-article-choices');
        var articleMessage = document.getElementById('voice-article-message');
        var articleOptions = document.getElementById('voice-article-options');
        var articleFree = document.getElementById('voice-article-free');
        var articleUncertain = false, articleNeedsReview = false, articleExact = null;
        var forgetLearning = document.getElementById('voice-learning-forget');
        var retryLearning = document.getElementById('voice-learning-retry');
        var dictionaryList = document.getElementById('voice-dictionary-list');
        var dictionaryStatus = document.getElementById('voice-dictionary-status');
        var dictionarySnapshot = null;
        function renderDictionary() {
            if (!dictionaryList) return;
            var list = voiceProfile && canLearnVoice ? voiceProfile.corrections : null;
            var signature = JSON.stringify(list);
            if (signature === dictionarySnapshot) return;
            dictionarySnapshot = signature; dictionaryList.textContent = '';
            if (!list) { dictionaryStatus.textContent = voiceMessage('Ordlistan är inte tillgänglig.', 'The dictionary is unavailable.'); return; }
            if (!list.length) { dictionaryStatus.textContent = voiceMessage('Inga sparade beskrivningsrättningar.', 'No saved description corrections.'); return; }
            dictionaryStatus.textContent = voiceMessage('Sparade rättningar: ', 'Saved corrections: ') + list.length;
            list.forEach(function (item) {
                if (!item || typeof item.heard !== 'string' || typeof item.approved !== 'string') return;
                var row = document.createElement('div'); row.className = 'voice-dictionary-entry';
                var heard = document.createElement('span'); heard.textContent = voiceMessage('Igenkänt: ', 'Recognized: ') + item.heard;
                var label = document.createElement('label'); label.appendChild(document.createTextNode(voiceMessage('Rättad beskrivning', 'Corrected description')));
                var input = document.createElement('input'); input.type = 'text'; input.maxLength = 200; input.value = item.approved; label.appendChild(input);
                var save = document.createElement('button'); save.type = 'button'; save.className = 'btn btn-outline'; save.textContent = voiceMessage('Spara rättning', 'Save correction');
                var remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-outline'; remove.textContent = voiceMessage('Ta bort rättning', 'Delete correction');
                function update(op) {
                    if (learningBusy) return;
                    var value = input.value.trim();
                    if (op === 'dictionary_update' && (!value || Array.from(value).length > 200 || value === item.heard || /[\x00-\x1f\x7f]/.test(value))) { dictionaryStatus.textContent = voiceMessage(invMsg('Ange en annan giltig beskrivning.', 'Enter another valid description.'), 'Enter a different valid description.'); return; }
                    if (op === 'dictionary_delete' && !window.confirm(voiceMessage('Ta bort denna personliga rättning?', 'Delete this personal correction?'))) return;
                    learningBusy = true; save.disabled = true; remove.disabled = true; showLearningState();
                    profileRequest(op, {heard:item.heard, old_approved:item.approved, approved:value}).then(function () {
                        dictionaryStatus.textContent = voiceMessage('Ordlistan uppdaterades.', 'Dictionary updated.');
                        if (target.value === 'row' && transcript.value.trim()) { closeArticleChoices(); transcriptNeedsParsing = true; refreshUncertainty(); status.textContent = voiceMessage('Ordlistan ändrades. Tolka texten igen.', 'Dictionary changed. Interpret the text again.'); }
                    }).catch(function () {
                        dictionaryStatus.textContent = voiceMessage(invMsg('Kunde inte spara. Rättningen kan ha ändrats i en annan flik. Ladda om innan du försöker igen.', 'Could not save. The correction may have changed in another tab. Reload before you try again.'), 'Could not save. The correction may have changed in another tab. Reload before retrying.');
                    }).finally(function () { learningBusy = false; save.disabled = false; remove.disabled = false; showLearningState(); });
                }
                save.addEventListener('click', function () { update('dictionary_update'); });
                remove.addEventListener('click', function () { update('dictionary_delete'); });
                row.appendChild(heard); row.appendChild(label); row.appendChild(save); row.appendChild(remove); dictionaryList.appendChild(row);
            });
        }
        function showLearningState() {
            if (!learningState) return;
            if (!canLearnVoice) {
                learningState.textContent = voiceMessage(invMsg('Lärande kräver ett personligt fakturakonto. Central admin är inte en personlig identitet.', 'Learning requires a personal invoice account. A central admin is not a personal identity.'), 'Learning requires a personal invoice account. Central administration is not a personal identity.');
            } else if (!voiceProfile) {
                learningState.textContent = voiceMessage(invMsg('Personligt lärande kunde inte läsas. Automatiken är avstängd.', 'Personal learning could not be read. The automation is switched off.'), 'Personal learning could not be loaded. Automatic entry is disabled.');
            } else {
                learningState.textContent = (voiceProfile.learning ? voiceMessage(invMsg('Lärande på', 'Learning on'), 'Learning on') : voiceMessage(invMsg('Lärande av', 'Learning off'), 'Learning off')) + ' · ' + voiceProfile.reviewed + '/50 ' + voiceMessage('manuellt granskade röstrader', 'manually reviewed voice rows') + ' · ' + (voiceProfile.auto ? voiceMessage('automatisk inmatning på', 'automatic entry on') : voiceMessage('automatisk inmatning av', 'automatic entry off')) + '.';
            }
            if (learningToggle) { learningToggle.disabled = learningBusy || !voiceProfile || !canLearnVoice; learningToggle.textContent = voiceProfile && voiceProfile.learning ? voiceMessage(invMsg('Pausa lärande', 'Pause learning'), 'Pause learning') : voiceMessage(invMsg('Starta lärande', 'Start learning'), 'Start learning'); }
            if (shareToggle) { shareToggle.disabled = learningBusy || !voiceProfile || !voiceProfile.learning || !canLearnVoice; shareToggle.textContent = voiceProfile && voiceProfile.share_learning ? voiceMessage('Sluta dela rättningar', 'Stop sharing corrections') : voiceMessage('Dela rättningar för admingranskning', 'Share corrections for administrator review'); }
            if (numericToggle) { numericToggle.disabled = learningBusy || !voiceProfile || !voiceProfile.learning; numericToggle.textContent = voiceProfile && voiceProfile.numeric_learning ? voiceMessage(invMsg('Pausa lärande av antal och priser', 'Pause learning of quantities and prices'), 'Pause learning of quantities and prices') : voiceMessage(invMsg('Aktivera lärande av antal och priser', 'Enable learning of quantities and prices'), 'Enable learning of quantities and prices'); }
            if (autoToggle) { autoToggle.disabled = learningBusy || !voiceProfile || !voiceProfile.learning || voiceProfile.reviewed < 50; autoToggle.textContent = voiceProfile && voiceProfile.auto ? voiceMessage('Stäng av automatisk inmatning', 'Disable automatic entry') : voiceMessage(invMsg('Aktivera automatisk inmatning', 'Enable automatic entry'), 'Enable automatic entry'); }
            if (forgetLearning) forgetLearning.disabled = learningBusy || !voiceProfile || !canLearnVoice;
            renderDictionary();
        }
        function profileRequest(operation, fields) {
            if (!canLearnVoice) return Promise.reject(new Error('forbidden'));
            var csrf = form.querySelector('input[name="csrf_token"]');
            if (!csrf || !csrf.value) return Promise.reject(new Error('csrf'));
            var payload = new URLSearchParams();
            payload.set('action', 'voice_learning'); payload.set('operation', operation); payload.set('csrf_token', csrf.value);
            Object.keys(fields || {}).forEach(function (key) { payload.set(key, fields[key]); });
            var request = profileQueue.then(function () { return fetch('?tab=invoice', { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: payload }); }).then(function (response) {
                return response.json().then(function (data) { if (!response.ok) throw new Error(data && data.code || 'request_failed'); return data; });
            }).then(function (data) {
                var p = data && data.ok === true && data.profile;
                if (!p || typeof p.learning !== 'boolean' || typeof p.auto !== 'boolean' || typeof p.numeric_learning !== 'boolean' || typeof p.share_learning !== 'boolean' || !Array.isArray(p.numeric_corrections) || !Number.isInteger(p.reviewed) || p.reviewed < 0 || p.reviewed > 50 || !Array.isArray(p.corrections) || (p.auto && (!p.learning || p.reviewed < 50))) throw new Error('invalid_profile');
                if (typeof data.token === 'string') p._token = data.token;
                voiceProfile = p; showLearningState(); return p;
            });
            profileQueue = request.catch(function () {});
            return request;
        }
        function changeProfile(op) {
            if (learningBusy) return;
            if (op === 'auto_on' && !window.confirm(voiceMessage('Även efter 50 granskade rader kan antal och pris bli fel. Automatiska förslag läggs i utkastet utan granskning. Kontrollera alltid hela underlaget före sparning. Aktivera?', 'Even after 50 reviewed rows, quantities and prices can be wrong. Automatic proposals are added to the draft without review. Always check the entire record before saving. Enable?'))) return;
            if (op === 'share_on' && !window.confirm(voiceMessage('Fakturaadmin får se ditt kontonamn samt befintliga och framtida igenkända och rättade artikelbeskrivningar. De kan vara känsliga. Godkända alternativnamn läggs i den gemensamma katalogen och finns kvar även om du senare slutar dela eller raderar lärandet. Separata antal- och prisfält och ljud delas inte, men texten kan innehålla känsliga uppgifter. Aktivera delning?', 'Invoice administrators can see your account name and existing and future recognized and corrected item descriptions, which may be sensitive. Approved alternative names enter the shared catalog and remain even if you later stop sharing or erase learning. Separate quantity and price fields and audio are not shared, but the text may contain sensitive information. Enable sharing?'))) return;
            if (op === 'numeric_on' && !window.confirm(voiceMessage('Dina rättningar av antal och enhetspriser lagras med artikel och enhet på ditt personliga konto. Gamla rader utan nummerkoppling kan inte läras i efterhand. Förslag måste granskas manuellt. Aktivera?', 'Your quantity and unit price corrections are stored with the item and unit in your personal account. Old rows without a numeric link cannot be learned retroactively. Suggestions must be reviewed manually. Enable?'))) return;
            if (op === 'forget' && !window.confirm(voiceMessage('Radera alla inlärda textkorrigeringar och nollställa räknaren? Automatiken stängs av.', 'Delete all learned text corrections and reset the count? Automatic entry will be disabled.'))) return;
            learningBusy = true; showLearningState();
            profileRequest(op).then(function (profile) { if (op === 'start' && profile.learning) syncAllAutoRows(); if (op === 'numeric_on' || op === 'numeric_off') showNumericSuggestions(window.InvoiceVoice.parseRow(transcript.value)); if ((op === 'pause' || op === 'forget') && articleUncertain && target.value === 'row') { closeArticleChoices(); transcriptNeedsParsing = true; status.textContent = voiceMessage('Lärandet ändrades. Välj Tolka text för att uppdatera träffarna.', 'Learning changed. Select Interpret text to update the matches.'); } }).catch(function () { voiceProfile = null; }).finally(function () { learningBusy = false; showLearningState(); });
        }
        if (learningToggle) learningToggle.addEventListener('click', function () { if (voiceProfile) changeProfile(voiceProfile.learning ? 'pause' : 'start'); });
        if (shareToggle) shareToggle.addEventListener('click', function () { if (voiceProfile) changeProfile(voiceProfile.share_learning ? 'share_off' : 'share_on'); });
        if (numericToggle) numericToggle.addEventListener('click', function () { if (voiceProfile) changeProfile(voiceProfile.numeric_learning ? 'numeric_off' : 'numeric_on'); });
        if (autoToggle) autoToggle.addEventListener('click', function () { if (voiceProfile) changeProfile(voiceProfile.auto ? 'auto_off' : 'auto_on'); });
        if (forgetLearning) forgetLearning.addEventListener('click', function () { changeProfile('forget'); });
        if (canLearnVoice) profileRequest('load').then(function (profile) { if (profile.learning) syncAllAutoRows(); }).catch(function () { voiceProfile = null; showLearningState(); }); else showLearningState();
        function observationId() {
            if (!window.crypto || typeof window.crypto.getRandomValues !== 'function') return '';
            var bytes = new Uint8Array(16);
            try { window.crypto.getRandomValues(bytes); } catch (error) { return ''; }
            return Array.prototype.map.call(bytes, function (x) { return x.toString(16).padStart(2, '0'); }).join('');
        }
        function learnedDescription(raw) {
            if (!voiceProfile || !voiceProfile.learning || !Array.isArray(voiceProfile.corrections)) return raw;
            var key = raw.trim().replace(/\s+/g, ' ').toLocaleLowerCase('sv-SE');
            var found = voiceProfile.corrections.filter(function (item) { return item && typeof item.heard === 'string' && typeof item.approved === 'string' && item.heard.trim().replace(/\s+/g, ' ').toLocaleLowerCase('sv-SE') === key; });
            // Conflicting corrections are never guessed.
            return found.length === 1 ? found[0].approved : raw;
        }
        function recordLearning(operation, observation, approved) {
            if (!voiceProfile || !voiceProfile.learning || !observation || !approved) return;
            var fields = { heard: observation.heard, approved: approved };
            if (operation === 'review') {
                fields.observation_id = observation.id;
                if (voiceProfile.numeric_learning && observation.heard && observation.qty && observation.unit) {
                    fields.from_qty = observation.qty; fields.from_price = observation.price; fields.numeric_unit = observation.unit;
                    fields.to_qty = qty.value.trim(); fields.to_price = price.value.trim(); fields.approved_unit = unit.value;
                }
            }
            profileRequest(operation, fields).catch(function () { voiceProfile = null; showLearningState(); });
        }
        function syncAutoRow(row) {
            if (!row || !row.isConnected || !voiceProfile || !voiceProfile.learning) return;
            var tokenField = row.querySelector('.invoice-voice-token');
            var nameField = row.querySelector('.invoice-item-name');
            if (!tokenField || !nameField || !tokenField.value.startsWith(personalVoiceId + '.') || !nameField.value.trim()) return;
            if (autoRowSyncing.has(row)) return; // Completion rechecks the current value.
            var oldToken = tokenField.value, approved = nameField.value.trim();
            var numericFields = ['.invoice-item-qty', '.invoice-item-price', '.invoice-item-unit'].map(function (selector) { return row.querySelector(selector).value.trim(); });
            var fields = { voice_token: oldToken, approved: approved };
            if (voiceProfile.numeric_learning) { fields.numeric_qty = numericFields[0]; fields.numeric_price = numericFields[1]; fields.numeric_unit = numericFields[2]; }
            autoRowSyncing.set(row, true);
            profileRequest('sync_auto', fields).then(function (profile) {
                if (!row.isConnected || tokenField.value !== oldToken) { autoRowSyncing.delete(row); return; }
                if (typeof profile._token !== 'string' || !profile._token.startsWith(personalVoiceId + '.')) throw new Error('invalid_token_response');
                if (tokenField.value !== profile._token) {
                    tokenField.value = profile._token;
                    tokenField.dispatchEvent(new Event('input', { bubbles: true }));
                }
                autoRowSyncing.delete(row);
                // If the user edited while the request was in flight, send the
                // newest value using the newly acknowledged baseline.
                if (nameField.value.trim() && (nameField.value.trim() !== approved || numericFields.some(function (v, i) { return row.querySelector(['.invoice-item-qty', '.invoice-item-price', '.invoice-item-unit'][i]).value.trim() !== v; }))) syncAutoRow(row);
            }).catch(function (error) {
                autoRowSyncing.delete(row);
                if (error.message === 'invalid_token') {
                    // Reset destroyed the key, or a token was damaged. Never guess.
                    if (row.isConnected && tokenField.value === oldToken) {
                        tokenField.value = ''; tokenField.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                    status.textContent = voiceMessage(invMsg('Den här radens koppling till lärandet saknas eller har raderats.', 'This row’s link to the learning data is missing or has been deleted.'), 'This row’s learning link is missing or was erased.');
                    return;
                }
                voiceProfile = null; showLearningState();
                if (retryLearning) retryLearning.hidden = false;
                status.textContent = voiceMessage(invMsg('Ändringen kunde inte läras ännu. Spara utkastet och försök synkronisera igen.', 'The change could not be learned yet. Save the draft and try syncing again.'), 'The change could not yet be learned. Save the draft and retry learning sync.');
            });
        }
        function syncAllAutoRows() {
            Array.prototype.forEach.call(body.rows, syncAutoRow);
        }
        if (retryLearning) retryLearning.addEventListener('click', function () {
            retryLearning.disabled = true;
            profileRequest('load').then(function (profile) {
                if (profile.learning) { retryLearning.hidden = true; syncAllAutoRows(); }
                else status.textContent = voiceMessage(invMsg('Starta lärandet innan ändringar synkroniseras.', 'Start learning before changes are synced.'), 'Start learning before synchronizing changes.');
            }).catch(function () { voiceProfile = null; showLearningState(); }).finally(function () { retryLearning.disabled = false; });
        });
        body.addEventListener('input', function (event) {
            if (!event.target.matches('.invoice-item-name, .invoice-item-qty, .invoice-item-unit, .invoice-item-price')) return;
            var row = event.target.closest('tr'), field = row && row.querySelector('.invoice-voice-token');
            // If a different user changes a shared record, do not train the
            // original speaker's private profile from somebody else's edit.
            if (field && field.value && (!field.value.startsWith(personalVoiceId + '.') || (voiceProfile && !voiceProfile.learning))) field.value = '';
        });
        body.addEventListener('change', function (event) {
            if (!event.target.matches('.invoice-item-name, .invoice-item-qty, .invoice-item-unit, .invoice-item-price')) return;
            var row = event.target.closest('tr'), field = row && row.querySelector('.invoice-voice-token');
            if (field && field.value && (!field.value.startsWith(personalVoiceId + '.') || (voiceProfile && !voiceProfile.learning))) field.value = '';
            else syncAutoRow(row);
        });
        function numericMatches(row) {
            if (!voiceProfile || !voiceProfile.learning || !voiceProfile.numeric_learning || !row || !row.name || !row.unit) return [];
            var key = row.name.trim().replace(/\s+/g, ' ').toLocaleLowerCase('sv-SE');
            return voiceProfile.numeric_corrections.filter(function (item) {
                if (item && item.field === 'price' && (fixedArticle(row.name) || fixedArticle(learnedDescription(row.name)))) return false;
                if (!item || typeof item.heard !== 'string' || typeof item.from !== 'string' || typeof item.to !== 'string' || item.unit !== row.unit || !['qty','price'].includes(item.field)) return false;
                var parsed = item.field === 'qty' ? row.qty : row.price;
                return parsed !== '' && item.heard.trim().replace(/\s+/g, ' ').toLocaleLowerCase('sv-SE') === key && Number(item.from) === Number(parsed);
            });
        }
        function showNumericSuggestions(row) {
            if (!numericSuggestions) return [];
            var matches = numericMatches(row);
            numericSuggestions.hidden = matches.length === 0;
            if (!matches.length) return matches;
            numericMessage.textContent = voiceMessage('Tidigare rättningar för samma beskrivning och enhet finns. De kan gälla ett annat arbete eller pris. Kontrollera och välj själv; ingen ändring görs automatiskt.', 'Previous corrections for the same description and unit exist. They may apply to a different job or price. Check and choose yourself; nothing is changed automatically.');
            [['qty', numericQtyButton, qty], ['price', numericPriceButton, price]].forEach(function (entry) {
                var candidates = matches.filter(function (item) { return item.field === entry[0]; });
                var button = entry[1], input = entry[2]; if (!button) return;
                button.hidden = candidates.length !== 1 || input.value.trim() === '' || Number(input.value.replace(',', '.')) !== Number(candidates[0].from);
                if (button.hidden) return;
                var candidate = candidates[0];
                button.textContent = (entry[0] === 'qty' ? voiceMessage('Tidigare antal: ', 'Previous quantity: ') : voiceMessage(invMsg('Tidigare enhetspris: ', 'Previous unit price: '), 'Previous unit price: ')) + candidate.from + ' → ' + candidate.to;
                button.onclick = function () {
                    var current = window.InvoiceVoice.parseRow(transcript.value);
                    if (target.value !== 'row' || transcriptNeedsParsing || numericMatches(current).indexOf(candidate) === -1 || name.value !== learnedDescription(current.name) || unit.value !== current.unit || input.value.trim() === '' || Number(input.value.replace(',', '.')) !== Number(candidate.from)) return;
                    input.value = candidate.to; input.dispatchEvent(new Event('input', { bubbles: true }));
                    button.hidden = true;
                    status.textContent = voiceMessage('Tidigare rättning kopierades till förslaget. Granska hela raden och välj Använd förslaget.', 'Previous correction copied into the proposal. Review the whole row and choose Apply suggestion.');
                };
            });
            return matches;
        }
        function quantityOnlyStrict(text, row) {
            if (!row || !row.name || !row.qty || !row.unit || row.price) return false;
            var escaped = row.name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            var unitRe = '(timmar|timme|tim|stycken|styck|st|kilometer|km|dagar|dag|meter|m|kilogram|kilo|kg)';
            var textClean = String(text).trim().replace(/[.!?]+$/, '');
            var first = textClean.match(new RegExp('^(.+?)\\s*' + unitRe + '[\\s,;]+' + escaped + '$', 'i'));
            var last = textClean.match(new RegExp('^' + escaped + '[\\s,;]+(.+?)\\s*' + unitRe + '$', 'i'));
            var parts = first || last;
            if (!parts) return false;
            var units = { timme:'tim', timmar:'tim', tim:'tim', stycken:'st', styck:'st', st:'st', kilometer:'km', km:'km', dag:'dag', dagar:'dag', meter:'m', m:'m', kilogram:'kg', kilo:'kg', kg:'kg' };
            return units[parts[2].toLocaleLowerCase('sv-SE')] === row.unit && window.InvoiceVoice.number(parts[1]) === Number(row.qty);
        }
        function suggestionKey(value) { return String(value || '').trim().replace(/\s+/g, ' ').toLocaleLowerCase('sv-SE'); }
        function setArticleReason(message) {
            if (!articleReason) return;
            articleReason.textContent = message || '';
            articleReason.hidden = !message;
        }
        function articleMatchReason(article, row) {
            var raw = suggestionKey(row.name), learned = suggestionKey(learnedDescription(row.name));
            var names = [article.name].concat(Array.isArray(article.aliases) ? article.aliases : []).map(suggestionKey);
            if (raw && raw === names[0]) return voiceMessage('Exakt artikelnamn', 'Exact item name');
            if (raw && names.slice(1).indexOf(raw) !== -1) return voiceMessage('Alternativt artikelnamn', 'Alternative item name');
            if (learned && learned !== raw && names.indexOf(learned) !== -1) return voiceMessage('Personlig rättning', 'Personal correction');
            return voiceMessage(invMsg('Liknande namn – osäkert förslag', 'Similar name – uncertain suggestion'), 'Similar name – uncertain suggestion');
        }
        var uncertainty = document.getElementById('voice-uncertainty');
        var uncertaintyList = document.getElementById('voice-uncertainty-list');
        function refreshUncertainty() {
            if (!uncertainty || !uncertaintyList) return;
            uncertaintyList.textContent = '';
            [name,qty,unit,price].forEach(function (field) { field.classList.remove('voice-needs-review'); });
            if (target.value !== 'row' || !transcript.value.trim() || transcriptNeedsParsing) { uncertainty.hidden = true; return; }
            var parsed = window.InvoiceVoice.parseRow(transcript.value);
            var exact = fixedNameMatches(parsed.name);
            var flags = [];
            function mark(field, label) { if (flags.indexOf(label) === -1) flags.push(label); field.classList.add('voice-needs-review'); }
            if (!name.value.trim() || articleUncertain) mark(name, voiceMessage(invMsg('Beskrivning/artikel: saknas eller kräver val.', 'Description/item: missing or requires a choice.'), 'Description/item: missing or requires a choice.'));
            if (!qty.value.trim()) mark(qty, voiceMessage(invMsg('Antal: saknas eller kunde inte tolkas.', 'Quantity: missing or could not be interpreted.'), 'Quantity: missing or not interpreted.'));
            if (!unit.value || (exact.length === 1 && parsed.unit && parsed.unit !== exact[0].unit)) mark(unit, voiceMessage(invMsg('Enhet: saknas eller skiljer sig från artikeln.', 'Unit: missing or differs from the item.'), 'Unit: missing or differs from the item.'));
            if (!price.value.trim() || (exact.length === 1 && exact[0].fixed_price && parsed.price && Number(parsed.price) !== Number(exact[0].price))) mark(price, voiceMessage(invMsg('Pris: saknas eller skiljer sig från fastpriset.', 'Price: missing or differs from the fixed price.'), 'Price: missing or differs from the fixed price.'));
            numericMatches(parsed).forEach(function (item) {
                var field = item.field === 'qty' ? qty : price;
                if (Number(field.value.trim().replace(',', '.')) === Number(item.from)) mark(field, item.field === 'qty'
                    ? voiceMessage(invMsg('Antal: en tidigare rättning finns.', 'Quantity: a previous correction exists.'), 'Quantity: a previous correction exists.')
                    : voiceMessage(invMsg('Pris: en tidigare rättning finns.', 'Price: a previous correction exists.'), 'Price: a previous correction exists.'));
            });
            flags.forEach(function (text) { var li=document.createElement('li'); li.textContent=text; uncertaintyList.appendChild(li); });
            uncertainty.hidden = !flags.length;
        }
        function closeArticleChoices() {
            articleUncertain = false; articleNeedsReview = false; articleExact = null;
            setArticleReason('');
            if (articleChoices) articleChoices.hidden = true;
        }
        function showArticleChoices(row, candidates) {
            if (!articleChoices || !articleOptions) return;
            articleUncertain = true; articleOptions.textContent = '';
            setArticleReason(candidates.length ? voiceMessage(invMsg('Välj bland möjliga träffar. Orsaken visas vid varje alternativ.', 'Choose from possible matches. The reason is shown with each option.'), 'Choose from possible matches. The reason is shown with each option.') : voiceMessage('Ingen säker katalogträff.', 'No certain catalog match.'));
            articleChoices.hidden = false;
            articleMessage.textContent = candidates.length ? voiceMessage(invMsg('Osäker artikel. Välj en artikel eller bekräfta fritext; inget förs in automatiskt.', 'Uncertain item. Choose an item or confirm free text; nothing is entered automatically.'), 'Uncertain item. Select an item or explicitly choose free text; nothing is entered automatically.') : voiceMessage(invMsg('Ingen säker artikelträff. Välj fritext eller välj artikel i katalogen; kontrollera fasta priser.', 'No certain item match. Choose free text or pick an item in the catalogue; check fixed prices.'), 'No certain item match. Choose free text or select an item in the catalog; check fixed prices.');
            var initialTranscript = transcript.value;
            candidates.slice(0, 6).forEach(function (article) {
                var button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-outline';
                var matchReason = articleMatchReason(article, row);
                button.textContent = article.name + ' · ' + matchReason + ' · ' + article.unit + ' · ' + Number(article.price).toFixed(2) + ' kr' + (article.fixed_price ? voiceMessage(' fast', ' fixed') : '');
                button.addEventListener('click', function () {
                    if (recognition || target.value !== 'row' || transcript.value !== initialTranscript || transcriptNeedsParsing) return;
                    if (learningBusy || articleMatchReason(article, row) !== matchReason) {
                        status.textContent = voiceMessage('Träffunderlaget har ändrats. Tolka texten igen innan du väljer.', 'The basis for the match changed. Interpret the text again before choosing.'); return;
                    }
                    name.value = article.name; unit.value = article.unit; price.value = Number(article.price).toFixed(2);
                    articleUncertain = false; articleNeedsReview = true; articleExact = null; articleChoices.hidden = true; previewPending = true;
                    refreshUncertainty();
                    setArticleReason(voiceMessage(invMsg('Vald artikel – ', 'Selected item – '), 'Selected item – ') + matchReason + voiceMessage(invMsg('. Kontrollera raden.', '. Check the row.'), '. Review the line.'));
                    status.textContent = voiceMessage(invMsg('Artikeln valdes. Kontrollera antal, enhet och pris och välj Använd förslaget.', 'The item was selected. Check quantity, unit and price and choose Use suggestion.'), 'Item selected. Check quantity, unit and price and choose Apply suggestion.');
                });
                articleOptions.appendChild(button);
            });
            var personal = learnedDescription(row.name);
            if (suggestionKey(personal) !== suggestionKey(row.name) && !fixedNameMatches(personal).length) {
                var learnedButton = document.createElement('button'); learnedButton.type = 'button'; learnedButton.className = 'btn btn-outline';
                learnedButton.textContent = voiceMessage(invMsg('Tidigare rättning: ', 'Previous correction: '), 'Previous correction: ') + personal;
                learnedButton.addEventListener('click', function () {
                    if (recognition || target.value !== 'row' || transcript.value !== initialTranscript || transcriptNeedsParsing) return;
                    if (learningBusy || suggestionKey(learnedDescription(row.name)) !== suggestionKey(personal)) {
                        status.textContent = voiceMessage('Den personliga rättningen är inte längre tillgänglig. Tolka texten igen.', 'The personal correction is no longer available. Interpret the text again.'); return;
                    }
                    name.value = personal; articleUncertain = false; articleNeedsReview = true; articleExact = null; articleChoices.hidden = true;
                    refreshUncertainty();
                    setArticleReason(voiceMessage('Personlig rättning vald – ingen säker koppling till katalogartikel.', 'Personal correction selected – not reliably linked to a catalog item.'));
                    status.textContent = voiceMessage(invMsg('Tidigare rättning vald. Kontrollera beskrivning och pris manuellt.', 'Previous correction selected. Check description and price manually.'), 'Previous correction selected. Check the description and price manually.');
                });
                articleOptions.appendChild(learnedButton);
            }
            if (articleFree) articleFree.onclick = function () {
                if (recognition || target.value !== 'row' || transcript.value !== initialTranscript || transcriptNeedsParsing) return;
                if (!window.confirm(voiceMessage('Fritext kopplas inte säkert till en katalogartikel eller dess fasta pris. Kontrollera beskrivning och pris. Använd fritext?', 'Free text is not reliably linked to a catalog item or its fixed price. Check the description and price. Use free text?'))) return;
                articleUncertain = false; articleNeedsReview = true; articleExact = null; articleChoices.hidden = true;
                refreshUncertainty();
                setArticleReason(voiceMessage(invMsg('Fritext vald – ingen säker katalogträff.', 'Free text selected – no certain catalogue match.'), 'Free text selected – no certain catalog match.'));
                status.textContent = voiceMessage(invMsg('Fritext vald. Kontrollera priset och granska raden manuellt.', 'Free text selected. Check the price and review the row manually.'), 'Free text selected. Check the price and review the line manually.');
            };
        }
        function similarNames(a, b) {
            if (a.length < 4 || b.length < 4 || a.length > 80 || b.length > 80 || Math.abs(a.length - b.length) > 2) return false;
            var previous = Array.from({length: b.length + 1}, function (_, i) { return i; });
            for (var i = 1; i <= a.length; i++) {
                var next = [i];
                for (var j = 1; j <= b.length; j++) next[j] = Math.min(next[j - 1] + 1, previous[j] + 1, previous[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
                previous = next;
            }
            return previous[b.length] <= (Math.max(a.length, b.length) >= 8 ? 2 : 1);
        }
        function suggestArticles(row) {
            var raw = suggestionKey(row.name), learned = suggestionKey(learnedDescription(row.name));
            if (!raw || raw.length < 3) return [];
            return fixedCatalog.filter(function (article) {
                return [article.name].concat(Array.isArray(article.aliases) ? article.aliases : []).some(function (label) {
                    var key = suggestionKey(label);
                    return (learned !== raw && learned === key) || (key.length >= 4 && raw.length >= 4 && (key.indexOf(raw) !== -1 || raw.indexOf(key) !== -1 || similarNames(key, raw)));
                });
            }).sort(function (a, b) {
                return (suggestionKey(b.name) === learned ? 1 : 0) - (suggestionKey(a.name) === learned ? 1 : 0);
            });
        }
        var priorText = '';
        var lastApplied = null;
        var recognition = null;
        var transcriptNeedsParsing = false;
        var lastRowFingerprint = null;
        var previewPending = false;
        var advanceAfterApproval = false;
        var activeVoiceRow = null;
        var destinationRow = null;
        var destination = document.getElementById('voice-destination');
        function refreshVoiceDestination() {
            if (!destination || !destination.isConnected) return;
            if (destinationRow && !body.contains(destinationRow)) destinationRow = null;
            if (activeVoiceRow && !body.contains(activeVoiceRow)) activeVoiceRow = null;
            var selected = destinationRow ? Array.prototype.indexOf.call(body.rows, destinationRow) + 1 : 0;
            destination.textContent = '';
            var automatic = document.createElement('option');
            automatic.value = 'auto'; automatic.textContent = voiceMessage('Nästa tomma rad', 'Next empty row');
            destination.appendChild(automatic);
            Array.prototype.forEach.call(body.rows, function (row, index) {
                var option = document.createElement('option'); option.value = String(index + 1);
                option.textContent = voiceMessage('Rad ', 'Row ') + (index + 1); destination.appendChild(option);
            });
            destination.value = selected > 0 ? String(selected) : 'auto';
        }
        if (destination) destination.addEventListener('change', function () {
            var index = Number(destination.value) - 1;
            destinationRow = destination.value === 'auto' ? null : (body.rows[index] || null);
            if (destinationRow) activeVoiceRow = destinationRow;
        });
        body.addEventListener('focusin', function (event) {
            var row = event.target.closest('tr');
            if (!row || !body.contains(row)) return;
            activeVoiceRow = row; destinationRow = row; refreshVoiceDestination();
        });
        if (window.MutationObserver) new MutationObserver(refreshVoiceDestination).observe(body, { childList: true });
        refreshVoiceDestination();
        function nextVoiceRow() {
            if (previewPending) {
                status.textContent = voiceMessage('Förslaget är inte använt. Granska och välj Använd förslaget, eller rensa det, innan du byter rad.', 'The proposal has not been applied. Review and apply or clear it before moving to the next row.');
                return;
            }
            var current = activeVoiceRow && body.contains(activeVoiceRow) ? Array.prototype.indexOf.call(body.rows, activeVoiceRow) : 0;
            var next = body.rows[current + 1] || addRow('', '', '', 'st');
            if (!next) { status.textContent = voiceMessage('Högst 100 rader kan läggas till.', 'At most 100 rows can be added.'); return; }
            activeVoiceRow = next; destinationRow = next; refreshVoiceDestination();
            if (mobileStepQuery && mobileStepQuery.matches) activateStep(2, false);
            next.querySelector('.invoice-item-name').focus();
            status.textContent = voiceMessage('Klar på rad ', 'Ready on row ') + (Array.prototype.indexOf.call(body.rows, next) + 1) + '.';
        }
        function undoApplied() {
            if (!lastApplied) return false;
            if (!lastApplied.undo()) { status.textContent = <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "The change cannot be undone because the content has since changed." : "Ändringen kan inte ångras eftersom innehållet har ändrats efteråt.", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>; return true; }
            lastApplied = null; lastRowFingerprint = null; status.textContent = <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "The last application was undone." : "Senaste användningen ångrades.", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>; return true;
        }
        document.getElementById('voice-undo').addEventListener('click', function () { if (!undoApplied()) status.textContent = <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "There is nothing to undo." : "Det finns ingen användning att ångra.", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>; });
        function parse() {
            if (target.value !== 'row') return;
            var row = window.InvoiceVoice.parseRow(transcript.value);
            closeArticleChoices();
            var exact = fixedNameMatches(row.name);
            var candidate = exact.length === 1 ? exact[0] : null;
            name.value = row.name;
            qty.value = row.qty;
            unit.value = row.unit;
            price.value = row.price;
            transcriptNeedsParsing = false;
            advanceAfterApproval = false;
            previewPending = transcript.value.trim() !== '';
            if (candidate && candidate.fixed_price && row.unit === candidate.unit && (row.price || quantityOnlyStrict(transcript.value, row))) {
                if (row.price && Number(row.price) !== Number(candidate.price)) {
                    name.value = row.name;
                    showArticleChoices(row, [candidate]);
                    articleMessage.textContent = voiceMessage(invMsg('Dikterat pris ', 'Dictated price ') + row.price + ' skiljer sig från fast pris ' + Number(candidate.price).toFixed(2) + invMsg('. Välj artikeln för att använda det fasta priset.', '. Choose the item to use the fixed price.'), 'Dictated price ' + row.price + ' differs from fixed price ' + Number(candidate.price).toFixed(2) + '. Select the item to use its fixed price.');
                } else {
                    articleExact = candidate;
                    setArticleReason(voiceMessage('Katalogträff: ', 'Catalog match: ') + articleMatchReason(candidate, row) + voiceMessage(invMsg('. Kontrollera raden.', '. Check the row.'), '. Review the line.'));
                    name.value = candidate.name;
                    price.value = Number(candidate.price).toFixed(2);
                }
            } else if (candidate && !candidate.fixed_price && row.unit === candidate.unit) {
                articleExact = candidate; name.value = candidate.name;
                setArticleReason(voiceMessage('Katalogträff: ', 'Catalog match: ') + articleMatchReason(candidate, row) + voiceMessage(invMsg('. Kontrollera raden.', '. Check the row.'), '. Review the line.'));
            } else {
                var possible = exact.length ? exact : suggestArticles(row);
                if (possible.length || (fixedCatalog.length && row.name) || suggestionKey(learnedDescription(row.name)) !== suggestionKey(row.name)) {
                    showArticleChoices(row, possible);
                    if (candidate && !row.price && row.unit === candidate.unit) articleMessage.textContent = voiceMessage(invMsg('Pris eller enhet kunde inte tolkas säkert. Kontrollera hela dikteringen och välj artikel eller fritext.', 'Price or unit could not be interpreted with certainty. Check the whole dictation and choose an item or free text.'), 'Price or unit could not be interpreted safely. Check the entire dictation and select an item or free text.');
                    if (candidate && row.unit && row.unit !== candidate.unit) articleMessage.textContent = voiceMessage('Dikterad enhet ' + row.unit + ' skiljer sig från artikelns enhet ' + candidate.unit + invMsg('. Välj artikeln eller fritext och granska.', '. Choose the item or free text and review.'), 'Dictated unit ' + row.unit + ' differs from the item unit ' + candidate.unit + '. Select the item or free text and review.');
                }
            }
            showNumericSuggestions(row);
            refreshUncertainty();
            if (articleUncertain || articleNeedsReview) return;
            if (!name.value || !qty.value || !unit.value || !price.value) {
                status.textContent = voiceMessage(invMsg('Vissa uppgifter kunde inte tolkas säkert. Fyll i beskrivning, antal, enhet och pris per enhet manuellt och granska hela förslaget.', 'Some entries could not be interpreted with certainty. Fill in description, quantity, unit and price per unit manually and review the whole suggestion.'), 'Some details could not be interpreted safely. Enter the description, quantity, unit and unit price manually and review the whole suggestion.');
                return;
            }
            status.textContent = <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "Review and edit the suggestion before applying it." : "Granska och ändra förslaget innan du använder det.", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        }
        function acceptSpeech(text) {
            var normalized = text.trim().toLocaleLowerCase('sv-SE').replace(/[.!?]+$/, '');
            if (target.value === 'row' && (normalized === 'punkt' || normalized === 'nästa' || normalized === 'nästa rad' || /^\.+$/.test(text.trim()))) { nextVoiceRow(); return true; }
            if (normalized === 'ångra senaste') {
                if (undoApplied()) return true;
                var current = transcript.value; transcript.value = priorText; priorText = current;
                parse(); status.textContent = 'Senaste dikteringen ångrades i förslaget.'; return true;
            }
            if (normalized === 'läs upp underlaget') { document.getElementById('voice-read').click(); return true; }
            if (normalized === 'ny rad' || normalized === 'lägg till rad') { if (target.value === 'row') nextVoiceRow(); else status.textContent = voiceMessage('Välj fakturarad först.', 'Select invoice line first.'); return true; }
            var fieldCommand = { 'till beskrivning':name, 'till antal':qty, 'till enhet':unit, [invMsg('till pris', 'to price')]:price };
            if (Object.prototype.hasOwnProperty.call(fieldCommand, normalized)) {
                if (target.value !== 'row') { status.textContent = voiceMessage('Välj fakturarad först.', 'Select invoice line first.'); return true; }
                fieldCommand[normalized].focus();
                status.textContent = voiceMessage('Fokus på ', 'Focus on ') + normalized.slice(5) + '.'; return true;
            }
            var rowCommand = normalized.match(/^rad\s+(\d+|en|ett|två|tre|fyra|fem|sex|sju|åtta|nio|tio)$/);
            if (rowCommand) {
                var rowIndex = window.InvoiceVoice.number(rowCommand[1]);
                if (rowIndex && body.rows[rowIndex - 1]) {
                    if (mobileStepQuery && mobileStepQuery.matches) activateStep(2, false);
                    body.rows[rowIndex - 1].querySelector('.invoice-item-name').focus();
                    status.textContent = 'Fokus på rad ' + rowIndex + '.';
                } else status.textContent = 'Raden finns inte.';
                return true;
            }
            if (normalized === 'till referens' || normalized === 'till kund' || normalized === 'nästa fält') {
                target.value = normalized === 'till kund' ? 'customer' : normalized === 'till referens' ? 'reference' : (target.value === 'customer' ? 'reference' : 'row');
                target.dispatchEvent(new Event('change'));
                status.textContent = 'Valt fält: ' + target.options[target.selectedIndex].textContent + '.'; return true;
            }
            var ending = target.value === 'row' ? text.trim().replace(/[.!?]+$/, '').match(/^(.+?)\s+(?:punkt|nästa(?: rad)?)$/i) : null;
            if (ending) {
                var possible = window.InvoiceVoice.parseRow(ending[1]);
                if (possible.name && possible.qty && possible.unit && (possible.price || quantityOnlyStrict(ending[1], possible))) {
                    priorText = transcript.value; transcript.value = ending[1]; parse(); advanceAfterApproval = true;
                    status.textContent = voiceMessage('Granska och använd förslaget först. Därefter flyttas fokus till nästa rad.', 'Review and apply the proposal first. Focus will then move to the next row.');
                    return 'dictated';
                }
            }
            var command = text.match(/^(ersätt med|lägg till)\s+(.+)$/i);
            priorText = transcript.value;
            transcript.value = command && command[1].toLocaleLowerCase('sv-SE') === 'lägg till' && transcript.value
                ? transcript.value + ' ' + command[2] : (command ? command[2] : text);
            parse();
            return 'dictated';
        }
        target.addEventListener('change', function () {
            voiceObservation = null;
            if (numericSuggestions) numericSuggestions.hidden = true;
            closeArticleChoices();
            document.getElementById('voice-row-preview').hidden = target.value !== 'row';
            refreshUncertainty();
            if (target.value === 'row') {
                transcriptNeedsParsing = true;
                status.textContent = voiceMessage('Välj Tolka text för att uppdatera radförslaget.', 'Select Interpret text to update the line suggestion.');
            } else status.textContent = '';
        });
        transcript.addEventListener('input', function () {
            voiceObservation = null;
            if (numericSuggestions) numericSuggestions.hidden = true;
            closeArticleChoices();
            if (target.value === 'row') {
                transcriptNeedsParsing = true;
                refreshUncertainty();
                advanceAfterApproval = false;
                previewPending = transcript.value.trim() !== '';
                status.textContent = voiceMessage(invMsg('Texten har ändrats. Välj Tolka text innan du använder förslaget.', 'The text has changed. Choose Interpret text before you use the suggestion.'), 'Text changed. Select Interpret text before applying the suggestion.');
            }
        });
        document.getElementById('voice-parse').addEventListener('click', parse);
        [name, qty, unit, price].forEach(function (field) {
            function onPreviewEdit() {
                previewPending = true;
                setArticleReason(voiceMessage(invMsg('Förslaget har ändrats manuellt. Kontrollera artikel, enhet och pris.', 'The suggestion was changed manually. Check item, unit and price.'), 'The proposal was edited manually. Check the item, unit and price.'));
                refreshUncertainty();
                var original = window.InvoiceVoice.parseRow(transcript.value);
                if (target.value === 'row' && !transcriptNeedsParsing && name.value === learnedDescription(original.name) && unit.value === original.unit) showNumericSuggestions(original);
                else if (numericSuggestions) numericSuggestions.hidden = true;
            }
            field.addEventListener('input', onPreviewEdit);
            field.addEventListener('change', onPreviewEdit);
        });
        document.getElementById('voice-clear').addEventListener('click', function () {
            if (recognition) { status.textContent = voiceMessage(invMsg('Avsluta eller avbryt inspelningen först.', 'Finish or cancel the recording first.'), 'Stop or cancel recording first.'); return; }
            transcript.value = ''; name.value = ''; qty.value = ''; unit.value = ''; price.value = ''; voiceObservation = null;
            if (numericSuggestions) numericSuggestions.hidden = true;
            closeArticleChoices();
            previewPending = false; transcriptNeedsParsing = false; advanceAfterApproval = false; refreshUncertainty();
            status.textContent = voiceMessage('Förslaget rensades. Fakturaraderna ändrades inte.', 'The proposal was cleared. Invoice rows were not changed.');
        });
        function setRecording(recording) {
            start.disabled = recording;
            if (stop) stop.hidden = !recording;
            if (cancel) cancel.hidden = !recording;
        }
        if (!window.SpeechRecognition && !window.webkitSpeechRecognition) {
            start.disabled = true;
            status.textContent = voiceMessage('Röstigenkänning stöds inte här. Du kan skriva och tolka texten i stället.', 'Speech recognition is not supported here. You can type and interpret the text instead.');
        } else {
            start.addEventListener('click', function () {
                if (recognition) return;
                if (readStop && !readStop.hidden) { status.textContent = voiceMessage('Stoppa uppläsningen innan du startar mikrofonen.', 'Stop reading before starting the microphone.'); return; }
                var Engine = window.SpeechRecognition || window.webkitSpeechRecognition;
                var engine;
                try { engine = new Engine(); } catch (error) {
                    status.textContent = voiceMessage(invMsg('Röstigenkänningen kunde inte startas.', 'Speech recognition could not start.'), 'Speech recognition could not start.'); return;
                }
                recognition = engine;
                var initialText = transcript.value, initialTarget = target.value;
                var initialSuggestion = JSON.stringify([name.value, qty.value, unit.value, price.value]);
                var initialDestination = destinationRow, initialActiveRow = activeVoiceRow;
                var fragments = [], cancelled = false, failed = false;
                engine.lang = 'sv-SE'; engine.interimResults = false; engine.maxAlternatives = 1;
                engine.onresult = function (event) {
                    if (cancelled || failed || recognition !== engine || !event.results) return;
                    var first = typeof event.resultIndex === 'number' ? event.resultIndex : 0;
                    for (var i = first; i < event.results.length; i++) {
                        var item = event.results[i];
                        if (item && item.isFinal !== false && item[0] && typeof item[0].transcript === 'string') fragments[i] = item[0].transcript;
                    }
                };
                engine.onerror = function (event) {
                    if (recognition !== engine || cancelled) return;
                    failed = true;
                    var code = event && event.error;
                    status.textContent = code === 'not-allowed' || code === 'service-not-allowed'
                        ? voiceMessage(invMsg('Mikrofonen eller rösttjänsten är blockerad. Kontrollera behörigheter i webbläsaren.', 'The microphone or speech service is blocked. Check the browser permissions.'), 'Microphone or speech service blocked. Check browser permissions.')
                        : code === 'no-speech' ? voiceMessage(invMsg('Inget tal uppfattades. Försök igen eller skriv manuellt.', 'No speech was detected. Try again or type manually.'), 'No speech was detected. Try again or type manually.')
                        : voiceMessage(invMsg('Röstinmatningen misslyckades. Försök igen eller skriv manuellt.', 'Voice input failed. Try again or type manually.'), 'Voice input failed. Try again or type manually.');
                };
                engine.onend = function () {
                    if (recognition !== engine) return;
                    recognition = null; setRecording(false);
                    if (cancelled || failed) return;
                    var spoken = fragments.filter(function (part) { return typeof part === 'string' && part.trim(); }).join(' ').trim();
                    if (!spoken) { status.textContent = voiceMessage(invMsg('Inget tal uppfattades. Försök igen eller skriv manuellt.', 'No speech was detected. Try again or type manually.'), 'No speech was detected. Try again or type manually.'); return; }
                    if (Array.from(spoken).length > 1000) { status.textContent = voiceMessage(invMsg('Dikteringen blev för lång. Dela upp den i kortare förslag; inget har använts.', 'The dictation became too long. Split it into shorter suggestions; nothing was used.'), 'Dictation was too long. Split it into shorter suggestions; nothing was applied.'); return; }
                    if (transcript.value !== initialText || target.value !== initialTarget || initialSuggestion !== JSON.stringify([name.value, qty.value, unit.value, price.value]) || initialDestination !== destinationRow || initialActiveRow !== activeVoiceRow) {
                        status.textContent = voiceMessage(invMsg('Förslaget ändrades under inspelningen. Dikteringen användes inte; försök igen.', 'The suggestion changed during recording. The dictation was not used; try again.'), 'The suggestion changed during recording. Dictation was not applied; try again.'); return;
                    }
                    var outcome = acceptSpeech(spoken);
                    if (initialTarget !== 'row' && outcome !== true) status.textContent = voiceMessage('Granska texten innan du använder den.', 'Review the text before applying it.');
                    if (initialTarget === 'row' && outcome === 'dictated') {
                        var original = window.InvoiceVoice.parseRow(transcript.value);
                        var id = observationId();
                        voiceObservation = id ? { id: id, heard: original.name && original.qty && original.unit ? original.name : '', qty: original.qty, price: original.price, unit: original.unit } : null;
                        var matchingNumeric = numericMatches(original);
                        if (matchingNumeric.length && !articleUncertain && !articleNeedsReview) status.textContent = voiceMessage(invMsg('En tidigare rättning av antal eller pris finns. Granska förslaget manuellt.', 'A previous correction of quantity or price exists. Review the suggestion manually.'), 'A previous quantity or price correction exists. Review the proposal manually.');
                        // Refresh even when a local match already blocks automation, so
                        // corrections learned in another session are not shown stale.
                        if (voiceProfile && voiceProfile.learning && (matchingNumeric.length || !voiceProfile.auto || voiceProfile.reviewed < 50)) {
                            var reviewObservation = voiceObservation;
                            var reviewSnapshot = JSON.stringify([transcript.value, name.value, qty.value, unit.value, price.value, target.value]);
                            profileRequest('load').then(function () {
                                if (recognition || voiceObservation !== reviewObservation || reviewSnapshot !== JSON.stringify([transcript.value, name.value, qty.value, unit.value, price.value, target.value])) return;
                                if (showNumericSuggestions(original).length) { refreshUncertainty(); status.textContent = voiceMessage(invMsg('En tidigare rättning av antal eller pris finns. Granska förslaget manuellt.', 'A previous correction of quantity or price exists. Review the suggestion manually.'), 'A previous quantity or price correction exists. Review the proposal manually.'); }
                            }).catch(function () { voiceProfile = null; showLearningState(); status.textContent = voiceMessage(invMsg('Personliga rättningar kunde inte hämtas. Granska förslaget manuellt.', 'Personal corrections could not be fetched. Review the suggestion manually.'), 'Personal corrections could not be loaded. Review the proposal manually.'); });
                        }
                        if (!matchingNumeric.length && !articleUncertain && !articleNeedsReview && voiceObservation && voiceProfile && voiceProfile.learning && voiceProfile.auto && voiceProfile.reviewed >= 50 && original.name && original.qty && original.unit && price.value) {
                            var observation = voiceObservation;
                            var selectedDestination = destinationRow;
                            var selectedActiveRow = activeVoiceRow;
                            var snapshot = JSON.stringify([transcript.value, name.value, qty.value, unit.value, price.value, target.value]);
                            var trackFields = { heard: original.name, approved: name.value.trim() };
                            if (voiceProfile.numeric_learning) { trackFields.numeric_qty = original.qty; if (original.price) trackFields.numeric_price = original.price; trackFields.numeric_unit = original.unit; }
                            profileRequest('track_auto', trackFields).then(function (current) {
                                if (!articleExact && suggestionKey(learnedDescription(original.name)) !== suggestionKey(original.name) && snapshot === JSON.stringify([transcript.value, name.value, qty.value, unit.value, price.value, target.value]) && voiceObservation === observation && !recognition) {
                                    showArticleChoices(original, suggestArticles(original));
                                    status.textContent = voiceMessage(invMsg('En tidigare rättning finns. Välj själv och granska raden.', 'A previous correction exists. Choose yourself and review the row.'), 'A previous correction exists. Choose and review the line yourself.');
                                    return;
                                }
                                if (numericMatches(original).length) { showNumericSuggestions(original); status.textContent = voiceMessage(invMsg('En tidigare rättning av antal eller pris finns. Granska förslaget manuellt.', 'A previous correction of quantity or price exists. Review the suggestion manually.'), 'A previous quantity or price correction exists. Review the proposal manually.'); return; }
                                if (!current.learning || !current.auto || current.reviewed < 50 || learningBusy || voiceObservation !== observation || recognition || destinationRow !== selectedDestination || activeVoiceRow !== selectedActiveRow || snapshot !== JSON.stringify([transcript.value, name.value, qty.value, unit.value, price.value, target.value]) || articleUncertain || articleNeedsReview || (!articleExact && learnedDescription(original.name) !== name.value) || typeof current._token !== 'string' || !current._token.startsWith(personalVoiceId + '.')) return;
                                applyVoiceSuggestion(true, current._token);
                            }).catch(function () { voiceProfile = null; showLearningState(); status.textContent = voiceMessage(invMsg('Automatiken kunde inte verifieras. Granska och använd förslaget manuellt.', 'The automation could not be verified. Review and use the suggestion manually.'), 'Automatic entry could not be verified. Review and apply the proposal manually.'); });
                        }
                    }
                };
                if (stop) stop.onclick = function () {
                    if (recognition !== engine) return;
                    try { status.textContent = voiceMessage('Avslutar och väntar på resultat…', 'Stopping and waiting for the result…'); engine.stop(); }
                    catch (error) { status.textContent = voiceMessage(invMsg('Kunde inte stoppa. Avbryt inspelningen i stället.', 'Could not stop. Cancel the recording instead.'), 'Could not stop. Cancel the recording instead.'); }
                };
                if (cancel) cancel.onclick = function () {
                    if (recognition !== engine) return;
                    cancelled = true; recognition = null; setRecording(false);
                    var stopped = false;
                    try { engine.abort(); stopped = true; }
                    catch (error) { try { engine.stop(); stopped = true; } catch (stopError) { /* Ignore any late result regardless. */ } }
                    status.textContent = stopped
                        ? voiceMessage(invMsg('Inspelningen avbröts. Inget förslag användes.', 'The recording was cancelled. No suggestion was used.'), 'Recording cancelled. No suggestion was applied.')
                        : voiceMessage(invMsg('Kunde inte stoppa mikrofonen. Stäng sidan eller återkalla mikrofontillståndet. Inget förslag användes.', 'Could not stop the microphone. Close the page or revoke the microphone permission. No suggestion was used.'), 'Could not stop the microphone. Close the page or revoke microphone permission. No suggestion was applied.');
                };
                try { setRecording(true); status.textContent = voiceMessage('Lyssnar…', 'Listening…'); engine.start(); }
                catch (error) { recognition = null; setRecording(false); status.textContent = voiceMessage(invMsg('Mikrofonen kunde inte startas.', 'The microphone could not start.'), 'The microphone could not be started.'); }
            });
        }
        function applyVoiceSuggestion(isAuto, provenanceToken) {
            if (recognition) { status.textContent = voiceMessage(invMsg('Avsluta eller avbryt inspelningen först.', 'Finish or cancel the recording first.'), 'Stop or cancel recording first.'); return; }
            if (target.value === 'row') {
                if (articleUncertain) { status.textContent = voiceMessage(invMsg('Välj artikel eller bekräfta fritext innan förslaget används.', 'Choose an item or confirm free text before the suggestion is used.'), 'Select an item or confirm free text before applying the proposal.'); return; }
                if (transcriptNeedsParsing) { status.textContent = voiceMessage(invMsg('Texten har ändrats. Välj Tolka text först.', 'The text has changed. Choose Interpret text first.'), 'The text has changed. Select Interpret text first.'); return; }
                if (!name.value.trim() || !unit.value || !Object.prototype.hasOwnProperty.call(invoiceUnitOptions || {}, unit.value) || !/^\d+(?:[.,]\d{1,2})?$/.test(qty.value.trim()) || !/^\d+(?:[.,]\d{1,2})?$/.test(price.value.trim()) || Number(qty.value.replace(',', '.')) > 100000 || Number(price.value.replace(',', '.')) > 10000000) {
                    status.textContent = <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "Enter a valid description, quantity and price before adding the line." : "Ange giltig beskrivning, antal och pris innan raden läggs till.", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>; return;
                }
                if (!fixedCatalogAvailable) { status.textContent = voiceMessage('Artikelkatalogen är otillgänglig. Inget röstförslag förs in förrän fasta priser kan kontrolleras.', 'The item catalog is unavailable. No voice proposal is applied until fixed prices can be checked.'); return; }
                var fixed = fixedArticle(name.value);
                if (fixed && (fixed.ambiguous || unit.value !== fixed.unit || Number(price.value.replace(',', '.')) !== Number(fixed.price))) {
                    status.textContent = voiceMessage(invMsg('Artikeln har fast pris. Välj den i katalogen med rätt enhet och pris; det dikterade priset används inte.', 'The item has a fixed price. Choose it in the catalogue with the right unit and price; the dictated price is not used.'), 'This item has a fixed price. Select it in the catalog with the correct unit and price; the dictated price is not used.'); return;
                }
                var fingerprint = JSON.stringify([name.value.trim().toLocaleLowerCase('sv-SE'), Number(qty.value.trim().replace(',', '.')), unit.value, Number(price.value.trim().replace(',', '.'))]);
                var duplicateRow = Array.prototype.some.call(body.rows, function (candidate) {
                    return JSON.stringify([candidate.querySelector('.invoice-item-name').value.trim().toLocaleLowerCase('sv-SE'), Number(candidate.querySelector('.invoice-item-qty').value.trim().replace(',', '.')), candidate.querySelector('.invoice-item-unit').value, Number(candidate.querySelector('.invoice-item-price').value.trim().replace(',', '.'))]) === fingerprint;
                });
                if (isAuto && duplicateRow) { status.textContent = voiceMessage(invMsg('Möjlig dubblett. Granska förslaget manuellt.', 'Possible duplicate. Review the suggestion manually.'), 'Possible duplicate. Review the proposal manually.'); return; }
                if (fingerprint === lastRowFingerprint && (isAuto || !window.confirm(voiceMessage(invMsg('Samma förslag har redan lagts till. Vill du lägga till en likadan rad igen?', 'The same suggestion has already been added. Do you want to add an identical row again?'), 'This suggestion was already added. Add an identical line again?')))) { if (isAuto) status.textContent = voiceMessage(invMsg('Möjlig dubblett. Granska förslaget manuellt.', 'Possible duplicate. Review the suggestion manually.'), 'Possible duplicate. Review the proposal manually.'); return; }
                if (destinationRow && !body.contains(destinationRow)) destinationRow = null;
                var row = destinationRow;
                if (!row) row = Array.prototype.find.call(body.rows, function (candidate) {
                    return !candidate.querySelector('.invoice-item-name').value && !candidate.querySelector('.invoice-item-qty').value && !candidate.querySelector('.invoice-item-price').value;
                }) || null;
                var created = false;
                if (row && ((row.querySelector('.invoice-fixed-lock') && row.querySelector('.invoice-fixed-lock').value) || fixedArticle(row.querySelector('.invoice-item-name').value))) { status.textContent = voiceMessage(invMsg('Fastprisraden kan inte ersättas med röst. Ta bort raden om den inte ska vara kvar.', 'A fixed-price row cannot be replaced by voice. Delete the row if it should not remain.'), 'A fixed-price line cannot be replaced by voice. Remove the line if it is no longer needed.'); return; }
                if (row && (row.querySelector('.invoice-item-name').value || row.querySelector('.invoice-item-qty').value || row.querySelector('.invoice-item-price').value)) {
                    if (isAuto || !window.confirm(voiceMessage('Den valda raden innehåller uppgifter. Vill du ersätta dem med det granskade förslaget?', 'The selected row contains data. Replace it with the reviewed proposal?'))) { if (isAuto) status.textContent = voiceMessage(invMsg('Vald rad innehåller uppgifter. Granska förslaget manuellt.', 'The selected row contains entries. Review the suggestion manually.'), 'The selected row contains data. Review the proposal manually.'); return; }
                }
                if (!row) { row = addRow('', '', '', unit.value); created = true; }
                if (!row) { status.textContent = 'Maximalt antal rader har nåtts.'; return; }
                var controls = ['.invoice-item-name', '.invoice-item-qty', '.invoice-item-unit', '.invoice-item-price'].map(function (selector) { return row.querySelector(selector); });
                var before = controls.map(function (field) { return field.value; });
                var rowTokenField = row.querySelector('.invoice-voice-token');
                var previousToken = rowTokenField.value;
                rowTokenField.value = isAuto ? provenanceToken : '';
                row.querySelector('.invoice-item-name').value = Array.from(name.value.trim()).slice(0, 200).join('');
                row.querySelector('.invoice-item-qty').value = qty.value.trim().replace(',', '.');
                row.querySelector('.invoice-item-unit').value = unit.value;
                row.querySelector('.invoice-item-price').value = price.value.trim().replace(',', '.');
                updateFixedRow(row, false);
                var after = controls.map(function (field) { return field.value; });
                var usedObservation = voiceObservation; voiceObservation = null;
                autoRowObservations.delete(row);
                if (usedObservation && voiceProfile && voiceProfile.learning) {
                    if (!isAuto) recordLearning('review', usedObservation, after[0]);
                    if (!isAuto) autoRowObservations.set(row, usedObservation);
                    // A subsequent explicit description edit is correction feedback,
                    // never another manually reviewed voice observation.
                    var descriptionField = row.querySelector('.invoice-item-name');
                    descriptionField.addEventListener('change', function () {
                        if (row.isConnected && autoRowObservations.get(row) === usedObservation && descriptionField.value.trim()) recordLearning('correction', usedObservation, descriptionField.value.trim());
                    });
                }
                lastApplied = { undo: function () {
                    if (!row.isConnected || controls.some(function (field, index) { return field.value !== after[index]; })) return false;
                    if (autoRowObservations.get(row) === usedObservation) autoRowObservations.delete(row);
                    if (!created) { controls.forEach(function (field, index) { field.value = before[index]; }); rowTokenField.value = previousToken; }
                    else row.remove();
                    if (!created) updateFixedRow(row, false);
                    updateRowControls(); recalculate(); refreshPriceHistory(); form.dispatchEvent(new Event('input', { bubbles: true })); return true;
                } };
                row.querySelector('.invoice-item-name').dispatchEvent(new Event('input', { bubbles: true }));
                recalculate(); activeVoiceRow = row; destinationRow = null; refreshVoiceDestination(); previewPending = false; lastRowFingerprint = fingerprint; status.textContent = <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "The line was added. Review it before saving." : "Raden är tillagd. Granska den innan du sparar.", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                if (isAuto) status.textContent = voiceMessage(invMsg('Raden lades till automatiskt. Kontrollera antal, enhet och pris före sparning.', 'The row was added automatically. Check quantity, unit and price before saving.'), 'The row was added automatically. Check quantity, unit and price before saving.');
                if (advanceAfterApproval) { advanceAfterApproval = false; nextVoiceRow(); status.textContent = voiceMessage(invMsg('Raden är tillagd. ', 'The row was added. '), 'The row was added. ') + status.textContent; }
            } else if (target.value === 'catalog') {
                var select = document.getElementById('invoice-catalog-article');
                if (!select) { status.textContent = invMsg('Artikelkatalogen är tom.', 'The item catalogue is empty.'); return; }
                var query = transcript.value.trim().toLocaleLowerCase('sv-SE');
                var matches = Array.prototype.filter.call(select.options, function (option) { return option.value && query && (option.getAttribute('data-name') || '').toLocaleLowerCase('sv-SE').includes(query); });
                if (matches.length !== 1) { status.textContent = matches.length ? invMsg('Flera artiklar matchar. Välj artikel i katalogen.', 'Several items match. Choose an item in the catalogue.') : invMsg('Ingen artikel matchar sökningen.', 'No item matches the search.'); return; }
                select.value = matches[0].value; select.closest('details').open = true;
                status.textContent = invMsg('Artikeln är vald i katalogen. Kontrollera priset och välj Lägg till på fakturarad.', 'The item is selected in the catalogue. Check the price and choose Add to invoice row.');
            } else {
                var field = document.getElementById(target.value === 'customer' ? 'invoice-customer' : 'invoice-reference');
                if (!transcript.value.trim()) { status.textContent = invMsg('Ange text innan den används.', 'Enter text before it is used.'); return; }
                var previousValue = field.value;
                field.value = transcript.value.trim().slice(0, field.maxLength);
                var appliedValue = field.value;
                lastApplied = { undo: function () { if (field.value !== appliedValue) return false; field.value = previousValue; field.dispatchEvent(new Event('input', { bubbles: true })); return true; } };
                field.dispatchEvent(new Event('input', { bubbles: true }));
                status.textContent = invMsg('Fältet har uppdaterats. Kontrollera uppgiften.', 'The field was updated. Check the entry.');
            }
        }
        document.getElementById('voice-apply').addEventListener('click', function () { applyVoiceSuggestion(false); });
        var readStop = document.getElementById('voice-read-stop');
        var speechGeneration = 0;
        function stopReading(showMessage) {
            speechGeneration++;
            if (window.speechSynthesis) window.speechSynthesis.cancel();
            if (readStop) readStop.hidden = true;
            if (showMessage) status.textContent = voiceMessage(invMsg('Uppläsningen stoppades.', 'The read-out was stopped.'), 'Reading stopped.');
        }
        if (readStop) readStop.addEventListener('click', function () { stopReading(true); });
        window.addEventListener('pagehide', function () { stopReading(false); });
        document.getElementById('voice-read').addEventListener('click', function () {
            if (recognition) { status.textContent = voiceMessage('Avsluta eller avbryt mikrofonen innan uppläsning.', 'Stop or cancel the microphone before reading.'); return; }
            if (!window.speechSynthesis || !window.SpeechSynthesisUtterance) { status.textContent = voiceMessage('Uppläsning stöds inte i den här webbläsaren.', 'Reading aloud is not supported in this browser.'); return; }
            stopReading(false);
            var generation = speechGeneration;
            function value(id) { var field=document.getElementById(id); return field ? (typeof field.value === 'string' ? field.value.trim() : field.textContent.trim()) : ''; }
            var pieces = [
                invMsg('Fakturaunderlag. Datum: ', 'Invoice record. Date: ') + (value('invoice-date') || invMsg('saknas', 'missing')) + '.',
                invMsg('Kund: ', 'Customer: ') + (value('invoice-customer') || invMsg('saknas', 'missing')) + '.',
                invMsg('Referens: ', 'Reference: ') + (value('invoice-reference') || invMsg('saknas', 'missing')) + '.',
                invMsg('Uppdrag: ', 'Assignment: ') + (value('invoice-description') || invMsg('saknas', 'missing')) + '.',
                invMsg('Fortnox fakturanummer: ', 'Fortnox invoice number: ') + (value('invoice-fortnox-number') || invMsg('saknas', 'missing')) + '.',
                invMsg('Fakturadatum i Fortnox: ', 'Invoice date in Fortnox: ') + (value('invoice-fortnox-date') || invMsg('saknas', 'missing')) + '.',
                invMsg('Priserna är ', 'The prices are ') + (value('invoice-vat-mode') === 'inc' ? invMsg('inklusive', 'including') : invMsg('exklusive', 'excluding')) + invMsg(' moms.', ' VAT.')
            ];
            var count = 0, sum = 0, validSum = true;
            Array.prototype.forEach.call(body.rows, function (row, index) {
                var itemName = row.querySelector('.invoice-item-name'), itemQty = row.querySelector('.invoice-item-qty'), itemUnit = row.querySelector('.invoice-item-unit'), itemPrice = row.querySelector('.invoice-item-price');
                if (!itemName || !itemQty || !itemUnit || !itemPrice || (!itemName.value && !itemQty.value && !itemPrice.value)) return;
                count++;
                var itemUnitText = itemUnit.selectedIndex >= 0 ? itemUnit.options[itemUnit.selectedIndex].textContent : invMsg('saknas', 'missing');
                pieces.push(invMsg('Rad ', 'Row ') + (index + 1) + ': ' + (itemName.value || invMsg('beskrivning saknas', 'description missing')) + invMsg(', antal ', ', quantity ') + (itemQty.value || invMsg('saknas', 'missing')) + invMsg(', enhet ', ', unit ') + itemUnitText + invMsg(', pris per enhet ', ', price per unit ') + (itemPrice.value || invMsg('saknas', 'missing')) + ' kronor.');
                if (itemQty.value === '' || itemPrice.value === '' || !Number.isFinite(Number(itemQty.value)) || !Number.isFinite(Number(itemPrice.value))) validSum = false;
                else sum += roundCurrency(Number(itemQty.value) * Number(itemPrice.value));
            });
            if (previewPending) pieces.push('Det aktuella röstförslaget är inte infört och ingår inte i uppläsningen.');
            pieces.push(count ? (validSum ? 'Summa av inmatade rader: ' + sum.toFixed(2) + invMsg(' kronor. Kontrollera alltid underlaget före sparning.', ' kronor. Always check the record before saving.') : invMsg('Summan kan inte läsas upp eftersom en rad saknar antal eller pris.', 'The sum cannot be read aloud because a row lacks quantity or price.')) : 'Inga rader är ifyllda.');
            // Chunk long assignment texts without dropping characters. Browsers
            // often fail silently on one very long utterance.
            var chunks = [];
            pieces.forEach(function (piece) { var chars=Array.from(piece); while (chars.length) chunks.push(chars.splice(0, 220).join('')); });
            if (readStop) readStop.hidden = false;
            status.textContent = voiceMessage(invMsg('Läser upp underlaget. Kontrollera även de synliga uppgifterna.', 'Reading the record aloud. Also check the visible entries.'), 'Reading the record. Also check the visible details.');
            var index = 0;
            function speakNext() {
                if (generation !== speechGeneration) return;
                if (index >= chunks.length) { if (readStop) readStop.hidden = true; status.textContent = voiceMessage(invMsg('Uppläsningen är klar.', 'The read-out is complete.'), 'Reading complete.'); return; }
                var utterance = new window.SpeechSynthesisUtterance(chunks[index++]); utterance.lang = 'sv-SE';
                utterance.onend = speakNext;
                utterance.onerror = function () { if (generation === speechGeneration) { if (readStop) readStop.hidden = true; status.textContent = voiceMessage(invMsg('Uppläsningen avbröts. Kontrollera underlaget visuellt.', 'The read-out was cancelled. Check the record visually.'), 'Reading was interrupted. Check the record visually.'); } };
                try { window.speechSynthesis.speak(utterance); } catch (error) { utterance.onerror(); }
            }
            speakNext();
        });
    }());

    // Price comparison never changes the amount; it is advisory and VAT-aware.
    function updatePriceHistory(row) {
        var nameField = row.querySelector('.invoice-item-name');
        var priceField = row.querySelector('.invoice-item-price');
        var unitField = row.querySelector('.invoice-item-unit');
        if (!nameField || !priceField || !unitField) return;
        var hint = row.querySelector('.invoice-price-history');
        if (!hint) { hint = document.createElement('small'); hint.className = 'invoice-price-history invoice-help'; priceField.parentNode.appendChild(hint); }
        var name = nameField.value.trim().toLocaleLowerCase('sv-SE');
        var match = priorPrices.find(function (item) { return item.name.trim().toLocaleLowerCase('sv-SE') === name && item.unit === unitField.value && item.vat === vatMode.value; });
        if (!name || !match) { hint.textContent = ''; return; }
        var entered = priceField.value === '' ? null : Number(priceField.value);
        hint.textContent = invMsg('Tidigare sparat pris: ', 'Previously saved price: ') + money(Number(match.price)) + ' / ' + match.unit + (match.date ? ' (underlagsdatum ' + match.date + ')' : '') + (entered !== null && Math.abs(entered - Number(match.price)) > 0.005 ? ' · Avviker från angivet pris.' : '') + invMsg(' Samma momsläge; kontrollera villkoren.', ' Same VAT mode; check the terms.');
    }
    function refreshPriceHistory() { Array.prototype.forEach.call(body.rows, updatePriceHistory); }
    body.addEventListener('input', function (event) { var row = event.target.closest('tr'); if (row) updatePriceHistory(row); });
    body.addEventListener('change', function (event) { var row = event.target.closest('tr'); if (row) updatePriceHistory(row); });
    var priceVatCheckbox = document.getElementById('invoice-vat-included');
    if (priceVatCheckbox) priceVatCheckbox.addEventListener('change', function () { window.setTimeout(refreshPriceHistory, 0); });
    refreshPriceHistory();

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
            option.textContent = invMsg('Rad ', 'Row ') + (index + 1) + ': ' + article.name + ' · ' + (invoiceUnitOptions[article.unit] || article.unit) + ' · ' + formattedPrice + ' kr per enhet';
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
                if (catalogSaveStatus) catalogSaveStatus.textContent = invMsg('Välj en rad med beskrivning och giltigt pris först.', 'Choose a row with a description and a valid price first.');
                updateCatalogSourceRows();
                catalogSourceRowSelect.focus();
                return;
            }
            var csrfInput = form.querySelector('input[name="csrf_token"]');
            if (!csrfInput || !csrfInput.value) {
                if (catalogSaveStatus) catalogSaveStatus.textContent = invMsg('Säkerhetskontrollen saknas. Ladda om sidan och försök igen.', 'The security check is missing. Reload the page and try again.');
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
                    invalid_name: invMsg('Artikelns beskrivning kan inte sparas. Kontrollera texten och försök igen.', 'The item description cannot be saved. Check the text and try again.'),
                    invalid_unit: invMsg('Enheten är ogiltig. Välj en av de förvalda enheterna.', 'The unit is invalid. Choose one of the preset units.'),
                    invalid_price: invMsg('Priset är ogiltigt. Ange ett belopp mellan 0 och 10 000 000 kr.', 'The price is invalid. Enter an amount between 0 and 10,000,000 kronor.'),
                    catalog_full: invMsg('Artikelkatalogen har nått sin gräns på 500 artiklar.', 'The item catalogue has reached its limit of 500 items.'),
                    storage_error: invMsg('Artikelkatalogen kunde inte sparas. Försök igen senare.', 'The item catalogue could not be saved. Try again later.'),
                    admin_required: invMsg('Endast administratörer kan spara en ny artikel från formuläret.', 'Only administrators can save a new item from the form.'),
                    login_required: 'Inloggningen har gått ut. Logga in igen och försök.',
                    invalid_tab: invMsg('Artikelkatalogen kunde inte nås från den här appvyn.', 'The item catalogue could not be reached from this app view.')
                };
                if (catalogSaveStatus) catalogSaveStatus.textContent = messages[error && error.message] || invMsg('Artikeln kunde inte sparas. Kontrollera anslutningen och försök igen.', 'The item could not be saved. Check the connection and try again.');
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
                catalogArticleStatus.textContent = invMsg('Välj en artikel först.', 'Choose an item first.');
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
                var targetVoiceToken = targetRow.querySelector('.invoice-voice-token');
                if (targetVoiceToken) targetVoiceToken.value = '';
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
            updateFixedRow(targetRow, false);
            catalogArticleStatus.textContent = invMsg('Artikeln lades till med enhet ', 'The item was added with unit ') + invoiceUnitOptions[unit] + invMsg(' och antal 1. Ändra vid behov.', ' and quantity 1. Adjust it if needed.');
            catalogArticleSelect.value = '';
            targetRow.querySelector('.invoice-item-qty').focus();
            scheduleAutosave();
        });
    }

    table.addEventListener('change', function (event) { if (event.target.matches('.invoice-item-name, .invoice-item-unit')) updateFixedRow(event.target.closest('tr'), true); });
    Array.prototype.forEach.call(body.rows, function (row) { updateFixedRow(row, false); });
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
            updateFixedRow(row, false);
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
                promptOption.textContent = invMsg('Välj kund först', 'Choose a customer first');
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

        if (partialRow) return <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "Complete the description, quantity and price on every started line, or clear the line completely." : "Fyll i beskrivning, antal och pris på varje påbörjad rad – eller töm raden helt.", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        if (completedRows === 0) return <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "Add at least one work or material line before continuing." : "Lägg till minst en rad med arbete eller material innan du fortsätter.", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
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
    var invLang = <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? 'en' : 'sv') ?>;
    function invMsg(sv, en) { return invLang === 'en' ? en : sv; }
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
