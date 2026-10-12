<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Unit\Adder;

use Sylius\Component\Core\Model\ProductVariantInterface;
use Tmonier\SyliusQuickOrderPlugin\Adder\VariantLookupInterface;

final class InMemoryVariantLookup implements VariantLookupInterface
{
    public int $calls = 0;

    /** @var array<string, ProductVariantInterface> */
    private array $variants = [];

    public function add(ProductVariantInterface $variant): void
    {
        $this->variants[(string) $variant->getCode()] = $variant;
    }

    public function alias(string $code, ProductVariantInterface $variant): void
    {
        $this->variants[$code] = $variant;
    }

    public function findByCodes(array $codes): array
    {
        ++$this->calls;
        $found = [];
        foreach ($codes as $code) {
            if (isset($this->variants[$code])) {
                $found[$code] = $this->variants[$code];
            }
        }

        return $found;
    }
}
