<?php
declare(strict_types=1);

$archiveFixtureFiles = [
    ['rel' => 'Bilrutan Skadeblankett.pdf', 'name' => 'Bilrutan Skadeblankett.pdf', 'date' => '2026-10-06 10:09', 'size' => 200.0],
    ['rel' => 'ABC123/Skadeblankett-Bilglas-ABC123-20261006-101112-a1b2c3.pdf', 'name' => 'Skadeblankett-Bilglas-ABC123-20261006-101112-a1b2c3.pdf', 'date' => '2026-10-06 10:11', 'size' => 120.0],
    ['rel' => 'overlatelseavtal/Overlatelseavtal-20261006.pdf', 'name' => 'Overlatelseavtal-20261006.pdf', 'date' => '2026-10-06 10:12', 'size' => 80.0],
    ['rel' => 'service/Serviceprotokoll-1000-01.pdf', 'name' => 'Serviceprotokoll-1000-01.pdf', 'date' => '2026-10-06 10:13', 'size' => 90.0],
    ['rel' => 'fakturaunderlag/Fakturaunderlag-2001.pdf', 'name' => 'Fakturaunderlag-2001.pdf', 'date' => '2026-10-06 10:14', 'size' => 70.0],
    ['rel' => 'Overlåtelseavtal-legacy.pdf', 'name' => 'Överlåtelseavtal-legacy.pdf', 'date' => '2026-10-06 10:16', 'size' => 60.0],
    ['rel' => 'Serviceprotokoll-legacy.pdf', 'name' => 'Serviceprotokoll-legacy.pdf', 'date' => '2026-10-06 10:17', 'size' => 60.0],
    ['rel' => 'Fakturaunderlag-legacy.pdf', 'name' => 'Fakturaunderlag-legacy.pdf', 'date' => '2026-10-06 10:18', 'size' => 60.0],
    ['rel' => 'allmant/Anteckning.pdf', 'name' => 'Anteckning.pdf', 'date' => '2026-10-06 10:15', 'size' => 50.0],
    ['rel' => 'ovrigt/Manuell-anteckning.pdf', 'name' => 'Manuell-anteckning.pdf', 'date' => '2026-10-06 10:19', 'size' => 55.0],
];

function h(mixed $value): string
{
    return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_test" value="test">';
}

function get_archive_files(): array
{
    return $GLOBALS['archiveFixtureFiles'];
}

function render_archive_subtab(string $sub, bool $centralAdminCanAccess = false): string
{
    $_GET = ['sub' => $sub];
    $_POST = [];
    $_FILES = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    include __DIR__ . '/../../tabs/files.php';
    return (string) ob_get_clean();
}

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$categoryCases = [
    'skade' => ['Skadeblankett-Bilglas-ABC123-20261006-101112-a1b2c3.pdf', 'Serviceprotokoll-1000-01.pdf', 'Skadeblanketter', 'Skadeblankett-Bilglas-ABC123-20261006-101112-a1b2c3.pdf'],
    'overlatelse' => ['Overlatelseavtal-20261006.pdf', 'Fakturaunderlag-2001.pdf', 'Överlåtelseavtal', 'Överlåtelseavtal-legacy.pdf'],
    'service' => ['Serviceprotokoll-1000-01.pdf', 'Anteckning.pdf', 'Service', 'Serviceprotokoll-legacy.pdf'],
    'invoice' => ['Fakturaunderlag-2001.pdf', 'Overlatelseavtal-20261006.pdf', 'Fakturaunderlag', 'Fakturaunderlag-legacy.pdf'],
    'ovrigt' => ['Manuell-anteckning.pdf', 'Serviceprotokoll-1000-01.pdf', 'Övriga filer', 'Anteckning.pdf'],
];
foreach ($categoryCases as $sub => [$expectedFile, $excludedFile, $label, $legacyFile]) {
    $html = render_archive_subtab($sub);
    $assert(strpos($html, $expectedFile) !== false, "{$label}-fliken visar sin PDF");
    $assert(strpos($html, $excludedFile) === false, "{$label}-fliken filtrerar bort andra dokumenttyper");
    $assert(strpos($html, 'name="upload_category" value="' . $sub . '"') !== false, "uppladdning i {$label}-fliken får rätt kategori");
    $assert(strpos($html, 'aria-current="page"') !== false, "{$label}-fliken markeras som aktiv");
    $assert(strpos($html, $legacyFile) !== false, "{$label}-fliken hittar också äldre PDF:er via filnamnet");
}

$templateHtml = render_archive_subtab('templates');
$assert(strpos($templateHtml, 'Bilrutan Skadeblankett.pdf') !== false, 'Originalmallen visas i en separat flik');
$assert(strpos($templateHtml, 'Skadeblankett-Bilglas-ABC123-20261006-101112-a1b2c3.pdf') === false, 'Genererade skadeblanketter blandas inte med originalmallar');
$assert(strpos($templateHtml, 'templates') !== false, 'Originalmallar har en separat uppladdningskategori');
$assert(strpos($templateHtml, 'utan att befintliga filer flyttas') !== false, 'Originalmallfliken anger att befintliga mallar inte flyttas');

