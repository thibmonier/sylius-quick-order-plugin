<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tmonier\SyliusQuickOrderPlugin\DependencyInjection\TmonierSyliusQuickOrderExtension;

final class ExtensionPrependTest extends TestCase
{
    public function testTheTierPriceTableIsRenderedForMatrixProductsWhenTheTierPluginIsInstalled(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['TmonierSyliusTierPricingPlugin' => 'Tmonier\SyliusTierPricingPlugin\TmonierSyliusTierPricingPlugin']);

        (new TmonierSyliusQuickOrderExtension())->prepend($container);

        self::assertSame([['hooks' => ['tmonier_quick_order.shop.product.add_to_cart' => ['tmonier_tier_pricing' => [
            'template' => '@TmonierSyliusTierPricingPlugin/shop/product/tier_prices.html.twig',
            'priority' => 50,
        ]]]]], $container->getExtensionConfig('sylius_twig_hooks'));
    }

    public function testNothingIsPrependedWithoutTheTierPlugin(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', []);

        (new TmonierSyliusQuickOrderExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('sylius_twig_hooks'));
    }
}
