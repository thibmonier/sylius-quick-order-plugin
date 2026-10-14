<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Unit\Matrix;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Calculator\ProductVariantPricesCalculatorInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Inventory\Checker\AvailabilityChecker;
use Tmonier\SyliusQuickOrderPlugin\Matrix\CellStatus;
use Tmonier\SyliusQuickOrderPlugin\Matrix\Matrix;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixBuilder;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixCell;
use Tmonier\SyliusQuickOrderPlugin\Quantity\QuantityConstraint;
use Tmonier\SyliusQuickOrderPlugin\Quantity\QuantityConstraintProviderInterface;

final class MatrixBuilderTest extends TestCase
{
    private ChannelInterface $channel;

    protected function setUp(): void
    {
        $this->channel = MatrixFixtures::channel();
    }

    public function testTwoOptionsMakeAGridWithEveryCellStatus(): void
    {
        $product = MatrixFixtures::product('TEE', ['size' => ['S', 'M'], 'colour' => ['RED', 'BLUE']], $this->channel);
        MatrixFixtures::variant($product, ['S', 'RED']);
        MatrixFixtures::variant($product, ['S', 'BLUE'], enabled: false);
        MatrixFixtures::variant($product, ['M', 'RED'], null);
        MatrixFixtures::variant($product, ['M', 'BLUE'], 1000, true, 0);

        $matrix = $this->builder()->build($product, $this->channel);

        self::assertSame(Matrix::LAYOUT_GRID, $matrix->layout);
        self::assertSame('Size', $matrix->rowLabel);
        self::assertSame('Colour', $matrix->columnLabel);
        self::assertSame(['S', 'M'], array_column($matrix->rows, 'label'));
        self::assertSame(['RED', 'BLUE'], array_column($matrix->columns, 'label'));
        self::assertSame(
            ['orderable', 'disabled', 'no_price', 'out_of_stock'],
            [$matrix->grid['size_S']['colour_RED']->status->value, $matrix->grid['size_S']['colour_BLUE']->status->value, $matrix->grid['size_M']['colour_RED']->status->value, $matrix->grid['size_M']['colour_BLUE']->status->value],
        );
        self::assertSame(1000, $matrix->grid['size_S']['colour_RED']->price);
        // A list leaves disabled variants out.
        self::assertCount(3, $matrix->getListedCells());
        self::assertTrue($matrix->hasOrderableCell());
    }

    public function testUnusedOptionValuesAreNotShown(): void
    {
        $product = MatrixFixtures::product('TEE', ['size' => ['S', 'M', 'L'], 'colour' => ['RED', 'BLUE']], $this->channel);
        MatrixFixtures::variant($product, ['S', 'RED']);
        MatrixFixtures::variant($product, ['M', 'BLUE']);

        $matrix = $this->builder()->build($product, $this->channel);

        self::assertSame(['S', 'M'], array_column($matrix->rows, 'label'));
        self::assertArrayNotHasKey('colour_BLUE', $matrix->grid['size_S']);
    }

    public function testOneOptionMoreThanTwoOptionsOrTooManyCellsMakeAList(): void
    {
        $one = MatrixFixtures::product('ONE', ['size' => ['S', 'M']], $this->channel);
        MatrixFixtures::variant($one, ['S']);
        $matrix = $this->builder()->build($one, $this->channel);
        self::assertSame(Matrix::LAYOUT_LIST, $matrix->layout);
        self::assertSame('Size', $matrix->rowLabel);

        $three = MatrixFixtures::product('THREE', ['a' => ['1'], 'b' => ['1'], 'c' => ['1']], $this->channel);
        MatrixFixtures::variant($three, ['1', '1', '1']);
        self::assertSame(Matrix::LAYOUT_LIST, $this->builder()->build($three, $this->channel)->layout);

        $big = MatrixFixtures::product('BIG', ['size' => ['S', 'M', 'L'], 'colour' => ['RED', 'BLUE']], $this->channel);
        foreach (['S', 'M', 'L'] as $size) {
            MatrixFixtures::variant($big, [$size, 'RED']);
            MatrixFixtures::variant($big, [$size, 'BLUE']);
        }
        self::assertSame(Matrix::LAYOUT_LIST, $this->builder(maxGridCells: 5)->build($big, $this->channel)->layout);
        self::assertSame(Matrix::LAYOUT_GRID, $this->builder(maxGridCells: 6)->build($big, $this->channel)->layout);
    }

    public function testOnlyTheOptionValuesUsedByTheProductCountForTheGridLimit(): void
    {
        // Option values are shared by every product: 30 sizes x 10 colours in the catalog.
        $sizes = array_map(static fn (int $i): string => 'S' . $i, range(1, 30));
        $colours = array_map(static fn (int $i): string => 'C' . $i, range(1, 10));
        $product = MatrixFixtures::product('TEE', ['size' => $sizes, 'colour' => $colours], $this->channel);
        foreach (['S1', 'S2', 'S3', 'S4'] as $size) {
            foreach (['C1', 'C2', 'C3'] as $colour) {
                MatrixFixtures::variant($product, [$size, $colour]);
            }
        }

        $matrix = $this->builder(maxGridCells: 12)->build($product, $this->channel);

        self::assertSame(Matrix::LAYOUT_GRID, $matrix->layout);
        self::assertCount(4, $matrix->rows);
        self::assertCount(3, $matrix->columns);
    }

