<?php
declare(strict_types=1);
$skadeList = array_values(array_filter(load_skadeblanketter(), 'is_array'));
$protocols = array_values(array_filter(load_protocols(), 'is_array'));
$invoices = array_values(array_filter(load_invoices(), 'is_array'));
$files = get_archive_files();
usort($skadeList, static function (array $a, array $b): int { return strcmp((string) ($b['updated_at'] ?? $b['created_at'] ?? ''), (string) ($a['updated_at'] ?? $a['created_at'] ?? '')); });
usort($protocols, static function (array $a, array $b): int { return strcmp((string) ($b['updated_at'] ?? $b['date'] ?? ''), (string) ($a['updated_at'] ?? $a['date'] ?? '')); });
usort($invoices, static function (array $a, array $b): int { return strcmp((string) ($b['updated_at'] ?? $b['date'] ?? ''), (string) ($a['updated_at'] ?? $a['date'] ?? '')); });
$archiveAuditEvents = static function (array $record): array {
    return is_array($record['history'] ?? null)
        ? array_values(array_filter($record['history'], 'is_array'))
        : [];
};
?>
<style>
.arc-wrap{--a-ink:#142d36;--a-muted:#61757b;--a-line:#d9e5e3;max-width:1180px;margin:0 auto;color:var(--a-ink)}.arc-head{display:flex;justify-content:flex-end;gap:16px;align-items:center;margin-bottom:12px}.arc-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:18px}.arc-stat{padding:15px;border:1px solid var(--a-line);border-radius:13px;background:#fff;box-shadow:0 4px 18px rgba(16,40,58,.03)}.arc-stat strong{display:block;font-size:1.35rem;letter-spacing:-.05em}.arc-stat span{color:var(--a-muted);font-size:.75rem}.arc-section{margin-top:18px;padding:16px;border:1px solid var(--a-line);border-radius:14px;background:#fff}.arc-section-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:11px}.arc-section-head h2{margin:0;font-size:.98rem}.arc-section-head span{color:var(--a-muted);font-size:.73rem}.arc-search{width:min(100%,360px);min-height:42px;padding:9px 12px;border:1px solid var(--a-line);border-radius:9px;font:inherit}.arc-table-wrap{overflow-x:auto;border:1px solid var(--a-line);border-radius:10px}.arc-table{width:100%;min-width:620px;border-collapse:collapse}.arc-table th{padding:10px 12px;background:#f8fafc;color:#475467;text-align:left;font-size:.68rem;letter-spacing:.05em;text-transform:uppercase}.arc-table td{padding:10px 12px;border-top:1px solid #edf0f4;font-size:.8rem;vertical-align:middle}.arc-table tr:hover td{background:#fbfcfe}.arc-empty{padding:18px;border:1px dashed #d0d5dd;border-radius:10px;color:var(--a-muted);font-size:.82rem;text-align:center}.arc-actions{display:flex;gap:6px;white-space:nowrap}.arc-name{font-weight:750;overflow-wrap:anywhere}.arc-history{max-width:320px;font-size:.74rem}.arc-history summary{cursor:pointer;color:#315462;font-weight:750}.arc-history ol{margin:8px 0 0;padding-left:20px}.arc-history li{margin:6px 0;overflow-wrap:anywhere}.arc-history time{color:var(--a-muted);font-size:.68rem;white-space:nowrap}
@media(max-width:760px){.arc-stats{grid-template-columns:1fr 1fr}.arc-section-head{align-items:stretch;flex-direction:column}.arc-search{width:100%}}
@media(max-width:760px){.page-content .arc-table-wrap{overflow:visible;border:0;background:transparent;box-shadow:none}.arc-table{display:block;min-width:0}.arc-table thead{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}.arc-table tbody{display:grid;gap:11px}.arc-table tbody tr{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px 11px;padding:13px;border:1px solid #dce7e4;border-radius:15px;background:#fff;box-shadow:0 7px 20px rgba(18,54,58,.055)}.arc-table td{display:block;min-width:0;padding:0;border:0;overflow-wrap:anywhere}.arc-table td::before{display:block;margin:0 0 5px;color:#6a7c7d;content:attr(data-label);font-size:.62rem;font-weight:850;letter-spacing:.07em;text-transform:uppercase}.arc-table td[data-label="Protokollnr"],.arc-table td[data-label="Underlagsnr"],.arc-table td[data-label="Registreringsnummer"],.arc-table td[data-label="Fil"],.arc-table td[data-label="Historik"],.arc-table td[data-label="Åtgärder"]{grid-column:1/-1}.arc-table .arc-name{font-size:.9rem;line-height:1.35}.arc-table td[data-label="Åtgärder"] .btn{width:100%}.arc-table .arc-actions{display:flex;flex-wrap:wrap;gap:8px;white-space:normal}.arc-table .arc-actions .btn{flex:1 1 130px;min-height:46px}.arc-history{max-width:none;width:100%}.arc-row[hidden]{display:none!important}}
</style>
<section class="arc-wrap" aria-label="Digitalt arkiv och historik">
    <header class="arc-head"><a class="btn btn-main" href="?tab=protocol">Skapa dokument</a></header>
    <div class="arc-stats"><div class="arc-stat"><strong><?= count($skadeList) ?></strong><span>Skadeblanketter</span></div><div class="arc-stat"><strong><?= count($protocols) ?></strong><span>Serviceprotokoll</span></div><div class="arc-stat"><strong><?= count($invoices) ?></strong><span>Fakturaunderlag</span></div><div class="arc-stat"><strong><?= count($files) ?></strong><span>Filer i PDF-arkivet</span></div></div>
    <div style="margin:0 0 15px"><input class="arc-search" id="archive-search" type="search" placeholder="Sök i historiken…" aria-label="Sök i historiken"></div>

    <section class="arc-section"><div class="arc-section-head"><h2>Serviceprotokoll</h2><span><?= count($protocols) ?> sparade</span></div>
        <?php if ($protocols): ?><div class="arc-table-wrap"><table class="arc-table"><thead><tr><th>Nr</th><th>Datum</th><th>Reg.nr</th><th>Kund</th><th>Ändringshistorik</th><th></th></tr></thead><tbody>
            <?php foreach (array_slice($protocols, 0, 100) as $row): $search = implode(' ', [(string) ($row['nummer'] ?? ''), (string) ($row['date'] ?? ''), (string) ($row['reg'] ?? $row['regnr'] ?? ''), (string) ($row['customer'] ?? '')]); ?>
                <tr class="arc-row" data-search="<?= h($search) ?>"><td class="arc-name" data-label="Protokollnr"><?= h($row['nummer'] ?? '—') ?></td><td data-label="Datum"><?= h($row['date'] ?? '') ?></td><td data-label="Registreringsnummer"><?= h($row['reg'] ?? $row['regnr'] ?? '') ?></td><td data-label="Kund"><?= h($row['customer'] ?? '') ?></td><td data-label="Historik"><?php $auditEvents = $archiveAuditEvents($row); ?><?php if ($auditEvents): ?><details class="arc-history"><summary><?= count($auditEvents) ?> händelse<?= count($auditEvents) === 1 ? '' : 'r' ?></summary><ol><?php foreach (array_reverse($auditEvents) as $auditEvent): $auditActor = is_array($auditEvent['actor'] ?? null) ? $auditEvent['actor'] : []; $auditAction = ($auditEvent['action'] ?? '') === 'created' ? 'Skapad' : 'Ändrad'; $auditName = (string) ($auditActor['name'] ?? $auditActor['username'] ?? 'Okänt konto'); $auditAt = (string) ($auditEvent['at'] ?? ''); ?><li><strong><?= h($auditAction) ?></strong> av <?= h($auditName) ?><br><time datetime="<?= h($auditAt) ?>"><?= h($auditAt) ?></time></li><?php endforeach; ?></ol></details><?php else: ?><span>Ingen historik registrerad</span><?php endif; ?></td><td data-label="Åtgärder"><div class="arc-actions"><a class="btn btn-outline" href="?tab=protocol&amp;edit_id=<?= rawurlencode((string) ($row['id'] ?? '')) ?>">Öppna</a></div></td></tr>
            <?php endforeach; ?>
        </tbody></table></div><?php else: ?><div class="arc-empty">Inga serviceprotokoll sparade ännu.</div><?php endif; ?>
    </section>

    <section class="arc-section"><div class="arc-section-head"><h2>Fakturaunderlag</h2><span><?= count($invoices) ?> sparade</span></div>
        <?php if ($invoices): ?><div class="arc-table-wrap"><table class="arc-table"><thead><tr><th>Nr</th><th>Underlagsdatum</th><th>Fakturadatum</th><th>Kund</th><th>Sparad summa</th><th>Ändringshistorik</th><th></th></tr></thead><tbody>
            <?php foreach (array_slice($invoices, 0, 100) as $row): $search = implode(' ', [(string) ($row['nummer'] ?? ''), (string) ($row['date'] ?? ''), (string) ($row['fortnox_invoice_number'] ?? ''), (string) ($row['fortnox_invoice_date'] ?? ''), (string) ($row['customer'] ?? ''), (string) ($row['ref'] ?? '')]); ?>
                <tr class="arc-row" data-search="<?= h($search) ?>"><td class="arc-name" data-label="Underlagsnr"><?= h($row['nummer'] ?? '—') ?></td><td data-label="Underlagsdatum"><?= h($row['date'] ?? '') ?></td><td data-label="Fakturadatum"><?= h(is_scalar($row['fortnox_invoice_date'] ?? null) && trim((string) $row['fortnox_invoice_date']) !== '' ? (string) $row['fortnox_invoice_date'] : '—') ?></td><td data-label="Kund"><?= h($row['customer'] ?? '') ?></td><td data-label="Sparad summa"><?= h(number_format((float) ($row['total_sum'] ?? 0), 2, ',', ' ')) ?> kr</td><td data-label="Historik"><?php $auditEvents = $archiveAuditEvents($row); ?><?php if ($auditEvents): ?><details class="arc-history"><summary><?= count($auditEvents) ?> händelse<?= count($auditEvents) === 1 ? '' : 'r' ?></summary><ol><?php foreach (array_reverse($auditEvents) as $auditEvent): $auditActor = is_array($auditEvent['actor'] ?? null) ? $auditEvent['actor'] : []; $auditAction = ($auditEvent['action'] ?? '') === 'created' ? 'Skapad' : 'Ändrad'; $auditName = (string) ($auditActor['name'] ?? $auditActor['username'] ?? 'Okänt konto'); $auditAt = (string) ($auditEvent['at'] ?? ''); ?><li><strong><?= h($auditAction) ?></strong> av <?= h($auditName) ?><br><time datetime="<?= h($auditAt) ?>"><?= h($auditAt) ?></time></li><?php endforeach; ?></ol></details><?php else: ?><span>Ingen historik registrerad</span><?php endif; ?></td><td data-label="Åtgärder"><div class="arc-actions"><a class="btn btn-outline" href="?tab=invoice&amp;edit_id=<?= rawurlencode((string) ($row['id'] ?? '')) ?>" aria-label="Redigera fakturaunderlag <?= h($row['nummer'] ?? '') ?>">Redigera</a><a class="btn btn-outline" href="?tab=invoice&amp;copy_id=<?= rawurlencode((string) ($row['id'] ?? '')) ?>" aria-label="Kopiera fakturaunderlag <?= h($row['nummer'] ?? '') ?>">Kopiera</a></div></td></tr>
            <?php endforeach; ?>
        </tbody></table></div><?php else: ?><div class="arc-empty">Inga fakturaunderlag sparade ännu.</div><?php endif; ?>
    </section>

    <section class="arc-section"><div class="arc-section-head"><h2>Skadeblanketter</h2><span><?= count($skadeList) ?> sparade</span></div>
        <?php if ($skadeList): ?><div class="arc-table-wrap"><table class="arc-table"><thead><tr><th>Reg.nr</th><th>Senast ändrad</th><th>PDF och äldre versioner</th><th>Åtgärder</th></tr></thead><tbody>
            <?php foreach (array_slice($skadeList, 0, 100) as $row):
                $rel = (string) ($row['file'] ?? '');
                $safeRel = preg_match('#\Apdf/[A-Z0-9-]{2,12}/Skadeblankett-Bilglas-[A-Z0-9-]{2,12}-\d{8}-\d{6}-[a-f0-9]{6}\.pdf\z#i', $rel) && function_exists('portal_skade_record_file_path') && portal_skade_record_file_path($row) !== null ? $rel : '';
                $recordId = isset($row['id']) && is_string($row['id']) && preg_match('/\A[a-f0-9]{32}\z/', $row['id']) ? $row['id'] : '';
                $isEditable = $safeRel !== '' && $recordId !== '' && is_array($row['form_data'] ?? null);
                $versions = is_array($row['versions'] ?? null) ? array_values(array_filter($row['versions'], 'is_array')) : [];
                $lastChanged = (string) ($row['updated_at'] ?? $row['created_at'] ?? '');
                $privateDataPurgedAt = isset($row['private_data_purged_at']) && is_string($row['private_data_purged_at']) ? $row['private_data_purged_at'] : '';
                $search = (string) ($row['regnr'] ?? '') . ' ' . (string) ($row['created_at'] ?? '') . ' ' . $lastChanged . ' ' . $rel . ' ' . $privateDataPurgedAt;
            ?>
                <tr class="arc-row" data-search="<?= h($search) ?>">
                    <td class="arc-name" data-label="Registreringsnummer"><?= h($row['regnr'] ?? '') ?></td>
                    <td data-label="Senast ändrad"><?= h($lastChanged) ?></td>
                    <td data-label="PDF och äldre versioner">
                        <?= h(basename($rel)) ?>
                        <?php if (!empty($row['pdf_text_fit_warning'])): ?><div style="margin-top:6px;color:#8a3b00;font-size:.72rem;line-height:1.4"><strong>Varning:</strong> ett eller flera PDF-textfält är mindre än 5 pt; kontrollera läsbarheten.</div><?php endif; ?>
                        <?php if ($versions): ?><details class="arc-history"><summary><?= count($versions) ?> äldre version<?= count($versions) === 1 ? '' : 'er' ?></summary><ol>
                            <?php foreach (array_reverse($versions) as $version):
                                $versionFile = isset($version['file']) && is_string($version['file']) ? $version['file'] : '';
                                $versionRegnr = isset($version['regnr']) && is_string($version['regnr']) ? $version['regnr'] : '';
                                $versionSafe = $versionFile !== '' && $versionRegnr !== '' && function_exists('portal_skade_record_file_path') && portal_skade_record_file_path(['regnr' => $versionRegnr, 'file' => $versionFile]) !== null;
                                $versionDate = isset($version['created_at']) && is_string($version['created_at']) ? $version['created_at'] : '';
                            ?>
                                <li><time datetime="<?= h($versionDate) ?>"><?= h($versionDate) ?></time><?php if ($versionSafe): ?> · <a href="?view_file=<?= rawurlencode($versionFile) ?>" target="_blank" rel="noopener noreferrer">Öppna äldre PDF</a><?php else: ?> · PDF saknas<?php endif; ?></li>
                            <?php endforeach; ?>
                        </ol></details><?php endif; ?>
                        <?php if ($privateDataPurgedAt !== ''): ?><div style="margin-top:7px;color:#8a4b00;font-size:.72rem;line-height:1.4"><strong>Privata formuläruppgifter och signaturbild gallrades</strong><br><time datetime="<?= h($privateDataPurgedAt) ?>"><?= h($privateDataPurgedAt) ?></time> · PDF-versionerna behålls.</div><?php endif; ?>
                    </td>
                    <td data-label="Åtgärder"><div class="arc-actions">
                        <?php if ($safeRel !== ''): ?><a class="btn btn-outline" href="?view_file=<?= rawurlencode($safeRel) ?>" target="_blank" rel="noopener noreferrer">Öppna PDF</a><?php endif; ?>
                        <?php if ($isEditable): ?><a class="btn btn-main" href="?tab=skadeblankett&amp;edit_id=<?= rawurlencode($recordId) ?>">Redigera</a><?php elseif ($privateDataPurgedAt !== '' && $safeRel !== ''): ?><span title="Privata formuläruppgifter och signaturbild har gallrats efter ett år. PDF-versionerna finns kvar.">PDF kvar · uppgifter gallrade</span><?php elseif ($safeRel !== ''): ?><span title="Äldre historikposter saknar sparade formulärfält.">Endast PDF</span><?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
        </tbody></table></div><?php else: ?><div class="arc-empty">Inga skadeblanketter sparade ännu.</div><?php endif; ?>
    </section>

    <section class="arc-section"><div class="arc-section-head"><h2>PDF-arkiv</h2><span><?= count($files) ?> filer</span></div>
        <?php if ($files): ?><div class="arc-table-wrap"><table class="arc-table"><thead><tr><th>Mapp</th><th>Fil</th><th>Datum</th><th>Storlek</th><th></th></tr></thead><tbody>
            <?php foreach (array_slice($files, 0, 100) as $file): $parts = explode('/', (string) $file['rel']); $folder = count($parts) > 1 ? $parts[0] : 'PDF'; ?>
                <tr class="arc-row" data-search="<?= h($folder . ' ' . $file['name'] . ' ' . $file['date']) ?>"><td data-label="Mapp"><?= h($folder) ?></td><td class="arc-name" data-label="Fil"><?= h($file['name']) ?></td><td data-label="Datum"><?= h($file['date']) ?></td><td data-label="Storlek"><?= h(number_format((float) $file['size'], 1, ',', ' ')) ?> KB</td><td data-label="Åtgärder"><a class="btn btn-outline" href="?view_file=<?= rawurlencode('pdf/' . (string) $file['rel']) ?>" target="_blank" rel="noopener noreferrer">Öppna</a></td></tr>
            <?php endforeach; ?>
        </tbody></table></div><?php else: ?><div class="arc-empty">Inga filer hittades i PDF-arkivet.</div><?php endif; ?>
    </section>
    <div class="arc-empty" id="archive-no-results" hidden>Inga träffar. Prova en annan sökning.</div>
</section>
<script>
(function(){var input=document.getElementById('archive-search'),rows=[].slice.call(document.querySelectorAll('.arc-row')),empty=document.getElementById('archive-no-results');if(!input)return;input.addEventListener('input',function(){var q=input.value.toLocaleLowerCase('sv-SE').trim(),shown=0;rows.forEach(function(row){var match=(row.getAttribute('data-search')||'').toLocaleLowerCase('sv-SE').indexOf(q)!==-1;row.hidden=!match;if(match)shown++;});if(empty)empty.hidden=shown!==0;});})();
</script>
