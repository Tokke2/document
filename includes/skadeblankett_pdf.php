<?php
declare(strict_types=1);
/**
 * Creates a filled copy of Bilrutan's original skadeblankett.
 * The source PDF is kept as an interactive, fillable PDF for preview/download;
 * a 300 dpi render of that same PDF is used as the dependable FPDF background
 * so every printed box/label is retained even when PDF annotations are present.
 */

function skade_is_app_file(mixed $path, array $allowedFolders = ['pdf', 'png']): bool
{
    if (!is_string($path) || $path === '' || is_link($path)) {
        return false;
    }
    $root = realpath(dirname(__DIR__));
    $real = realpath($path);
    if ($root === false || $real === false || !is_file($real)) {
        return false;
    }
    foreach ($allowedFolders as $folder) {
        $folderPath = $root . DIRECTORY_SEPARATOR . $folder;
        if (is_link($folderPath)) {
            continue;
        }
        $allowed = realpath($folderPath);
        if ($allowed !== false && strpos($real, rtrim($allowed, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0) {
            return true;
        }
    }
    return false;
}

function skade_find_png_template(mixed $passed = ''): string
{
    $root = realpath(dirname(__DIR__));
    if ($root === false) {
        return '';
    }
    $candidates = [$root . '/png/blankett.png', $root . '/png/Bilrutan_Skadeblankett.png'];
    if (is_string($passed) && $passed !== '' && skade_is_app_file($passed, ['png'])) {
        array_unshift($candidates, $passed);
    }
    foreach (array_unique($candidates) as $candidate) {
        if (skade_is_app_file($candidate, ['png']) && @filesize($candidate) > 100000) {
            return $candidate;
        }
    }
    return '';
}

function skade_pdf_encoding(string $value): string
{
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $value);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
        if (is_string($converted)) {
            return $converted;
        }
    }
    return strtr($value, [
        'Å' => 'A', 'Ä' => 'A', 'Ö' => 'O', 'å' => 'a', 'ä' => 'a', 'ö' => 'o',
        'É' => 'E', 'é' => 'e', 'Ü' => 'U', 'ü' => 'u', '–' => '-', '—' => '-',
    ]);
}

function skade_pdf_mm(float $points): float
{
    return $points * 25.4 / 72;
}

function skade_pdf_track_font_size(float $size, ?string $fieldLabel = null): void
{
    $stats = $GLOBALS['skade_pdf_text_fit_stats'] ?? null;
    if (!is_array($stats) || $size >= 5.0) {
        return;
    }
    $stats['below_5pt_fields'] = max(0, (int) ($stats['below_5pt_fields'] ?? 0)) + 1;
    $currentMinimum = $stats['minimum_font_pt'] ?? null;
    $stats['minimum_font_pt'] = is_numeric($currentMinimum) ? min((float) $currentMinimum, $size) : $size;
    if (is_string($fieldLabel) && $fieldLabel !== '') {
        $labels = is_array($stats['below_5pt_labels'] ?? null) ? $stats['below_5pt_labels'] : [];
        if (!in_array($fieldLabel, $labels, true) && count($labels) < 20) {
            $labels[] = $fieldLabel;
        }
        $stats['below_5pt_labels'] = $labels;
    }
    $GLOBALS['skade_pdf_text_fit_stats'] = $stats;
}

function skade_pdf_mark_text_unfit(?string $fieldLabel = null): void
{
    $stats = $GLOBALS['skade_pdf_text_fit_stats'] ?? null;
    if (!is_array($stats)) {
        return;
    }
    $stats['unfit_fields'] = max(0, (int) ($stats['unfit_fields'] ?? 0)) + 1;
    if (is_string($fieldLabel) && $fieldLabel !== '') {
        $labels = is_array($stats['unfit_labels'] ?? null) ? $stats['unfit_labels'] : [];
        if (!in_array($fieldLabel, $labels, true) && count($labels) < 20) {
            $labels[] = $fieldLabel;
        }
        $stats['unfit_labels'] = $labels;
    }
    $GLOBALS['skade_pdf_text_fit_stats'] = $stats;
}

