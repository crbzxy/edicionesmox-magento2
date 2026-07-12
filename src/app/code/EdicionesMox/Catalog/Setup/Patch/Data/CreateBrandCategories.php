<?php
declare(strict_types=1);

namespace EdicionesMox\Catalog\Setup\Patch\Data;

use EdicionesMox\Catalog\BrandCatalog;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterfaceFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class CreateBrandCategories implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly CategoryInterfaceFactory $categoryFactory,
        private readonly CategoryRepositoryInterface $categoryRepository
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $coleccion = $this->ensureCategory(
            BrandCatalog::COLECCION_URL_KEY,
            'Colección',
            BrandCatalog::ROOT_CATEGORY_ID,
            true,
            10,
            'Archivo de piezas Ediciones Mox.'
        );

        $this->ensureCategory(
            BrandCatalog::DESTACADOS_URL_KEY,
            'Destacados',
            (int) $coleccion->getId(),
            false,
            10,
            'Piezas destacadas en la home.'
        );

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    private function ensureCategory(
        string $urlKey,
        string $name,
        int $parentId,
        bool $includeInMenu,
        int $position,
        string $description
    ): \Magento\Catalog\Api\Data\CategoryInterface {
        try {
            return $this->categoryRepository->get($this->resolveCategoryIdByUrlKey($urlKey));
        } catch (NoSuchEntityException) {
            // Create below.
        }

        $category = $this->categoryFactory->create();
        $category->setName($name);
        $category->setParentId($parentId);
        $category->setIsActive(true);
        $category->setIncludeInMenu($includeInMenu);
        $category->setIsAnchor(true);
        $category->setUrlKey($urlKey);
        $category->setPosition($position);
        $category->setDescription($description);
        $category->setStoreId(0);

        return $this->categoryRepository->save($category);
    }

    private function resolveCategoryIdByUrlKey(string $urlKey): int
    {
        $connection = $this->moduleDataSetup->getConnection();
        $entityTable = $this->moduleDataSetup->getTable('catalog_category_entity');
        $varcharTable = $this->moduleDataSetup->getTable('catalog_category_entity_varchar');
        $attributeTable = $this->moduleDataSetup->getTable('eav_attribute');
        $entityTypeTable = $this->moduleDataSetup->getTable('eav_entity_type');

        $select = $connection->select()
            ->from(['cce' => $entityTable], ['entity_id'])
            ->join(
                ['ccev' => $varcharTable],
                'cce.entity_id = ccev.entity_id',
                []
            )
            ->join(
                ['ea' => $attributeTable],
                'ccev.attribute_id = ea.attribute_id',
                []
            )
            ->join(
                ['eet' => $entityTypeTable],
                'ea.entity_type_id = eet.entity_type_id',
                []
            )
            ->where('eet.entity_type_code = ?', 'catalog_category')
            ->where('ea.attribute_code = ?', 'url_key')
            ->where('ccev.store_id = ?', 0)
            ->where('ccev.value = ?', $urlKey)
            ->limit(1);

        $categoryId = $connection->fetchOne($select);
        if (!$categoryId) {
            throw new NoSuchEntityException(
                __('Category with URL key "%1" does not exist.', $urlKey)
            );
        }

        return (int) $categoryId;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
