<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Tmonier\SyliusQuickOrderPlugin\DependencyInjection\Compiler\CatalogPriceCalculatorPass;

final class CatalogPriceCalculatorPassTest extends TestCase
{
    public function testSylius23UsesTheCatalogCalculator(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('sylius.calculator.product_variant_price', new Definition(\stdClass::class));
        $container->setDefinition('sylius.calculator.product_variant_catalog_price', new Definition(\stdClass::class));

        (new CatalogPriceCalculatorPass())->process($container);

        self::assertSame('sylius.calculator.product_variant_catalog_price', (string) $container->getAlias(CatalogPriceCalculatorPass::ALIAS));
    }

    public function testSylius22UsesTheVariantPriceCalculator(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('sylius.calculator.product_variant_price', new Definition(\stdClass::class));

        (new CatalogPriceCalculatorPass())->process($container);

        self::assertSame('sylius.calculator.product_variant_price', (string) $container->getAlias(CatalogPriceCalculatorPass::ALIAS));
    }

    public function testAnApplicationAliasIsKept(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('sylius.calculator.product_variant_catalog_price', new Definition(\stdClass::class));
        $container->setDefinition('app.matrix_prices', new Definition(\stdClass::class));
        $container->setAlias(CatalogPriceCalculatorPass::ALIAS, 'app.matrix_prices');

        (new CatalogPriceCalculatorPass())->process($container);

        self::assertSame('app.matrix_prices', (string) $container->getAlias(CatalogPriceCalculatorPass::ALIAS));
    }
}
