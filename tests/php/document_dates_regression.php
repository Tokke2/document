<?php
declare(strict_types=1);
// Isolated execution of production save/date code; never writes customer data.
$source = (string) file_get_contents(__DIR__ . '/../../index.php');
function extract_date_test_function(string $name): string {
    global $source;
    $start = strpos($source, 'function ' . $name . '(');
    $end = strpos($source, "\nfunction ", $start + 1);
    if ($start === false || $end === false) throw new RuntimeException('Function not found: ' . $name);
    return substr($source, $start, $end - $start);
}
eval(extract_date_test_function('portal_post_text'));
eval(extract_date_test_function('portal_valid_iso_date'));
eval(extract_date_test_function('portal_save_invoice'));
function portal_invoice_auth_current_user(): array { return ['id'=>'staff1','role'=>$GLOBALS['role'] ?? 'staff']; }
function portal_process_items($items, $type): array { return []; }
function portal_invoice_catalog_lock() { return fopen('php://memory', 'r+'); }
function portal_invoice_catalog_unlock($lock): void { fclose($lock); }
function portal_invoice_catalog_read(): array { return []; }
function portal_invoice_catalog_fixed_rules($records): array { return []; }
function portal_invoice_catalog_enforce_fixed($items, $old, $rules): array { return $items; }
function portal_voice_learning_filter_shared_tokens($items, $old, $id): array { return $items; }
function load_data($file, $default): array { return $GLOBALS['records'] ?? []; }
function save_data($file, $records): bool { $GLOBALS['records'] = $records; return true; }
function portal_random_id(): string { return str_repeat('a',32); }
function get_next_invoice_number(): string { return 'TEST1'; }
function portal_send_invoice_saved_email($invoice): array { return ['accepted'=>false]; }
function portal_invoice_email_status_update($id,$email,$actor): bool { return true; }
class TadegaPrivacyLogger { public static function event(...$args): void {} public static function applicationError(...$args): void {} }
define('INVOICES_FILE','in-memory');
$_SESSION = [];
$checks = 0;
function check_date_test(bool $ok, string $message): void {
    $GLOBALS['checks']++;
    if (!$ok) throw new RuntimeException($message);
}
foreach (['staff', 'admin'] as $role) {
    $GLOBALS['role'] = $role;
    $GLOBALS['records'] = [];
    $_POST = ['i'=>['vat_mode'=>'ex','date'=>'2026-01-03','delivery_date'=>'2026-01-02']];
    check_date_test(portal_save_invoice(), "$role saves independent record/delivery dates");
    check_date_test($GLOBALS['records'][0]['delivery_date'] === '2026-01-02', 'Delivery date stored');
    check_date_test($GLOBALS['records'][0]['date'] === '2026-01-03', 'Record date stays selectable');
    $_POST['i']['id'] = str_repeat('a',32);
    $_POST['i']['delivery_date'] = '2024-02-29';
    check_date_test(portal_save_invoice(), 'Edit accepts leap day');
    check_date_test(count($GLOBALS['records']) === 1 && $GLOBALS['records'][0]['delivery_date'] === '2024-02-29', 'Edit preserves chosen delivery date');
    foreach (['2026-02-30','2026-13-01','not-a-date',['2026-01-02']] as $bad) {
        $_POST['i']['delivery_date'] = $bad;
        check_date_test(!portal_save_invoice(), 'Invalid or array date rejected');
        check_date_test($GLOBALS['records'][0]['delivery_date'] === '2024-02-29', 'Invalid save does not change old record');
    }
    $_POST['i']['delivery_date'] = '';
    check_date_test(portal_save_invoice() && $GLOBALS['records'][0]['delivery_date'] === '', 'Optional delivery date can be cleared');
    unset($_POST['i']['delivery_date']);
    check_date_test(portal_save_invoice(), 'Older forms without delivery date remain supported');
}
require_once __DIR__ . '/../../includes/autosaved_drafts.php';
foreach (['2024-02-29',''] as $date) {
    $draft = portal_autosaved_drafts_sanitize_payload('invoice',['i'=>['vat_mode'=>'ex','delivery_date'=>$date]]);
    check_date_test(is_array($draft) && $draft['i']['delivery_date'] === $date, 'Autosave retains optional delivery date');
}
foreach (['2026-02-30','2026-00-10',['2026-01-02']] as $bad) {
    check_date_test(portal_autosaved_drafts_sanitize_payload('invoice',['i'=>['vat_mode'=>'ex','delivery_date'=>$bad]]) === null, 'Autosave rejects invalid delivery dates');
}
// Execute the production customer/admin input stage, stopping before signature
// image validation (covered separately by the PDF/customer suites).
function portal_skade_is_customer_route(): bool { return $GLOBALS['customerMode']; }
function portal_skade_get_action_token(...$args): array { return ['status'=>'pending']; }
$handler = extract_date_test_function('portal_handle_skade_submission');
$handler = substr($handler, 0, strpos($handler, '    $signatureRequired =')) . "    return \$clean;\n}";
$handler = str_replace('function portal_handle_skade_submission(): bool', 'function test_skade_date_input(): array|bool', $handler);
eval($handler);
$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
foreach (['2000-01-01','2099-12-31','not-a-date',['forged']] as $date) {
    $_POST = ['s'=>['signature_date'=>$date]];
    $GLOBALS['customerMode'] = true;
    check_date_test(test_skade_date_input()['signature_date'] === $today, 'Customer date is server-controlled, regardless of client input');
}
$GLOBALS['customerMode'] = false;
$_POST = ['s'=>['signature_date'=>'2020-04-03']];
check_date_test(test_skade_date_input()['signature_date'] === '2020-04-03', 'Staff signed date is not overwritten');
echo "PASS: $checks document date save, edit, validation, autosave and customer server-date checks.\n";
