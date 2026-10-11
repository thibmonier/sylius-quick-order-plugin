<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Quantity;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

final class NullQuantityConstraintProvider implements QuantityConstraintProviderInterface
{
    public function getConstraint(ProductVariantInterface $variant, ChannelInterface $channel): ?QuantityConstraint
    {
        return null;
    }
}
