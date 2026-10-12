<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Matrix;

use Sylius\Component\Core\Calculator\ProductVariantPricesCalculatorInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Inventory\Checker\AvailabilityCheckerInterface;
use Sylius\Component\Product\Model\ProductOptionInterface;
use Sylius\Component\Product\Model\ProductOptionValueInterface;
use Tmonier\SyliusQuickOrderPlugin\Quantity\QuantityConstraintProviderInterface;

/**
 * Builds the matrix of a product for a channel. Prices come from the catalog price calculator
 * (CatalogPricesCalculatorInterface on Sylius 2.3, the variant price calculator on 2.2); the
 * struck price is the Omnibus "lowest price before discount" when the channel shows it.
 */
final class MatrixBuilder implements MatrixBuilderInterface
{
    public function __construct(
        private readonly ProductVariantPricesCalculatorInterface $priceCalculator,
        private readonly AvailabilityCheckerInterface $availabilityChecker,
        private readonly QuantityConstraintProviderInterface $quantityConstraintProvider,
        private readonly int $maxGridCells,
    ) {
    }

    public function build(ProductInterface $product, ChannelInterface $channel): Matrix
    {
        $cells = [];
        $variants = [];
        foreach ($product->getVariants() as $variant) {
            if ($variant instanceof ProductVariantInterface && null !== $variant->getId()) {
                $cells[] = $this->cell($variant, $channel);
                $variants[] = $variant;
            }
        }

        /** @var list<ProductOptionInterface> $options */
        $options = array_values($product->getOptions()->toArray());
        if (2 === \count($options)) {
            $grid = $this->grid($options, $variants, $cells);
            if (null !== $grid) {
                return $grid;
            }
        }

        return new Matrix(Matrix::LAYOUT_LIST, $cells, 1 === \count($options) ? $options[0]->getName() : null);
    }

    /**
     * @param list<ProductOptionInterface> $options
     * @param list<ProductVariantInterface> $variants
     * @param list<MatrixCell> $cells same order as $variants
     */
    private function grid(array $options, array $variants, array $cells): ?Matrix
    {
        $rows = self::values($options[0]);
        $columns = self::values($options[1]);
        if (\count($rows) * \count($columns) > $this->maxGridCells) {
            return null;
        }

        $grid = [];
        foreach ($variants as $index => $variant) {
            $row = self::valueOf($variant, $options[0]);
            $column = self::valueOf($variant, $options[1]);
            // A variant without both values, or two variants in one cell: a list is safer.
            if (null === $row || null === $column || isset($grid[$row][$column])) {
                return null;
            }
            $grid[$row][$column] = $cells[$index];
        }

        // Option values without any variant are left out.
        $usedColumns = [];
        foreach ($grid as $rowCells) {
            foreach (array_keys($rowCells) as $columnCode) {
                $usedColumns[$columnCode] = true;
            }
        }
        $rows = array_values(array_filter($rows, static fn (array $value): bool => isset($grid[$value['code']])));
        $columns = array_values(array_filter($columns, static fn (array $value): bool => isset($usedColumns[$value['code']])));

        return new Matrix(Matrix::LAYOUT_GRID, $cells, $options[0]->getName(), $options[1]->getName(), $rows, $columns, $grid);
    }

    private function cell(ProductVariantInterface $variant, ChannelInterface $channel): MatrixCell
    {
        $id = $variant->getId();
        $variantId = \is_int($id) ? $id : (int) (\is_scalar($id) ? $id : 0);
        $label = $variant->getName() ?? implode(' / ', array_map(
            static fn (ProductOptionValueInterface $value): string => (string) $value->getValue(),
            $variant->getOptionValues()->toArray(),
        ));
        $code = (string) $variant->getCode();

        if (!$variant->isEnabled()) {
            return new MatrixCell($variantId, $code, $label, CellStatus::Disabled);
        }
        if (null === $variant->getChannelPricingForChannel($channel)?->getPrice()) {
            return new MatrixCell($variantId, $code, $label, CellStatus::NoPrice);
        }

        $context = ['channel' => $channel];
        $price = $this->priceCalculator->calculate($variant, $context);
        $lowest = $this->priceCalculator->calculateLowestPriceBeforeDiscount($variant, $context);
        $struck = null !== $lowest && $lowest > $price ? $lowest : null;
        if (!$this->availabilityChecker->isStockAvailable($variant)) {
            return new MatrixCell($variantId, $code, $label, CellStatus::OutOfStock, $price, $struck);
        }

        $constraint = $this->quantityConstraintProvider->getConstraint($variant, $channel);

        return new MatrixCell($variantId, $code, $label, CellStatus::Orderable, $price, $struck, $constraint->minimum ?? 1, $constraint->multipleOf ?? 1);
    }

    /** @return list<array{code: string, label: string}> */
    private static function values(ProductOptionInterface $option): array
    {
        $values = [];
        foreach ($option->getValues() as $value) {
            $values[] = ['code' => (string) $value->getCode(), 'label' => (string) $value->getValue()];
        }

        return $values;
    }

    private static function valueOf(ProductVariantInterface $variant, ProductOptionInterface $option): ?string
    {
        foreach ($variant->getOptionValues() as $value) {
            if ($value->getOption() === $option || $value->getOptionCode() === $option->getCode()) {
                return (string) $value->getCode();
            }
        }

        return null;
    }
}