function skade_pdf_write_line(FPDF $pdf, mixed $value, float $xPt, float $topPt, float $widthPt, float $heightPt, float $fontSize = 8.5, ?string $fieldLabel = null): void
{
    $text = skade_pdf_encoding((string) $value);
    $text = trim(str_replace("\n", ' ', $text));
    if ($text === '') {
        return;
    }
    $width = max(2.0, skade_pdf_mm($widthPt) - 2.0);
    $boxHeightMm = max(1.2, skade_pdf_mm($heightPt));
    $verticalPaddingMm = 0.1;
    $availableHeightMm = max(0.1, $boxHeightMm - ($verticalPaddingMm * 2.0));
    $maxSizeByHeight = ($availableHeightMm * 72.0 / 25.4) * 0.82;
    $size = max(0.1, min($fontSize, $maxSizeByHeight));
    $pdf->SetFont('Arial', '', $size);
    $textWidth = $pdf->GetStringWidth($text);
    if ($textWidth > $width && $textWidth > 0.0) {
        $size = max(0.1, $size * ($width / $textWidth) * 0.98);
        $pdf->SetFont('Arial', '', $size);
        while ($pdf->GetStringWidth($text) > $width && $size > 0.1) {
            $size = max(0.1, $size * 0.9);
            $pdf->SetFont('Arial', '', $size);
        }
    }
    if ($pdf->GetStringWidth($text) > $width + 0.001) {
        skade_pdf_mark_text_unfit($fieldLabel);
        return;
    }
    skade_pdf_track_font_size($size, $fieldLabel);
    $x = skade_pdf_mm($xPt) + 1.0;
    $fontHeightMm = skade_pdf_mm($size);
    $lineBoxHeightMm = min($availableHeightMm, $fontHeightMm);
    $baselineOffsetMm = max(0.0, ($availableHeightMm - $lineBoxHeightMm) / 2.0) + ($lineBoxHeightMm * 0.78);
    $y = skade_pdf_mm($topPt) + $verticalPaddingMm + $baselineOffsetMm;
    $pdf->Text($x, $y, $text);
}

function skade_pdf_wrap_lines(FPDF $pdf, string $text, float $width, int $maxLines, ?bool &$complete = null): array
{
    $text = skade_pdf_encoding($text);
    $lines = [];
    $oversizedWord = false;
    foreach (explode("\n", $text) as $paragraph) {
        $words = preg_split('/\s+/', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY);
        $line = '';
        foreach ($words ?: [] as $word) {
            if ($pdf->GetStringWidth($word) > $width) {
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }
                $lines[] = $word;
                $oversizedWord = true;
                continue;
            }
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if ($line !== '' && $pdf->GetStringWidth($candidate) > $width) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
    }
    $complete = !$oversizedWord && count($lines) <= $maxLines;
    return $lines;
}

function skade_pdf_write_multiline(FPDF $pdf, mixed $value, float $xPt, float $topPt, float $widthPt, float $heightPt, float $fontSize = 8.5, float $lineHeight = 4.1, ?string $fieldLabel = null): void
{
    $text = trim((string) $value);
    if ($text === '') {
        return;
    }
    $width = max(5.0, skade_pdf_mm($widthPt) - 3.0);
    $boxHeightMm = max(1.2, skade_pdf_mm($heightPt));
    $verticalPaddingMm = 0.1;
    $height = max(0.1, $boxHeightMm - ($verticalPaddingMm * 2.0));
    $maxSizeByHeight = ($height * 72.0 / 25.4) * 0.82;
    $minimumSize = 0.1;
    $size = max($minimumSize, min($fontSize, $maxSizeByHeight));
    $lines = [];
    $usedLineHeight = $lineHeight;
    $complete = false;
    while ($size >= $minimumSize) {
        $usedLineHeight = max(0.15, min($lineHeight, $size * 0.47, $height));
        $maxLines = max(1, (int) floor($height / $usedLineHeight));
        $pdf->SetFont('Arial', '', $size);
        $complete = false;
        $lines = skade_pdf_wrap_lines($pdf, $text, $width, $maxLines, $complete);
        if ($complete) {
            break;
        }
        if ($size <= $minimumSize) {
            break;
        }
        $size = max($minimumSize, $size - 0.5);
    }
    if (!$complete) {
        $flattenedText = trim((string) preg_replace('/[\\r\\n]+/', ' ', $text));
        if ($flattenedText !== '' && $flattenedText !== $text) {
            $text = $flattenedText;
            $size = max($minimumSize, min($fontSize, $maxSizeByHeight));
            while ($size >= $minimumSize) {
                $usedLineHeight = max(0.15, min($lineHeight, $size * 0.47, $height));
                $maxLines = max(1, (int) floor($height / $usedLineHeight));
                $pdf->SetFont('Arial', '', $size);
                $complete = false;
                $lines = skade_pdf_wrap_lines($pdf, $text, $width, $maxLines, $complete);
                if ($complete || $size <= $minimumSize) {
                    break;
                }
                $size = max($minimumSize, $size - 0.5);
            }
        }
    }
    if (!$complete || !$lines) {
        skade_pdf_mark_text_unfit($fieldLabel);
        return;
    }
    skade_pdf_track_font_size($size, $fieldLabel);
    $pdf->SetFont('Arial', '', $size);
    $pdf->SetXY(skade_pdf_mm($xPt) + 1.2, skade_pdf_mm($topPt) + $verticalPaddingMm);
    $pdf->MultiCell($width, $usedLineHeight, implode("\n", $lines), 0, 'L');
}

