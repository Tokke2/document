<?php
declare(strict_types=1);
/** Private PDF/image archive UI. Uploaded files are validated and never overwritten. */
$archiveCategories = [
    'templates' => ['label' => 'Originalmallar', 'folder' => 'mallar'],
    'skade' => ['label' => 'Skadeblanketter', 'folder' => 'skadeblanketter'],
    'overlatelse' => ['label' => 'Överlåtelseavtal', 'folder' => 'overlatelseavtal'],
    'service' => ['label' => 'Service', 'folder' => 'service'],
    'invoice' => ['label' => 'Fakturaunderlag', 'folder' => 'fakturaunderlag'],
];
$validArchiveSubtabs = array_merge(['all'], array_keys($archiveCategories));
$sub = isset($_GET['sub']) && is_string($_GET['sub']) ? $_GET['sub'] : 'all';
$sub = in_array($sub, $validArchiveSubtabs, true) ? $sub : 'all';
$uploadResult = '';
$postedUploadCategory = $_POST['upload_category'] ?? $sub;
$uploadCategory = is_string($postedUploadCategory) ? $postedUploadCategory : '';

$archiveFileCategory = static function (array $item) use ($archiveCategories): ?string {
    $relative = str_replace(chr(92), '/', (string) ($item['rel'] ?? ''));
    $name = basename($relative !== '' ? $relative : (string) ($item['name'] ?? ''));
    $firstDirectory = explode('/', $relative, 2)[0] ?? '';
    foreach ($archiveCategories as $key => $category) {
        if ($firstDirectory === $category['folder']) {
            return $key;
        }
    }
    if (in_array(strtolower($name), ['bilrutan skadeblankett.pdf', 'bilrutan_skadeblankett.pdf'], true)) {
        return 'templates';
    }
    if (preg_match('/\\Askadeblankett-bilglas-/iu', $name) === 1) {
        return 'skade';
    }
    $normalizedName = strtolower(strtr($name, ['å' => 'a', 'ä' => 'a', 'ö' => 'o', 'Å' => 'A', 'Ä' => 'A', 'Ö' => 'O']));
    if (strpos($normalizedName, 'overlatelse') !== false) {
        return 'overlatelse';
    }
    if (preg_match('/service/iu', $name) === 1) {
        return 'service';
    }
    if (preg_match('/fakturaunderlag/iu', $name) === 1) {
        return 'invoice';
    }
    return null;
};
$archiveCategoryFolderNames = array_map('strtolower', array_column($archiveCategories, 'folder'));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'upload_pdf_file') {
    $file = $_FILES['new_file'] ?? null;
    $tmpName = is_array($file) ? ($file['tmp_name'] ?? null) : null;
    $uploadSize = is_array($file) ? ($file['size'] ?? null) : null;
    if (!in_array($uploadCategory, $validArchiveSubtabs, true)) {
        $uploadResult = 'upload_error';
    } elseif (is_array($file) && in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        $uploadResult = 'upload_size';
    } elseif (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($tmpName) || !is_uploaded_file($tmpName) || !is_numeric($uploadSize)) {
        $uploadResult = 'upload_error';
    } elseif ((float) $uploadSize <= 0 || (float) $uploadSize > 12582912) {
        $uploadResult = 'upload_size';
    } else {
        $original = is_string($file['name'] ?? null) ? basename($file['name']) : '';
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $valid = false;
        if ($extension === 'pdf') {
            $head = @file_get_contents($file['tmp_name'], false, null, 0, 8);
            $finfo = function_exists('finfo_open') ? new finfo(FILEINFO_MIME_TYPE) : null;
            $mime = $finfo ? $finfo->file($file['tmp_name']) : '';
            $valid = is_string($head) && strpos($head, '%PDF-') === 0 && in_array($mime, ['application/pdf', 'application/octet-stream'], true);
        } elseif (in_array($extension, ['png', 'jpg', 'jpeg'], true)) {
            $image = @getimagesize($file['tmp_name']);
            $expected = $extension === 'png' ? 'image/png' : 'image/jpeg';
            $valid = is_array($image)
                && isset($image['mime'], $image[0], $image[1])
                && $image['mime'] === $expected
                && (int) $image[0] > 0 && (int) $image[1] > 0
                && (int) $image[0] <= 12000 && (int) $image[1] <= 12000
                && (int) $image[0] * (int) $image[1] <= 60000000;
        }

        if (!$valid) {
            $uploadResult = 'upload_type';
        } else {
            $pdfDir = __DIR__ . '/../pdf';
            $categoryFolder = $uploadCategory === 'all' ? '' : $archiveCategories[$uploadCategory]['folder'];
            $targetDir = $pdfDir . ($categoryFolder === '' ? '' : DIRECTORY_SEPARATOR . $categoryFolder);
            if (
                is_link($pdfDir) ||
                (!is_dir($pdfDir) && !@mkdir($pdfDir, 0750, true)) ||
                ($categoryFolder !== '' && (is_link($targetDir) || (!is_dir($targetDir) && !@mkdir($targetDir, 0750))))
            ) {
                $uploadResult = 'upload_error';
            } else {
                $stem = pathinfo($original, PATHINFO_FILENAME);
                $stem = strtr($stem, ['å' => 'a', 'ä' => 'a', 'ö' => 'o', 'Å' => 'A', 'Ä' => 'A', 'Ö' => 'O']);
                $stem = preg_replace('/[^A-Za-z0-9._-]+/', '-', $stem);
                $stem = trim(substr($stem, 0, 90), '.-_');
                if ($stem === '') {
                    $stem = 'document';
                }
                try {
                    $suffix = bin2hex(random_bytes(5));
                    $targetName = $stem . '-' . gmdate('Ymd-His') . '-' . $suffix . '.' . $extension;
                    $targetPath = $targetDir . DIRECTORY_SEPARATOR . $targetName;
                    $oldUmask = umask(0027);
                    $moved = !file_exists($targetPath) && move_uploaded_file($file['tmp_name'], $targetPath);
                    umask($oldUmask);
                    if ($moved) {
                        @chmod($targetPath, 0640);
                        TadegaPrivacyLogger::event('file.uploaded');
                        $uploadResult = 'upload_ok';
                    } else {
                        $uploadResult = 'upload_error';
                    }
                } catch (Throwable $e) {
                    $uploadResult = 'upload_error';
                }
            }
        }
    }
}

