<?php
declare(strict_types=1);
/**
 * Renders an example signature into the original blankett's "Bilägarens/förarens
 * underskrift" box at 300 dpi using the real skade_pdf_signature_placement() math,
 * so the result shows exactly how a drawn signature fills the long PDF box.
 * Run: npx --yes @php-wasm/cli tools/signature_example.php
 */
require __DIR__ . '/../includes/skadeblankett_pdf.php';

$root = dirname(__DIR__);

// --- 1. Build a flat, pen-styled example signature (~12:1) ------------------
$sigW = 840;
$sigH = 70;
$sig = imagecreatetruecolor($sigW, $sigH);
$white = imagecolorallocate($sig, 255, 255, 255);
imagefilledrectangle($sig, 0, 0, $sigW - 1, $sigH - 1, $white);
imagecolortransparent($sig, $white);
imageantialias($sig, true);

/** Quadratic bezier sampling. */
function ex_bezier(array $p0, array $p1, array $p2, int $steps = 24): array
{
    $points = [];
    for ($i = 0; $i <= $steps; $i++) {
        $t = $i / $steps;
        $u = 1 - $t;
        $points[] = [
            $u * $u * $p0[0] + 2 * $u * $t * $p1[0] + $t * $t * $p2[0],
            $u * $u * $p0[1] + 2 * $u * $t * $p1[1] + $t * $t * $p2[1],
        ];
    }
    return $points;
}

/** Chain several quadratic segments into one polyline. */
function ex_chain(array ...$segments): array
{
    $out = [];
    foreach ($segments as $segment) {
        $sampled = ex_bezier($segment[0], $segment[1], $segment[2]);
        $out = array_merge($out, $out === [] ? $sampled : array_slice($sampled, 1));
    }
    return $out;
}

$ink = imagecolorallocatealpha($sig, 26, 63, 150, 0);
$soft = imagecolorallocatealpha($sig, 26, 63, 150, 34);
$softer = imagecolorallocatealpha($sig, 26, 63, 150, 66);

/** Draw one polyline as a soft multi-pass pen. */
function ex_pen($canvas, array $points, $center, $soft, $softer): void
{
    $passes = [[0.0, $center], [-0.9, $soft], [0.9, $soft], [-1.8, $softer], [1.8, $softer]];
    $count = count($points);
    for ($i = 1; $i < $count; $i++) {
        [$x0, $y0] = $points[$i - 1];
        [$x1, $y1] = $points[$i];
        $dx = $x1 - $x0;
        $dy = $y1 - $y0;
        $len = sqrt($dx * $dx + $dy * $dy);
        if ($len < 0.0001) {
            continue;
        }
        $nx = -$dy / $len;
        $ny = $dx / $len;
        foreach ($passes as [$offset, $color]) {
            imageline(
                $canvas,
                (int) round($x0 + $nx * $offset),
                (int) round($y0 + $ny * $offset),
                (int) round($x1 + $nx * $offset),
                (int) round($y1 + $ny * $offset),
                $color
            );
        }
    }
}

// "A" initial (up and down), crossbar, double hump, long flat surname wave,
// and a closing sweep back underneath — all kept inside the flat box.
$strokes = [
    // Looping cursive "L" initial.
    ex_chain([[52, 62], [48, 24], [76, 12]], [[76, 12], [94, 10], [88, 34]], [[88, 34], [84, 58], [110, 58]]),
    // Quick uneven humps, like the middle of a name written fast.
    ex_chain([[110, 54], [128, 14], [146, 56]], [[146, 56], [162, 22], [176, 52]], [[176, 52], [190, 34], [206, 48]]),
    // Long surname wave with deliberately uneven amplitude and rhythm.
    ex_chain(
        [[206, 46], [258, 10], [308, 54]],
        [[308, 54], [348, 32], [390, 42]],
        [[390, 42], [438, 12], [486, 52]],
        [[486, 52], [528, 36], [570, 38]],
        [[570, 38], [618, 10], [666, 50]],
        [[666, 50], [708, 28], [752, 44]]
    ),
    // Closing flourish up to the right.
    ex_chain([[752, 44], [788, 26], [818, 18]]),
    // And a sweep back underneath, crossing the wave.
    ex_chain([[818, 26], [744, 64], [592, 58]]),
];
foreach ($strokes as $stroke) {
    ex_pen($sig, $stroke, $ink, $soft, $softer);
}

// --- 2. Crop the Datum + signature row from the 300 dpi blankett ------------
$templatePath = $root . '/png/blankett.png';
$blanket = @imagecreatefrompng($templatePath);
if ($blanket === false) {
    fwrite(STDERR, "FAIL: cannot read png/blankett.png\n");
    exit(1);
}
$mmToPx = 300 / 25.4;
$cropX = (int) round(28 * $mmToPx);      // left of the Datum box
$cropY = (int) round(160 * $mmToPx);     // above both boxes
$cropW = (int) round(164 * $mmToPx);     // Datum + signature row + air
$cropH = (int) round(21 * $mmToPx);
$row = imagecrop($blanket, ['x' => $cropX, 'y' => $cropY, 'width' => $cropW, 'height' => $cropH]);
if ($row === false) {
    fwrite(STDERR, "FAIL: cannot crop the signature row\n");
    exit(1);
}

// --- 3. Place it with the exact production placement math -------------------
$placement = skade_pdf_signature_placement($sigW, $sigH);
if ($placement === null) {
    fwrite(STDERR, "FAIL: no placement\n");
    exit(1);
}
$destX = (int) round($placement['x_mm'] * $mmToPx) - $cropX;
$destY = (int) round($placement['top_mm'] * $mmToPx) - $cropY;
$destW = (int) round($placement['width_mm'] * $mmToPx);
$destH = (int) round($placement['height_mm'] * $mmToPx);
imagecopyresampled($row, $sig, $destX, $destY, 0, 0, $destW, $destH, $sigW, $sigH);

$outPath = $root . '/signature-example.png';
imagepng($row, $outPath);
$widthPx = (int) ($placement['width_mm'] * $mmToPx);
$boxPx = (int) (round(203.1 * 25.4 / 72) * $mmToPx);
echo 'WROTE ' . $outPath . ' width=' . $widthPx . '/' . $boxPx . 'px ('
    . round(100 * $widthPx / $boxPx, 1) . "% of the box)\n";