function skade_pdf_mark(FPDF $pdf, bool $checked, float $xPt, float $topPt, float $boxPt = 14.0): void
{
    if (!$checked) {
        return;
    }
    $x = skade_pdf_mm($xPt) + 0.9;
    $y = skade_pdf_mm($topPt) + 0.9;
    $size = max(3.0, skade_pdf_mm($boxPt) - 1.8);
    $pdf->SetDrawColor(19, 72, 90);
    $pdf->SetLineWidth(0.65);
    $pdf->Line($x, $y + $size * 0.55, $x + $size * 0.35, $y + $size * 0.9);
    $pdf->Line($x + $size * 0.35, $y + $size * 0.9, $x + $size, $y + $size * 0.12);
}

function skade_pdf_format_date(mixed $value): string
{
    if (!is_string($value) && !is_numeric($value)) {
        return '';
    }
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    if (preg_match('~^([0-9]{4})[-/.]([0-9]{1,2})[-/.]([0-9]{1,2})$~D', $value, $parts)) {
        $year = (int) $parts[1];
        $month = (int) $parts[2];
        $day = (int) $parts[3];
        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : $value;
    }
    if (preg_match('~^([0-9]{1,2})[./-]([0-9]{1,2})[./-]([0-9]{4})$~D', $value, $parts)) {
        $day = (int) $parts[1];
        $month = (int) $parts[2];
        $year = (int) $parts[3];
        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : $value;
    }
    return $value;
}

function skade_pdf_format_datetime(mixed $value): string
{
    if (!is_string($value) && !is_numeric($value)) {
        return '';
    }
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    if (preg_match('~^([0-9]{4})[-/.]([0-9]{1,2})[-/.]([0-9]{1,2})[T ]([0-9]{1,2}):([0-9]{2})(?::[0-9]{2})?$~D', $value, $parts)) {
        $year = (int) $parts[1];
        $month = (int) $parts[2];
        $day = (int) $parts[3];
        $hour = (int) $parts[4];
        $minute = (int) $parts[5];
        if (checkdate($month, $day, $year) && $hour <= 23 && $minute <= 59) {
            return sprintf('%04d-%02d-%02d %02d:%02d', $year, $month, $day, $hour, $minute);
        }
    }
    if (preg_match('~^([0-9]{1,2})[./-]([0-9]{1,2})[./-]([0-9]{4})[T ]([0-9]{1,2}):([0-9]{2})(?::[0-9]{2})?$~D', $value, $parts)) {
        $day = (int) $parts[1];
        $month = (int) $parts[2];
        $year = (int) $parts[3];
        $hour = (int) $parts[4];
        $minute = (int) $parts[5];
        if (checkdate($month, $day, $year) && $hour <= 23 && $minute <= 59) {
            return sprintf('%04d-%02d-%02d %02d:%02d', $year, $month, $day, $hour, $minute);
        }
    }
    return strpos($value, 'T') !== false ? str_replace('T', ' ', $value) : skade_pdf_format_date($value);
}

function skade_pdf_parse_number(mixed $value): ?float
{
    if (!is_string($value) && !is_numeric($value)) {
        return null;
    }
    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }
    $text = str_replace([' ', "\t", "\n", "\r"], '', $text);
    if (preg_match('~^([+-]?[0-9]{1,3}(?:[.][0-9]{3})+),([0-9]+)$~D', $text, $parts)) {
        $text = str_replace('.', '', $parts[1]) . '.' . $parts[2];
    } else {
        $text = str_replace(',', '.', $text);
    }
    if (!preg_match('~^[+-]?(?:[0-9]+(?:[.][0-9]*)?|[.][0-9]+)$~D', $text)) {
        return null;
    }
    return (float) $text;
}

function skade_pdf_format_number(mixed $value, int $decimals = 0, bool $trimTrailingZeros = false): string
{
    if (!is_string($value) && !is_numeric($value)) {
        return '';
    }
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }
    $number = skade_pdf_parse_number($text);
    if ($number === null) {
        return $text;
    }
    $decimals = max(0, min(4, $decimals));
    $formatted = number_format($number, $decimals, ',', ' ');
    if ($trimTrailingZeros && $decimals > 0) {
        $formatted = rtrim(rtrim($formatted, '0'), ',');
    }
    return $formatted;
}

function skade_pdf_amount(mixed $value): string
{
    return skade_pdf_format_number($value, 2);
}

/**
 * Calculate the exact proportional signature placement in the original skadeblankett box.
 * `mm_per_px` lets regression/manual calibration tools report PNG crop padding at PDF size.
 *
 * @return array{x_mm:float,top_mm:float,width_mm:float,height_mm:float,mm_per_px:float,left_overrun_mm:float,right_overrun_mm:float,top_overrun_mm:float,bottom_overrun_mm:float}|null
 */
