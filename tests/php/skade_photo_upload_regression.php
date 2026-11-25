<?php
declare(strict_types=1);

/**
 * Regression checks for the optional skadeblankett photos:
 * data-URI validator, PDF photo page, temp-file cleanup, 1-year purge and
 * the static wiring in index.php / tabs / send_skade_email.php.
 * Run with a PHP CLI that can read the repository files:
 *   npx --yes @php-wasm/cli tests/php/skade_photo_upload_regression.php
 */

// Isolated no-op loggers/timers: this test must never write app logs.
if (!function_exists('skade_timer_start')) {
    function skade_timer_start(string $name = 'default'): void {}
}
if (!function_exists('skade_timer_end')) {
    function skade_timer_end(string $name = 'default', string $label = ''): float { return 0.0; }
}
if (!function_exists('skade_log_error')) {
    function skade_log_error(string $message, array $context = []): void {}
}

require_once __DIR__ . '/../../includes/fpdf/fpdf.php';
require_once __DIR__ . '/../../includes/skadeblankett_pdf.php';
require_once __DIR__ . '/../../functions.php';

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $description) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $description;
    }
};

// --- Validator fixtures (small base64 literals; regenerate with sharp if /tmp is cleared) ---
$j200Literal = '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';   // 200x200 JPEG, valid
$j50Literal = '/9j/2wBDAAYEBQYFBAYGBQYHBwYIChAKCgkJChQODwwQFxQYGBcUFhYaHSUfGhsjHBYWICwgIyYnKSopGR8tMC0oMCUoKSj/2wBDAQcHBwoIChMKChMoGhYaKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCj/wAARCAAyADIDASIAAhEBAxEB/8QAGgABAQEBAAMAAAAAAAAAAAAABAAFAwIGCP/EACQQAAICAgEEAgMBAAAAAAAAAAABAgMEYSEREjGhFFEFNEGB/8QAGQEAAgMBAAAAAAAAAAAAAAAABQYDBAcC/8QAJxEAAQMCBAYDAQAAAAAAAAAAAQADBAIFERIxYRMUIVGRsTM1QXH/2gAMAwEAAhEDEQA/APmWrH0Mqx9DKsbQ2rG0H4kdVqJKHVjaGVY2hlWPobVjaGiJH0VuiSh1Y2hDhCmtzs4S9jXCFNbnZwl7M61TyLO6a6JeI/R1d703aW8lHV06DtudvfkojGrLhx/FweXd14rrS/nVMhPx9EIxvt0PXj1Inno7LhVjaG1Y2hlWNobVjaGuJHWY0SUKrG0JcIU1udnCXsY4Qprc7OEvZn2qeRZ3SXRLxH6Orvem7Q3ko6unQdtzt78lEY1ZcOyFap5FndJdEvEfo71Y+hlWNobVjaEBviSXC88cajqUcbfAGAWd8bRGz8fRBHl1PzK4VY2hLhCmtzs4S9jHCFNbnZwl7M+1TyLO6S6JeI/QxXe9N2lvJR1dOg7bnb35KzONWXDshWqeRZ3SXRLxH6O9WNoZVjaGVY2jP2+JJcLzxxqOpRxt8AYBDqxtDKsbQ2rG0MqxtDFEj6K3RJWesfRGysbRBjl1PzK9Y/J/sQX87PH+s8KkuCISL99s9/R6CTYvxhNqSGVJERPERChNqS4GVJEQ0RPxWqEjoREF1Ov/2Q==';    // 50x50 JPEG, below minimum dimensions
$p200Literal = 'iVBORw0KGgoAAAANSUhEUgAAAMgAAADICAIAAAAiOjnJAAAACXBIWXMAAAPoAAAD6AG1e1JrAAAD8UlEQVR4nO2UAQnAQACEFsJ0n2TxV2JDdggmOOUu7hMtwNsZXG3aAnxwLoVVWKewiuD85V97LN8BixSW74BFCst3wCKF5TtgkcLyHbBIYfkOWKSwfAcsUli+AxYpLN8BixSW74BFCst3wCKF5TtgkcLyHbBIYfkOWKSwfAcsUli+AxYpLN8BixSW74BFCst3wCKF5TtgkcLyHbBIYfkOWKSwfAcsUli+AxYpLN8BixSW74BFCst3wCKF5TtgkcLyHbBIYfkOWKSwfAcsUli+AxYpLN8BixSW74BFCst3wCKF5TtgkcLyHbBIYfkOWKSwfAcsUli+AxYpLN8BixSW74BFCst3wCKF5TtgkcLyHbBIYfkOWKSwfAcsUli+AxYpLN8BixSW74BFCst3wCKF5TtgkcLyHbBIYfkOWKSwfAcsUli+AxYpLN8BixSW74BFCst3wCKF5TtgkcLyHbBIYfkOWKSwfAcsUli+AxYpLN8BixSW74BFCst3wCKF5TtgkcLyHbBIYfkOWKSwfAcsUli+AxYpLN8BixSW74BFCst3wCKF5TtgkcLyHbBIYfkOWKSwfAcsUli+AxYpLN8BixSW74BFCst3wCKF5TtgkcLyHbBIYfkOWKSwfAcsUli+AxYpLN8BixSW74BFCst3wCKF5TtgkcLyHbBIYfkOWKSwfAcsUli+AxYpLN8BixSW74BFCst3wCKF5TtgkcLyHbBIYfkOWKSwfAcsUli+AxYpLN/BJIXlO2CRwvIdsEhh+Q5YpLB8ByxSWL4DFiks3wGLFJbvgEUKy3fAIoXlO2CRwvIdsEhh+Q5YpLB8ByxSWL4DFiks3wGLFJbvgEUKy3fAIoXlO2CRwvIdsEhh+Q5YpLB8ByxSWL4DFiks3wGLFJbvgEUKy3fAIoXlO2CRwvIdsEhh+Q5YpLB8ByxSWL4DFiks3wGLFJbvgEUKy3fAIoXlO2CRwvIdsEhh+Q5YpLB8ByxSWL4DFiks3wGLFJbvgEUKy3fAIoXlO2CRwvIdsEhh+Q5YpLB8ByxSWL4DFiks3wGLFJbvgEUKy3fAIoXlO2CRwvIdsEhh+Q5YpLB8ByxSWL4DFiks3wGLFJbvgEUKy3fAIoXlO2CRwvIdsEhh+Q5YpLB8ByxSWL4DFiks3wGLFJbvgEUKy3fAIoXlO2CRwvIdsEhh+Q5YpLB8ByxSWL4DFiks3wGLFJbvgEUKy3fAIoXlO2CRwvIdsEhh+Q5YpLB8ByxSWL4DFiks3wGLFJbvgEUKy3fAIoXlO2CRwvIdsEhh+Q5YpLB8ByxSWL4DFiks3wGLFJbvgEUKy3fAIoXlO2CRwvIdsEhh+Q5Y5AE9zGm1gspjSwAAAABJRU5ErkJggg==';  // 200x200 PNG, valid

