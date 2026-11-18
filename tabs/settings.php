<?php
declare(strict_types=1);

$settings = load_settings();
$messageKey = isset($_GET['msg']) && is_string($_GET['msg']) ? $_GET['msg'] : '';
$messages = [
    'success' => ['success', 'Inställningarna har sparats.'],
    'logo_ok' => ['success', 'Logotypen har laddats upp och sanerats.'],
    'logo_error' => ['error', 'Logotypen kunde inte laddas upp. Kontrollera filformat och storlek.'],
    'backup_ok' => ['success', 'En säkerhetskopia har skapats i privat lagring.'],
    'backup_error' => ['error', 'Säkerhetskopian kunde inte skapas. Kontrollera ZIP-stöd och lagringsutrymme.'],
    'email_error' => ['error', 'Ange en giltig e-postadress.'],
    'save_error' => ['error', 'Inställningarna kunde inte sparas.'],
];
$notice = $messages[$messageKey] ?? null;

$notificationEmail = $settings['notification_email'] ?? '';
$notificationEmail = is_string($notificationEmail) ? $notificationEmail : '';

$svgDir = __DIR__ . '/../svg';
$svgFiles = [];
if (is_dir($svgDir) && !is_link($svgDir)) {
    foreach (glob($svgDir . '/*.svg') ?: [] as $path) {
        if (is_file($path) && !is_link($path)) {
            $svgFiles[] = $path;
        }
    }
    sort($svgFiles, SORT_NATURAL | SORT_FLAG_CASE);
}

$backupDir = __DIR__ . '/../storage/backups';
$backupFiles = [];
if (is_dir($backupDir) && !is_link($backupDir)) {
    foreach (glob($backupDir . '/tadega-backup-*.zip') ?: [] as $path) {
        $fileName = basename($path);
        if (
            !is_file($path) || is_link($path) ||
            !preg_match('/\Atadega-backup-\d{8}-\d{6}-[a-f0-9]{8}\.zip\z/', $fileName)
        ) {
            continue;
        }
        $backupFiles[] = [
            'name' => $fileName,
            'size' => (int) (filesize($path) ?: 0),
            'modified' => (int) (filemtime($path) ?: 0),
        ];
    }
}
usort($backupFiles, static function (array $left, array $right): int {
    return ($right['modified'] ?? 0) <=> ($left['modified'] ?? 0);
});