// Post/Redirect/Get prevents an accidental re-upload when the page is refreshed.
if ($uploadResult !== '') {
    $redirectSub = $uploadResult === 'upload_ok' && in_array($uploadCategory, $validArchiveSubtabs, true) ? $uploadCategory : $sub;
    if (ob_get_level() > 0 && !headers_sent()) {
        ob_clean();
    }
    header('Location: ?tab=files&sub=' . rawurlencode($redirectSub) . '&msg=' . rawurlencode($uploadResult), true, 303);
    exit;
}

$noticeMap = [
    'delete_ok' => ['success', 'Filen har tagits bort från arkivet. Säkerhetskopior och eventuella uppgifter som hänvisar till filen påverkas inte.'],
    'delete_error' => ['error', 'Filen kunde inte tas bort. Kontrollera bekräftelsen och att filen inte är en skyddad originalmall.'],
    'upload_ok' => ['success', 'Filen har laddats upp. En befintlig fil har inte skrivits över.'],
    'upload_error' => ['error', 'Filen kunde inte laddas upp. Försök igen.'],
    'upload_size' => ['error', 'Filstorleken överskrider serverns gräns eller portalens maxgräns på 12 MB. Prova en mindre fil eller kontrollera PHP-inställningarna.'],
    'upload_type' => ['error', 'Endast giltiga PDF-, PNG- och JPG-filer kan laddas upp.'],
];
$getMsg = isset($_GET['msg']) && is_string($_GET['msg']) ? $_GET['msg'] : '';
$notice = $noticeMap[$uploadResult !== '' ? $uploadResult : $getMsg] ?? null;
$allFiles = get_archive_files();
$archiveTotalCount = count($allFiles);
$categoryCounts = array_fill_keys(array_keys($archiveCategories), 0);
foreach ($allFiles as $item) {
    if (!is_array($item)) {
        continue;
    }
    $fileCategory = $archiveFileCategory($item);
    if ($fileCategory !== null && isset($categoryCounts[$fileCategory])) {
        $categoryCounts[$fileCategory]++;
    }
}
if ($sub !== 'all') {
    $allFiles = array_values(array_filter($allFiles, static function (array $item) use ($archiveFileCategory, $sub): bool {
        return $archiveFileCategory($item) === $sub;
    }));
}

