<?php
declare(strict_types=1);

$searchInvoiceUser = is_array($invoiceAuthUser ?? null) ? $invoiceAuthUser : null;
$searchProtocolUser = is_array($protocolAuthUser ?? null) ? $protocolAuthUser : null;
$searchRawQuery = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && ($_POST['action'] ?? '') === 'portal_search'
    && !($portalCsrfFailed ?? false)
    && ($portalPostError ?? '') === ''
    && isset($_POST['q']) && is_string($_POST['q'])
    ? $_POST['q']
    : '';
$searchQuery = portal_global_search_clean_text($searchRawQuery, 120);
$searchTerms = portal_global_search_terms($searchQuery);
$searchHasQuery = $searchQuery !== '';
$searchCanRun = $searchTerms !== [];
$searchCustomerResults = [];
$searchInvoiceResults = [];
$searchProtocolResults = [];

if ($searchInvoiceUser === null && ($invoiceSetupState ?? '') === 'configured') {
    // After a successful PIN login, return to the search page the user requested.
    $_SESSION['portal_invoice_login_return_tab'] = 'search';
}

if ($searchCanRun) {
    if ($searchInvoiceUser !== null) {
        $customerRecords = load_data(CUSTOMERS_FILE, []);
        $invoiceRecords = load_data(INVOICES_FILE, []);
        $searchCustomerResults = portal_global_search_records(
            'customers',
            is_array($customerRecords) ? array_values(array_filter($customerRecords, 'is_array')) : [],
            $searchQuery
        );
        $searchInvoiceResults = portal_global_search_records(
            'invoice',
            is_array($invoiceRecords) ? array_values(array_filter($invoiceRecords, 'is_array')) : [],
            $searchQuery
        );
    }
    if ($searchProtocolUser !== null) {
        $protocolRecords = load_data(PROTOCOLS_FILE, []);
        $searchProtocolResults = portal_global_search_records(
            'protocol',
            is_array($protocolRecords) ? array_values(array_filter($protocolRecords, 'is_array')) : [],
            $searchQuery
        );
    }
}
?>
<style>
.portal-global-search{--pgs-ink:#18313a;--pgs-muted:#64777d;--pgs-line:#dce6e7;--pgs-accent:#08776f;max-width:1120px;margin:0 auto;color:var(--pgs-ink)}
.portal-global-search-intro{max-width:820px;margin:0 0 16px;color:var(--pgs-muted);font-size:.9rem;line-height:1.6}
.portal-global-search-no-auth{padding:18px;border:1px solid var(--pgs-line);border-radius:15px;background:#fff;box-shadow:0 5px 20px rgba(16,48,55,.04)}
.portal-global-search-no-auth h2{margin:0;color:var(--pgs-ink);font-size:1rem}
.portal-global-search-no-auth p{max-width:760px;margin:7px 0 14px;color:var(--pgs-muted);font-size:.84rem;line-height:1.55}
.portal-global-search-no-auth>div{display:flex;flex-wrap:wrap;gap:8px}
.portal-global-search-no-auth .btn{min-height:42px}
.portal-global-search-form{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:end;padding:16px;border:1px solid var(--pgs-line);border-radius:16px;background:linear-gradient(135deg,#fff,#f5faf9);box-shadow:0 8px 26px rgba(16,48,55,.055)}
.portal-global-search-form label{display:block;margin:0 0 6px;color:#52666c;font-size:.72rem;font-weight:800;letter-spacing:.045em;text-transform:uppercase}
.portal-global-search-form input{display:block;width:100%;min-height:48px;padding:10px 13px;border:1px solid #cbd9db;border-radius:10px;background:#fff;color:var(--pgs-ink);font:inherit;font-size:1rem;box-shadow:inset 0 1px 2px rgba(16,48,55,.025)}
.portal-global-search-form input:focus{outline:3px solid rgba(8,119,111,.18);border-color:var(--pgs-accent)}
.portal-global-search-form .btn{min-height:48px;padding-inline:20px}
.portal-global-search-help{min-height:1.4em;margin:9px 2px 17px;color:var(--pgs-muted);font-size:.78rem}
.portal-global-search-areas{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;align-items:start}
.portal-global-search-area{min-width:0;padding:16px;border:1px solid var(--pgs-line);border-radius:15px;background:#fff;box-shadow:0 5px 20px rgba(16,48,55,.04)}
.portal-global-search-area.protocol{grid-column:1/-1}
.portal-global-search-area-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin:0 0 12px}
.portal-global-search-area-head h2{margin:0;color:var(--pgs-ink);font-size:1rem;line-height:1.3}
.portal-global-search-area-head p{margin:4px 0 0;color:var(--pgs-muted);font-size:.75rem;line-height:1.45}
.portal-global-search-count{flex:0 0 auto;padding:5px 8px;border:1px solid #d7e8e2;border-radius:999px;background:#f1f8f5;color:#236458;font-size:.7rem;font-weight:800;white-space:nowrap}
.portal-global-search-login,.portal-global-search-empty{padding:13px;border:1px dashed #d5e0e2;border-radius:11px;background:#fafcfc;color:#586d73;font-size:.82rem;line-height:1.55}
.portal-global-search-login p,.portal-global-search-empty p{margin:0 0 10px}.portal-global-search-login p:last-child,.portal-global-search-empty p:last-child{margin-bottom:0}
.portal-global-search-login .btn{min-height:40px}
.portal-global-search-list{display:grid;gap:8px;margin:0;padding:0;list-style:none}
.portal-global-search-hit{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center;padding:11px 12px;border:1px solid #e5ecee;border-radius:11px;background:#fff}
.portal-global-search-hit h3{margin:0;color:#17353d;font-size:.87rem;font-weight:800;line-height:1.35;overflow-wrap:anywhere}
.portal-global-search-hit p{margin:3px 0 0;color:var(--pgs-muted);font-size:.75rem;line-height:1.45;overflow-wrap:anywhere}
.portal-global-search-hit .btn{min-height:39px;padding:7px 11px;font-size:.77rem;white-space:nowrap}
.portal-global-search-subsection+.portal-global-search-subsection{margin-top:16px;padding-top:14px;border-top:1px solid #edf1f2}
.portal-global-search-subsection-head{display:flex;justify-content:space-between;align-items:center;gap:8px;margin:0 0 8px}
.portal-global-search-subsection-head h3{margin:0;color:#3b555c;font-size:.78rem;font-weight:850;letter-spacing:.035em}
.portal-global-search-subsection-head span{color:var(--pgs-muted);font-size:.68rem}
.portal-global-search-safe-note{margin:16px 2px 0;color:#718287;font-size:.72rem;line-height:1.5}
.portal-global-search-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
@media(max-width:720px){.portal-global-search-form{grid-template-columns:1fr;padding:13px}.portal-global-search-form .btn{width:100%}.portal-global-search-areas{grid-template-columns:1fr}.portal-global-search-area.protocol{grid-column:auto}.portal-global-search-area{padding:13px}.portal-global-search-hit{grid-template-columns:minmax(0,1fr) auto;gap:7px;padding:10px}.portal-global-search-hit .btn{padding-inline:9px}}
@media(max-width:380px){.portal-global-search-hit{grid-template-columns:1fr}.portal-global-search-hit .btn{width:100%}}
@media print{.portal-global-search{display:none!important}}
</style>
<section class="portal-global-search" aria-labelledby="portal-global-search-title">
    <h2 class="portal-global-search-sr-only" id="portal-global-search-title">Sök i Dokumentportalen</h2>
    <p class="portal-global-search-intro">Sökningen visar bara uppgifter från områden där du redan är inloggad. Kund- och fakturaunderlag kräver fakturakontot; serviceprotokoll kräver det separata protokollkontot.</p>

    <?php if ($searchInvoiceUser === null && $searchProtocolUser === null): ?>
        <div class="portal-global-search-no-auth">
            <h2>Logga in för att söka</h2>
            <p>Sökfunktionen blir tillgänglig när du har loggat in på minst ett behörigt personalområde. Kund- och fakturaunderlag samt serviceprotokoll har separata inloggningar.</p>
            <div><a class="btn btn-outline" href="?tab=invoice">Logga in på Fakturaunderlag</a><a class="btn btn-outline" href="?tab=protocol">Logga in på Serviceprotokoll</a></div>
        </div>
    <?php else: ?>
    <form class="portal-global-search-form" method="post" action="?tab=search" role="search" aria-label="Sök i Dokumentportalen">
        <input type="hidden" name="action" value="portal_search">
        <?= csrf_field() ?>
        <div>
            <label for="portal-global-search-query">Sök kunder, underlag eller serviceprotokoll</label>
            <input id="portal-global-search-query" name="q" type="search" value="<?= h($searchQuery) ?>" maxlength="120" minlength="2" placeholder="Namn, telefon, Fortnox-nr, registreringsnummer, artikeltext…" autocomplete="off" spellcheck="false" enterkeyhint="search">
        </div>
        <button class="btn btn-main" type="submit">Sök</button>
    </form>
    <p class="portal-global-search-help" role="status" aria-live="polite">
        <?php if (!$searchHasQuery): ?>Skriv minst två tecken för att söka. Alla ord i sökningen måste finnas i samma post.<?php elseif (!$searchCanRun): ?>Ange minst två tecken för att söka.<?php else: ?>Visar högst 10 träffar i varje lista. Förfina sökningen om du inte hittar rätt post.<?php endif; ?>
    </p>

    <div class="portal-global-search-areas">
        <section class="portal-global-search-area" aria-labelledby="portal-global-search-invoice-heading">
            <header class="portal-global-search-area-head">
                <div><h2 id="portal-global-search-invoice-heading">Kunder och fakturaunderlag</h2><p>Träffar i kundregistret och sparade fakturaunderlag.</p></div>
                <?php if ($searchInvoiceUser !== null && $searchCanRun): ?><span class="portal-global-search-count" aria-label="Kundträffar och underlagsträffar"><?= count($searchCustomerResults) + count($searchInvoiceResults) ?> visade</span><?php endif; ?>
            </header>
            <?php if ($searchInvoiceUser === null): ?>
                <div class="portal-global-search-login"><p>Logga in med ditt fakturakonto för att söka i kundregistret och fakturaunderlagen.</p><a class="btn btn-outline" href="?tab=invoice">Öppna fakturainloggning</a></div>
            <?php elseif (!$searchCanRun): ?>
                <div class="portal-global-search-empty"><p>Ange en sökfras med minst två tecken för att visa kund- och underlagsträffar.</p></div>
            <?php else: ?>
                <section class="portal-global-search-subsection" aria-labelledby="portal-global-search-customers-heading">
                    <header class="portal-global-search-subsection-head"><h3 id="portal-global-search-customers-heading">Kunder</h3><span><?= count($searchCustomerResults) ?> visade</span></header>
                    <?php if ($searchCustomerResults): ?>
                        <ul class="portal-global-search-list">
                            <?php foreach ($searchCustomerResults as $result): ?>
                                <li><article class="portal-global-search-hit"><div><h3><?= h($result['title']) ?></h3><p><?= h($result['subtitle']) ?></p></div><a class="btn btn-outline" href="?tab=customers&amp;edit_id=<?= h(rawurlencode($result['id'])) ?>" aria-label="Öppna kund <?= h($result['title']) ?>">Öppna</a></article></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="portal-global-search-empty"><p>Inga kundträffar.</p></div>
                    <?php endif; ?>
                </section>
                <section class="portal-global-search-subsection" aria-labelledby="portal-global-search-invoices-heading">
                    <header class="portal-global-search-subsection-head"><h3 id="portal-global-search-invoices-heading">Fakturaunderlag</h3><span><?= count($searchInvoiceResults) ?> visade</span></header>
                    <?php if ($searchInvoiceResults): ?>
                        <ul class="portal-global-search-list">
                            <?php foreach ($searchInvoiceResults as $result): ?>
                                <li><article class="portal-global-search-hit"><div><h3><?= h($result['title']) ?></h3><p><?= h($result['subtitle']) ?></p></div><a class="btn btn-outline" href="?tab=invoice&amp;edit_id=<?= h(rawurlencode($result['id'])) ?>" aria-label="Öppna <?= h($result['title']) ?>">Öppna</a></article></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="portal-global-search-empty"><p>Inga underlagsträffar.</p></div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </section>

        <section class="portal-global-search-area protocol" aria-labelledby="portal-global-search-protocol-heading">
            <header class="portal-global-search-area-head">
                <div><h2 id="portal-global-search-protocol-heading">Serviceprotokoll</h2><p>Träffar i sparade protokoll, inklusive registreringsnummer, kund, arbetstext och rader.</p></div>
                <?php if ($searchProtocolUser !== null && $searchCanRun): ?><span class="portal-global-search-count"><?= count($searchProtocolResults) ?> visade</span><?php endif; ?>
            </header>
            <?php if ($searchProtocolUser === null): ?>
                <div class="portal-global-search-login"><p>Logga in med ditt separata protokollkonto för att söka bland serviceprotokoll.</p><a class="btn btn-outline" href="?tab=protocol">Öppna protokollinloggning</a></div>
            <?php elseif (!$searchCanRun): ?>
                <div class="portal-global-search-empty"><p>Ange en sökfras med minst två tecken för att visa protokollträffar.</p></div>
            <?php elseif ($searchProtocolResults): ?>
                <ul class="portal-global-search-list">
                    <?php foreach ($searchProtocolResults as $result): ?>
                        <li><article class="portal-global-search-hit"><div><h3><?= h($result['title']) ?></h3><p><?= h($result['subtitle']) ?></p></div><a class="btn btn-outline" href="?tab=protocol&amp;view_id=<?= h(rawurlencode($result['id'])) ?>" aria-label="Öppna <?= h($result['title']) ?>">Öppna</a></article></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="portal-global-search-empty"><p>Inga protokollträffar.</p></div>
            <?php endif; ?>
        </section>
    </div>

    <p class="portal-global-search-safe-note">Sökningen är endast för läsning. Den matchar inte kunders personnummer och visar inte signaturbilder, fotofiler, prisrader eller summeringar.</p>
    <?php endif; ?>
</section>