$validJpeg = skade_photo_data_uri_bytes('data:image/jpeg;base64,' . $j200Literal);
$check(is_array($validJpeg) && $validJpeg['mime'] === 'image/jpeg' && $validJpeg['width'] === 200 && $validJpeg['height'] === 200, 'A 200x200 JPEG data URI must be accepted with its dimensions.');
$check(is_array($validJpeg) && strlen($validJpeg['bytes']) >= 500 && strlen($validJpeg['bytes']) <= 410000, 'Accepted photo bytes must stay inside the byte bounds.');

$validPng = skade_photo_data_uri_bytes('data:image/png;base64,' . $p200Literal);
$check(is_array($validPng) && $validPng['mime'] === 'image/png' && $validPng['width'] === 200 && $validPng['height'] === 200, 'A 200x200 PNG data URI must be accepted.');

$failureCode = '';
$tooSmall = skade_photo_data_uri_bytes('data:image/jpeg;base64,' . $j50Literal, $failureCode);
$check($tooSmall === null && $failureCode === 'skade.photo_image_invalid', 'A 50x50 photo must be rejected as an invalid image.');

$failureCode = '';
$overCap = skade_photo_data_uri_bytes('data:image/jpeg;base64,' . str_repeat('A', 550000), $failureCode);
$check($overCap === null && $failureCode === 'skade.photo_invalid', 'A data URI above 550000 characters must be rejected as invalid.');