$allHtml = render_archive_subtab('all');
$assert(strpos($allHtml, 'Anteckning.pdf') !== false, 'Alla filer visar även osorterade dokument');
$assert(strpos($allHtml, 'name="upload_category"') !== false, 'Alla filer-vyn erbjuder val av dokumenttyp vid uppladdning');
$assert(strpos($allHtml, 'Överlåtelseavtal') !== false, 'PDF-arkivet visar underfliken Överlåtelseavtal');
$assert(strpos($allHtml, 'Övriga filer') !== false, 'PDF-arkivet visar underfliken Övriga filer');
$assert(strpos($allHtml, 'data-category="ovrigt" data-date="2026-10-06"') !== false, 'Okända filer markeras som Övriga i Alla filer');
$assert(strpos($allHtml, 'value="ovrigt"') !== false, 'Alla filer-vyn kan välja Övriga filer vid uppladdning');
$assert(strpos($allHtml, 'Fakturaunderlag') !== false, 'PDF-arkivet visar underfliken Fakturaunderlag');
$assert(strpos($allHtml, 'id="file-date-from"') !== false && strpos($allHtml, 'id="file-date-to"') !== false, 'Alla filer-vyn erbjuder datumintervall');
$assert(strpos($allHtml, 'id="file-registration-filter"') !== false, 'Alla filer-vyn erbjuder registreringsnummerfilter');
$assert(strpos($allHtml, 'id="file-category-filter"') !== false, 'Alla filer-vyn erbjuder dokumenttypsfilter');
$assert(strpos($allHtml, 'data-category="skade" data-date="2026-10-06" data-regnr="ABC123"') !== false, 'Filrader innehåller sanerade filtermetadata för kategori, datum och registreringsnummer');
$assert(strpos($allHtml, 'Rensa filter') !== false && strpos($allHtml, 'file-filter-results') !== false, 'Filterpanelen har en återställningsknapp och tillgänglig träffräknare');
$assert(strpos($allHtml, 'Resultaten ändrar inte eller flyttar några filer.') !== false, 'Sökningen förklarar att filerna inte ändras eller flyttas');
$adminHtml = render_archive_subtab('all', true);
$assert(strpos($allHtml, 'name="action" value="delete_archive_file"') === false, 'Personal utan central admin ser inte raderingsknappar');
$assert(strpos($adminHtml, 'name="action" value="delete_archive_file"') !== false, 'Central admin ser raderingsknappar');
$assert(strpos($adminHtml, 'name="confirm_delete"') !== false, 'Radering kräver bekräftelse');
$templateAdminHtml = render_archive_subtab('templates', true);
$assert(strpos($templateAdminHtml, 'name="action" value="delete_archive_file"') === false, 'Originalmallen kan inte raderas från gränssnittet');

$categoryHtml = render_archive_subtab('service');
$assert(strpos($categoryHtml, 'id="file-category-filter"') === false && strpos($categoryHtml, 'Filtrera Service') !== false, 'En aktiv dokumenttypflik begränsar sökningen utan att visa ett missvisande tvärkategorifilter');

$ovrigtHtml = render_archive_subtab('ovrigt');
$assert(strpos($ovrigtHtml, 'endast inloggade användare kan ladda upp') !== false, 'Övriga filer förklarar att endast inloggade användare kan ladda upp');
$assert(strpos($ovrigtHtml, 'fylls på manuellt från appen') !== false, 'Övriga filer förklarar att fyllandet sker manuellt från appen');
$assert(strpos($ovrigtHtml, 'name="upload_category" value="ovrigt"') !== false, 'Övriga filer fylls på via uppladdning i underfliken');
$assert(strpos($ovrigtHtml, 'Manuell-anteckning.pdf') !== false && strpos($ovrigtHtml, 'data-search="ovrigt Manuell-anteckning.pdf"') !== false, 'Övriga filer visar filer i ovrigt-mappen');
$assert(strpos($ovrigtHtml, 'Serviceprotokoll-1000-01.pdf') === false, 'Övriga filer filtrerar bort serviceprotokoll');
$filesSource = (string) file_get_contents(__DIR__ . '/../../tabs/files.php');
$assert(strpos($filesSource, 'upload_denied') !== false && strpos($filesSource, '$portalIsScopedPwa') !== false, 'Uppladdning spärras för scoped PWA; endast inloggade användare i portalen kan ladda upp');
$indexSource = (string) file_get_contents(__DIR__ . '/../../index.php');
$assert(strpos($indexSource, "'invoice', 'ovrigt']") !== false, 'Radering godkänner underfliken Övriga filer');

printf("PASS: %d PDF archive subtab regression checks.\n", $checks);
