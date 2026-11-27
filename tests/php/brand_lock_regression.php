<?php
declare(strict_types=1);
// Legacy logo-upload API must reject all uploads without touching the filesystem.
$source = (string) file_get_contents(__DIR__ . '/../../index.php');
$start = strpos($source, 'function portal_handle_svg_upload(): bool');
$end = strpos($source, "\nfunction ", $start + 1);
if ($start === false || $end === false) throw new RuntimeException('Upload compatibility function missing');
eval(substr($source, $start, $end - $start));
$before = hash_file('sha256', __DIR__ . '/../../assets/tadega-icon.svg');
foreach ([[], ['logo_file'=>['error'=>UPLOAD_ERR_OK, 'tmp_name'=>__FILE__, 'name'=>'replacement.svg', 'size'=>100]], ['logo_file'=>'forged']] as $files) {
    $_FILES = $files;
    if (portal_handle_svg_upload() !== false) throw new RuntimeException('Locked logo accepted an upload');
}
if (hash_file('sha256', __DIR__ . '/../../assets/tadega-icon.svg') !== $before) throw new RuntimeException('Approved symbol was modified');
$actionStart = strpos($source, "} elseif (\$action === 'upload_logo') {");
$actionEnd = strpos($source, "} elseif (", $actionStart + 1);
$action = substr($source, $actionStart, $actionEnd - $actionStart);
if (!str_contains($action, 'http_response_code(403)') || str_contains($action, 'portal_handle_svg_upload()')) throw new RuntimeException('Crafted upload action must be forbidden, not processed');
foreach (['tabs/settings.php'=>'name="logo_file"', 'tabs/admin.php'=>'name="company_logo"', 'index.php'=>'class="brand-company-logo"'] as $file=>$forbidden) {
    if (str_contains((string) file_get_contents(__DIR__ . '/../../' . $file), $forbidden)) throw new RuntimeException('Legacy brand override remains: ' . $file);
}
echo "PASS: frozen brand rejects uploads and forged requests; no replacement UI or legacy logo display.\n";
