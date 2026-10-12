<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Twig;

use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixActivationCheckerInterface;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class QuickOrderExtension extends AbstractExtension
{
    public const TIERS_NONE = 'none';

    public const TIERS_SAME = 'same';

    public const TIERS_DIFFERENT = 'different';

    public function __construct(private readonly MatrixActivationCheckerInterface $matrixActivationChecker)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('tmonier_quick_order_is_matrix', fn (mixed $product): bool => $product instanceof ProductInterface && $this->matrixActivationChecker->isMatrixProduct($product)),
            new TwigFunction('tmonier_quick_order_tier_prices_scope', self::tierPricesScope(...), ['needs_environment' => true]),
        ];
    }

    /**
     * Whether the enabled variants of a product have the same tiers of
     * tmonier/sylius-tier-pricing-plugin (its "tmonier_tier_pricing_table" Twig function, looked up
     * at runtime: no dependency, "none" without that plugin).
     */
    public static function tierPricesScope(Environment $environment, mixed $product): string
    {
        $callable = $environment->getFunction('tmonier_tier_pricing_table')?->getCallable();
        if (!$product instanceof ProductInterface || !\is_callable($callable)) {
            return self::TIERS_NONE;
        }

        $signatures = [];
        foreach ($product->getEnabledVariants() as $variant) {
            if (!$variant instanceof ProductVariantInterface) {
                continue;
            }
            $table = $callable($variant);
            $rows = \is_object($table) && property_exists($table, 'rows') && \is_array($table->rows) ? $table->rows : [];
            $signatures[implode(',', array_map(
                static fn (mixed $row): string => \is_object($row) && property_exists($row, 'minQuantity') && property_exists($row, 'unitPrice')
                    ? (\is_scalar($row->minQuantity) ? (string) $row->minQuantity : '') . ':' . (\is_scalar($row->unitPrice) ? (string) $row->unitPrice : '')
                    : '?',
                $rows,
            ))] = true;
        }

        // An unknown table structure is never shown as if it were the product's.
        foreach (array_keys($signatures) as $signature) {
            if (str_contains((string) $signature, '?')) {
                return self::TIERS_DIFFERENT;
            }
        }
        if (1 === \count($signatures)) {
            return '' === array_key_first($signatures) ? self::TIERS_NONE : self::TIERS_SAME;
        }

        return [] === $signatures ? self::TIERS_NONE : self::TIERS_DIFFERENT;
    }
}
