<?php
declare(strict_types=1);

/**
 * Skadeblanketten must keep every entered value when a required field is missed.
 * Covers both the admin/personal route and the customer PWA route.
 * A missed required field (regnr) triggers the same server-side validation error
 * path; the form re-renders within the same request with $_POST['s'] intact, so
 * all other fields, the signature, the photo references and their previews, and
 * checkboxes must survive.
 * Run: npx --yes @php-wasm/cli tests/php/skade_field_preservation_regression.php
 */

require_once __DIR__ . '/skade_customer_render_fixture.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $description) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $description;
    }
};

function render_with_post(array $post, bool $customer, string $postError): string {
    $GLOBALS['isSkadeCustomerMode'] = $customer;
    $GLOBALS['csrfReady'] = true;
    $GLOBALS['portalLanguage'] = 'sv';
    $GLOBALS['portalCsrfFailed'] = false;
    $GLOBALS['portalSkadeInvalidPhotos'] = [];
    $GLOBALS['portalPostError'] = $postError;
    $GLOBALS['_GET'] = [];
    $GLOBALS['_POST'] = $post;
    ob_start();
    include __DIR__ . '/../../tabs/skadeblankett.php';
    return (string) ob_get_clean();
}

// Every field the form actually renders, with representative values.
// regnr is intentionally left empty so the required-field validation fails.
$sample = [
    'namn_adress' => 'Stina Andersson, Storgatan 1',
    'person_orgnr' => '1234567890',
    'telefon' => '0701234567',
    'epost' => 'stina@example.com',
    'regnr' => '', // required -> triggers the validation error
    'modell' => 'Volvo XC60',
    'matarstallning' => '12345',
    'forsakringsbolag' => 'If',
    'skadedatum' => '2026-03-21',
    'skadeplats' => 'Stockholm',
    'skadenummer' => 'SK-123',
    'foretag' => '1',
    'nya_torkarblad_bestalls' => '1',
    'photo_damage' => 'data:image/jpeg;base64,PHOTODAMAGE',
    'photo_front' => 'data:image/jpeg;base64,PHOTOFRONT',
    'signature_png' => 'data:image/png;base64,SIGNATURE',
    'signature_date' => '2026-03-21',
    'signature_confirmed' => '1',
];
$post = ['action' => 'generate_skadeblankett', 's' => $sample];

function field_value(string $html, string $name): ?string {
    if (preg_match('/name="s\[' . preg_quote($name, '/') . '\]"[^>]*>(.*?)<\/textarea>/s', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/name="s\[' . preg_quote($name, '/') . '\]"[^>]*value="([^"]*)"/', $html, $m)) {
        return $m[1];
    }
    return null;
}
function field_checked(string $html, string $name): bool {
    return (bool) preg_match('/name="s\[' . preg_quote($name, '/') . '\]"[^>]*checked/', $html);
}

foreach ([false, true] as $customer) {
    $mode = $customer ? 'customer' : 'admin';
    $html = render_with_post($post, $customer, 'Fyll i obligatoriska fält: Registreringsnummer.');

    $check(str_contains($html, 'Registreringsnummer'), "[$mode] the validation error is shown.");

    // Text/textarea fields must keep their values.
    foreach (['namn_adress', 'person_orgnr', 'telefon', 'epost', 'modell', 'matarstallning',
              'forsakringsbolag', 'skadedatum', 'skadeplats', 'skadenummer', 'signature_date'] as $f) {
        $got = field_value($html, $f);
        $check($got === $sample[$f], "[$mode] field '$f' preserved after a required-field miss (got: " . var_export($got, true) . ").");
    }

    // Checkboxes must keep their checked state.
    foreach (['foretag', 'nya_torkarblad_bestalls', 'signature_confirmed'] as $f) {
        $check(field_checked($html, $f) === ($sample[$f] === '1'), "[$mode] checkbox '$f' kept its state after a required-field miss.");
    }

    // Hidden photo references (the saved images) must survive so the photos stay attached.
    foreach (['photo_damage', 'photo_front', 'signature_png'] as $f) {
        $got = field_value($html, $f);
        $check($got === $sample[$f], "[$mode] stored reference '$f' preserved after a required-field miss.");
    }

    // regnr (the missed required field) is correctly empty.
    $regnr = field_value($html, 'regnr');
    $check($regnr === '' || $regnr === null, "[$mode] the missed required field regnr stays empty (no fabricated value).");
}

if ($failures) {
    echo "FAIL (" . count($failures) . " of $checks checks):\n - " . implode("\n - ", $failures) . "\n";
    exit(1);
}
echo "PASS: skadeblankett keeps every field when a required field is missed (" . $checks . " checks, both modes).\n";
