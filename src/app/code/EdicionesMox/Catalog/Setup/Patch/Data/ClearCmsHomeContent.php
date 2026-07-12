<?php
declare(strict_types=1);

namespace EdicionesMox\Catalog\Setup\Patch\Data;

use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Empty the CMS "home" body so only the Ediciones Mox gallery block remains on `/`.
 */
class ClearCmsHomeContent implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $criteria = $this->searchCriteriaBuilder
            ->addFilter('identifier', 'home')
            ->create();

        foreach ($this->pageRepository->getList($criteria)->getItems() as $page) {
            $page->setContent('');
            $page->setContentHeading('');
            $page->setLayoutUpdateXml('');
            $page->setCustomLayoutUpdateXml('');
            $this->pageRepository->save($page);
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
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