$failureCode = '';
$wrongPrefix = skade_photo_data_uri_bytes('data:image/gif;base64,' . $j200Literal, $failureCode);
$check($wrongPrefix === null && $failureCode === 'skade.photo_invalid', 'A GIF-prefixed data URI must be rejected.');

$failureCode = '';
$badPadding = skade_photo_data_uri_bytes('data:image/jpeg;base64,AAA', $failureCode);
$check($badPadding === null && $failureCode === 'skade.photo_invalid', 'A base64 payload whose length is not a multiple of 4 must be rejected.');

$failureCode = '';
$notBase64 = skade_photo_data_uri_bytes('data:image/jpeg;base64,!!!!', $failureCode);
$check($notBase64 === null && $failureCode === 'skade.photo_invalid', 'A payload outside the base64 alphabet must be rejected.');

$failureCode = '';
$notAnImage = skade_photo_data_uri_bytes('data:image/jpeg;base64,' . base64_encode(str_repeat('x', 600)), $failureCode);
$check($notAnImage === null && $failureCode === 'skade.photo_image_invalid', 'A payload that is not an image must be rejected as an invalid image.');

$failureCode = '';
$emptyPhoto = skade_photo_data_uri_bytes('', $failureCode);
$check($emptyPhoto === null && $failureCode === 'skade.photo_invalid', 'An empty value must be rejected by the validator itself.');

// --- PDF photo page: 2 photos + signature => 2 pages, >=3 embedded images, no temp leftovers ---
$projectRoot = dirname(__DIR__, 2);
$templatePath = $projectRoot . '/pdf/Bilrutan Skadeblankett.pdf';
$outputPath = $projectRoot . '/pdf/.photo-regression-' . bin2hex(random_bytes(6)) . '.pdf';
$photoData = [
    'photo_damage_bytes' => base64_decode($j200Literal, true),
    'photo_front_bytes' => base64_decode($p200Literal, true),
    'signature_png_bytes' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAFoAAAAoCAYAAAB+Qu3IAAABnUlEQVR4nO2ZzW4DIQyEaVUpz5drHzDXPl9P6QmJEjBjbLNkdz4pUiKxmJ0M4/1JiRBCCLk4H5rBt/vjmb///nyrjr06n0cv4CrAQpduJnqmHU3hdTA6FvGFDFrt3jM2XbWjz3LiM2QDzBjPFB2agrf745k/mjl36QW1yNp1DYU+4zbW0hMVMU5mSTNc5UrL1vaoKzEltNXZkkNaNZATsW7t0bzlulrnP3K3KDQSG9rMfSektUuCt8af4joa2SEec7aERd29jdCt3aONj9G8FqS4bLm7/j0tNJLTtXgewkk10HVFzdkySKYrtOayzjOHNUL16tZzaK/30bFI7cwW0TFqOsi4eqymhjTG694hTOjoG53R9tbWjBQ5pY7QqEjeeYjOh25vS4R4m8PN0eVJSOLNNMTRMdZcj2ioNVtktIWRKCsbucSL0Ec9RIqs1YuQ6FwuMTtaykHPRaN3ZSgrRU7JOTpmm9QKrBFj5Z/Qu8dGeXc5s74jn6eHNcOVmbvbfKGUr6p2fzR6xAsCt3+Sr7xk3v46mhBCCCGElPwB+nk8GNooPgkAAAAASUVORK5CYII=', true),
];
$photoResult = create_skadeblankett_pdf_php($photoData, $templatePath, $outputPath, 'pdf');
$check(is_array($photoResult) && ($photoResult[0] ?? false) === true && is_file($outputPath), 'A submission with both photos must produce a PDF.');
$pdfBytes = is_file($outputPath) ? file_get_contents($outputPath) : '';
$check(is_string($pdfBytes) && $pdfBytes !== '', 'The generated photo PDF must be readable.');
$check(is_string($pdfBytes) && preg_match_all('/\/Type\s*\/Page[^s]/', $pdfBytes) === 2, 'Photos must render on a dedicated second PDF page.');
$check(is_string($pdfBytes) && preg_match_all('/\/Subtype\s*\/Image/', $pdfBytes) >= 3, 'The PDF must embed the template, signature and both photos as images.');
$leftoverTemps = glob($projectRoot . '/storage/tmp/skade-photo-*') ?: [];
$check($leftoverTemps === [], 'Photo temp files must be deleted after PDF generation.');
if (is_file($outputPath)) {
    @unlink($outputPath);
}

