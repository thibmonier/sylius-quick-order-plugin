<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Matrix;

use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

/**
 * No field on Product: configuration lists first, then an optional checkbox attribute, then the
 * automatic rule "1 or 2 options and at most max_variants enabled variants".
 */
final class MatrixActivationChecker implements MatrixActivationCheckerInterface
{
    /**
     * @param list<string> $products
     * @param list<string> $excludedProducts
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly bool $auto,
        private readonly int $maxVariants,
        private readonly array $products = [],
        private readonly array $excludedProducts = [],
        private readonly ?string $attribute = null,
    ) {
    }

    public function isMatrixProduct(ProductInterface $product): bool
    {
        if (!$this->enabled || $product->isSimple() || $product->getOptions()->isEmpty()) {
            return false;
        }

        $code = (string) $product->getCode();
        if (\in_array($code, $this->excludedProducts, true)) {
            return false;
        }
        if (\in_array($code, $this->products, true)) {
            return true;
        }

        if (null !== $this->attribute) {
            foreach ($product->getAttributes() as $value) {
                if ($this->attribute === $value->getAttribute()?->getCode() && \is_bool($value->getValue())) {
                    return $value->getValue();
                }
            }
        }

        if (!$this->auto || $product->getOptions()->count() > 2) {
            return false;
        }

        $enabled = $product->getVariants()->filter(static fn (mixed $variant): bool => $variant instanceof ProductVariantInterface && $variant->isEnabled())->count();

        return $enabled > 0 && $enabled <= $this->maxVariants;
    }
}