    public function testVariantsThatDoNotFitAGridMakeAList(): void
    {
        $product = MatrixFixtures::product('TEE', ['size' => ['S'], 'colour' => ['RED']], $this->channel);
        MatrixFixtures::variant($product, ['S', 'RED']);
        MatrixFixtures::variant($product, ['S', 'RED']);
        self::assertSame(Matrix::LAYOUT_LIST, $this->builder()->build($product, $this->channel)->layout, 'Two variants in one cell');

        $incomplete = MatrixFixtures::product('INC', ['size' => ['S'], 'colour' => ['RED']], $this->channel);
        MatrixFixtures::variant($incomplete, ['S']);
        self::assertSame(Matrix::LAYOUT_LIST, $this->builder()->build($incomplete, $this->channel)->layout, 'A variant without colour');
    }

    public function testPricesFollowTheCoreRules(): void
    {
        $product = MatrixFixtures::product('TEE', ['size' => ['S', 'M', 'L']], $this->channel);
        MatrixFixtures::variant($product, ['S']);
        MatrixFixtures::variant($product, ['M']);
        MatrixFixtures::variant($product, ['L']);

        // S: discounted, the channel shows the Omnibus price. M: discounted, the channel does not.
        // L: not discounted (a lowest price alone is never shown).
        $matrix = $this->builder(lowest: ['TEE-S' => 900, 'TEE-L' => 800], original: ['TEE-S' => 1500, 'TEE-M' => 1200])->build($product, $this->channel);

        self::assertSame([1500, 1200, null], array_map(static fn (MatrixCell $cell): ?int => $cell->struckPrice, $matrix->cells));
        self::assertSame([900, null, null], array_map(static fn (MatrixCell $cell): ?int => $cell->lowestPriceBeforeDiscount, $matrix->cells));
    }

    public function testAnEmptyMatrixTellsStockFromAvailability(): void
    {
        $unpriced = MatrixFixtures::product('NOPRICE', ['size' => ['S', 'M']], $this->channel);
        MatrixFixtures::variant($unpriced, ['S'], null);
        MatrixFixtures::variant($unpriced, ['M'], enabled: false);
        $soldOut = MatrixFixtures::product('SOLDOUT', ['size' => ['S', 'M']], $this->channel);
        MatrixFixtures::variant($soldOut, ['S'], 1000, true, 0);
        MatrixFixtures::variant($soldOut, ['M'], null);

        self::assertFalse($this->builder()->build($unpriced, $this->channel)->isOutOfStock());
        self::assertTrue($this->builder()->build($soldOut, $this->channel)->isOutOfStock());
    }

    public function testQuantityRulesAreShown(): void
    {
        $product = MatrixFixtures::product('TEE', ['size' => ['S']], $this->channel);
        MatrixFixtures::variant($product, ['S']);

        $cell = $this->builder(constraint: new QuantityConstraint(12, 6, 60))->build($product, $this->channel)->cells[0];

        self::assertSame([12, 6, 60], [$cell->minimum, $cell->step, $cell->maximum]);
        self::assertEquals(new QuantityConstraint(12, 6, 60), $cell->getConstraint());
        self::assertSame(CellStatus::Orderable, $cell->status);
        self::assertSame('v' . $cell->variantId, $cell->getKey());
    }

    /**
     * @param array<string, int> $original original price by variant code (default: the price)
     * @param array<string, int> $lowest lowest price before discount by variant code
     */
    private function builder(int $maxGridCells = 60, array $lowest = [], ?QuantityConstraint $constraint = null, array $original = []): MatrixBuilder
    {
        return new MatrixBuilder(
            new class($lowest, $original) implements ProductVariantPricesCalculatorInterface {
                /**
                 * @param array<string, int> $lowest
                 * @param array<string, int> $original
                 */
                public function __construct(private readonly array $lowest, private readonly array $original)
                {
                }

                public function calculate(ProductVariantInterface $productVariant, array $context): int
                {
                    return 1000;
                }

                public function calculateOriginal(ProductVariantInterface $productVariant, array $context): int
                {
                    return $this->original[(string) $productVariant->getCode()] ?? 1000;
                }

                public function calculateLowestPriceBeforeDiscount(ProductVariantInterface $productVariant, array $context): ?int
                {
                    return $this->lowest[(string) $productVariant->getCode()] ?? null;
                }
            },
            new AvailabilityChecker(),
            new class($constraint) implements QuantityConstraintProviderInterface {
                public function __construct(private readonly ?QuantityConstraint $constraint)
                {
                }

                public function getConstraint(ProductVariantInterface $variant, ChannelInterface $channel): ?QuantityConstraint
                {
                    return $this->constraint;
                }
            },
            $maxGridCells,
        );
    }
}
