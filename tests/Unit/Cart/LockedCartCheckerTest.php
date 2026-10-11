<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Unit\Cart;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Tests\Tmonier\SyliusQuickOrderPlugin\Cart\FakeQuoteCartChecker;
use Tmonier\SyliusQuickOrderPlugin\Cart\CompositeLockedCartChecker;
use Tmonier\SyliusQuickOrderPlugin\Cart\LockedCartCheckerInterface;
use Tmonier\SyliusQuickOrderPlugin\Cart\QuoteRequestCartChecker;

final class LockedCartCheckerTest extends TestCase
{
    public function testTheCompositeLocksWhenOneCheckerLocks(): void
    {
        $never = new class() implements LockedCartCheckerInterface {
            public function isLocked(OrderInterface $cart): bool
            {
                return false;
            }
        };
        $cart = new Order();
        $cart->setNotes(FakeQuoteCartChecker::NOTES);

        self::assertFalse((new CompositeLockedCartChecker([]))->isLocked($cart));
        self::assertFalse((new CompositeLockedCartChecker([$never]))->isLocked($cart));
        self::assertTrue((new CompositeLockedCartChecker([$never, new QuoteRequestCartChecker(new FakeQuoteCartChecker())]))->isLocked($cart));
    }

    public function testTheQuoteRequestBridgeIsDuckTyped(): void
    {
        $quoteCart = new Order();
        $quoteCart->setNotes(FakeQuoteCartChecker::NOTES);

        self::assertTrue((new QuoteRequestCartChecker(new FakeQuoteCartChecker()))->isLocked($quoteCart));
        self::assertFalse((new QuoteRequestCartChecker(new FakeQuoteCartChecker()))->isLocked(new Order()));
        // An incompatible service (renamed method) never locks instead of crashing.
        self::assertFalse((new QuoteRequestCartChecker(new \stdClass()))->isLocked($quoteCart));
    }
}
