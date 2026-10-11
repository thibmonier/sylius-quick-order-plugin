<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Cart;

use Sylius\Component\Core\Model\OrderInterface;

final class CompositeLockedCartChecker implements LockedCartCheckerInterface
{
    /** @param iterable<LockedCartCheckerInterface> $checkers */
    public function __construct(private readonly iterable $checkers)
    {
    }

    public function isLocked(OrderInterface $cart): bool
    {
        foreach ($this->checkers as $checker) {
            if ($checker->isLocked($cart)) {
                return true;
            }
        }

        return false;
    }
}
