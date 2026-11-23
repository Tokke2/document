<?php
declare(strict_types=1);

/**
 * Lightweight regression checks for the skadeblankett PDF text-fit rules.
 * Run with a PHP CLI that can read the repository files:
 *   php tests/php/skadeblankett_pdf_regression.php
 */

require_once __DIR__ . '/../../includes/fpdf/fpdf.php';
require_once __DIR__ . '/../../includes/skadeblankett_pdf.php';

// Minimal no-op timers keep this isolated regression test from writing to app logs.
if (!function_exists('skade_timer_start')) {
    function skade_timer_start(string $name = 'default'): void {}
}
if (!function_exists('skade_timer_end')) {
    function skade_timer_end(string $name = 'default', string $label = ''): float { return 0.0; }
}

final class SkadePdfRegressionCapture extends FPDF
{
    /** @var list<array{x:float,y:float,text:string}> */
    public array $writtenText = [];
    /** @var list<array{width:float,lineHeight:float,text:string}> */
    public array $multiCellCalls = [];
    /** @var array{x:float,y:float}|null */
    public ?array $lastSetXY = null;

    public function Text($x, $y, $txt)
    {
        $this->writtenText[] = ['x' => (float) $x, 'y' => (float) $y, 'text' => (string) $txt];
    }

    public function SetXY($x, $y)
    {
        $this->lastSetXY = ['x' => (float) $x, 'y' => (float) $y];
        parent::SetXY($x, $y);
    }

