<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Cart;

use Sylius\Component\Order\Model\OrderInterface;

/**
 * Stands for the "tmonier_quote_request.quote_cart_checker" service of
 * tmonier/sylius-quote-request-plugin: a cart whose notes are "QUOTE CART" is a quote cart.
 */
final class FakeQuoteCartChecker
{
    public const NOTES = 'QUOTE CART';

    public function isQuoteCart(OrderInterface $order): bool
    {
        return self::NOTES === $order->getNotes();
    }
}
