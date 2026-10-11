<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Cart;

use Sylius\Component\Core\Model\OrderInterface;

/**
 * Bridge to tmonier/sylius-quote-request-plugin (no dependency on it): its
 * "tmonier_quote_request.quote_cart_checker" service tells whether a cart holds an accepted
 * quote at locked prices. Registered by QuoteRequestBridgePass only when that service exists.
 */
final class QuoteRequestCartChecker implements LockedCartCheckerInterface
{
    public function __construct(private readonly object $quoteCartChecker)
    {
    }

    public function isLocked(OrderInterface $cart): bool
    {
        if (!method_exists($this->quoteCartChecker, 'isQuoteCart')) {
            return false;
        }

        return true === $this->quoteCartChecker->isQuoteCart($cart);
    }
}
