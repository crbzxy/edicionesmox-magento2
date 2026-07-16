<?php
declare(strict_types=1);

namespace EdicionesMox\Catalog\Setup\Patch\Data;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config as AppConfig;
use Magento\Framework\App\Config\Storage\WriterInterface as ConfigWriter;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Sin sufijo .html en productos/categorías. Es dependencia de
 * CreateBrandCategories/CreateBrandProducts a propósito: el sufijo debe
 * quedar en '' ANTES de que se cree cualquier producto/categoría, porque
 * Magento\CatalogUrlRewrite\Model\ProductUrlPathGenerator cachea el sufijo
 * en una propiedad de instancia la primera vez que lo lee — cambiar el
 * config a mitad del mismo proceso de setup:upgrade (p.ej. en un patch
 * posterior) no alcanza a esa instancia ya cacheada, así que las entidades
 * creadas después seguirían recibiendo ".html".
 */
class ConfigureSeoUrls implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly ConfigWriter $configWriter,
        private readonly TypeListInterface $cacheTypeList,
        private readonly AppConfig $appConfig
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $this->configWriter->save('catalog/seo/product_url_suffix', '');
        $this->configWriter->save('catalog/seo/category_url_suffix', '');
        $this->cacheTypeList->invalidate(['config']);
        // invalidate() solo marca el tipo "config" como desactualizado para la
        // próxima limpieza manual; no alcanza a los patches que crean
        // productos/categorías más abajo en este mismo proceso de
        // setup:upgrade si algo ya leyó (y cacheó en memoria) el árbol
        // "default" de config antes de este punto. clean() fuerza la
        // relectura real desde la BD ahora mismo.
        $this->appConfig->clean();

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
