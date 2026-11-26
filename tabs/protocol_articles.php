<?php
declare(strict_types=1);
/**
 * tabs/protocol_articles.php — "Artikelregister" sub-view of Serviceprotokoll.
 *
 * Writes only to storage/protocol_articles.json via portal_protocol_catalog_*;
 * the invoice item register (storage/invoice_articles.json) is a separate
 * catalogue and never receives these articles. Requires the signed-in protocol
 * user that tabs/protocol.php already validated ($protocolIsAdmin gates edits).
 */
?>
<nav class="pro-subnav" aria-label="Serviceprotokollflikar"><a href="?tab=protocol"><?= ($portalLanguage ?? 'sv') === 'en' ? 'New report' : 'Nytt protokoll' ?></a><a href="?tab=protocol&amp;protocol_view=records">Sparade protokoll (<?= count($savedProtocols) ?>)</a><a href="?tab=protocol&amp;protocol_view=articles" aria-current="page"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Item register' : 'Artikelregister' ?></a></nav>
<section class="invoice-admin-panel invoice-catalog-panel" aria-labelledby="protocol-articles-heading">
    <header class="invoice-admin-header">
        <div>
            <p class="invoice-admin-eyebrow"><?= ($portalLanguage ?? 'sv') === 'en' ? 'SERVICE REPORT ITEMS' : 'SERVICEPROTOKOLLETS ARTIKLAR' ?></p>
            <h2 id="protocol-articles-heading"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Item register' : 'Artikelregister' ?></h2>
            <p><?= ($portalLanguage ?? 'sv') === 'en' ? 'Import items into new service reports. This register is separate from the invoice item catalogue — articles are never shared between the two.' : 'Importera artiklar till nya serviceprotokoll. Registret är skilt från Fakturaunderlagets artikelkatalog — artiklar delas aldrig mellan dem.' ?></p>
        </div>
    </header>

    <?php if (!$protocolCatalogStorageReady): ?>
        <div class="invoice-catalog-empty"><strong><?= ($portalLanguage ?? 'sv') === 'en' ? 'The register could not be read.' : 'Registret kunde inte läsas.' ?></strong><p><?= ($portalLanguage ?? 'sv') === 'en' ? 'Check that the private storage folder is accessible.' : 'Kontrollera att den privata storage-mappen är åtkomlig.' ?></p></div>
    <?php else: ?>
        <?php if ($protocolIsAdmin): ?>
            <form method="post" action="?tab=protocol&amp;protocol_view=articles" class="invoice-catalog-form" autocomplete="off">
                <input type="hidden" name="action" value="protocol_catalog_add">
                <?= csrf_field() ?>
                <div class="invoice-catalog-add-row">
                    <div>
                        <label for="protocol-article-name"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Item' : 'Artikel' ?></label>
                        <input id="protocol-article-name" name="article[name]" type="text" maxlength="200" required placeholder="<?= ($portalLanguage ?? 'sv') === 'en' ? 'e.g. Wiper blade' : 't.ex. vindusviskare' ?>">
                    </div>
                    <div>
                        <label for="protocol-article-spec"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Specification' : 'Specifikation' ?></label>
                        <input id="protocol-article-spec" name="article[spec]" type="text" maxlength="300" placeholder="<?= ($portalLanguage ?? 'sv') === 'en' ? 'Optional detail' : 'Valfri detalj' ?>">
                    </div>
                    <button class="btn btn-main" type="submit"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Add item' : 'Lägg till artikel' ?></button>
                </div>
            </form>
        <?php endif; ?>

        <?php if (!$protocolCatalogArticles): ?>
            <div class="invoice-catalog-empty"><strong><?= ($portalLanguage ?? 'sv') === 'en' ? 'No items yet.' : 'Inga artiklar än.' ?></strong><p><?= ($portalLanguage ?? 'sv') === 'en' ? 'Add the service items your mechanics import into reports.' : 'Lägg till de serviceartiklar som mekanikerna importerar i protokollen.' ?></p></div>
        <?php else: ?>
            <ul class="protocol-article-list">
                <?php foreach ($protocolCatalogArticles as $protocolCatalogArticle): ?>
                    <li class="protocol-article-row">
                        <div class="protocol-article-text">
                            <strong><?= h($protocolCatalogArticle['name']) ?></strong>
                            <?php if ($protocolCatalogArticle['spec'] !== ''): ?><span><?= h($protocolCatalogArticle['spec']) ?></span><?php endif; ?>
                        </div>
                        <?php if ($protocolIsAdmin): ?>
                            <form method="post" action="?tab=protocol&amp;protocol_view=articles" onsubmit="return confirm('Ta bort artikeln från registret?');">
                                <input type="hidden" name="action" value="protocol_catalog_delete">
                                <input type="hidden" name="id" value="<?= h($protocolCatalogArticle['id']) ?>">
                                <?= csrf_field() ?>
                                <button class="tc-link-btn tc-link-danger" type="submit"><?= ($portalLanguage ?? 'sv') === 'en' ? 'Delete' : 'Ta bort' ?></button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>
</section>
