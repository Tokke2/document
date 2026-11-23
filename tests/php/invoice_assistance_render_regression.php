<?php
declare(strict_types=1);
const CUSTOMERS_FILE = 'customers';
const INVOICES_FILE = 'invoices';
const PROTOCOLS_FILE = 'protocols';
function h(mixed $value): string { return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function app_legacy_text(mixed $value): string { return is_scalar($value) ? html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8') : ''; }
function load_data(string $file, mixed $default = []): array { if ($file === INVOICES_FILE) return $GLOBALS['TEST_INVOICES'] ?? []; if ($file === CUSTOMERS_FILE) return [['id' => 'customer1', 'name' => 'Kund', 'references' => ['Referens']]]; return $file === PROTOCOLS_FILE ? [['id' => 'protocol123', 'nummer' => 'SP123', 'customer' => 'PROTOCOL_ONLY_CUSTOMER', 'date' => '2026-01-01', 'description' => 'jobb', 'items' => [['art' => 'Material', 'qty' => '2', 'spec' => 'test']]]] : []; }
function portal_invoice_auth_read_users(): array { return []; }
function portal_voice_shared_candidates(array $requester, array $users, array $articles): array { return $GLOBALS['TEST_SHARED_CANDIDATES'] ?? []; }
function portal_voice_shared_article_summary(array $requester, array $users, array $articles): array { return $GLOBALS['TEST_SHARED_SUMMARY'] ?? []; }
function portal_invoice_auth_admin_users(): array { return []; }
function portal_protocol_auth_admin_users(): array { return []; }
function portal_email_status_read(): array { return []; }
function portal_email_transport_status(): array { return []; }
function load_settings(): array { return []; }
function portal_invoice_catalog_read(): array { return $GLOBALS['TEST_CATALOG'] ?? []; }
function portal_invoice_catalog_normalize(array $a): array { return $a; }
function portal_invoice_catalog_fixed_rules(array $a): array { return $a; }
function portal_invoice_catalog_price_history(array $records): ?array { return $GLOBALS['TEST_PRICE_HISTORY'] ?? []; }
function portal_invoice_catalog_units(): array { return ['st' => 'st', 'tim' => 'tim']; }
function portal_invoice_auth_current_user(): array { return ['id' => !empty($GLOBALS['TEST_VOICE_PERSONAL']) ? str_repeat('a', 32) : 'user1', 'role' => !empty($GLOBALS['TEST_INVOICE_ADMIN']) ? 'admin' : 'staff', 'auth_source' => !empty($GLOBALS['TEST_CENTRAL_ADMIN']) ? 'central_admin' : 'personal']; }
function portal_autosaved_drafts_list(string $type, string $id): array { return []; }
function portal_autosaved_drafts_new_id(): string { return str_repeat('a', 32); }
function portal_autosaved_drafts_get(string $type, string $id, string $draft): ?array { return null; }
function portal_search_protocol_auth_user(): ?array { return $GLOBALS['allowProtocol'] ? ['id' => 'protocol-user'] : null; }
function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="test">'; }
function render_invoice(bool $canRead, bool $scoped, array $query, string $portalLanguage = 'sv'): string {
    $GLOBALS['allowProtocol'] = $canRead;
    $_GET = $query;
    $portalIsScopedPwa = $scoped;
    $portalAssetEntry = './index.php';
    ob_start(); include __DIR__ . '/../../tabs/invoice.php'; return (string) ob_get_clean();
}
set_error_handler(static function (int $severity, string $message, string $file, int $line): never { throw new ErrorException($message, 0, $severity, $file, $line); });
$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void { $checks++; if (!$ok) throw new RuntimeException($message); };
$query = ['source_protocol' => 'protocol123', 'import_protocol' => '1', 'source_item' => ['0']];
$unauthorized = render_invoice(false, false, $query);
$assert(!str_contains($unauthorized, 'PROTOCOL_ONLY_CUSTOMER') && !str_contains($unauthorized, 'Skapa från sparat serviceprotokoll'), 'Invoice access alone cannot reveal protocol');
$scoped = render_invoice(true, true, $query);
$assert(!str_contains($scoped, 'PROTOCOL_ONLY_CUSTOMER'), 'Scoped invoice PWA cannot import protocol');
$authorized = render_invoice(true, false, $query);
$assert(str_contains($authorized, 'PROTOCOL_ONLY_CUSTOMER') && str_contains($authorized, 'Material – test'), 'Both authorised sessions can import selected line');
$assert(str_contains($authorized, 'name="i[source_protocol_id]" value="protocol123"'), 'Source link is present on import');
$assert(str_contains($authorized, 'Röst och snabb inmatning'), 'Voice panel rendered');
$english = render_invoice(true, false, $query, 'en');
$assert(str_contains($english, 'Create from saved service report') && str_contains($english, 'Voice and quick entry'), 'Invoice interface renders English');
$assert(str_contains($english, 'PROTOCOL_ONLY_CUSTOMER') && str_contains($english, 'Material – test') && str_contains($english, 'data-name="Kund"'), 'User content remains unchanged even when identical to a translated label');
$assert(str_contains($english, 'name="i[source_protocol_id]" value="protocol123"') && str_contains($english, '<option value="row">Invoice line</option>'), 'Submitted identifiers and option values do not change with language');
$token = str_repeat('a', 32) . '.opaqueToken';
$GLOBALS['TEST_INVOICES'] = [['id'=>'invoice123', 'items'=>[['art'=>'Stenskott','qty'=>'1','unit'=>'st','price'=>'100','voice_token'=>$token]]]];
$reopened = render_invoice(false, false, ['edit_id'=>'invoice123']);
$assert(str_contains($reopened, 'name="items[0][voice_token]" value="' . $token . '"'), 'Reopening an invoice restores encrypted row provenance');
$copied = render_invoice(false, false, ['copy_id'=>'invoice123']);
$assert(!str_contains($copied, $token), 'Copying an invoice does not clone voice provenance');
$GLOBALS['TEST_PRICE_HISTORY'] = [['at'=>'2026-01-02T12:30:00Z','article_id'=>str_repeat('b',32),'article_name'=>'Vindruta <test>','actor_id'=>'admin1','actor_name'=>'Namn <admin>','action'=>'changed','old_fixed'=>true,'new_fixed'=>true,'old_price'=>750,'new_price'=>850,'old_unit'=>'st','new_unit'=>'st']];
$GLOBALS['TEST_CATALOG'] = [['id'=>str_repeat('b',32),'name'=>'Glasbyte','unit'=>'st','price'=>750.0,'fixed_price'=>true,'aliases'=>['Rutbyte & prov']]];
$GLOBALS['TEST_INVOICE_ADMIN'] = false;
$staffCatalog = render_invoice(false, false, ['invoice_view'=>'articles']);
$assert(str_contains($staffCatalog, '(fast pris)') && !str_contains($staffCatalog, 'name="article[fixed_price]"') && str_contains($staffCatalog, 'Hanteras av fakturaadmin'), 'Staff can see but not manage fixed-price articles');
$assert(!str_contains($staffCatalog, 'name="article[aliases]"'), 'Staff cannot edit aliases in catalog');
$GLOBALS['TEST_INVOICE_ADMIN'] = true;
$adminCatalog = render_invoice(false, false, ['invoice_view'=>'articles','edit_article'=>str_repeat('b',32)]);
$assert(str_contains($adminCatalog, 'name="article[fixed_price]"') && str_contains($adminCatalog, 'checked'), 'Invoice admin can manage fixed-price flag');
$assert(str_contains($adminCatalog, 'name="article[aliases]"') && str_contains($adminCatalog, 'Rutbyte &amp; prov'), 'Admin can edit escaped existing aliases');
$assert(!str_contains($staffCatalog, 'invoice-price-history-heading') && !str_contains($staffCatalog, 'Namn &lt;admin&gt;'), 'Non-admin output contains no price history');
$assert(str_contains($adminCatalog, 'Vindruta &lt;test&gt;') && str_contains($adminCatalog, 'Namn &lt;admin&gt;') && str_contains($adminCatalog, '750,00') && str_contains($adminCatalog, '850,00'), 'Admin sees escaped actor, article and old/new prices');
$GLOBALS['TEST_CENTRAL_ADMIN'] = true;
$centralCatalog = render_invoice(false, false, ['invoice_view'=>'articles']);
$assert(!str_contains($centralCatalog, 'invoice-price-history-heading') && !str_contains($centralCatalog, 'Namn &lt;admin&gt;'), 'Shared central admin is not a personal invoice admin for history');
$GLOBALS['TEST_CENTRAL_ADMIN'] = false;
$scopedAdminHistory = render_invoice(false, true, ['invoice_view'=>'articles']);
$assert(str_contains($scopedAdminHistory, 'invoice-price-history-heading'), 'Personal invoice admin can see history in scoped PWA');
$oneEvent=$GLOBALS['TEST_PRICE_HISTORY'][0];
$GLOBALS['TEST_PRICE_HISTORY']=array_fill(0, 51, $oneEvent);
$pagedHistory=render_invoice(false, true, ['invoice_view'=>'articles','history_page'=>'2']);
$assert(str_contains($pagedHistory, 'Sida 2 / 2') && str_contains($pagedHistory, 'history_page=1'), 'History pagination stays in scoped invoice PWA');
$GLOBALS['TEST_PRICE_HISTORY']=[$oneEvent];
$englishHistory = render_invoice(false, false, ['invoice_view'=>'articles'], 'en');
$assert(str_contains($englishHistory, 'Fixed-price history') && str_contains($englishHistory, 'Fixed price changed'), 'History is translated into English');

$GLOBALS['TEST_SHARED_CANDIDATES'] = array_fill(0, 51, ['source_id'=>str_repeat('a',32), 'source_name'=>'Användare <x>', 'heard'=>'Fel <x>', 'approved'=>'Rätt & klart']);
$adminShared=render_invoice(false,true,['invoice_view'=>'articles','shared_page'=>'2']);
$assert(str_contains($adminShared, 'Fel &lt;x&gt;') && str_contains($adminShared, 'Användare &lt;x&gt;') && str_contains($adminShared, 'Rätt &amp; klart') && str_contains($adminShared, 'shared_page=1'), 'Paged PWA review escapes personal text');
$assert(str_contains($adminShared, 'name="action" value="invoice_shared_approve"') && str_contains($adminShared,'name="csrf_token" value="test"') && str_contains($adminShared,'name="shared_article" required'), 'Approval requires CSRF and explicit target');
$GLOBALS['TEST_INVOICE_ADMIN']=false;
$staffShared=render_invoice(false,true,['invoice_view'=>'articles']);
$assert(!str_contains($staffShared,'Fel &lt;x&gt;') && !str_contains($staffShared,'invoice_shared_approve'), 'Staff cannot view candidates');
$GLOBALS['TEST_INVOICE_ADMIN']=true; $GLOBALS['TEST_CENTRAL_ADMIN']=true;
$centralShared=render_invoice(false,false,['invoice_view'=>'articles']);
$assert(!str_contains($centralShared,'Fel &lt;x&gt;') && !str_contains($centralShared,'invoice_shared_approve'), 'Central administrator cannot view candidates');
$GLOBALS['TEST_CENTRAL_ADMIN']=false;
$englishShared=render_invoice(false,true,['invoice_view'=>'articles'],'en');
$assert(str_contains($englishShared,'Shared voice corrections for review') && str_contains($englishShared,'Approve alternative name'), 'Review is translated');
$assert(str_contains($english,'Share corrections for administrator review') && str_contains($authorized,'Dela rättningar för admingranskning'), 'Sharing controls are translated');
$GLOBALS['TEST_SHARED_SUMMARY']=array_fill(0,51,['article_id'=>str_repeat('b',32),'article_name'=>'Artikel <x>','heard'=>'Hört <x>','contributors'=>2]);
$summaryAdmin=render_invoice(false,true,['invoice_view'=>'articles','shared_stats_page'=>'2']);
$assert(str_contains($summaryAdmin,'Artikel &lt;x&gt;') && str_contains($summaryAdmin,'Hört &lt;x&gt;') && str_contains($summaryAdmin,'shared_stats_page=1'), 'Paged admin summary is escaped and PWA safe');
$GLOBALS['TEST_INVOICE_ADMIN']=false;
$summaryStaff=render_invoice(false,true,['invoice_view'=>'articles']);
$assert(!str_contains($summaryStaff,'Hört &lt;x&gt;'), 'Staff cannot see shared summary');
$GLOBALS['TEST_INVOICE_ADMIN']=true; $GLOBALS['TEST_CENTRAL_ADMIN']=true;
$summaryCentral=render_invoice(false,false,['invoice_view'=>'articles']);
$assert(!str_contains($summaryCentral,'Hört &lt;x&gt;'), 'Central admin cannot see shared summary');
$GLOBALS['TEST_CENTRAL_ADMIN']=false; $GLOBALS['TEST_SHARED_SUMMARY']=[];
$GLOBALS['TEST_SHARED_CANDIDATES']=[];
$GLOBALS['TEST_CATALOG'] = []; $GLOBALS['TEST_INVOICE_ADMIN'] = false;
echo "PASS: {$checks} invoice assistance render checks.\n";
