<?php
declare(strict_types=1);

namespace EdicionesMox\LandingPage\ViewModel;

use EdicionesMox\Catalog\BrandCatalog;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

class HomeGallery implements ArgumentInterface
{
    private const ARTIST = 'Ediciones Mox';

    private const INSTAGRAM_URL = 'https://www.instagram.com/edicionesmox/?hl=es';

    public function __construct(
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly ImageHelper $imageHelper
    ) {
    }

    public function getInstagramUrl(): string
    {
        return self::INSTAGRAM_URL;
    }

    public function getColeccionUrl(): string
    {
        try {
            $categoryId = $this->resolveCategoryIdByUrlKey(BrandCatalog::COLECCION_URL_KEY);
            $category = $this->categoryRepository->get(
                $categoryId,
                (int) $this->storeManager->getStore()->getId()
            );

            return (string) $category->getUrl();
        } catch (NoSuchEntityException) {
            return '#archive';
        }
    }

    /**
     * @return list<array{
     *     sku: string,
     *     name: string,
     *     meta: string,
     *     artist: string,
     *     url: string,
     *     imageUrl: string
     * }>
     */
    public function getSpecimens(): array
    {
        try {
            $categoryId = $this->resolveCategoryIdByUrlKey(BrandCatalog::DESTACADOS_URL_KEY);
        } catch (NoSuchEntityException) {
            return [];
        }

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(['name', 'short_description', 'image', 'small_image', 'thumbnail']);
        $collection->addCategoriesFilter(['in' => [$categoryId]]);
        $collection->addAttributeToFilter('status', 1);
        $collection->addAttributeToFilter('sku', ['in' => BrandCatalog::PRODUCT_SKUS]);
        $collection->setOrder('sku', 'ASC');
        $collection->addUrlRewrite($categoryId);
        $collection->addStoreFilter($this->storeManager->getStore());

        $specimens = [];
        /** @var Product $product */
        foreach ($collection as $product) {
            $meta = trim(strip_tags((string) $product->getShortDescription()));
            $specimens[] = [
                'sku' => (string) $product->getSku(),
                'name' => (string) $product->getName(),
                'meta' => $meta !== '' ? $meta : self::ARTIST,
                'artist' => self::ARTIST,
                'url' => (string) $product->getProductUrl(),
                'imageUrl' => $this->resolveProductImageUrl($product),
            ];
        }

        return $specimens;
    }

    public function getPieceCount(): int
    {
        return count($this->getSpecimens());
    }

    private function resolveProductImageUrl(Product $product): string
    {
        return $this->imageHelper
            ->init($product, 'category_page_grid')
            ->setImageFile($product->getSmallImage() ?: $product->getImage())
            ->getUrl();
    }

    /**
     * @throws NoSuchEntityException
     */
    private function resolveCategoryIdByUrlKey(string $urlKey): int
    {
        $collection = $this->categoryCollectionFactory->create();
        $collection->setStoreId((int) $this->storeManager->getStore()->getId());
        $collection->addAttributeToFilter('url_key', $urlKey);
        $collection->setPageSize(1);
        $category = $collection->getFirstItem();
        $categoryId = (int) $category->getId();
        if ($categoryId === 0) {
            throw new NoSuchEntityException(
                __('Category with URL key "%1" was not found.', $urlKey)
            );
        }

        return $categoryId;
    }
}
