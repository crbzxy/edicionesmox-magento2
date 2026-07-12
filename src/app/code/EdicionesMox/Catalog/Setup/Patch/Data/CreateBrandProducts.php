<?php
declare(strict_types=1);

namespace EdicionesMox\Catalog\Setup\Patch\Data;

use EdicionesMox\Catalog\BrandCatalog;
use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Gallery\Processor as GalleryProcessor;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\Store\Model\StoreManagerInterface;

class CreateBrandProducts implements DataPatchInterface
{
    private const DEFAULT_SOURCE_CODE = 'default';

    private const ARTIST = 'Ediciones Mox';

    /**
     * @var array<int, array{
     *     sku: string,
     *     name: string,
     *     meta: string,
     *     price: float,
     *     image: string
     * }>
     */
    private const PRODUCTS = [
        [
            'sku' => 'mox-001',
            'name' => 'CHUPALINAS / Edición GID',
            'meta' => 'PLA pintado · 2026',
            'price' => 1200.00,
            'image' => 'mox-001.jpg',
        ],
        [
            'sku' => 'mox-002',
            'name' => 'GOLDITOS / Serie kawaii',
            'meta' => 'PLA pintado · 2026',
            'price' => 980.00,
            'image' => 'mox-002.jpg',
        ],
        [
            'sku' => 'mox-003',
            'name' => 'ZURASHI / Mutante dual',
            'meta' => 'PLA pintado · 2026',
            'price' => 1400.00,
            'image' => 'mox-003.jpg',
        ],
        [
            'sku' => 'mox-004',
            'name' => 'CHUPALINAS / Gold Shift',
            'meta' => 'PLA pintado · 2026',
            'price' => 1300.00,
            'image' => 'mox-004.jpg',
        ],
    ];

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly ProductInterfaceFactory $productFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CategoryLinkManagementInterface $categoryLinkManagement,
        private readonly GalleryProcessor $galleryProcessor,
        private readonly Filesystem $filesystem,
        private readonly EavSetupFactory $eavSetupFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly SourceItemInterfaceFactory $sourceItemFactory,
        private readonly SourceItemsSaveInterface $sourceItemsSave,
        private readonly AppState $appState
    ) {
    }

    public function apply(): self
    {
        $this->setAdminArea();
        $this->moduleDataSetup->getConnection()->startSetup();

        $attributeSetId = $this->resolveDefaultAttributeSetId();
        $websiteIds = array_map(
            static fn ($website): int => (int) $website->getId(),
            $this->storeManager->getWebsites()
        );
        $categoryIds = $this->resolveBrandCategoryIds();
        $mediaImportPath = $this->stageProductImages();

        foreach (self::PRODUCTS as $definition) {
            $this->ensureProduct($definition, $attributeSetId, $websiteIds, $categoryIds, $mediaImportPath);
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

    /**
     * @param array{
     *     sku: string,
     *     name: string,
     *     meta: string,
     *     price: float,
     *     image: string
     * } $definition
     * @param int[] $websiteIds
     * @param int[] $categoryIds
     */
    private function ensureProduct(
        array $definition,
        int $attributeSetId,
        array $websiteIds,
        array $categoryIds,
        string $mediaImportPath
    ): void {
        try {
            $this->productRepository->get($definition['sku']);
            return;
        } catch (NoSuchEntityException) {
            // Create below.
        }

        /** @var Product $product */
        $product = $this->productFactory->create();
        $product->setTypeId(Type::TYPE_SIMPLE);
        $product->setAttributeSetId($attributeSetId);
        $product->setSku($definition['sku']);
        $product->setName($definition['name']);
        $product->setUrlKey($definition['sku']);
        $product->setPrice($definition['price']);
        $product->setVisibility(Visibility::VISIBILITY_BOTH);
        $product->setStatus(Status::STATUS_ENABLED);
        $product->setWebsiteIds($websiteIds);
        $product->setStockData([
            'use_config_manage_stock' => 1,
            'qty' => 10,
            'is_qty_decimal' => 0,
            'is_in_stock' => 1,
        ]);
        $product->setShortDescription($definition['meta']);
        $product->setDescription(
            sprintf(
                '%s — %s. Artista: %s.',
                $definition['name'],
                $definition['meta'],
                self::ARTIST
            )
        );
        $product->setMetaTitle($definition['name']);
        $product->setMetaDescription($definition['meta']);

        $this->galleryProcessor->addImage(
            $product,
            $mediaImportPath . '/' . $definition['image'],
            ['image', 'small_image', 'thumbnail'],
            false,
            false
        );

        $savedProduct = $this->productRepository->save($product);
        $this->categoryLinkManagement->assignProductToCategories(
            $savedProduct->getSku(),
            $categoryIds
        );
        $this->saveSourceItem($savedProduct->getSku(), 10.0);
    }

    private function saveSourceItem(string $sku, float $qty): void
    {
        $sourceItem = $this->sourceItemFactory->create();
        $sourceItem->setSourceCode(self::DEFAULT_SOURCE_CODE);
        $sourceItem->setSku($sku);
        $sourceItem->setQuantity($qty);
        $sourceItem->setStatus(1);
        $this->sourceItemsSave->execute([$sourceItem]);
    }

    /**
     * @return int[]
     */
    private function resolveBrandCategoryIds(): array
    {
        $ids = [];
        foreach ([BrandCatalog::COLECCION_URL_KEY, BrandCatalog::DESTACADOS_URL_KEY] as $urlKey) {
            $ids[] = $this->resolveCategoryIdByUrlKey($urlKey);
        }

        return $ids;
    }

    private function resolveCategoryIdByUrlKey(string $urlKey): int
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
            throw new NoSuchEntityException(
                __('Brand category with URL key "%1" was not found.', $urlKey)
            );
        }

        return $categoryId;
    }

    private function resolveDefaultAttributeSetId(): int
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        return (int) $eavSetup->getDefaultAttributeSetId(Product::ENTITY);
    }

    private function stageProductImages(): string
    {
        $mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $relativeImport = 'import/edmox';
        $mediaDirectory->create($relativeImport);

        $moduleFilesDir = dirname(__DIR__, 3) . '/files/products';
        foreach (self::PRODUCTS as $definition) {
            $source = $moduleFilesDir . '/' . $definition['image'];
            if (!is_file($source)) {
                throw new \RuntimeException(sprintf('Missing product image: %s', $source));
            }
            $destination = $relativeImport . '/' . $definition['image'];
            $mediaDirectory->writeFile(
                $destination,
                file_get_contents($source) ?: ''
            );
        }

        // Gallery Processor expects a path relative to the media directory.
        return $relativeImport;
    }

    public static function getDependencies(): array
    {
        return [CreateBrandCategories::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
