<?php
declare(strict_types=1);

namespace EdicionesMox\Catalog\Setup\Patch\Data;

use EdicionesMox\Catalog\BrandCatalog;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Reliable follow-up: hide Luma sample top-level categories from the menu via EAV int update.
 */
class HideSampleCategoriesFromMenuV2 implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $this->moduleDataSetup->getConnection()->startSetup();

        $attributeId = $this->resolveIncludeInMenuAttributeId();
        $sampleCategoryIds = $this->resolveSampleTopLevelCategoryIds();

        if ($sampleCategoryIds === [] || $attributeId === 0) {
            $this->moduleDataSetup->getConnection()->endSetup();
            return $this;
        }

        $intTable = $this->moduleDataSetup->getTable('catalog_category_entity_int');

        foreach ($sampleCategoryIds as $categoryId) {
            $existing = (int) $connection->fetchOne(
                $connection->select()
                    ->from($intTable, ['value_id'])
                    ->where('attribute_id = ?', $attributeId)
                    ->where('store_id = ?', 0)
                    ->where('entity_id = ?', $categoryId)
                    ->limit(1)
            );

            if ($existing > 0) {
                $connection->update(
                    $intTable,
                    ['value' => 0],
                    [
                        'value_id = ?' => $existing,
                    ]
                );
                continue;
            }

            $connection->insert(
                $intTable,
                [
                    'attribute_id' => $attributeId,
                    'store_id' => 0,
                    'entity_id' => $categoryId,
                    'value' => 0,
                ]
            );
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    /**
     * @return int[]
     */
    private function resolveSampleTopLevelCategoryIds(): array
    {
        $connection = $this->moduleDataSetup->getConnection();
        $entityTable = $this->moduleDataSetup->getTable('catalog_category_entity');
        $varcharTable = $this->moduleDataSetup->getTable('catalog_category_entity_varchar');
        $urlKeyAttributeId = $this->resolveUrlKeyAttributeId();

        $select = $connection->select()
            ->from(['cce' => $entityTable], ['entity_id'])
            ->joinLeft(
                ['url' => $varcharTable],
                $connection->quoteInto(
                    'cce.entity_id = url.entity_id AND url.store_id = 0 AND url.attribute_id = ?',
                    $urlKeyAttributeId
                ),
                ['url_key' => 'value']
            )
            ->where('cce.parent_id = ?', BrandCatalog::ROOT_CATEGORY_ID)
            ->where('cce.level = ?', 2);

        $rows = $connection->fetchAll($select);
        $brandKeys = [
            BrandCatalog::COLECCION_URL_KEY,
            BrandCatalog::DESTACADOS_URL_KEY,
        ];

        $ids = [];
        foreach ($rows as $row) {
            $urlKey = (string) ($row['url_key'] ?? '');
            if (in_array($urlKey, $brandKeys, true)) {
                continue;
            }
            $ids[] = (int) $row['entity_id'];
        }

        return $ids;
    }

    private function resolveIncludeInMenuAttributeId(): int
    {
        return $this->resolveCategoryAttributeId('include_in_menu');
    }

    private function resolveUrlKeyAttributeId(): int
    {
        return $this->resolveCategoryAttributeId('url_key');
    }

    private function resolveCategoryAttributeId(string $attributeCode): int
    {
        $connection = $this->moduleDataSetup->getConnection();
        $select = $connection->select()
            ->from(
                ['ea' => $this->moduleDataSetup->getTable('eav_attribute')],
                ['attribute_id']
            )
            ->join(
                ['eet' => $this->moduleDataSetup->getTable('eav_entity_type')],
                'ea.entity_type_id = eet.entity_type_id',
                []
            )
            ->where('eet.entity_type_code = ?', 'catalog_category')
            ->where('ea.attribute_code = ?', $attributeCode)
            ->limit(1);

        return (int) $connection->fetchOne($select);
    }

    public static function getDependencies(): array
    {
        return [
            CreateBrandCategories::class,
            HideSampleCategoriesFromMenu::class,
        ];
    }

    public function getAliases(): array
    {
        return [];
    }
}