$phpSupported = PHP_VERSION_ID >= 80500;
$zipSupported = class_exists(ZipArchive::class);
$csrfAvailable = csrf_token() !== '';
?>
<style>
    .settings-page { --sp-ink:#142d36; --sp-muted:#61757b; --sp-line:#d9e5e3; --sp-teal:#0b7a72; --sp-dark:#103740; --sp-panel:#fff; --sp-shadow:0 14px 34px rgba(18,54,58,.065); max-width:1240px; margin:0 auto; color:var(--sp-ink); }
    .settings-page * { box-sizing:border-box; }
    .settings-hero { position:relative; display:flex; align-items:center; justify-content:space-between; gap:24px; overflow:hidden; margin:0 0 18px; padding:clamp(22px,3vw,34px); border:1px solid #1e4c5d; border-radius:22px; background:radial-gradient(circle at 92% 12%,rgba(68,190,201,.22),transparent 27%),linear-gradient(125deg,#10283a 0%,#153d50 57%,#176174 100%); color:#fff; box-shadow:0 14px 34px rgba(16,40,58,.16); }
    .settings-hero-main { display:flex; align-items:center; gap:16px; min-width:0; }
    .settings-hero-icon { flex:0 0 54px; width:54px; height:54px; padding:5px; border:1px solid rgba(255,255,255,.2); border-radius:16px; background:#fff; object-fit:contain; }
    .settings-eyebrow { margin:0 0 6px; color:#9fe1eb; font-size:.68rem; font-weight:850; letter-spacing:.15em; text-transform:uppercase; }
    .settings-hero p:not(.settings-eyebrow) { max-width:650px; margin:8px 0 0; color:#d3e4ec; font-size:.86rem; }
    .settings-runtime { display:flex; flex:0 0 auto; align-items:center; gap:10px; padding:10px 13px; border:1px solid rgba(255,255,255,.2); border-radius:13px; background:rgba(255,255,255,.08); backdrop-filter:blur(8px); }
    .settings-runtime-dot { width:9px; height:9px; border-radius:50%; background:#67dfab; box-shadow:0 0 0 4px rgba(103,223,171,.15); }
    .settings-runtime-dot.is-warning { background:#ffc66d; box-shadow:0 0 0 4px rgba(255,198,109,.15); }
    .settings-runtime strong { display:block; color:#fff; font-size:.8rem; }
    .settings-runtime small { display:block; margin-top:1px; color:#c4dce5; font-size:.67rem; }
    .settings-alert { display:flex; align-items:flex-start; gap:11px; margin:0 0 16px; padding:13px 15px; border:1px solid #abefc6; border-radius:13px; background:#effaf3; color:#105b36; font-size:.84rem; }
    .settings-alert.error { border-color:#fecdca; background:#fff5f4; color:#912018; }
    .settings-alert-mark { display:grid; flex:0 0 23px; width:23px; height:23px; place-items:center; border-radius:50%; background:rgba(255,255,255,.7); font-weight:900; }
    .settings-stats { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin:0 0 16px; }
    .settings-stat { display:flex; align-items:center; gap:12px; min-width:0; padding:15px 16px; border:1px solid var(--sp-line); border-radius:15px; background:var(--sp-panel); box-shadow:0 5px 17px rgba(16,57,73,.035); }
    .settings-stat-icon { display:grid; flex:0 0 39px; width:39px; height:39px; place-items:center; border-radius:12px; background:#eaf6f8; color:#0c6b82; font-size:1rem; font-weight:850; }
    .settings-stat-label { display:block; color:var(--sp-muted); font-size:.68rem; font-weight:750; letter-spacing:.04em; text-transform:uppercase; }
    .settings-stat strong { display:block; overflow:hidden; margin-top:3px; color:var(--sp-ink); font-size:.88rem; text-overflow:ellipsis; white-space:nowrap; }
    .settings-grid { display:grid; grid-template-columns:minmax(0,1.08fr) minmax(310px,.92fr); align-items:start; gap:16px; }
    .settings-column { display:grid; gap:16px; min-width:0; }
    .settings-card { min-width:0; padding:20px; border:1px solid var(--sp-line); border-radius:16px; background:#fff; box-shadow:var(--sp-shadow); }
    .settings-card-head { display:flex; align-items:flex-start; gap:12px; margin:0 0 16px; }
    .settings-card-icon { display:grid; flex:0 0 36px; width:36px; height:36px; place-items:center; border:1px solid #d9edf1; border-radius:11px; background:#f0fafb; color:#0d7087; font-size:.92rem; font-weight:850; }
    .settings-card-head h2 { margin:0; color:#173747; font-size:.98rem; letter-spacing:-.02em; }
    .settings-card-head p { margin:4px 0 0; color:var(--sp-muted); font-size:.77rem; line-height:1.45; }
    .settings-label { display:block; margin:0 0 6px; color:#344957; font-size:.76rem; font-weight:780; }
    .settings-label-note { color:#71818a; font-weight:500; }
    .settings-subheading { margin:0; color:#173747; font-size:.86rem; }
    .settings-empty-spaced { margin-top:11px; }
    .settings-empty-spaced-lg { margin-top:12px; }
    .settings-input { display:block; width:100%; min-height:44px; padding:10px 12px; border:1px solid #cbd8de; border-radius:10px; background:#fff; color:#172d3a; font:inherit; font-size:.88rem; transition:border-color .15s,box-shadow .15s; }
    .settings-input:focus { border-color:#38a0b7; outline:0; box-shadow:0 0 0 3px rgba(17,113,138,.12); }
    .settings-input[type=file] { padding:8px; }
    .settings-help { display:block; margin:7px 0 0; color:#71818a; font-size:.72rem; line-height:1.55; }
    .settings-help strong { color:#425867; }
    .settings-form-actions { display:flex; flex-wrap:wrap; align-items:center; gap:9px; margin-top:15px; }
    .settings-inline-note { display:flex; gap:10px; margin-top:14px; padding:11px 12px; border:1px solid #d8eaf0; border-radius:11px; background:#f5fafb; color:#526b78; font-size:.74rem; line-height:1.5; }
    .settings-inline-note strong { color:#173f50; }
    .settings-recipient { display:flex; align-items:center; justify-content:space-between; gap:10px; margin:12px 0 0; padding:11px 12px; border:1px solid #bde8d0; border-radius:11px; background:#f0fbf5; }
    .settings-recipient span { color:#32634a; font-size:.72rem; }
    .settings-recipient strong { color:#145b36; font-size:.78rem; white-space:nowrap; }
    .settings-divider { height:1px; margin:17px 0; background:#e8eef1; }
    .settings-logo-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(124px,1fr)); gap:9px; max-height:300px; overflow:auto; margin-top:12px; }
    .settings-logo { display:grid; align-content:center; justify-items:center; gap:8px; min-height:105px; padding:11px; border:1px solid #e3eaee; border-radius:12px; background:#fbfdfe; text-align:center; }
    .settings-logo img { display:block; width:100%; max-height:58px; object-fit:contain; }
    .settings-logo span { max-width:100%; color:#61737e; font-size:.66rem; overflow-wrap:anywhere; }
    .settings-empty { padding:14px; border:1px dashed #cad8de; border-radius:11px; color:var(--sp-muted); font-size:.77rem; text-align:center; }
    .settings-backup-list { display:grid; gap:8px; margin-top:13px; }
    .settings-backup-row { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 11px; border:1px solid #e4ebef; border-radius:11px; background:#fbfdfe; }
    .settings-backup-meta { min-width:0; }
    .settings-backup-meta strong { display:block; overflow:hidden; color:#344957; font-size:.73rem; text-overflow:ellipsis; white-space:nowrap; }
    .settings-backup-meta small { display:block; margin-top:3px; color:#7a8991; font-size:.66rem; }
    .settings-backup-row .btn { flex:0 0 auto; min-height:34px; padding:7px 10px; font-size:.7rem; }
    .settings-check-list { display:grid; gap:9px; margin:0; padding:0; list-style:none; }
    .settings-check { display:flex; align-items:flex-start; gap:10px; padding:10px 11px; border:1px solid #e5edf0; border-radius:11px; background:#fcfefe; }
    .settings-check-mark { display:grid; flex:0 0 22px; width:22px; height:22px; place-items:center; border-radius:7px; background:#e6f7ed; color:#157044; font-size:.72rem; font-weight:900; }
    .settings-check strong { display:block; color:#344957; font-size:.75rem; }
    .settings-check small { display:block; margin-top:2px; color:#75858e; font-size:.69rem; line-height:1.45; }
    .settings-privacy-footer { display:flex; align-items:center; gap:9px; margin-top:16px; padding:12px 14px; border:1px solid #e2eaf0; border-radius:12px; background:#f8fafc; color:#667784; font-size:.72rem; line-height:1.5; }
    .settings-privacy-footer span { color:#0e7186; font-size:1rem; }
    @media (max-width:850px) { .settings-grid { grid-template-columns:1fr; } }
    @media (max-width:650px) { .settings-hero { align-items:flex-start; flex-direction:column; } .settings-runtime { align-self:flex-start; } .settings-stats { grid-template-columns:1fr; } .settings-card { padding:17px 14px; } .settings-backup-row { align-items:flex-start; flex-direction:column; } .settings-backup-row .btn { width:100%; } .settings-recipient { align-items:flex-start; flex-direction:column; } }
    @media (prefers-reduced-motion:reduce) { .settings-input { transition:none; } }
</style>

<section class="settings-page" aria-label="Inställningar och systemstatus">
    <header class="settings-hero">
        <div class="settings-hero-main">
            <img class="settings-hero-icon" src="?view_file=assets/tadega-icon.svg&amp;v=<?= (string) (@filemtime(__DIR__ . '/../assets/tadega-icon.svg') ?: '1') ?>" alt="" width="54" height="54">
            <div>
                <p class="settings-eyebrow">Portalstatus</p>
                <p>Hantera e-post, logotyper och säkerhetskopior från en samlad vy.</p>
            </div>
        </div>
        <div class="settings-runtime" aria-label="PHP-version <?= h(PHP_VERSION) ?>">
            <span class="settings-runtime-dot <?= $phpSupported ? '' : 'is-warning' ?>" aria-hidden="true"></span>
            <div><strong>PHP <?= h(PHP_VERSION) ?></strong><small><?= $phpSupported ? 'Målversion 8.5 uppfylld' : 'Uppgradera till PHP 8.5' ?></small></div>
        </div>
    </header>

    <?php if (is_array($notice)): ?>
        <div class="settings-alert <?= $notice[0] === 'error' ? 'error' : '' ?>" role="<?= $notice[0] === 'error' ? 'alert' : 'status' ?>" aria-live="polite">
            <span class="settings-alert-mark" aria-hidden="true"><?= $notice[0] === 'error' ? '!' : '✓' ?></span>
            <span><?= h($notice[1]) ?></span>
        </div>
    <?php endif; ?>

    <div class="settings-stats" aria-label="Inställningsöversikt">
        <div class="settings-stat"><span class="settings-stat-icon" aria-hidden="true">✉</span><div><span class="settings-stat-label">Primär avisering</span><strong><?= h($notificationEmail !== '' ? $notificationEmail : 'Inte angiven') ?></strong></div></div>
        <div class="settings-stat"><span class="settings-stat-icon" aria-hidden="true">◇</span><div><span class="settings-stat-label">Sanerade SVG-logotyper</span><strong><?= count($svgFiles) ?> tillgängliga</strong></div></div>
        <div class="settings-stat"><span class="settings-stat-icon" aria-hidden="true">▣</span><div><span class="settings-stat-label">Privata säkerhetskopior</span><strong><?= count($backupFiles) ?> sparade</strong></div></div>
    </div>

    <div class="settings-grid">
        <div class="settings-column">
            <section class="settings-card" aria-labelledby="settings-email-title">
                <div class="settings-card-head"><span class="settings-card-icon" aria-hidden="true">✉</span><div><h2 id="settings-email-title">E-postaviseringar</h2><p>Välj primär mottagare för portalens allmänna aviseringar.</p></div></div>
                <form method="post" action="?tab=settings" autocomplete="on">
                    <input type="hidden" name="action" value="save_settings">
                    <?= csrf_field() ?>
                    <label class="settings-label" for="notification-email">Primär mottagaradress</label>
                    <input class="settings-input" id="notification-email" type="email" name="notification_email" maxlength="254" autocomplete="email" required value="<?= h($notificationEmail) ?>" aria-describedby="notification-email-help">
                    <small class="settings-help" id="notification-email-help">Använd en fungerande adress som ni har åtkomst till. Webbhotellets e-posttransport måste vara aktiverad.</small>
                    <div class="settings-recipient"><span>Skadeblanketter skickas separat och alltid endast till</span><strong>rl@tadega.com</strong></div>
                    <p class="settings-help"><strong>SMTP och avsändaradress</strong> ändras av admin under Fakturaunderlag → E-poststatus. Det är en annan inställning än mottagaradressen ovan. Om E-poststatus inte visas behöver du logga in som admin; en låst SMTP-konfiguration kräver admin-PIN för upplåsning. <a href="?tab=invoice&amp;invoice_view=email">Öppna SMTP-inställningarna</a>.</p>
                    <div class="settings-form-actions"><button class="btn btn-main" type="submit">Spara e-postinställning</button></div>
                </form>
            </section>

            <section class="settings-card" aria-labelledby="settings-logo-title">
                <div class="settings-card-head"><span class="settings-card-icon" aria-hidden="true">◇</span><div><h2 id="settings-logo-title">Varumärke och logotyper</h2><p>Ladda upp en SVG-logotyp. Filen saneras före lagring och kan därefter förhandsvisas här.</p></div></div>
                <form method="post" action="?tab=settings" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_logo">
                    <?= csrf_field() ?>
                    <label class="settings-label" for="logo-file">Välj SVG-fil <span class="settings-label-note">(max 512 KB)</span></label>
                    <input class="settings-input" id="logo-file" type="file" name="logo_file" accept=".svg,image/svg+xml" required aria-describedby="logo-file-help">
                    <small class="settings-help" id="logo-file-help">Script, externa länkar och aktiva SVG-element tas bort. Den valda portalikonen används fortsatt konsekvent i gränssnittet.</small>
                    <div class="settings-form-actions"><button class="btn btn-main" type="submit">Ladda upp och granska</button></div>
                </form>
                <div class="settings-divider"></div>
                <h3 class="settings-subheading">Tillgängliga uppladdningar</h3>
                <?php if ($svgFiles !== []): ?>
                    <div class="settings-logo-grid">
                        <?php foreach ($svgFiles as $svgPath): $fileName = basename($svgPath); ?>
                            <div class="settings-logo">
                                <img src="?view_file=<?= rawurlencode('svg/' . $fileName) ?>" alt="Förhandsvisning av <?= h($fileName) ?>" loading="lazy">
                                <span><?= h($fileName) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="settings-empty settings-empty-spaced">Inga uppladdade SVG-logotyper ännu.</div>
                <?php endif; ?>
            </section>
        </div>

        <div class="settings-column">
            <section class="settings-card" aria-labelledby="settings-backup-title">
                <div class="settings-card-head"><span class="settings-card-icon" aria-hidden="true">▣</span><div><h2 id="settings-backup-title">Säkerhetskopior</h2><p>Skapa en skyddad ZIP-kopia av applikationens lagrade data och dokument.</p></div></div>
                <form method="post" action="?tab=settings">
                    <input type="hidden" name="action" value="trigger_backup">
                    <?= csrf_field() ?>
                    <button class="btn btn-main" type="submit">Skapa säkerhetskopia nu</button>
                </form>
                <small class="settings-help">Kopior lagras privat på servern och kan hämtas via portalen. Ettårsgallringen gäller endast skadeblanketternas sparade formuläruppgifter och signaturbild, även i serverlagrade ZIP-kopior. Den påverkar inte kundregistret, fakturaunderlag, serviceprotokoll eller några PDF-filer. Nedladdade ZIP-kopior och mejl/bilagor som redan skickats utanför portalen kan inte raderas automatiskt. Kontrollera webbhotellets separata backupfunktion inför driftsättning.</small>
                <?php if ($backupFiles !== []): ?>
                    <div class="settings-backup-list" aria-label="Senaste säkerhetskopior">
                        <?php foreach (array_slice($backupFiles, 0, 5) as $backup): ?>
                            <div class="settings-backup-row">
                                <div class="settings-backup-meta"><strong><?= h($backup['name']) ?></strong><small><?= h(date('Y-m-d H:i', $backup['modified'])) ?> · <?= h(number_format($backup['size'] / 1024, 1, ',', ' ')) ?> KB</small></div>
                                <a class="btn btn-outline" href="?download_backup=<?= rawurlencode($backup['name']) ?>">Ladda ned</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="settings-empty settings-empty-spaced-lg">Ingen privat säkerhetskopia har skapats ännu.</div>
                <?php endif; ?>
            </section>

            <section class="settings-card" aria-labelledby="settings-health-title">
                <div class="settings-card-head"><span class="settings-card-icon" aria-hidden="true">✓</span><div><h2 id="settings-health-title">Drift och integritet</h2><p>Tekniska förutsättningar för den här arbetskopian.</p></div></div>
                <ul class="settings-check-list">
                    <li class="settings-check"><span class="settings-check-mark" aria-hidden="true"><?= $csrfAvailable ? '✓' : '!' ?></span><div><strong><?= $csrfAvailable ? 'Säker formulärsession' : 'Session saknas' ?></strong><small>POST-formulär skyddas med CSRF-token och säkra sessionscookies.</small></div></li>
                    <li class="settings-check"><span class="settings-check-mark" aria-hidden="true"><?= $zipSupported ? '✓' : '!' ?></span><div><strong><?= $zipSupported ? 'ZIP-stöd tillgängligt' : 'ZIP-stöd saknas' ?></strong><small><?= $zipSupported ? 'Säkerhetskopior kan skapas av portalen.' : 'Aktivera PHP-tillägget ZipArchive på webbhotellet.' ?></small></div></li>
                    <li class="settings-check"><span class="settings-check-mark" aria-hidden="true">✓</span><div><strong>Loggning utan kundfält</strong><small>Auditloggen lagrar fasta händelsenamn, UTC-tid och slumpmässigt request-ID — inte kunduppgifter eller IP-adresser.</small></div></li>
                    <li class="settings-check"><span class="settings-check-mark" aria-hidden="true">i</span><div><strong>Åtkomstskydd hanteras uppströms</strong><small>Webbhotellets/proxyns skydd ska täcka hela /dokument, även privata filrutter och direkta URL:er. Detta måste verifieras i staging.</small></div></li>
                </ul>
            </section>
        </div>
    </div>

    <div class="settings-privacy-footer"><span aria-hidden="true">ⓘ</span><div>Adminpanelen använder en separat central admin-PIN för personalsidorna. Den ersätter inte Websupport/proxyns grundskydd; behåll det och testa att alla privata resurser nekas vid direkt åtkomst innan publicering.</div></div>
</section>