// --- PDF stage rejects photo bytes that are not a valid image ---
$badOutputPath = $projectRoot . '/pdf/.photo-regression-bad-' . bin2hex(random_bytes(6)) . '.pdf';
$badResult = create_skadeblankett_pdf_php(['photo_damage_bytes' => 'not-an-image'], $templatePath, $badOutputPath, 'pdf');
$check(is_array($badResult) && ($badResult[0] ?? true) === false && ($badResult[2] ?? '') === 'skade.pdf_photo_invalid', 'Invalid photo bytes must fail the PDF stage with skade.pdf_photo_invalid.');
$check(!file_exists($badOutputPath), 'A rejected photo must not leave a PDF behind.');

// --- 1-year purge removes photos stored inside form_data ---
$purgeCutoff = strtotime('2026-01-01T00:00:00+00:00');
$purgedAt = strtotime('2026-11-25T00:00:00+00:00');
$photoFormdata = ['photo_damage' => 'data:image/jpeg;base64,' . $j200Literal, 'photo_front' => 'data:image/png;base64,' . $p200Literal];
$purgeResult = skade_purge_expired_private_data([
    'old' => ['form_data' => $photoFormdata, 'signature_png_base64' => 'abc', 'created_at' => '2024-06-01T00:00:00+00:00'],
    'new' => ['form_data' => $photoFormdata, 'signature_png_base64' => 'abc', 'created_at' => '2026-11-01T00:00:00+00:00'],
], $purgeCutoff, $purgedAt);
$check(($purgeResult['purged_count'] ?? 0) === 1, 'Only records older than one year must be purged.');
$check(!array_key_exists('form_data', $purgeResult['records']['old'] ?? []) && !array_key_exists('signature_png_base64', $purgeResult['records']['old'] ?? []), 'Purging must remove form_data (and with it both photos) from expired records.');
$check(isset($purgeResult['records']['new']['form_data']['photo_damage']), 'Recent records must keep their photos.');

// --- Static wiring greps ---
$indexSource = file_get_contents($projectRoot . '/index.php');
$tabsSource = file_get_contents($projectRoot . '/tabs/skadeblankett.php');
$emailSource = file_get_contents($projectRoot . '/includes/send_skade_email.php');
$check(is_string($indexSource) && strpos($indexSource, 'skade_photo_data_uri_bytes') !== false && strpos($indexSource, 'skade_photo_data_uri_bytes') < strpos($indexSource, '$formData = $clean'), 'index.php must validate photos before $formData = $clean so they reach form_data.');
$check(is_string($indexSource) && strpos($indexSource, "'skade-customer-v4-photos'") !== false, 'index.php must allowlist form_version skade-customer-v4-photos.');
$check(is_string($indexSource) && strpos($indexSource, 'skade.pdf_photo') !== false, 'index.php must map skade.pdf_photo* failures to the photo error message.');
$check(is_string($tabsSource) && strpos($tabsSource, 'value="skade-customer-v4-photos"') !== false, 'tabs/skadeblankett.php must post form_version skade-customer-v4-photos.');
$check(is_string($tabsSource) && strpos($tabsSource, 'data-invalid-photos') !== false, 'The form must expose data-invalid-photos for server-side photo rejections.');
$check(is_string($emailSource) && strpos($emailSource, 'Bilder: ') !== false, 'The notification email must list attached photos.');
$check(is_string($emailSource) && strpos($emailSource, 'Bilder: ') > strpos($emailSource, 'Skadedatum:'), 'The photo line must follow the Skadedatum line.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . "\n");
    }
    fwrite(STDERR, sprintf("%d/%d checks failed.\n", count($failures), $checks));
    exit(1);
}
echo "PASS: {$checks} checks passed (skade photo upload).\n";
