<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Adder;

use Sylius\Component\Core\Model\ProductVariantInterface;

interface VariantLookupInterface
{
    /**
     * @param list<string> $codes
     *
     * @return array<string, ProductVariantInterface> variants indexed by the requested code
     */
    public function findByCodes(array $codes): array;
}