    public function MultiCell($w, $h, $txt, $border = 0, $align = 'J', $fill = false)
    {
        $this->multiCellCalls[] = ['width' => (float) $w, 'lineHeight' => (float) $h, 'text' => (string) $txt];
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

// Signature placement preserves aspect ratio and exposes crop padding in physical mm.
$signaturePlacement = skade_pdf_signature_placement(600, 100);
$check(is_array($signaturePlacement), 'A valid cropped PNG should receive a PDF placement.');
if (is_array($signaturePlacement)) {
    $check(abs(($signaturePlacement['width_mm'] / $signaturePlacement['height_mm']) - 6.0) < 0.0001, 'Signature placement must preserve the cropped PNG aspect ratio.');
    $measuredPadMm = 12 * $signaturePlacement['mm_per_px'];
    $check(abs($measuredPadMm - 0.7154) < 0.01, 'A 12 px crop inset must be measurable at the actual PDF placement.');
    $boxTopMm = skade_pdf_mm(471.8);
    $boxBottomMm = skade_pdf_mm(471.8 + 21.9);
    $check($signaturePlacement['top_mm'] - $boxTopMm >= skade_pdf_mm(2.49) && $boxBottomMm - ($signaturePlacement['top_mm'] + $signaturePlacement['height_mm']) >= skade_pdf_mm(2.49), 'There must be space above and below the signature in the original box.');
    $maximumOverrunMm = max(
        $signaturePlacement['left_overrun_mm'],
        $signaturePlacement['right_overrun_mm'],
        $signaturePlacement['top_overrun_mm'],
        $signaturePlacement['bottom_overrun_mm']
    );
    $check($maximumOverrunMm < 0.0001, 'Signature must stay inside the box border.');
}
foreach ([[100, 600], [600, 100], [6000, 25], [25, 6000], [100, 100]] as [$w, $h]) {
    $placement = skade_pdf_signature_placement($w, $h);
    $check(is_array($placement) && max($placement['left_overrun_mm'], $placement['right_overrun_mm'], $placement['top_overrun_mm'], $placement['bottom_overrun_mm']) < 0.0001, 'Every signature aspect ratio must fit inside the PDF box.');
    $check(is_array($placement) && abs($placement['width_mm'] / $placement['height_mm'] - $w / $h) < 0.0001, 'Signature proportions must be preserved.');
    $check(is_array($placement) && $placement['top_mm'] >= skade_pdf_mm(471.8 + 2.49) && $placement['top_mm'] + $placement['height_mm'] <= skade_pdf_mm(471.8 + 21.9 - 2.49), 'Top and bottom breathing room must remain for all aspect ratios.');
}
$check(skade_pdf_signature_placement(0, 100) === null, 'Zero-sized signature images must not receive a PDF placement.');

$pdf = new SkadePdfRegressionCapture('P', 'mm', 'A4');
$pdf->AddPage();
$pdf->SetFont('Arial', '', 8.0);

// The line writer must preserve its 0.1 mm vertical inset and the full string.
$GLOBALS['skade_pdf_text_fit_stats'] = ['below_5pt_fields' => 0, 'minimum_font_pt' => null, 'unfit_fields' => 0];
$topPt = 200.0;
$heightPt = 40.0;
$fontSize = 8.0;
skade_pdf_write_line($pdf, 'Margin test 123', 100.0, $topPt, 100.0, $heightPt, $fontSize);
$check(count($pdf->writtenText) === 1, 'A fitting single-line field should be written once.');
if (count($pdf->writtenText) === 1) {
    $boxHeightMm = max(1.2, skade_pdf_mm($heightPt));
    $availableHeightMm = max(0.1, $boxHeightMm - 0.2);
    $fontHeightMm = skade_pdf_mm($fontSize);
    $lineBoxHeightMm = min($availableHeightMm, $fontHeightMm);
    $baselineOffsetMm = max(0.0, ($availableHeightMm - $lineBoxHeightMm) / 2.0) + ($lineBoxHeightMm * 0.78);
    $expectedY = skade_pdf_mm($topPt) + 0.1 + $baselineOffsetMm;
    $check(abs($pdf->writtenText[0]['y'] - $expectedY) < 0.0001, 'Single-line text must use a 0.1 mm vertical inset.');
    $check($pdf->writtenText[0]['text'] === 'Margin test 123', 'Single-line text must not be shortened or altered.');
}

// The multiline writer must begin exactly 0.1 mm below the field's top edge.
$pdf->multiCellCalls = [];
$pdf->lastSetXY = null;
$multiTopPt = 240.0;
skade_pdf_write_multiline($pdf, 'A few words', 100.0, $multiTopPt, 150.0, 40.0, 8.0, 4.0);
$check(count($pdf->multiCellCalls) === 1, 'A fitting multiline field should be written once.');
if ($pdf->lastSetXY !== null && count($pdf->multiCellCalls) === 1) {
    $check(abs($pdf->lastSetXY['y'] - (skade_pdf_mm($multiTopPt) + 0.1)) < 0.0001, 'Multiline text must use a 0.1 mm vertical inset.');
    $check($pdf->multiCellCalls[0]['text'] === 'A few words', 'Multiline text must remain complete.');
}

// Wrapping may break only between words: even an oversized word remains intact,
// and all supplied words remain represented in the returned lines.
$longWord = 'Supercalifragilisticexpialidocious1234567890';
$sourceText = 'Kort rad med ' . $longWord . ' sedan slut';
$complete = null;
$wrapped = skade_pdf_wrap_lines($pdf, $sourceText, 20.0, 80, $complete);
$check($complete === false, 'An oversized unbreakable word must be marked as not fitting.');
$check(in_array($longWord, $wrapped, true), 'An oversized word must remain whole rather than being split.');
$check(implode(' ', $wrapped) === $sourceText, 'Wrapping must preserve every character and word.');

// A line-count overflow must report incomplete, without discarding the tail.
$lineOverflowText = 'one two three four five six';
$overflowLines = skade_pdf_wrap_lines($pdf, $lineOverflowText, 14.0, 1, $complete);
$check($complete === false, 'A line-count overflow must be reported as incomplete.');
$check(implode(' ', $overflowLines) === $lineOverflowText, 'Line-count overflow must not truncate the remaining text.');

// Below-5-point tracking is strict: 5.0 pt itself is not a warning; anything
// smaller is counted and the minimum size is retained for the preflight.
$GLOBALS['skade_pdf_text_fit_stats'] = ['below_5pt_fields' => 0, 'minimum_font_pt' => null, 'unfit_fields' => 0];
skade_pdf_track_font_size(5.0);
skade_pdf_track_font_size(4.99);
$fontStats = $GLOBALS['skade_pdf_text_fit_stats'];
$check(($fontStats['below_5pt_fields'] ?? 0) === 1, 'Only font sizes below 5 pt should trigger the warning threshold.');
$check(abs((float) ($fontStats['minimum_font_pt'] ?? 0) - 4.99) < 0.0001, 'The smallest fitted font size should be retained.');

// Text that cannot fit even at the minimum font size must never be drawn or
// clipped; it is recorded as an unfit field so the PDF submission can be stopped.
$pdf->writtenText = [];
$GLOBALS['skade_pdf_text_fit_stats'] = ['below_5pt_fields' => 0, 'minimum_font_pt' => null, 'unfit_fields' => 0];
skade_pdf_write_line($pdf, str_repeat('W', 1200), 100.0, 200.0, 0.1, 40.0, 8.0);
skade_pdf_write_multiline($pdf, str_repeat('W', 6000), 100.0, 200.0, 7.0, 40.0, 8.0, 4.0);
$unfitStats = $GLOBALS['skade_pdf_text_fit_stats'];
$check($pdf->writtenText === [], 'Text too wide to fit must not be emitted partially or outside the field.');
$check(($unfitStats['unfit_fields'] ?? 0) === 2, 'Both unfit fields must be reported for a hard pre-save error.');

// Exercise the complete PDF generator: unfit input must fail before a PDF is saved.
$projectRoot = dirname(__DIR__, 2);
$templatePath = $projectRoot . '/pdf/Bilrutan Skadeblankett.pdf';
$outputPath = $projectRoot . '/pdf/.pdf-regression-' . bin2hex(random_bytes(6)) . '.pdf';
$generatorResult = create_skadeblankett_pdf_php(['notes' => str_repeat('W', 6000)], $templatePath, $outputPath, 'pdf');
$check(is_array($generatorResult) && ($generatorResult[0] ?? true) === false && ($generatorResult[2] ?? '') === 'skade.pdf_text_unfit', 'The PDF generator must refuse a field that cannot fit without clipping.');
$check(!file_exists($outputPath), 'An unfit submission must not leave a partial or misleading PDF.');

// A field that fits only below 5 pt should return warning metadata to the
// submission preflight, and the temporary test PDF must be removed afterwards.
$warningPath = $projectRoot . '/pdf/.pdf-regression-warning-' . bin2hex(random_bytes(6)) . '.pdf';
try {
    $warningResult = create_skadeblankett_pdf_php(['regnr' => str_repeat('W', 50)], $templatePath, $warningPath, 'pdf');
    $warningMeta = is_array($warningResult[2] ?? null) ? $warningResult[2] : [];
    $check(is_array($warningResult) && ($warningResult[0] ?? false) === true, 'Fitting small text should still produce a preview PDF.');
    $check((int) ($warningMeta['below_5pt_fields'] ?? 0) > 0, 'The PDF preflight must report fields rendered below 5 pt.');
    $check((float) ($warningMeta['minimum_font_pt'] ?? 5.0) < 5.0, 'The PDF preflight must report the actual smallest font size.');
} finally {
    if (is_file($warningPath)) {
        @unlink($warningPath);
    }
}

// Exercise the real FPDF image placement with a synthetic, transparent blue PNG.
// Decompress the content stream and check the actual PDF transform, not only the helper.
$syntheticSignature = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAFoAAAAoCAYAAAB+Qu3IAAABnUlEQVR4nO2ZzW4DIQyEaVUpz5drHzDXPl9P6QmJEjBjbLNkdz4pUiKxmJ0M4/1JiRBCCLk4H5rBt/vjmb///nyrjr06n0cv4CrAQpduJnqmHU3hdTA6FvGFDFrt3jM2XbWjz3LiM2QDzBjPFB2agrf745k/mjl36QW1yNp1DYU+4zbW0hMVMU5mSTNc5UrL1vaoKzEltNXZkkNaNZATsW7t0bzlulrnP3K3KDQSG9rMfSektUuCt8af4joa2SEec7aERd29jdCt3aONj9G8FqS4bLm7/j0tNJLTtXgewkk10HVFzdkySKYrtOayzjOHNUL16tZzaK/30bFI7cwW0TFqOsi4eqymhjTG694hTOjoG53R9tbWjBQ5pY7QqEjeeYjOh25vS4R4m8PN0eVJSOLNNMTRMdZcj2ioNVtktIWRKCsbucSL0Ec9RIqs1YuQ6FwuMTtaykHPRaN3ZSgrRU7JOTpmm9QKrBFj5Z/Qu8dGeXc5s74jn6eHNcOVmbvbfKGUr6p2fzR6xAsCt3+Sr7xk3v46mhBCCCGElPwB+nk8GNooPgkAAAAASUVORK5CYII=', true);
$signedPath = $projectRoot . '/pdf/.pdf-regression-signature-' . bin2hex(random_bytes(6)) . '.pdf';
try {
    $signedResult = create_skadeblankett_pdf_php(['signature_png_bytes' => $syntheticSignature], $templatePath, $signedPath, 'pdf');
    $check(($signedResult[0] ?? false) === true && is_file($signedPath), 'A real PDF must be generated with the test signature.');
    $content = '';
    $pdfBytes = is_file($signedPath) ? file_get_contents($signedPath) : '';
    if (is_string($pdfBytes) && preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdfBytes, $streams)) {
        foreach ($streams[1] as $stream) {
            $decoded = @gzuncompress($stream);
            if (is_string($decoded) && str_contains($decoded, '/I2 Do')) { $content = $decoded; break; }
        }
    }
    $matched = preg_match('/q ([0-9.]+) 0 0 ([0-9.]+) ([0-9.]+) ([0-9.]+) cm \/I2 Do Q/', $content, $transform);
    $check($matched === 1, 'The generated PDF must contain the signature image transform.');
    if ($matched === 1) {
        $imageWidth = (float) $transform[1]; $imageHeight = (float) $transform[2];
        $imageX = (float) $transform[3]; $imageTop = 841.89 - (float) $transform[4] - $imageHeight;
        $check(abs($imageWidth / $imageHeight - 90 / 40) < 0.005, 'The actual PDF transform preserves the signature proportions.');
        $check($imageX >= 317.0 && $imageX + $imageWidth <= 317.0 + 203.1 && $imageTop >= 471.8 + 2.48 && $imageTop + $imageHeight <= 471.8 + 21.9 - 2.48, 'The actual PDF transform leaves vertical space inside the box.');
    }
} finally {
    if (is_file($signedPath)) @unlink($signedPath);
}

unset($GLOBALS['skade_pdf_text_fit_stats']);

if ($failures !== []) {
    fwrite(STDERR, "FAIL: " . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}

echo "PASS: {$checks} skadeblankett PDF regression checks.\n";