?>
<style>
.file-wrap{--f-ink:#142d36;--f-muted:#61757b;--f-line:#d9e5e3;max-width:1180px;margin:0 auto;color:var(--f-ink)}.file-head{display:flex;justify-content:flex-end;align-items:center;gap:16px;margin-bottom:12px}.file-tabs{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:18px}.file-subtab{padding:9px 13px;border:1px solid var(--f-line);border-radius:999px;background:#fff;color:#475467;text-decoration:none;font-size:.8rem;font-weight:750}.file-subtab.active{border-color:#bfe1d5;background:#e8f5f0;color:#075c59;box-shadow:inset 0 -2px 0 #0b7a72}.file-subtab-count{display:inline-flex;min-width:1.4em;justify-content:center;margin-left:4px;padding:1px 5px;border-radius:999px;background:#eef2f3;color:#54666a;font-size:.68rem}.file-subtab.active .file-subtab-count{background:#cfe8dc;color:#075c59}.file-alert{margin-bottom:14px;padding:12px 14px;border-radius:10px;font-size:.85rem}.file-alert.success{background:#ecfdf3;color:#05603a}.file-alert.error{background:#fff5f4;color:#912018}.file-stats{display:flex;gap:10px;margin-bottom:14px}.file-stat{padding:11px 14px;border:1px solid var(--f-line);border-radius:12px;background:#fff}.file-stat strong{font-size:1.1rem}.file-panel{padding:18px;border:1px solid var(--f-line);border-radius:15px;background:#fff;box-shadow:0 4px 18px rgba(16,40,58,.035);margin-bottom:16px}.file-panel-title{margin:0;font-size:.97rem;line-height:1.3;letter-spacing:-.02em}.file-upload{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}.file-upload small{display:block;margin-top:4px;color:var(--f-muted);font-size:.76rem}.file-upload form{display:flex;align-items:center;gap:9px;flex-wrap:wrap}.file-upload select{min-height:42px;padding:8px 11px;border:1px solid var(--f-line);border-radius:9px;background:#fff;color:var(--f-ink);font:inherit}.file-search{width:min(100%,340px);min-height:42px;padding:9px 12px;border:1px solid var(--f-line);border-radius:9px;font:inherit}.file-table-wrap{overflow-x:auto;border:1px solid var(--f-line);border-radius:12px;background:#fff}.file-table{width:100%;border-collapse:collapse;min-width:650px}.file-table th{padding:11px 13px;background:#f8fafc;color:#475467;text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em}.file-table td{padding:11px 13px;border-top:1px solid #eef1f4;font-size:.82rem;vertical-align:middle}.file-table tr:hover td{background:#fbfcfe}.file-name{max-width:380px;overflow-wrap:anywhere;font-weight:700}.file-kind{display:inline-flex;padding:4px 8px;border-radius:999px;background:#eaf5ef;color:#17655c;font-size:.69rem;font-weight:800}.file-empty{padding:30px;text-align:center;color:var(--f-muted);border:1px dashed #cbd5e1;border-radius:12px;background:#fbfcfe}.file-actions{display:flex;gap:7px;white-space:nowrap;flex-wrap:wrap}.file-delete-form{display:flex;align-items:center;gap:7px;white-space:normal}.file-delete-form label{font-size:.72rem;max-width:130px}
@media(max-width:650px){.file-upload{align-items:stretch;flex-direction:column}.file-upload form{display:grid;grid-template-columns:1fr}.file-upload form select,.file-upload form input[type=file],.file-upload form .btn{width:100%}.file-stats{flex-wrap:wrap}.file-search{width:100%}}
@media(max-width:650px){.file-table-wrap{overflow:visible;border:0;background:transparent;box-shadow:none}.file-table{display:block;min-width:0}.file-table thead{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}.file-table tbody{display:grid;gap:11px}.file-table tbody tr{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 12px;padding:13px;border:1px solid #dce7e4;border-radius:15px;background:#fff;box-shadow:0 7px 20px rgba(18,54,58,.055)}.file-table td{display:block;min-width:0;padding:0;border:0;overflow-wrap:anywhere}.file-table td::before{display:block;margin:0 0 5px;color:#6a7c7d;content:attr(data-label);font-size:.64rem;font-weight:850;letter-spacing:.07em;text-transform:uppercase}.file-table td[data-label="Fil"],.file-table td[data-label="Åtgärder"]{grid-column:1/-1}.file-table .file-name{font-size:.9rem;line-height:1.35}.file-table .file-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px;white-space:normal}.file-table .file-actions .btn{width:100%;min-height:46px;padding:9px 7px;font-size:.8rem}.file-row[hidden]{display:none!important}}
.file-filter-panel{display:grid;gap:14px}.file-filter-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}.file-filter-heading p{margin:4px 0 0;color:var(--color-muted);font-size:.75rem;line-height:1.45}.file-filter-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:12px}.file-filter-field label{display:block;margin:0 0 6px;color:var(--color-muted);font-size:.72rem;font-weight:750}.file-filter-field :is(input,select){display:block;width:100%;min-height:42px;padding:8px 10px;border:1px solid var(--color-border);border-radius:9px;background:var(--color-surface);color:var(--color-ink);font:inherit;font-size:.82rem}.file-filter-grid .file-search{width:100%;max-width:none}.file-filter-fixed{display:flex;align-items:center;gap:8px;min-height:42px;padding:8px 10px;border:1px solid var(--color-border);border-radius:9px;background:var(--color-surface)}.file-filter-fixed span{color:var(--color-muted);font-size:.76rem}.file-filter-fixed strong{color:var(--color-ink);font-size:.82rem}.file-filter-bottom{display:flex;justify-content:space-between;align-items:center;gap:12px}.file-filter-results{margin:0;color:var(--color-muted);font-size:.75rem}.file-filter-bottom .btn{min-height:38px}
@media(max-width:650px){.file-filter-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.file-filter-bottom{align-items:stretch;flex-direction:column}.file-filter-bottom .btn{width:100%}}
@media(max-width:420px){.file-filter-grid{grid-template-columns:1fr}}
</style>
<section class="file-wrap" aria-label="<?= ($portalLanguage ?? 'sv') === 'en' ? 'PDF archive' : 'PDF-arkiv' ?>">
    <div class="file-head"><span class="file-stat"><strong id="file-view-count"><?= count($allFiles) ?></strong> filer i den här vyn</span></div>
    <nav class="file-tabs" aria-label="PDF-arkivets underflikar">
        <a class="file-subtab <?= $sub === 'all' ? 'active' : '' ?>" href="?tab=files&amp;sub=all"<?= $sub === 'all' ? ' aria-current="page"' : '' ?>><?= ($portalLanguage ?? 'sv') === 'en' ? 'All files' : 'Alla filer' ?> <span class="file-subtab-count"><?= $archiveTotalCount ?></span></a>
        <?php foreach ($archiveCategories as $categoryKey => $category): ?>
            <a class="file-subtab <?= $sub === $categoryKey ? 'active' : '' ?>" href="?tab=files&amp;sub=<?= rawurlencode($categoryKey) ?>"<?= $sub === $categoryKey ? ' aria-current="page"' : '' ?>><?= h($category['label']) ?> <span class="file-subtab-count"><?= (int) ($categoryCounts[$categoryKey] ?? 0) ?></span></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($notice): ?><div class="file-alert <?= h($notice[0]) ?>" role="status"><?= h($notice[1]) ?></div><?php endif; ?>
    <div class="file-panel file-upload">
        <div>
            <h2 class="file-panel-title"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Upload to PDF archive' : 'Ladda upp till PDF-arkivet' ?></h2>
            <?php if ($sub === 'all'): ?>
                <small>Välj dokumenttyp för att placera filen i rätt underflik. Giltiga PDF/JPG/PNG · max 12 MB · befintliga filer skrivs inte över.</small>
            <?php elseif ($sub === 'templates'): ?>
                <small>Originalmallar visas här utan att befintliga filer flyttas. Nya uppladdningar sparas i mallmappen. PDF/JPG/PNG · max 12 MB.</small>
            <?php else: ?>
                <small>Filer som laddas upp här sparas i underfliken <?= h($archiveCategories[$sub]['label']) ?>. Giltiga PDF/JPG/PNG · max 12 MB.</small>
            <?php endif; ?>
        </div>
        <form method="post" action="?tab=files&amp;sub=<?= h($sub) ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_pdf_file">
            <?php if ($sub === 'all'): ?>
                <select name="upload_category" aria-label="Välj dokumenttyp för uppladdningen">
                    <option value="all"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Uncategorized' : 'Ej kategoriserad' ?></option>
                    <?php foreach ($archiveCategories as $categoryKey => $category): ?><option value="<?= h($categoryKey) ?>"><?= h($category['label']) ?></option><?php endforeach; ?>
                </select>
            <?php else: ?>
                <input type="hidden" name="upload_category" value="<?= h($sub) ?>">
            <?php endif; ?>
            <?= csrf_field() ?>
            <input type="file" name="new_file" accept=".pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg" required>
            <button class="btn btn-main" type="submit"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Upload' : 'Ladda upp' ?></button>
        </form>
    </div>
    <div class="file-panel file-filter-panel" aria-labelledby="file-filter-title">
        <div class="file-filter-heading">
            <div>
                <h2 class="file-panel-title" id="file-filter-title"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Search and filter' : 'Sök och filtrera' ?></h2>
                <p><?= $sub === 'all' ? 'Filtrera på filnamn, registreringsnummer, datum och dokumenttyp. Resultaten ändrar inte eller flyttar några filer.' : 'Filtrera ' . h($archiveCategories[$sub]['label']) . ' på filnamn, registreringsnummer och datum.' ?></p>
            </div>
        </div>
        <div class="file-filter-grid">
            <div class="file-filter-field">
                <label for="file-search"><?= ($portalLanguage ?? 'sv') === 'en' ? 'File name or folder' : 'Filnamn eller mapp' ?></label>
                <input class="file-search" id="file-search" type="search" placeholder="Sök filnamn eller mapp…" autocomplete="off">
            </div>
            <div class="file-filter-field">
                <label for="file-registration-filter"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Registration number' : 'Registreringsnummer' ?></label>
                <input id="file-registration-filter" type="search" placeholder="Till exempel ABC123" autocomplete="off" autocapitalize="characters" spellcheck="false">
            </div>
            <div class="file-filter-field">
                <label for="file-date-from"><?= ($portalLanguage ?? 'sv') === 'en' ? 'From date' : 'Från datum' ?></label>
                <input id="file-date-from" type="date">
            </div>
            <div class="file-filter-field">
                <label for="file-date-to"><?= ($portalLanguage ?? 'sv') === 'en' ? 'To date' : 'Till datum' ?></label>
                <input id="file-date-to" type="date">
            </div>
            <?php if ($sub === 'all'): ?>
                <div class="file-filter-field">
                    <label for="file-category-filter"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Document type' : 'Dokumenttyp' ?></label>
                    <select id="file-category-filter">
                        <option value=""><?= ($portalLanguage ?? 'sv') === 'en' ? 'All document types' : 'Alla dokumenttyper' ?></option>
                        <option value="uncategorized"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Uncategorized' : 'Ej kategoriserad' ?></option>
                        <?php foreach ($archiveCategories as $categoryKey => $category): ?>
                            <option value="<?= h($categoryKey) ?>"><?= h($category['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>
        <div class="file-filter-bottom">
            <p class="file-filter-results" id="file-filter-results" role="status" aria-live="polite">Visar <?= count($allFiles) ?> av <?= count($allFiles) ?> filer i den här vyn.</p>
            <button class="btn btn-outline" id="file-clear-filters" type="button"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Clear filters' : 'Rensa filter' ?></button>
        </div>
    </div>
    <?php if ($allFiles): ?>
        <div class="file-table-wrap"><table class="file-table"><thead><tr><th><?= ($portalLanguage ?? 'sv') === 'en' ? 'Folder' : 'Mapp' ?></th><th><?= ($portalLanguage ?? 'sv') === 'en' ? 'File' : 'Fil' ?></th><th><?= ($portalLanguage ?? 'sv') === 'en' ? 'Type' : 'Typ' ?></th><th><?= ($portalLanguage ?? 'sv') === 'en' ? 'Date' : 'Datum' ?></th><th><?= ($portalLanguage ?? 'sv') === 'en' ? 'Size' : 'Storlek' ?></th><th></th></tr></thead><tbody id="file-rows">
        <?php foreach ($allFiles as $file):
            $relativeFile = str_replace(chr(92), '/', is_string($file['rel'] ?? null) ? $file['rel'] : '');
            $parts = explode('/', $relativeFile);
            $folder = count($parts) > 1 ? $parts[0] : 'PDF';
            $fileName = is_string($file['name'] ?? null) ? $file['name'] : basename($relativeFile);
            $fileCategory = $archiveFileCategory($file) ?? 'uncategorized';
            $registrationNumber = '';
            if (count($parts) > 1 && !in_array(strtolower($parts[0]), $archiveCategoryFolderNames, true) && preg_match('/\\A[A-Z0-9-]{2,12}\\z/iD', $parts[0]) === 1) {
                $registrationNumber = strtoupper($parts[0]);
            }
            if (preg_match('/\\ASkadeblankett-Bilglas-([A-Z0-9-]{2,12})-\\d{8}-\\d{6}-[a-f0-9]{6}\\.pdf\\z/iD', $fileName, $registrationMatch) === 1) {
                $registrationNumber = strtoupper($registrationMatch[1]);
            }
            $archiveTimestamp = is_numeric($file['time'] ?? null) ? (int) $file['time'] : strtotime((string) ($file['date'] ?? ''));
            $archiveDate = is_int($archiveTimestamp) && $archiveTimestamp > 0 ? date('Y-m-d', $archiveTimestamp) : '';
            $fileDateLabel = is_string($file['date'] ?? null) ? $file['date'] : '';
            $fileSize = is_numeric($file['size'] ?? null) ? (float) $file['size'] : 0.0;
        ?>
            <tr class="file-row" data-category="<?= h($fileCategory) ?>" data-date="<?= h($archiveDate) ?>" data-regnr="<?= h($registrationNumber) ?>" data-search="<?= h($folder . ' ' . $fileName) ?>"><td data-label="Mapp"><?= h($folder) ?></td><td class="file-name" data-label="Fil"><?= h($fileName) ?></td><td data-label="Typ"><span class="file-kind"><?= h(strtoupper(pathinfo($fileName, PATHINFO_EXTENSION))) ?></span></td><td data-label="Datum"><?= h($fileDateLabel) ?></td><td data-label="Storlek"><?= h(number_format($fileSize, 1, ',', ' ')) ?> KB</td><td data-label="Åtgärder"><div class="file-actions"><a class="btn btn-outline" href="?view_file=<?= rawurlencode('pdf/' . $relativeFile) ?>" target="_blank" rel="noopener noreferrer"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Open' : 'Öppna' ?></a><a class="btn btn-outline" href="?download_file=<?= rawurlencode('pdf/' . $relativeFile) ?>"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Download' : 'Ladda ner' ?></a><?php if (!empty($centralAdminCanAccess) && empty($centralAdminNeedsPinChange) && $fileCategory !== 'templates'): ?><form method="post" action="?tab=files&amp;sub=<?= h($sub) ?>" class="file-delete-form"><?= csrf_field() ?><input type="hidden" name="action" value="delete_archive_file"><input type="hidden" name="archive_sub" value="<?= h($sub) ?>"><input type="hidden" name="archive_file" value="<?= h($relativeFile) ?>"><label><input type="checkbox" name="confirm_delete" value="1" required> <?= ($portalLanguage ?? 'sv') === 'en' ? 'Confirm permanent deletion' : 'Bekräfta permanent radering' ?></label><button type="submit" class="btn btn-outline" aria-label="Ta bort <?= h($fileName) ?>"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Remove' : 'Ta bort' ?></button></form><?php endif; ?></div></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <div class="file-empty" id="file-no-results" hidden><?= ($portalLanguage ?? 'sv') === 'en' ? 'No files match the filters.' : 'Inga filer matchar filtren.' ?></div>
    <?php else: ?>
        <div class="file-empty">
            <?php if ($sub === 'all'): ?>Inga filer hittades i PDF-arkivet ännu.<?php else: ?>Inga filer hittades under <?= h($archiveCategories[$sub]['label']) ?> ännu. Ladda upp en fil här så sparas den i rätt underflik.<?php endif; ?>
        </div>
    <?php endif; ?>
</section>
<script>
(function () {
    'use strict';
    var search = document.getElementById('file-search');
    var registration = document.getElementById('file-registration-filter');
    var dateFrom = document.getElementById('file-date-from');
    var dateTo = document.getElementById('file-date-to');
    var category = document.getElementById('file-category-filter');
    var clearButton = document.getElementById('file-clear-filters');
    var results = document.getElementById('file-filter-results');
    var visibleCount = document.getElementById('file-view-count');
    var rows = Array.prototype.slice.call(document.querySelectorAll('.file-row'));
    var empty = document.getElementById('file-no-results');
    var filters = [search, registration, dateFrom, dateTo, category].filter(Boolean);

    function normalizeRegistration(value) {
        return String(value || '').toLocaleUpperCase('sv-SE').replace(/[^A-Z0-9]/g, '');
    }

    function applyFilters() {
        var searchQuery = search ? search.value.toLocaleLowerCase('sv-SE').trim() : '';
        var registrationQuery = registration ? normalizeRegistration(registration.value) : '';
        var fromDate = dateFrom ? dateFrom.value : '';
        var toDate = dateTo ? dateTo.value : '';
        var categoryQuery = category ? category.value : '';
        var invalidDateRange = !!fromDate && !!toDate && fromDate > toDate;
        var matchedCount = 0;

        rows.forEach(function (row) {
            var rowSearch = String(row.getAttribute('data-search') || row.textContent || '').toLocaleLowerCase('sv-SE');
            var registrationSearch = normalizeRegistration((row.getAttribute('data-regnr') || '') + ' ' + (row.getAttribute('data-search') || ''));
            var rowDate = row.getAttribute('data-date') || '';
            var rowCategory = row.getAttribute('data-category') || 'uncategorized';
            var matchesDate = (fromDate === '' && toDate === '')
                || (rowDate !== '' && (!fromDate || rowDate >= fromDate) && (!toDate || rowDate <= toDate));
            var matches = !invalidDateRange
                && (!searchQuery || rowSearch.indexOf(searchQuery) !== -1)
                && (!registrationQuery || registrationSearch.indexOf(registrationQuery) !== -1)
                && matchesDate
                && (!categoryQuery || rowCategory === categoryQuery);
            row.hidden = !matches;
            if (matches) matchedCount++;
        });

        if (visibleCount) visibleCount.textContent = String(matchedCount);
        if (results) {
            results.textContent = invalidDateRange
                ? <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "The date range is invalid. The start date must be on or before the end date." : "Datumintervallet är ogiltigt. Från datum måste vara före eller samma som till datum.", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
                : <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "Showing " : "Visar ", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> + matchedCount + <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? " of " : " av ", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> + rows.length + <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? " files in this view." : " filer i den här vyn.", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        }
        if (empty) {
            empty.textContent = invalidDateRange ? <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "Check the start and end dates." : "Kontrollera från- och till-datum.", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> : <?= json_encode(($portalLanguage ?? 'sv') === 'en' ? "No files match the filters." : "Inga filer matchar filtren.", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            empty.hidden = matchedCount > 0;
        }
    }

    filters.forEach(function (filter) {
        filter.addEventListener('input', applyFilters);
        filter.addEventListener('change', applyFilters);
    });
    if (clearButton) {
        clearButton.addEventListener('click', function () {
            filters.forEach(function (filter) { filter.value = ''; });
            applyFilters();
            if (search) search.focus();
        });
    }
    applyFilters();
})();
</script>
