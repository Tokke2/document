<?php
declare(strict_types=1);

$protocolRegParameterInvalid = array_key_exists('protocol_reg', $_GET) && !is_string($_GET['protocol_reg']);
$protocolRegRawInput = isset($_GET['protocol_reg']) && is_string($_GET['protocol_reg'])
    ? trim($_GET['protocol_reg'])
    : '';
$protocolRegInputTooLong = strlen($protocolRegRawInput) > 40;
$protocolRegInput = substr($protocolRegRawInput, 0, 40);
$protocolNormalizeRegistration = static function (mixed $value): string {
    if (!is_scalar($value)) {
        return '';
    }
    return strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim((string) $value)) ?? '');
};
$protocolRecordRegistration = static function (array $record): string {
    foreach (['reg', 'regnr'] as $key) {
        $value = $record[$key] ?? null;
        if (is_scalar($value)) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }
    }
    return '';
};
$protocolRegFilter = $protocolNormalizeRegistration($protocolRegInput);
$protocolFromParameterInvalid = array_key_exists('protocol_from', $_GET) && !is_string($_GET['protocol_from']);
$protocolToParameterInvalid = array_key_exists('protocol_to', $_GET) && !is_string($_GET['protocol_to']);
$protocolFromRawInput = isset($_GET['protocol_from']) && is_string($_GET['protocol_from'])
    ? trim($_GET['protocol_from'])
    : '';
$protocolToRawInput = isset($_GET['protocol_to']) && is_string($_GET['protocol_to'])
    ? trim($_GET['protocol_to'])
    : '';
$protocolFromInputTooLong = strlen($protocolFromRawInput) > 10;
$protocolToInputTooLong = strlen($protocolToRawInput) > 10;
$protocolFromInput = substr($protocolFromRawInput, 0, 10);
$protocolToInput = substr($protocolToRawInput, 0, 10);
$protocolSearchErrors = [];
$protocolFromDate = '';
$protocolToDate = '';
if ($protocolRegParameterInvalid) {
    $protocolSearchErrors[] = 'Registreringssökningen är ogiltig. Ange ett textvärde.';
} elseif ($protocolRegInputTooLong) {
    $protocolSearchErrors[] = 'Sökningen får innehålla högst 40 tecken.';
} elseif ($protocolRegInput !== '' && preg_match('/\\A[A-Za-z0-9 -]{1,40}\\z/D', $protocolRegInput) !== 1) {
    $protocolSearchErrors[] = 'Registreringsnumret får innehålla bokstäver, siffror, mellanslag och bindestreck.';
} elseif ($protocolRegInput !== '' && $protocolRegFilter === '') {
    $protocolSearchErrors[] = 'Ange minst en bokstav eller siffra i registreringsnumret.';
}
if ($protocolFromParameterInvalid || $protocolFromInputTooLong || ($protocolFromInput !== '' && !portal_valid_iso_date($protocolFromInput))) {
    $protocolSearchErrors[] = 'Från-datumet är ogiltigt. Ange ett giltigt datum.';
} else {
    $protocolFromDate = $protocolFromInput;
}
if ($protocolToParameterInvalid || $protocolToInputTooLong || ($protocolToInput !== '' && !portal_valid_iso_date($protocolToInput))) {
    $protocolSearchErrors[] = 'Till-datumet är ogiltigt. Ange ett giltigt datum.';
} else {
    $protocolToDate = $protocolToInput;
}
if ($protocolFromDate !== '' && $protocolToDate !== '' && $protocolFromDate > $protocolToDate) {
    $protocolSearchErrors[] = 'Från-datum måste vara samma som eller tidigare än till-datum.';
}
$protocolSearchError = implode(' ', array_unique($protocolSearchErrors));

