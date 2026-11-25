<?php
declare(strict_types=1);

// The brand SVGs are served through portal_sanitize_svg, which strips
// elements outside its allowlist (no defs/gradients, no font text). Extract
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
$assert(str_contains($safeIcon, 'M176 368'), 'Check-mark stroke survives sanitizing');
$assert(str_contains($safeIcon, 'stroke="#0f9b8e"'), 'Teal stroke colour survives sanitizing');

$logo = (string) file_get_contents(__DIR__ . '/../../assets/tadega-logo.svg');
$safeLogo = portal_sanitize_svg($logo);
$assert(is_string($safeLogo), 'Wordmark logo passes the sanitizer');
$assert(str_contains($safeLogo, 'scale(0.46875)'), 'Badge scale transform survives sanitizing');
$assert(substr_count($safeLogo, '<path') >= 6, 'Badge, strokes and outlined wordmark stay paths');
$assert(!str_contains($logo, '<text'), 'Wordmark is outlined paths, not font text');
$assert(!str_contains($logo, 'url(') && !str_contains($logo, 'linearGradient'), 'Logo uses only flat fills the sanitizer keeps');

echo "PASS: svg logo sanitizer regression\n";