function skade_pdf_signature_placement(int $imageWidthPx, int $imageHeightPx): ?array
{
    if ($imageWidthPx < 1 || $imageHeightPx < 1) {
        return null;
    }
    $aspect = $imageWidthPx / $imageHeightPx;
    $boxXPt = 317.0;
    $boxTopPt = 471.8;
    $boxWidthPt = 203.1;
    $boxHeightPt = 21.9;
    // Leave the original box border visible, even for tall or very wide signatures,
    // while letting flat signatures use nearly the full width of the long box.
    $preferredWidthPt = $boxWidthPt - 4.0;
    $preferredHeightPt = $boxHeightPt - 5.0;
    $imageWidthPt = min($preferredWidthPt, $preferredHeightPt * $aspect);
    $imageHeightPt = $imageWidthPt / $aspect;
    $imageXPt = $boxXPt + (($boxWidthPt - $imageWidthPt) / 2);
    $imageTopPt = $boxTopPt + (($boxHeightPt - $imageHeightPt) / 2);
    return [
        'x_mm' => skade_pdf_mm($imageXPt),
        'top_mm' => skade_pdf_mm($imageTopPt),
        'width_mm' => skade_pdf_mm($imageWidthPt),
        'height_mm' => skade_pdf_mm($imageHeightPt),
        'mm_per_px' => skade_pdf_mm($imageWidthPt) / $imageWidthPx,
        'left_overrun_mm' => max(0.0, skade_pdf_mm($boxXPt - $imageXPt)),
        'right_overrun_mm' => max(0.0, skade_pdf_mm(($imageXPt + $imageWidthPt) - ($boxXPt + $boxWidthPt))),
        'top_overrun_mm' => max(0.0, skade_pdf_mm($boxTopPt - $imageTopPt)),
        'bottom_overrun_mm' => max(0.0, skade_pdf_mm(($imageTopPt + $imageHeightPt) - ($boxTopPt + $boxHeightPt))),
    ];
}

/**
 * Validates an optional customer photo supplied as a data URI and returns its raw bytes.
 * Accepts JPEG/PNG only; full value ≤ 550000 chars, payload strictly base64 (%4 == 0),
 * decoded size 500–410000 bytes, dimensions 200–4000 px per side and ≤ 8 megapixels,
 * and the declared prefix must match what getimagesizefromstring reports.
 * $failureCode receives skade.photo_invalid, skade.photo_processor_unavailable or
 * skade.photo_image_invalid when null is returned.
 *
 * @return array{bytes: string, mime: string, width: int, height: int}|null
 */
function skade_photo_data_uri_bytes(string $value, ?string &$failureCode = null): ?array
{
    $failureCode = 'skade.photo_invalid';
    $prefix = null;
    $mime = '';
    foreach (['data:image/jpeg;base64,' => 'image/jpeg', 'data:image/png;base64,' => 'image/png'] as $candidatePrefix => $candidateMime) {
        if (str_starts_with($value, $candidatePrefix)) {
            $prefix = $candidatePrefix;
            $mime = $candidateMime;
            break;
        }
    }
    if ($prefix === null || strlen($value) > 550000) {
        return null;
    }
    $payload = substr($value, strlen($prefix));
    if ($payload === '' || strlen($payload) % 4 !== 0) {
        return null;
    }
    $bytes = base64_decode($payload, true);
    if (!is_string($bytes) || strlen($bytes) < 500 || strlen($bytes) > 410000) {
        return null;
    }
    if (!function_exists('getimagesizefromstring')) {
        $failureCode = 'skade.photo_processor_unavailable';
        return null;
    }
    $info = @getimagesizefromstring($bytes);
    if (!is_array($info) || ($info['mime'] ?? '') !== $mime) {
        $failureCode = 'skade.photo_image_invalid';
        return null;
    }
    $width = (int) $info[0];
    $height = (int) $info[1];
    if ($width < 200 || $height < 200 || $width > 4000 || $height > 4000 || $width * $height > 8000000) {
        $failureCode = 'skade.photo_image_invalid';
        return null;
    }
    return ['bytes' => $bytes, 'mime' => $mime, 'width' => $width, 'height' => $height];
}

