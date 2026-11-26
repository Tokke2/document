<?php
declare(strict_types=1);

/**
 * Admin can fill and print a damage form without a signature or damage date:
 * - the signed-date field ("Påskrivet datum") exists for admin only,
 * - the damage date stays optional for admin and required for the customer,
 * - print CSS prints the PDF frame instead of hiding the whole page,
 * - the result view offers a Print button wired to window.print(),
 * - the server normalises the signed date like the damage date,
 * - the PDF date box stays empty when nothing was signed.
 * Run: npx --yes @php-wasm/cli tests/php/skade_admin_print_regression.php
 */

require_once __DIR__ . '/skade_customer_render_fixture.php';

if (!function_exists('portal_skade_admin_can_omit_accident_date')) {
    function portal_skade_admin_can_omit_accident_date(): bool
    {
        return true;
    }
}

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $description) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $description;
    }
};

// --- Admin rendering (internal mode, not customer route) ---
// A throw-away PDF path enables the success view with the Print button.
$testPdfDir = __DIR__ . '/../../pdf/TEST123';
$testPdfPath = $testPdfDir . '/Skadeblankett-Bilglas-TEST123-20261125-120000-abcdef.pdf';
$testPdfCreated = is_dir($testPdfDir) || @mkdir($testPdfDir, 0775, true);
if ($testPdfCreated && !is_file($testPdfPath)) {
    $testPdfCreated = @file_put_contents($testPdfPath, '%PDF-1.4 test') !== false;
}
$isSkadeCustomerMode = false;
$portalLanguage = 'sv';
$csrfReady = true;
$_GET = $testPdfCreated ? ['pdf' => 'pdf/TEST123/Skadeblankett-Bilglas-TEST123-20261125-120000-abcdef.pdf'] : [];
$_POST = [];
ob_start();
include __DIR__ . '/../../tabs/skadeblankett.php';
$adminHtml = (string) ob_get_clean();
if (is_file($testPdfPath)) {
    @unlink($testPdfPath);
}
if (is_dir($testPdfDir)) {
    @rmdir($testPdfDir);
}
$check($testPdfCreated, 'Test PDF fixture could be created for the success view.');

$check(str_contains($adminHtml, 'name="s[signature_date]"'), 'Admin form exposes the signed-date field.');
$check((bool) preg_match('/<input id="sk-signature-date"[^>]*type="date"/', $adminHtml), 'Signed-date field uses a date input.');
$check(str_contains($adminHtml, 'Påskrivet datum'), 'Signed-date field is labelled "Påskrivet datum".');
$check(str_contains($adminHtml, 'frivilligt för admin'), 'Damage date is marked optional for admin.');
$check(str_contains($adminHtml, 'window.print()'), 'Print button triggers the browser print dialog.');
$check(str_contains($adminHtml, 'id="skade-print-pdf"'), 'Result view offers a Print button.');

if (preg_match('/<input id="sk-datum"[^>]*>/', $adminHtml, $match)) {
    $check(!str_contains($match[0], 'required'), 'Admin damage date must not be required.');
} else {
    $check(false, 'Admin rendering must contain the damage-date input.');
}

// Print CSS keeps the page wrapper (PDF frame) visible and hides the form column.
$check((bool) preg_match('/@media print \{[^}]*\.skade-main[^}]*display:none/', $adminHtml), 'Print CSS hides the form column.');
$check(!preg_match('/@media print \{[^}]*\.skade-page \{ display:none/', $adminHtml), 'Print CSS keeps the page wrapper visible so the PDF frame can print.');
$check((bool) preg_match('/@media print \{.*\.skade-preview \{ position:static/', $adminHtml), 'Print CSS shows the PDF preview pane.');

// --- Customer rendering stays strict ---
$customerHtml = render_customer_skade(false);
$check(!str_contains($customerHtml, 'name="s[signature_date]"'), 'Customer form has no signed-date field.');
if (preg_match('/<input id="sk-datum"[^>]*>/', $customerHtml, $match)) {
    $check(str_contains($match[0], 'required'), 'Customer damage date stays required.');
} else {
    $check(false, 'Customer rendering must contain the damage-date input.');
}

// --- Server-side rules ---
$indexSource = (string) file_get_contents(__DIR__ . '/../../index.php');
$check(str_contains($indexSource, "\$clean['signature_date'] = \$normalizeSkadeDate(\$clean['signature_date']);"), 'Server normalises the signed date with the shared date normaliser.');
$check(str_contains($indexSource, "\$signatureRequired = portal_skade_is_customer_route();"), 'A drawn signature is only required on the customer route.');
$check(str_contains($indexSource, "\$clean['signature_date'] = (new DateTimeImmutable('now'"), 'A drawn signature without a date auto-fills today.');

// --- PDF date box ---
require_once __DIR__ . '/../../includes/fpdf/fpdf.php';
require_once __DIR__ . '/../../includes/skadeblankett_pdf.php';
if (!function_exists('skade_timer_start')) {
    function skade_timer_start(string $name = 'default'): void {}
}
if (!function_exists('skade_timer_end')) {
    function skade_timer_end(string $name = 'default', string $label = ''): float { return 0.0; }
}
final class SkadeAdminPrintCapture extends FPDF
{
    /** @var list<string> */
    public array $writtenText = [];
    public function Text($x, $y, $txt)
    {
        $this->writtenText[] = (string) $txt;
    }
}
$pdf = new SkadeAdminPrintCapture('P', 'mm', 'A4');
$pdf->AddPage();
skade_pdf_write_line($pdf, '', 88.0, 471.8, 201.9, 21.9, 8.5);
$check($pdf->writtenText === [], 'An empty signed date writes nothing into the PDF date box.');
skade_pdf_write_line($pdf, '2026-03-21', 88.0, 471.8, 201.9, 21.9, 8.5);
$check(count($pdf->writtenText) === 1 && $pdf->writtenText[0] === '2026-03-21', 'An explicit signed date is written into the PDF date box.');

if ($failures) {
    foreach ($failures as $failure) {
        echo "FAIL: {$failure}\n";
    }
    echo 'FAILED: ' . count($failures) . " of {$checks} checks failed.\n";
    exit(1);
}
echo "PASS: admin skadeblankett without signature or damage date is fillable and printable ({$checks} checks).\n";
