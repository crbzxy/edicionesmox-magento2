<?php
declare(strict_types=1);

namespace EdicionesMox\Catalog\Setup\Patch\Data;

use EdicionesMox\Catalog\BrandCatalog;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Migración única para instalaciones donde CreateBrandProducts ya corrió
 * antes de que existiera ConfigureSeoUrls: regenera el url_key de los
 * productos de marca a partir de su nombre (estaba fijado al SKU, ej.
 * "mox-004") y reguarda las categorías. En una instalación nueva,
 * ConfigureSeoUrls corre ANTES de crear productos/categorías (ver sus
 * dependencias), así que esos ya nacen con url_key correcto.
 *
 * El resave por sí solo NO alcanza para quitar el ".html" del url_rewrite ya
 * generado: Magento solo regenera el rewrite cuando `url_key` cambia de
 * valor (ProductProcessUrlRewriteSavingObserver::isNeedUpdateRewrites), y en
 * reintentos posteriores el url_key ya no cambia. Por eso el sufijo se quita
 * con un UPDATE directo sobre url_rewrite, sin depender de ese dirty-check.
 */
class UseSeoFriendlyUrls implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly AppState $appState
    ) {
    }

    public function apply(): self
    {
        $this->setAdminArea();
        $this->moduleDataSetup->getConnection()->startSetup();

        foreach (BrandCatalog::PRODUCT_SKUS as $sku) {
            $this->regenerateProductUrlKey($sku);
        }

        foreach ([BrandCatalog::COLECCION_URL_KEY, BrandCatalog::DESTACADOS_URL_KEY] as $urlKey) {
            $this->resaveCategory($urlKey);
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    private function setAdminArea(): void
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (LocalizedException) {
            // Area code already set by another patch/setup step.
        }
    }

    private function regenerateProductUrlKey(string $sku): void
    {
        try {
            $product = $this->productRepository->get($sku, true);
        } catch (NoSuchEntityException) {
            return;
        }

        // Vacío (no `false`): así ProductUrlKeyAutogeneratorObserver cae al
        // fallback de generar el slug desde el nombre del producto.
        $product->setUrlKey('');
        $product = $this->productRepository->save($product);
        $this->stripHtmlSuffix('product', (int) $product->getId());
    }

    private function resaveCategory(string $urlKey): void
    {
        $connection = $this->moduleDataSetup->getConnection();
        $select = $connection->select()
            ->from(
                ['cce' => $this->moduleDataSetup->getTable('catalog_category_entity')],
                ['entity_id']
            )
            ->join(
                ['ccev' => $this->moduleDataSetup->getTable('catalog_category_entity_varchar')],
                'cce.entity_id = ccev.entity_id',
                []
            )
            ->join(
                ['ea' => $this->moduleDataSetup->getTable('eav_attribute')],
                'ccev.attribute_id = ea.attribute_id',
                []
            )
            ->join(
                ['eet' => $this->moduleDataSetup->getTable('eav_entity_type')],
                'ea.entity_type_id = eet.entity_type_id',
                []
            )
            ->where('eet.entity_type_code = ?', 'catalog_category')
            ->where('ea.attribute_code = ?', 'url_key')
            ->where('ccev.store_id = ?', 0)
            ->where('ccev.value = ?', $urlKey)
            ->limit(1);

        $categoryId = (int) $connection->fetchOne($select);
        if ($categoryId === 0) {
            return;
        }

        try {
            $category = $this->categoryRepository->get($categoryId);
        } catch (NoSuchEntityException) {
            return;
        }

        $this->categoryRepository->save($category);
        $this->stripHtmlSuffix('category', $categoryId);
    }

    private function stripHtmlSuffix(string $entityType, int $entityId): void
    {
        $connection = $this->moduleDataSetup->getConnection();
        $connection->update(
            $this->moduleDataSetup->getTable('url_rewrite'),
            ['request_path' => new \Zend_Db_Expr("TRIM(TRAILING '.html' FROM request_path)")],
            [
                $connection->quoteInto('entity_type = ?', $entityType),
                $connection->quoteInto('entity_id = ?', $entityId),
                'is_autogenerated = 1',
                "request_path LIKE '%.html'",
            ]
        );
    }

    public static function getDependencies(): array
    {
        return [CreateBrandProducts::class, CreateBrandCategories::class, ConfigureSeoUrls::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
