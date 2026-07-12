<?php
declare(strict_types=1);

namespace EdicionesMox\Catalog\Setup\Patch\Data;

use EdicionesMox\Catalog\BrandCatalog;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Keep Luma sample categories in DB for reference, but remove them from the storefront menu.
 */
class HideSampleCategoriesFromMenu implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly CategoryRepositoryInterface $categoryRepository
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $brandUrlKeys = [
            BrandCatalog::COLECCION_URL_KEY,
            BrandCatalog::DESTACADOS_URL_KEY,
        ];

        foreach ($this->resolveSampleTopLevelCategoryIds($brandUrlKeys) as $categoryId) {
            $category = $this->categoryRepository->get($categoryId, 0);
            if ((int) $category->getIncludeInMenu() === 0) {
                continue;
            }
            $category->setIncludeInMenu(false);
            $this->categoryRepository->save($category);
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    /**
     * @param string[] $brandUrlKeys
     * @return int[]
     */
    private function resolveSampleTopLevelCategoryIds(array $brandUrlKeys): array
    {
        $connection = $this->moduleDataSetup->getConnection();
        $entityTable = $this->moduleDataSetup->getTable('catalog_category_entity');
        $varcharTable = $this->moduleDataSetup->getTable('catalog_category_entity_varchar');
        $attributeTable = $this->moduleDataSetup->getTable('eav_attribute');
        $entityTypeTable = $this->moduleDataSetup->getTable('eav_entity_type');

        $select = $connection->select()
            ->from(['cce' => $entityTable], ['entity_id'])
            ->joinLeft(
                ['url' => $varcharTable],
                'cce.entity_id = url.entity_id AND url.store_id = 0 AND url.attribute_id = ('
                . $connection->select()
                    ->from(['ea' => $attributeTable], ['attribute_id'])
                    ->join(
                        ['eet' => $entityTypeTable],
                        'ea.entity_type_id = eet.entity_type_id',
                        []
                    )
                    ->where('eet.entity_type_code = ?', 'catalog_category')
                    ->where('ea.attribute_code = ?', 'url_key')
                . ')',
                []
            )
            ->where('cce.parent_id = ?', BrandCatalog::ROOT_CATEGORY_ID)
            ->where('cce.level = ?', 2);

        if ($brandUrlKeys !== []) {
            $select->where('url.value IS NULL OR url.value NOT IN (?)', $brandUrlKeys);
        }

        return array_map('intval', $connection->fetchCol($select));
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
