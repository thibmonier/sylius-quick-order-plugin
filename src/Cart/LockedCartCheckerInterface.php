<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Cart;

use Sylius\Component\Core\Model\OrderInterface;

/**
 * Extension point: a cart whose content must not change (quote cart, approval in progress...).
 * Tag implementations with "tmonier_quick_order.locked_cart_checker"; quick order and reorder
 * refuse such carts as a whole.
 */
interface LockedCartCheckerInterface
{
    public function isLocked(OrderInterface $cart): bool;
}
