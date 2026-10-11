<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Quantity;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

/**
 * Extension point for the owner of logistics data (units per case, "multiple of N"): alias
 * "tmonier_quick_order.quantity_constraint_provider" to your implementation. The default one
 * returns no constraint.
 */
interface QuantityConstraintProviderInterface
{
    public function getConstraint(ProductVariantInterface $variant, ChannelInterface $channel): ?QuantityConstraint;
}