function create_skadeblankett_pdf_php(array $data, string $templatePath = '', string $outputPath = '', string $templateType = ''): array
{
    skade_timer_start('total');
    $appRoot = realpath(dirname(__DIR__));
    $pdfRoot = $appRoot !== false ? realpath($appRoot . '/pdf') : false;
    $outputParent = is_string($outputPath) && $outputPath !== '' ? realpath(dirname($outputPath)) : false;
    if (
        $appRoot === false || $pdfRoot === false || $outputParent === false ||
        is_link($outputPath) || strtolower(pathinfo($outputPath, PATHINFO_EXTENSION)) !== 'pdf' ||
        ($outputParent !== $pdfRoot && strpos($outputParent, rtrim($pdfRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0)
    ) {
        skade_log_error('PDF output path rejected');
        return [false, 'Invalid output path', 'skade.pdf_output_path_invalid', null, []];
    }

    if (!skade_is_app_file($templatePath, ['pdf', 'png'])) {
        skade_log_error('No approved skadeblankett template was selected');
        return [false, 'Template unavailable', 'skade.template_missing', null, []];
    }
    $pngTemplate = skade_find_png_template();
    if ($pngTemplate === '' || !is_file($pngTemplate)) {
        skade_log_error('No rendered original-PDF template is available');
        return [false, 'Rendered template unavailable', 'skade.template_missing', null, []];
    }

    $fpdfPath = __DIR__ . '/fpdf/fpdf.php';
    if (!is_file($fpdfPath)) {
        skade_log_error('FPDF library unavailable');
        return [false, 'PDF library unavailable', 'skade.pdf_library_missing', null, []];
    }
    require_once $fpdfPath;

    $GLOBALS['skade_pdf_text_fit_stats'] = [
        'below_5pt_fields' => 0,
        'minimum_font_pt' => null,
        'unfit_fields' => 0,
    ];
    $failureCode = 'skade.pdf_generation_failed';
    $failureFields = [];
    try {
        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->AddPage();
        $pdf->Image($pngTemplate, 0, 0, 210, 297, 'PNG');
        $pdf->SetTextColor(20, 34, 46);
        $pdf->SetFont('Arial', '', 8.5);

        // Appointment line and the top form fields.
        skade_pdf_write_line($pdf, skade_pdf_format_datetime($data['inbokad'] ?? ''), 85.9, 18.1, 64.6, 12.3, 8.5, 'Inbokad tid');
        // Center the company tick inside the smaller square in the source form.
        skade_pdf_mark($pdf, !empty($data['company']), 76, 85, 14);
        skade_pdf_write_multiline($pdf, $data['namn_adress'] ?? '', 85.5, 115.8, 199.5, 80.3, 8.5, 4.0, 'Namn och adress');
        skade_pdf_write_line($pdf, $data['person_orgnr'] ?? '', 86.5, 217.8, 199.5, 18.1, 8.2, 'Person- eller organisationsnummer');
        skade_pdf_write_line($pdf, $data['telefon'] ?? '', 85.5, 257.8, 199.5, 19.0, 8.2, 'Telefon');
        skade_pdf_write_multiline($pdf, $data['epost'] ?? '', 75.9, 297.8, 224.0, 39.0, 8.0, 3.6, 'E-post');
        skade_pdf_write_line($pdf, $data['regnr'] ?? '', 317.0, 97.8, 203.1, 19.0, 10.0, 'Registreringsnummer');
        skade_pdf_write_line($pdf, $data['modell'] ?? '', 318.3, 140.0, 202.7, 18.7, 8.5, 'Modell');
        skade_pdf_write_line($pdf, skade_pdf_format_number($data['matarstallning'] ?? '', 0), 309.9, 176.8, 211.1, 20.0, 8.5, 'Mätarställning');
        skade_pdf_write_line($pdf, $data['forsakringsbolag'] ?? '', 318.9, 217.8, 201.1, 19.0, 8.5, 'Försäkringsbolag');
        skade_pdf_write_line($pdf, skade_pdf_format_date($data['skadedatum'] ?? ''), 318.6, 259.8, 201.4, 19.0, 8.5, 'Skadedatum');
        skade_pdf_write_line($pdf, $data['skadeplats'] ?? '', 319.3, 298.8, 200.6, 18.8, 8.5, 'Skadeplats');
        skade_pdf_write_line($pdf, $data['skadenummer'] ?? '', 309.9, 337.9, 211.1, 20.0, 8.5, 'Skadenummer');
        skade_pdf_mark($pdf, !empty($data['nya_torkarblad_bestalls']), 315.0, 365.0, 14);

        // Keep an explicit date if supplied; otherwise date the customer's drawn signature
        // using Stockholm local time. The signature is centered inside the original box below.
        $signatureBytes = $data['signature_png_bytes'] ?? '';
        $signatureDate = skade_pdf_format_date($data['signature_date'] ?? '');
        if ($signatureDate === '' && is_string($signatureBytes) && $signatureBytes !== '') {
            $signatureDate = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
        }
        skade_pdf_write_line($pdf, $signatureDate, 88.0, 471.8, 201.9, 21.9, 8.5, 'Påskrivet datum');
        if (is_string($signatureBytes) && $signatureBytes !== '') {
            $storageDirectory = $appRoot . '/storage';
            $storageGuard = $storageDirectory . '/.htaccess';
            $tempDirectory = $storageDirectory . '/tmp';
            if (
                is_link($storageDirectory) || is_link($storageGuard) || !is_file($storageGuard) ||
                is_link($tempDirectory) ||
                (!is_dir($tempDirectory) && !@mkdir($tempDirectory, 0700, true))
            ) {
                $failureCode = 'skade.pdf_signature_temp_unavailable';
                $failureFields = ['signature_png'];
                throw new RuntimeException('Private signature temp directory unavailable');
            }
            $realStorageDirectory = realpath($storageDirectory);
            $realTempDirectory = realpath($tempDirectory);
            if (
                $realStorageDirectory === false || $realTempDirectory === false ||
                dirname($realTempDirectory) !== $realStorageDirectory || !is_writable($realTempDirectory)
            ) {
                $failureCode = 'skade.pdf_signature_temp_unavailable';
                $failureFields = ['signature_png'];
                throw new RuntimeException('Private signature temp directory unavailable');
            }
            @chmod($realTempDirectory, 0700);
            $signatureTemp = @tempnam($realTempDirectory, 'skade-sign-');
            if (!is_string($signatureTemp) || $signatureTemp === '' || is_link($signatureTemp)) {
                $failureCode = 'skade.pdf_signature_temp_unavailable';
                $failureFields = ['signature_png'];
                throw new RuntimeException('Signature temp file unavailable');
            }
            try {
                @chmod($signatureTemp, 0600);
                $written = @file_put_contents($signatureTemp, $signatureBytes, LOCK_EX);
                if ($written !== strlen($signatureBytes)) {
                    $failureCode = 'skade.pdf_signature_write_failed';
                    $failureFields = ['signature_png'];
                    throw new RuntimeException('Signature temp file write failed');
                }
                $signatureInfo = @getimagesize($signatureTemp);
                if (!is_array($signatureInfo) || ($signatureInfo['mime'] ?? '') !== 'image/png' || (int) ($signatureInfo[0] ?? 0) < 25 || (int) ($signatureInfo[1] ?? 0) < 20) {
                    $failureCode = 'skade.pdf_signature_invalid';
                    $failureFields = ['signature_png'];
                    throw new RuntimeException('Signature image rejected');
                }
                // Preserve the PNG's aspect ratio and keep crop air compact in physical PDF units.
                // The helper also exposes mm/px for regression measurements and leaves breathing room around the ink.
                $placement = skade_pdf_signature_placement((int) $signatureInfo[0], (int) $signatureInfo[1]);
                if ($placement === null) {
                    $failureCode = 'skade.pdf_signature_invalid';
                    $failureFields = ['signature_png'];
                    throw new RuntimeException('Signature placement could not be calculated');
                }
                $pdf->Image($signatureTemp, $placement['x_mm'], $placement['top_mm'], $placement['width_mm'], $placement['height_mm'], 'PNG');
            } finally {
                @unlink($signatureTemp);
            }
        }

        $locations = is_array($data['glass_locations'] ?? null) ? $data['glass_locations'] : [];
        skade_pdf_mark($pdf, in_array('windshield', $locations, true), 86, 532, 14);
        skade_pdf_mark($pdf, in_array('side', $locations, true), 86, 553, 14);
        skade_pdf_mark($pdf, in_array('rear', $locations, true), 86, 574, 14);

        if (($data['damage_cause'] ?? '') === 'stone_chip') {
            skade_pdf_mark($pdf, true, 307, 524, 14);
        } elseif (($data['damage_cause'] ?? '') === 'other') {
            skade_pdf_write_line($pdf, $data['other_cause'] ?? '', 392, 529, 120, 13, 7.5, 'Annan orsak');
        }

        $actions = is_array($data['actions'] ?? null) ? $data['actions'] : [];
        skade_pdf_mark($pdf, in_array('replacement', $actions, true), 307, 560, 14);
        skade_pdf_mark($pdf, in_array('repair', $actions, true), 307, 580, 14);
        skade_pdf_write_line($pdf, skade_pdf_format_number($data['mount_time'] ?? '', 2, true), 458.3, 544.1, 58.4, 14, 7.5, 'Monteringstid');
        skade_pdf_write_line($pdf, skade_pdf_amount($data['glass_price'] ?? ''), 431.5, 560.9, 58.4, 14, 7.5, 'Glaspris');
        skade_pdf_write_line($pdf, skade_pdf_format_number($data['stone_count'] ?? '', 0), 486.2, 576.5, 20.5, 14, 7.5, 'Antal stenskott');
        skade_pdf_write_multiline($pdf, $data['notes'] ?? '', 78, 612, 215, 65, 7.5, 3.7, 'Anteckningar');

        $equipment = is_array($data['equipment'] ?? null) ? $data['equipment'] : [];
        $equipmentBoxes = [
            'head_up' => [414, 600], 'rain_sensor' => [306, 615], 'mirror_mount' => [414, 617],
            'camera' => [306, 632], 'electric' => [414, 634], 'static_calibration' => [306, 650],
            'im_sensor' => [414, 650], 'dynamic_calibration' => [306, 668],
        ];
        foreach ($equipmentBoxes as $key => $coordinates) {
            skade_pdf_mark($pdf, in_array($key, $equipment, true), $coordinates[0], $coordinates[1], 14);
        }

        skade_pdf_mark($pdf, !empty($data['wiper_service']), 96, 706, 14);
        skade_pdf_write_line($pdf, !empty($data['wiper_service']) ? skade_pdf_amount($data['wiper_price'] ?? '') : '', 207.1, 706.3, 28.1, 14, 7.0, 'Torkarbladspris');
        skade_pdf_write_line($pdf, skade_pdf_format_number($data['washer_liters'] ?? '', 2, true), 205.5, 730.5, 39.6, 14, 7.0, 'Spolvätska i liter');
        skade_pdf_mark($pdf, !empty($data['solar_film']), 96, 754, 14);
        skade_pdf_write_line($pdf, !empty($data['solar_film']) ? skade_pdf_amount($data['solar_price'] ?? '') : '', 216.0, 753.7, 28.1, 14, 7.0, 'Solfilmspris');

        $payment = (string) ($data['payment_method'] ?? 'pickup');
        $paymentAmount = skade_pdf_amount($data['payment_amount'] ?? '');
        skade_pdf_mark($pdf, $payment === 'izettle', 307, 700, 14);
        skade_pdf_mark($pdf, $payment === 'invoice', 307, 718, 14);
        skade_pdf_mark($pdf, $payment === 'swish', 307, 734, 14);
        skade_pdf_mark($pdf, $payment === 'cash', 307, 751, 14);
        if ($payment === 'izettle') {
            skade_pdf_write_line($pdf, $paymentAmount, 397.3, 700.1, 44.5, 13, 7.0, 'Betalat belopp');
        } elseif ($payment === 'swish') {
            skade_pdf_write_line($pdf, $paymentAmount, 393.7, 732.5, 55.2, 13, 7.0, 'Betalat belopp');
        } elseif ($payment === 'cash') {
            skade_pdf_write_line($pdf, $paymentAmount, 401.5, 748.9, 55.0, 13, 7.0, 'Betalat belopp');
        }
        skade_pdf_write_line($pdf, skade_pdf_format_date($data['replacement_date'] ?? ''), 211.0, 796.0, 168.0, 20.0, 8.0, 'Bytesdatum');

        $fitStats = $GLOBALS['skade_pdf_text_fit_stats'] ?? [];
        if ((int) ($fitStats['unfit_fields'] ?? 0) > 0) {
            unset($GLOBALS['skade_pdf_text_fit_stats']);
            skade_timer_end('total');
            return [false, 'One or more text fields do not fit without clipping', 'skade.pdf_text_unfit', null, []];
        }

        // Optional customer photos ride on a dedicated page after the text-fit gate.
        // $clean carries validated raw bytes in photo_*_bytes; captions use plain
        // Cell/SetFont (never skade_pdf_write_* — those feed the gate).
        $photoSpecs = [
            ['key' => 'photo_damage', 'caption' => 'Bild på skadan'],
            ['key' => 'photo_front', 'caption' => 'Bild på fordonets front – regnr syns'],
        ];
        $embeddedPhotos = [];
        foreach ($photoSpecs as $photoSpec) {
            $photoBytes = $data[$photoSpec['key'] . '_bytes'] ?? '';
            if (!is_string($photoBytes) || $photoBytes === '') {
                continue;
            }
            $photoInfo = null;
            if (function_exists('getimagesizefromstring')) {
                $photoSize = @getimagesizefromstring($photoBytes);
                if (is_array($photoSize) && in_array($photoSize['mime'] ?? '', ['image/jpeg', 'image/png'], true)) {
                    $photoWidth = (int) $photoSize[0];
                    $photoHeight = (int) $photoSize[1];
                    if ($photoWidth >= 200 && $photoHeight >= 200 && $photoWidth <= 4000 && $photoHeight <= 4000 && $photoWidth * $photoHeight <= 8000000) {
                        $photoInfo = ['bytes' => $photoBytes, 'mime' => $photoSize['mime'], 'width' => $photoWidth, 'height' => $photoHeight];
                    }
                }
            }
            if ($photoInfo === null) {
                $failureCode = 'skade.pdf_photo_invalid';
                $failureFields = [$photoSpec['key']];
                throw new RuntimeException('Customer photo rejected by PDF generator');
            }
            $embeddedPhotos[] = ['caption' => $photoSpec['caption'], 'info' => $photoInfo, 'key' => $photoSpec['key']];
        }
        if ($embeddedPhotos !== []) {
            $pdf->AddPage();
            $pdf->SetTextColor(20, 34, 46);
            $pdf->SetFont('Arial', 'B', 14);
            $pdf->SetXY(10, 12);
            $pdf->Cell(190, 8, skade_pdf_encoding('Bilder till skadeblanketten'), 0, 1, 'C');
            $boxWidth = 190.0;
            $slotTop = 28.0;
            $slotBottom = 287.0;
            $slotHeight = ($slotBottom - $slotTop) / count($embeddedPhotos);
            foreach ($embeddedPhotos as $index => $photo) {
                $captionY = $slotTop + $index * $slotHeight;
                $pdf->SetFont('Arial', '', 11);
                $pdf->SetXY(10, $captionY);
                $pdf->Cell(190, 7, skade_pdf_encoding($photo['caption']), 0, 1, 'L');
                $imageTop = $captionY + 9;
                $imageBoxHeight = $slotHeight - 16;
                $pixelWidth = $photo['info']['width'];
                $pixelHeight = $photo['info']['height'];
                $scale = min($boxWidth / $pixelWidth, $imageBoxHeight / $pixelHeight);
                $drawWidth = $pixelWidth * $scale;
                $drawHeight = $pixelHeight * $scale;
                $drawX = 10 + ($boxWidth - $drawWidth) / 2;
                $drawY = $imageTop + max(0.0, ($imageBoxHeight - $drawHeight) / 2);
                $storageDirectory = $appRoot . '/storage';
                $storageGuard = $storageDirectory . '/.htaccess';
                $tempDirectory = $storageDirectory . '/tmp';
                if (
                    is_link($storageDirectory) || is_link($storageGuard) || !is_file($storageGuard) ||
                    is_link($tempDirectory) ||
                    (!is_dir($tempDirectory) && !@mkdir($tempDirectory, 0700, true))
                ) {
                    $failureCode = 'skade.pdf_photo_temp_unavailable';
                    $failureFields = [$photo['key']];
                    throw new RuntimeException('Private photo temp directory unavailable');
                }
                $realStorageDirectory = realpath($storageDirectory);
                $realTempDirectory = realpath($tempDirectory);
                if (
                    $realStorageDirectory === false || $realTempDirectory === false ||
                    dirname($realTempDirectory) !== $realStorageDirectory || !is_writable($realTempDirectory)
                ) {
                    $failureCode = 'skade.pdf_photo_temp_unavailable';
                    $failureFields = [$photo['key']];
                    throw new RuntimeException('Private photo temp directory unavailable');
                }
                @chmod($realTempDirectory, 0700);
                $photoTemp = @tempnam($realTempDirectory, 'skade-photo-');
                if (!is_string($photoTemp) || $photoTemp === '' || is_link($photoTemp)) {
                    $failureCode = 'skade.pdf_photo_temp_unavailable';
                    $failureFields = [$photo['key']];
                    throw new RuntimeException('Photo temp file unavailable');
                }
                try {
                    @chmod($photoTemp, 0600);
                    $written = @file_put_contents($photoTemp, $photo['info']['bytes'], LOCK_EX);
                    if ($written !== strlen($photo['info']['bytes'])) {
                        $failureCode = 'skade.pdf_photo_temp_unavailable';
                        $failureFields = [$photo['key']];
                        throw new RuntimeException('Photo temp file write failed');
                    }
                    $pdf->Image($photoTemp, $drawX, $drawY, $drawWidth, $drawHeight, $photo['info']['mime'] === 'image/png' ? 'PNG' : 'JPEG');
                } finally {
                    @unlink($photoTemp);
                }
            }
        }

        $pdf->Output('F', $outputPath);
        if (!is_file($outputPath) || filesize($outputPath) < 1000) {
            unset($GLOBALS['skade_pdf_text_fit_stats']);
            return [false, 'PDF output was not written', 'skade.pdf_output_not_written', null, []];
        }
        @chmod($outputPath, 0640);
        $fitStats = $GLOBALS['skade_pdf_text_fit_stats'] ?? [];
        unset($GLOBALS['skade_pdf_text_fit_stats']);
        $textFitWarning = (int) ($fitStats['below_5pt_fields'] ?? 0) > 0
            ? [
                'below_5pt_fields' => (int) $fitStats['below_5pt_fields'],
                'minimum_font_pt' => is_numeric($fitStats['minimum_font_pt'] ?? null) ? (float) $fitStats['minimum_font_pt'] : 5.0,
            ]
            : null;
        skade_timer_end('total');
        return [true, '', $textFitWarning];
    } catch (Throwable $e) {
        unset($GLOBALS['skade_pdf_text_fit_stats']);
        skade_log_error('Could not generate filled Bilrutan PDF');
        skade_timer_end('total');
        return [false, 'PDF generation failed', $failureCode, $e, $failureFields];
    }
}
