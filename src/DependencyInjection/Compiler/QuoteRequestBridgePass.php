<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Tmonier\SyliusQuickOrderPlugin\Cart\QuoteRequestCartChecker;

/**
 * Refuses the carts of accepted quotes of tmonier/sylius-quote-request-plugin when it is
 * installed: their lines and prices are locked, bulk additions would be merged into locked lines.
 */
final class QuoteRequestBridgePass implements CompilerPassInterface
{
    public const QUOTE_CART_CHECKER = 'tmonier_quote_request.quote_cart_checker';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(self::QUOTE_CART_CHECKER)) {
            return;
        }

        $container->setDefinition(
            'tmonier_quick_order.locked_cart_checker.quote_request',
            (new Definition(QuoteRequestCartChecker::class))
                ->setArguments([new Reference(self::QUOTE_CART_CHECKER)])
                ->addTag('tmonier_quick_order.locked_cart_checker'),
        );
    }
}
