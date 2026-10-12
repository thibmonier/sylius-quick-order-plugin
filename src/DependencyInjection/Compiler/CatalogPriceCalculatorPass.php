<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Catalog prices of the matrix: the catalog calculator of Sylius 2.3 (decorated by shops that
 * customise displayed prices), the variant price calculator on Sylius 2.2 (UPGRADE-2.3.md).
 */
final class CatalogPriceCalculatorPass implements CompilerPassInterface
{
    public const ALIAS = 'tmonier_quick_order.catalog_price_calculator';

    public function process(ContainerBuilder $container): void
    {
        if ($container->has(self::ALIAS)) {
            return;
        }

        $container->setAlias(self::ALIAS, $container->has('sylius.calculator.product_variant_catalog_price')
            ? 'sylius.calculator.product_variant_catalog_price'
            : 'sylius.calculator.product_variant_price');
    }
}
