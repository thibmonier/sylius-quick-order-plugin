<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Tests\Tmonier\SyliusQuickOrderPlugin\Cart\FakeQuoteCartChecker;
use Tmonier\SyliusQuickOrderPlugin\DependencyInjection\Compiler\QuoteRequestBridgePass;

final class QuoteRequestBridgePassTest extends TestCase
{
    public function testNothingIsRegisteredWithoutTheQuotePlugin(): void
    {
        $container = new ContainerBuilder();
        (new QuoteRequestBridgePass())->process($container);

        self::assertFalse($container->has('tmonier_quick_order.locked_cart_checker.quote_request'));
    }

    public function testTheBridgeIsTaggedWhenTheQuotePluginIsInstalled(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(QuoteRequestBridgePass::QUOTE_CART_CHECKER, new Definition(FakeQuoteCartChecker::class));
        (new QuoteRequestBridgePass())->process($container);

        $definition = $container->getDefinition('tmonier_quick_order.locked_cart_checker.quote_request');
        self::assertTrue($definition->hasTag('tmonier_quick_order.locked_cart_checker'));
        $argument = $definition->getArgument(0);
        self::assertInstanceOf(Reference::class, $argument);
        self::assertSame(QuoteRequestBridgePass::QUOTE_CART_CHECKER, (string) $argument);
    }
}
