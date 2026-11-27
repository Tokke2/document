<?php
declare(strict_types=1);

// The brand SVGs are served through portal_sanitize_svg, which strips
// elements outside its allowlist (no defs/gradients, no scripts). Live <text>
// is allowed only so the editable print original downloads intact. Extract
// the sanitizer from index.php and prove both logo assets render intact.
$source = (string) file_get_contents(__DIR__ . '/../../index.php');
$start = strpos($source, 'function portal_sanitize_svg(');
$next = $start === false ? false : strpos($source, "\nfunction ", $start);
$assert = static function (bool $ok, string $msg): void {
    static $count = 0;
    $count++;
    if (!$ok) {
        throw new RuntimeException("check {$count}: {$msg}");
    }
};
$assert($start !== false && $next !== false, 'Sanitizer function found in index.php');
eval(substr($source, $start, $next - $start));
$assert(function_exists('portal_sanitize_svg'), 'Sanitizer defined');

$icon = (string) file_get_contents(__DIR__ . '/../../assets/tadega-icon.svg');
$safeIcon = portal_sanitize_svg($icon);
$assert(is_string($safeIcon), 'App icon passes the sanitizer');
$assert(str_contains($safeIcon, 'rx="112"'), 'Badge corner radius survives sanitizing');
$assert(str_contains($safeIcon, 'id="windshield-frame"'), 'Large windshield frame survives sanitizing');
$assert(str_contains($safeIcon, 'id="lightning"'), 'Large lightning bolt survives sanitizing');
$assert(str_contains($safeIcon, 'fill="#7fd6c8"'), 'Turquoise windshield glass survives sanitizing');

$logo = (string) file_get_contents(__DIR__ . '/../../assets/tadega-logo.svg');
$safeLogo = portal_sanitize_svg($logo);
$assert(is_string($safeLogo), 'Wordmark logo passes the sanitizer');
$assert(str_contains($safeLogo, 'scale(0.46875)'), 'Badge scale transform survives sanitizing');
$assert(substr_count($safeLogo, '<path') >= 6, 'Badge, strokes and outlined wordmark stay paths');
$assert(!str_contains($logo, '<text'), 'Wordmark is outlined paths, not font text');
$assert(!str_contains($logo, 'url(') && !str_contains($logo, 'linearGradient'), 'Logo uses only flat fills the sanitizer keeps');

$print = (string) file_get_contents(__DIR__ . '/../../assets/tadega-logo-tryck.svg');
$safePrint = portal_sanitize_svg($print);
$assert(is_string($safePrint), 'Editable print original passes the sanitizer');
$assert(str_contains($safePrint, '<text id="namn"'), 'Print original keeps its live text objects');
$assert(str_contains($safePrint, 'font-family="Inter'), 'Print original keeps font attributes');
$assert(str_contains($safePrint, 'letter-spacing="7"'), 'Print original keeps letter spacing');
$assert(str_contains($safePrint, 'TADGA AB'), 'Print original keeps the company line');
$assert(!str_contains($safePrint, '<script'), 'Scripts are still stripped from the print original');
$evil = str_replace('</svg>', '<script>alert(1)</script></svg>', $print);
$safeEvil = portal_sanitize_svg($evil);
$assert(is_string($safeEvil) && !str_contains($safeEvil, '<script'), 'Script injection into the print original is stripped');

echo "PASS: svg logo sanitizer regression\n";
