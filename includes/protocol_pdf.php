<?php
declare(strict_types=1);

/** Convert UTF-8 text to the Windows-1252 encoding used by FPDF's core fonts. */
function portal_protocol_pdf_text(mixed $value): string
{
    $text = is_scalar($value) ? (string) $value : '';
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
    if (function_exists('iconv')) {
        $encoded = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if (is_string($encoded)) {
            return $encoded;
        }
    }
    return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $text) ?? '';
}

function portal_protocol_pdf_section(FPDF $pdf, string $title): void
{
    $pdf->Ln(3);
    $pdf->SetFillColor(239, 243, 246);
    $pdf->SetTextColor(24, 43, 57);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(180, 7, portal_protocol_pdf_text($title), 0, 1, 'L', true);
    $pdf->SetTextColor(28, 41, 50);
}

function portal_protocol_pdf_field(FPDF $pdf, string $label, mixed $value): void
{
    $valueText = trim(is_scalar($value) ? (string) $value : '');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(43, 6, portal_protocol_pdf_text($label), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell(137, 6, portal_protocol_pdf_text($valueText !== '' ? $valueText : '—'), 0, 'L');
}

/**
 * Build a complete service-protocol PDF in memory for the internal mail attachment.
 * The source media paths are revalidated against the private protocol-media directory.
 */
function portal_protocol_generate_pdf_bytes(array $protocol): string|false
{
    $fpdfPath = __DIR__ . '/fpdf/fpdf.php';
    if (!is_file($fpdfPath) || !is_readable($fpdfPath)) {
        return false;
    }
    require_once $fpdfPath;
    if (!class_exists('FPDF', false)) {
        return false;
    }

    $photoAbsolute = '';
    $signatureAbsolute = '';
    $mediaDirectory = function_exists('portal_protocol_media_directory') ? portal_protocol_media_directory() : false;
    if ($mediaDirectory === false) {
        return false;
    }
    foreach ([
        ['field' => 'meter_photo_file', 'target' => 'photo'],
        ['field' => 'signature_file', 'target' => 'signature'],
    ] as $media) {
        $stored = $protocol[$media['field']] ?? '';
        if (!is_string($stored) || $stored === '') {
            continue;
        }
        $safeRelative = portal_protocol_media_file($stored, $mediaDirectory);
        if ($safeRelative === '') {
            return false;
        }
        $absolute = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safeRelative);
        if (!is_file($absolute) || is_link($absolute) || !is_readable($absolute)) {
            return false;
        }
        $imageInfo = @getimagesize($absolute);
        $mime = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : '';
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            return false;
        }
        if ($media['target'] === 'photo') {
            $photoAbsolute = $absolute;
        } else {
            if ($mime !== 'image/png') {
                return false;
            }
            $signatureAbsolute = $absolute;
        }
    }

    try {
        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->SetMargins(15, 14, 15);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->AddPage();
        $pdf->SetTitle(portal_protocol_pdf_text('Serviceprotokoll ' . (string) ($protocol['nummer'] ?? '')));
        $pdf->SetAuthor('Tadega Dokumentportalen');
        $pdf->SetCreator('Tadega Dokumentportalen');

        $pdf->SetTextColor(24, 43, 57);
        $logoPath = __DIR__ . '/../assets/tadega-logo-fpdf.png';
        $logoInfo = !is_link($logoPath) && is_file($logoPath) && is_readable($logoPath) ? @getimagesize($logoPath) : false;
        $logoWidthPx = is_array($logoInfo) ? (int) ($logoInfo[0] ?? 0) : 0;
        $logoHeightPx = is_array($logoInfo) ? (int) ($logoInfo[1] ?? 0) : 0;
        if (
            is_array($logoInfo) && ($logoInfo['mime'] ?? '') === 'image/png' &&
            $logoWidthPx >= 1 && $logoHeightPx >= 1 && $logoWidthPx <= 5000 && $logoHeightPx <= 2000
        ) {
            $logoWidth = 80.0;
            $logoHeight = $logoWidth * ($logoHeightPx / $logoWidthPx);
            $pdf->Image($logoPath, 15, 14, $logoWidth, $logoHeight, 'PNG');
            $pdf->SetFont('Arial', 'B', 15);
            $pdf->SetXY(100, 14 + max(0.0, ($logoHeight - 10.0) / 2));
            $pdf->Cell(95, 10, portal_protocol_pdf_text('Serviceprotokoll'), 0, 0, 'R');
            $pdf->SetY(14 + max($logoHeight, 10.0) + 4);
        } else {
            $pdf->SetFont('Arial', 'B', 18);
            $pdf->Cell(180, 10, portal_protocol_pdf_text('Serviceprotokoll'), 0, 1, 'L');
        }
        $pdf->SetDrawColor(34, 96, 125);
        $pdf->SetLineWidth(0.7);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(4);

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(43, 7, portal_protocol_pdf_text('Protokollnummer'), 0, 0, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(57, 7, portal_protocol_pdf_text((string) ($protocol['nummer'] ?? '')), 0, 0, 'L');
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(25, 7, portal_protocol_pdf_text('Datum'), 0, 0, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(55, 7, portal_protocol_pdf_text((string) ($protocol['date'] ?? '')), 0, 1, 'L');

        portal_protocol_pdf_section($pdf, 'Kund och fordon');
        portal_protocol_pdf_field($pdf, 'Registreringsnummer', (string) ($protocol['reg'] ?? ''));
        portal_protocol_pdf_field($pdf, 'Mätarställning', (string) ($protocol['km'] ?? ''));
        portal_protocol_pdf_field($pdf, 'Kund', (string) ($protocol['customer'] ?? ''));
        portal_protocol_pdf_field($pdf, 'Tekniker', (string) ($protocol['tech'] ?? ''));

        portal_protocol_pdf_section($pdf, 'Utfört arbete');
        $description = trim(is_scalar($protocol['description'] ?? null) ? (string) $protocol['description'] : '');
        $pdf->SetFont('Arial', '', 9);
        $pdf->MultiCell(180, 5, portal_protocol_pdf_text($description !== '' ? $description : 'Inget arbetsmoment angivet.'), 0, 'L');

        portal_protocol_pdf_section($pdf, 'Material och arbetsmoment');
        $items = is_array($protocol['items'] ?? null) ? $protocol['items'] : [];
        $hasItems = false;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim(is_scalar($item['art'] ?? null) ? (string) $item['art'] : '');
            $quantity = trim(is_scalar($item['qty'] ?? null) ? (string) $item['qty'] : '');
            $specification = trim(is_scalar($item['spec'] ?? null) ? (string) $item['spec'] : '');
            if ($name === '' && $quantity === '' && $specification === '') {
                continue;
            }
            $hasItems = true;
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->MultiCell(180, 5, portal_protocol_pdf_text($name !== '' ? $name : 'Material/arbetsmoment'), 0, 'L');
            $pdf->SetFont('Arial', '', 8);
            if ($quantity !== '') {
                $pdf->Cell(180, 4.5, portal_protocol_pdf_text('Antal: ' . $quantity), 0, 1, 'L');
            }
            if ($specification !== '') {
                $pdf->MultiCell(180, 4.5, portal_protocol_pdf_text('Specifikation: ' . $specification), 0, 'L');
            }
            $pdf->SetDrawColor(215, 224, 230);
            $pdf->SetLineWidth(0.2);
            $pdf->Ln(1);
            $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
            $pdf->Ln(2);
        }
        if (!$hasItems) {
            $pdf->SetFont('Arial', '', 9);
            $pdf->Cell(180, 6, portal_protocol_pdf_text('Inga material eller arbetsmoment angivna.'), 0, 1, 'L');
        }

        portal_protocol_pdf_section($pdf, 'Mätarställning – foto');
        if ($photoAbsolute !== '') {
            $photoInfo = @getimagesize($photoAbsolute);
            $pixelWidth = is_array($photoInfo) ? (int) ($photoInfo[0] ?? 0) : 0;
            $pixelHeight = is_array($photoInfo) ? (int) ($photoInfo[1] ?? 0) : 0;
            if ($pixelWidth < 1 || $pixelHeight < 1) {
                return false;
            }
            $ratio = $pixelWidth / $pixelHeight;
            $imageWidth = min(180.0, 82.0 * $ratio);
            $imageHeight = $imageWidth / $ratio;
            if ($imageHeight > 82.0) {
                $imageHeight = 82.0;
                $imageWidth = $imageHeight * $ratio;
            }
            if ($pdf->GetY() + $imageHeight + 5 > 282) {
                $pdf->AddPage();
            }
            $x = 15 + ((180 - $imageWidth) / 2);
            $type = str_ends_with(strtolower($photoAbsolute), '.png') ? 'PNG' : 'JPG';
            $pdf->Image($photoAbsolute, $x, $pdf->GetY(), $imageWidth, $imageHeight, $type);
            $pdf->SetY($pdf->GetY() + $imageHeight + 4);
        } else {
            $pdf->SetFont('Arial', '', 9);
            $pdf->Cell(180, 6, portal_protocol_pdf_text('Inget foto bifogat.'), 0, 1, 'L');
        }

        portal_protocol_pdf_section($pdf, 'Teknikerns signatur');
        if ($signatureAbsolute !== '') {
            $signatureInfo = @getimagesize($signatureAbsolute);
            $signatureWidthPx = is_array($signatureInfo) ? (int) ($signatureInfo[0] ?? 0) : 0;
            $signatureHeightPx = is_array($signatureInfo) ? (int) ($signatureInfo[1] ?? 0) : 0;
            if ($signatureWidthPx < 1 || $signatureHeightPx < 1) {
                return false;
            }
            $signatureWidth = 85.0;
            $signatureHeight = $signatureWidth * ($signatureHeightPx / $signatureWidthPx);
            if ($pdf->GetY() + $signatureHeight + 11 > 282) {
                $pdf->AddPage();
            }
            $pdf->Image($signatureAbsolute, 15, $pdf->GetY(), $signatureWidth, $signatureHeight, 'PNG');
            $pdf->SetY($pdf->GetY() + $signatureHeight + 1);
            $pdf->SetDrawColor(70, 82, 90);
            $pdf->Line(15, $pdf->GetY(), 100, $pdf->GetY());
            $pdf->Ln(2);
            $pdf->SetFont('Arial', '', 8);
            $pdf->Cell(85, 5, portal_protocol_pdf_text((string) ($protocol['tech'] ?? 'Tekniker')), 0, 1, 'L');
        } else {
            $pdf->SetFont('Arial', '', 9);
            $pdf->Cell(180, 6, portal_protocol_pdf_text('Ingen signatur finns sparad.'), 0, 1, 'L');
        }

        $bytes = $pdf->Output('S');
        return is_string($bytes) && str_starts_with($bytes, '%PDF-') ? $bytes : false;
    } catch (Throwable) {
        return false;
    }
}