$filteredProtocolRecords = [];
if ($protocolSearchError === '') {
    $filteredProtocolRecords = array_values(array_filter($savedProtocols, static function (array $record) use ($protocolRegFilter, $protocolFromDate, $protocolToDate, $protocolNormalizeRegistration, $protocolRecordRegistration): bool {
        $registration = $protocolNormalizeRegistration($protocolRecordRegistration($record));
        if ($protocolRegFilter !== '' && !str_contains($registration, $protocolRegFilter)) {
            return false;
        }
        $recordDate = is_scalar($record['date'] ?? null) ? trim((string) $record['date']) : '';
        if (($protocolFromDate !== '' || $protocolToDate !== '') && !portal_valid_iso_date($recordDate)) {
            return false;
        }
        if ($protocolFromDate !== '' && $recordDate < $protocolFromDate) {
            return false;
        }
        if ($protocolToDate !== '' && $recordDate > $protocolToDate) {
            return false;
        }
        return true;
    }));
    usort($filteredProtocolRecords, static function (array $left, array $right): int {
        $leftUpdated = is_scalar($left['updated_at'] ?? null) ? (string) $left['updated_at'] : '';
        $rightUpdated = is_scalar($right['updated_at'] ?? null) ? (string) $right['updated_at'] : '';
        $leftDate = is_scalar($left['date'] ?? null) ? (string) $left['date'] : $leftUpdated;
        $rightDate = is_scalar($right['date'] ?? null) ? (string) $right['date'] : $rightUpdated;
        $dateOrder = strcmp($rightDate, $leftDate);
        return $dateOrder !== 0 ? $dateOrder : strcmp($rightUpdated, $leftUpdated);
    });
}
?>
<style>
.protocol-records{--pr-ink:#142d36;--pr-muted:#61757b;--pr-line:#d9e5e3;max-width:1000px;margin:0 auto;color:var(--pr-ink)}
.protocol-records .pro-subnav{display:flex;gap:8px;overflow-x:auto;margin:8px 0 16px;padding-bottom:8px;border-bottom:1px solid var(--pr-line)}
.protocol-records .pro-subnav a{flex:0 0 auto;padding:9px 13px;border:1px solid var(--pr-line);border-radius:9px;background:#fff;color:#315462;text-decoration:none;font-size:.82rem;font-weight:750}
.protocol-records .pro-subnav a[aria-current="page"]{border-color:#bfe1d5;background:#e8f5f0;color:#075c59;box-shadow:inset 0 -2px 0 #0b7a72}
.protocol-records-head{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;margin:0 0 14px}
.protocol-records-head h2{margin:0;font-size:1.18rem}.protocol-records-head p{margin:4px 0 0;color:var(--pr-muted);font-size:.82rem}
.protocol-records-panel{padding:16px;border:1px solid var(--pr-line);border-radius:14px;background:#fff;box-shadow:0 6px 24px rgba(16,40,58,.04)}
.protocol-records-search{display:grid;grid-template-columns:minmax(180px,1.3fr) repeat(2,minmax(145px,.8fr)) auto;gap:10px;align-items:end;margin-bottom:12px}
.protocol-records-search label{display:block;color:#475467;font-size:.69rem;font-weight:800;letter-spacing:.035em;text-transform:uppercase}
.protocol-records-search input{display:block;width:100%;min-height:44px;margin-top:5px;padding:8px 10px;border:1px solid var(--pr-line);border-radius:8px;background:#fff;color:var(--pr-ink);font:inherit;font-size:.88rem}
.protocol-records-search small{display:block;margin-top:5px;color:var(--pr-muted);font-size:.68rem;font-weight:500;letter-spacing:0;line-height:1.4;text-transform:none}
.protocol-records-search .btn{min-height:44px}.protocol-filter-actions{display:flex;gap:8px;align-items:center}
.protocol-records-summary{margin:0 0 10px;color:var(--pr-muted);font-size:.8rem}
.protocol-records-note{margin:0 0 14px;padding:11px 13px;border:1px solid #cce5dc;border-radius:12px;background:#f0f8f4;color:#28564e;font-size:.82rem;line-height:1.5}
.protocol-records-table-wrap{overflow-x:auto;border:1px solid var(--pr-line);border-radius:10px}
.protocol-records-table{width:100%;min-width:690px;border-collapse:collapse}
.protocol-records-table th{padding:10px 11px;background:#f1f7f4;color:#38565a;text-align:left;font-size:.67rem;letter-spacing:.05em;text-transform:uppercase}
.protocol-records-table td{padding:10px 11px;border-top:1px solid #edf0f4;font-size:.82rem;vertical-align:middle}
.protocol-records-table tbody tr:hover td{background:#fbfdfe}
.protocol-record-number{font-weight:800;white-space:nowrap}.protocol-record-reg{font-weight:750;white-space:nowrap}
.protocol-record-actions{display:flex;gap:7px;white-space:nowrap}.protocol-record-actions .btn{min-height:40px;padding:8px 11px;font-size:.78rem}
.protocol-records-empty{padding:22px;border:1px dashed #d0d5dd;border-radius:10px;color:var(--pr-muted);font-size:.84rem;text-align:center}
.protocol-records-error{margin:0 0 12px;padding:10px 12px;border:1px solid #f0c7c3;border-radius:9px;background:#fff7f6;color:#9b2c21;font-size:.82rem}
@media(max-width:760px){.protocol-records-panel{padding:12px}.protocol-records-search{grid-template-columns:1fr 1fr}.protocol-records-search label:first-child{grid-column:1/-1}.protocol-records-search .btn{width:100%}.protocol-records-search .protocol-filter-actions{grid-column:1/-1;display:grid;grid-template-columns:1fr 1fr;gap:8px}.page-content .protocol-records-table-wrap{overflow:visible;border:0;background:transparent;box-shadow:none}.protocol-records-table{display:block;min-width:0}.protocol-records-table thead{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}.protocol-records-table tbody{display:grid;gap:11px}.protocol-records-table tbody tr{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px 11px;padding:13px;border:1px solid #dce7e4;border-radius:15px;background:#fff;box-shadow:0 7px 20px rgba(18,54,58,.055)}.protocol-records-table td{display:block;min-width:0;padding:0;border:0;overflow-wrap:anywhere}.protocol-records-table td::before{display:block;margin:0 0 5px;color:#6a7c7d;content:attr(data-label);font-size:.62rem;font-weight:850;letter-spacing:.07em;text-transform:uppercase}.protocol-records-table td[data-label="Protokollnr"],.protocol-records-table td[data-label="Kund / företag"],.protocol-records-table td[data-label="Åtgärder"]{grid-column:1/-1}.protocol-record-actions{display:grid;grid-template-columns:1fr 1fr;white-space:normal}.protocol-record-actions .btn{width:100%;min-height:46px}.protocol-record-number,.protocol-record-reg{white-space:normal}.protocol-records-table tbody tr:hover td{background:transparent}}
@media print{.protocol-records{display:none!important}}
</style>
<section class="pro-wrap protocol-records" aria-label="Sparade serviceprotokoll">
    <div class="invoice-session-bar"><div class="invoice-session-person"><strong><?= h($protocolAuthUser['name'] ?? $protocolAuthUser['username'] ?? 'Inloggad') ?></strong><span class="invoice-session-role"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Service report account' : 'Protokollkonto' ?></span></div><form class="invoice-session-form" method="post" action="?tab=protocol"><input type="hidden" name="action" value="protocol_pin_logout"><?= csrf_field() ?><button class="btn btn-outline" type="submit"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Sign out of service reports' : 'Logga ut från protokoll' ?></button></form></div>
    <nav class="pro-subnav" aria-label="Serviceprotokollflikar"><a href="?tab=protocol"><?= ($portalLanguage ?? 'sv') === 'en' ? 'New report' : 'Nytt protokoll' ?></a><a href="?tab=protocol&amp;protocol_view=records" aria-current="page">Sparade protokoll (<?= count($savedProtocols) ?>)</a></nav>
    <section class="protocol-records-panel">
        <header class="protocol-records-head"><div><h2>Alla sparade serviceprotokoll</h2><p>Sök på hela eller delar av registreringsnumret och begränsa vid behov med servicedatum.</p></div><a class="btn btn-outline" href="?tab=protocol"><?= ($portalLanguage ?? 'sv') === 'en' ? 'New report' : 'Nytt protokoll' ?></a></header>
        <p class="protocol-records-note">Kopiering skapar ett nytt protokoll med dagens datum och ett nytt nummer. Kund-/fordonsuppgifter, arbetsbeskrivning och rader följer med; foto och tidigare signatur kopieras inte. Teknikern signerar den nya posten.</p>
        <form class="protocol-records-search" method="get" action="?tab=protocol" role="search" aria-label="Sök serviceprotokoll">
            <input type="hidden" name="protocol_view" value="records">
            <label for="protocol-record-reg"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Registration number' : 'Registreringsnummer' ?><input id="protocol-record-reg" name="protocol_reg" type="search" value="<?= h($protocolRegInput) ?>" maxlength="40" placeholder="t.ex. ABC 123" aria-describedby="protocol-record-reg-help"><small id="protocol-record-reg-help">Stora/små bokstäver och mellanslag/bindestreck påverkar inte sökningen.</small></label>
            <label for="protocol-record-from"><?= ($portalLanguage ?? 'sv') === 'en' ? 'From date' : 'Från datum' ?><input id="protocol-record-from" name="protocol_from" type="date" value="<?= h($protocolFromDate) ?>"></label>
            <label for="protocol-record-to"><?= ($portalLanguage ?? 'sv') === 'en' ? 'To date' : 'Till datum' ?><input id="protocol-record-to" name="protocol_to" type="date" value="<?= h($protocolToDate) ?>"></label>
            <div class="protocol-filter-actions"><button class="btn btn-main" type="submit"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Search' : 'Sök' ?></button><a class="btn btn-outline" href="?tab=protocol&amp;protocol_view=records"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Clear' : 'Rensa' ?></a></div>
        </form>
        <?php if ($protocolSearchError !== ''): ?><p class="protocol-records-error" role="alert"><?= h($protocolSearchError) ?></p><?php endif; ?>
        <p class="protocol-records-summary" role="status" aria-live="polite"><?= $protocolSearchError !== '' ? 'Sökningen kunde inte köras.' : 'Visar ' . h((string) count($filteredProtocolRecords)) . ' av ' . h((string) count($savedProtocols)) . ' protokoll.' ?></p>
        <?php if ($filteredProtocolRecords): ?>
            <div class="protocol-records-table-wrap"><table class="protocol-records-table"><thead><tr><th>Protokollnr</th><th><?= ($portalLanguage ?? 'sv') === 'en' ? 'Date' : 'Datum' ?></th><th>Reg.nr</th><th><?= ($portalLanguage ?? 'sv') === 'en' ? 'Customer / company' : 'Kund / företag' ?></th><th></th></tr></thead><tbody>
                <?php foreach ($filteredProtocolRecords as $record):
                    $recordId = is_string($record['id'] ?? null) ? $record['id'] : '';
                    $safeRecordId = preg_match('/\A[a-f0-9]{32}\z/i', $recordId) ? $recordId : '';
                    $recordNumber = is_scalar($record['nummer'] ?? null) ? app_legacy_text($record['nummer']) : '—';
                    $recordDate = is_scalar($record['date'] ?? null) ? app_legacy_text($record['date']) : '';
                    $recordRegistration = app_legacy_text($protocolRecordRegistration($record));
                    $recordCustomer = is_scalar($record['customer'] ?? null) ? app_legacy_text($record['customer']) : '';
                ?>
                    <tr><td class="protocol-record-number" data-label="Protokollnr"><?= h($recordNumber) ?></td><td data-label="Datum"><?= h($recordDate) ?></td><td class="protocol-record-reg" data-label="Registreringsnummer"><?= h($recordRegistration) ?></td><td data-label="Kund / företag"><?= h($recordCustomer) ?></td><td data-label="Åtgärder"><div class="protocol-record-actions"><?php if ($safeRecordId !== ''): ?><a class="btn btn-outline" href="?tab=protocol&amp;view_id=<?= h(rawurlencode($safeRecordId)) ?>" aria-label="Visa protokoll <?= h($recordNumber) ?>"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Open' : 'Öppna' ?></a><a class="btn btn-main" href="?tab=protocol&amp;copy_id=<?= h(rawurlencode($safeRecordId)) ?>" aria-label="Kopiera protokoll <?= h($recordNumber) ?> som nytt"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Copy' : 'Kopiera' ?></a><?php endif; ?></div></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
        <?php else: ?>
            <div class="protocol-records-empty">
                <?php if ($protocolSearchError !== ''): ?>Korrigera sökningen och försök igen.
                <?php elseif ($savedProtocols): ?>Inga protokoll matchar sökningen. Prova hela eller en del av registreringsnumret, eller rensa filtren.
                <?php else: ?>Inga serviceprotokoll har sparats ännu.
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</section>
