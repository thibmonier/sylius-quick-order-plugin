<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Model\ChannelInterface as BaseChannelInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\OrderItemUnit;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Factory\OrderItemUnitFactory;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifier;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tests\Tmonier\SyliusQuickOrderPlugin\Unit\Matrix\MatrixFixtures;
use Tests\Tmonier\SyliusQuickOrderPlugin\Unit\Matrix\RecordingBulkCartAdder;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddMode;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult;
use Tmonier\SyliusQuickOrderPlugin\Adder\LineResult;
use Tmonier\SyliusQuickOrderPlugin\Adder\LineStatus;
use Tmonier\SyliusQuickOrderPlugin\Matrix\CellStatus;
use Tmonier\SyliusQuickOrderPlugin\Matrix\Matrix;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixActivationCheckerInterface;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixBuilderInterface;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixCell;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixOrderHandler;
use Tmonier\SyliusQuickOrderPlugin\Quantity\QuantityRounding;
use Tmonier\SyliusQuickOrderPlugin\Twig\Component\MatrixOrderComponent;

final class MatrixOrderComponentTest extends TestCase
{
    private ChannelInterface $channel;

    private Order $cart;

    /** @var list<MatrixCell> */
    private array $cells = [];

    protected function setUp(): void
    {
        $this->channel = MatrixFixtures::channel();
        $this->cart = new Order();
    }

    public function testTheEstimateReadsQuantitiesLikeTheCart(): void
    {
        $this->cells = [
            new MatrixCell(1, 'A', 'A', CellStatus::Orderable, 1000),
            new MatrixCell(2, 'B', 'B', CellStatus::Orderable, 500),
            new MatrixCell(3, 'C', 'C', CellStatus::Orderable, 700),
            new MatrixCell(4, 'D', 'D', CellStatus::OutOfStock, 900),
            new MatrixCell(5, 'E', 'E', CellStatus::Orderable, 100),
        ];
        $component = $this->component();
        $component->quantities = ['v1' => '12.0', 'v2' => '3,00', 'v3' => '1.000', 'v4' => '5', 'v5' => '10000', 'v99' => '7'];

        // 12 x 10.00 + 3 x 5.00; "1.000" is ambiguous, D not orderable, 10000 > line limit, v99 unknown.
        self::assertSame(['units' => 15, 'total' => 13500], $component->getEstimate());
    }

    public function testTheEstimateAppliesQuantityRulesWithTheCartContent(): void
    {
        $this->cells = [
            new MatrixCell(1, 'A', 'A', CellStatus::Orderable, 1000, null, 12, 6, 30),
            new MatrixCell(2, 'B', 'B', CellStatus::Orderable, 1000, null, 1, 6),
        ];
        $variant = MatrixFixtures::variant(MatrixFixtures::product('P', ['size' => ['S']], $this->channel), ['S']);
        (new \ReflectionProperty($variant, 'id'))->setValue($variant, 1);
        $this->addToCart($variant, 12);

        $roundUp = $this->component(QuantityRounding::RoundUp);
        // A: 12 in the cart + 5 = 17 -> 18 (6 more); B: 4 -> 6.
        $roundUp->quantities = ['v1' => '5', 'v2' => '4'];
        self::assertSame(['units' => 12, 'total' => 12000], $roundUp->getEstimate());
        // A: 12 + 24 = 36 > maximum 30: not counted.
        $roundUp->quantities = ['v1' => '24', 'v2' => ''];
        self::assertSame(['units' => 0, 'total' => 0], $roundUp->getEstimate());

        $reject = $this->component(QuantityRounding::Reject);
        $reject->quantities = ['v1' => '5', 'v2' => '6'];
        self::assertSame(['units' => 6, 'total' => 6000], $reject->getEstimate());
    }

    public function testAChangedQuantityDropsItsOldMessage(): void
    {
        $this->cells = [
            new MatrixCell(1, 'A', 'A', CellStatus::Orderable, 1000),
            new MatrixCell(2, 'B', 'B', CellStatus::Orderable, 1000),
        ];
        $component = $this->component();
        $component->quantities = ['v1' => '500', 'v2' => '7'];
        $component->lastResult = (new BulkAddResult([
            new LineResult('A', '500', 500, LineStatus::InsufficientStock, [1]),
            new LineResult('B', '7', 7, LineStatus::InvalidQuantity, [2]),
        ], true, null, BulkAddMode::Partial))->toArray();

        $previous = $component->quantities;
        $component->quantities['v1'] = '3';
        $component->onQuantitiesUpdated($previous);
        self::assertNull($component->getLineResult('A'));
        self::assertNotNull($component->getLineResult('B'));

        $previous = $component->quantities;
        $component->quantities['v2'] = '2';
        $component->onQuantitiesUpdated($previous);
        self::assertNull($component->lastResult, 'No failed line left: the banner goes too.');
    }

    public function testCellsThatBecomeOrderableGetAKey(): void
    {
        $this->cells = [new MatrixCell(1, 'A', 'A', CellStatus::OutOfStock, 1000)];
        $component = $this->component();
        $component->initializeQuantities();
        self::assertSame([], $component->quantities);

        // Restocked between two renders: the next re-render creates the model path.
        $this->cells = [new MatrixCell(1, 'A', 'A', CellStatus::Orderable, 1000)];
        $component = $this->component();
        $component->initializeQuantities();
        self::assertSame(['v1' => ''], $component->quantities);
    }

    public function testAddedLinesAreClearedAndFailedOnesKept(): void
    {
        $product = MatrixFixtures::product('P', ['size' => ['S', 'M']], $this->channel);
        $s = MatrixFixtures::variant($product, ['S']);
        $m = MatrixFixtures::variant($product, ['M']);
        $this->cells = [
            new MatrixCell(self::id($s), 'P-S', 'S', CellStatus::Orderable, 1000),
            new MatrixCell(self::id($m), 'P-M', 'M', CellStatus::Orderable, 1000),
        ];
        $component = $this->component(adder: new MatrixOrderHandler(new class() implements \Tmonier\SyliusQuickOrderPlugin\Adder\BulkCartAdderInterface {
            public function validate(\Sylius\Component\Core\Model\OrderInterface $cart, \Tmonier\SyliusQuickOrderPlugin\Input\QuickOrderInput $input): BulkAddResult
            {
                return new BulkAddResult([]);
            }

            public function add(\Sylius\Component\Core\Model\OrderInterface $cart, \Tmonier\SyliusQuickOrderPlugin\Input\QuickOrderInput $input, BulkAddMode $mode): BulkAddResult
            {
                return new BulkAddResult([
                    new LineResult('P-S', '2', 2, LineStatus::Added, [1], addedQuantity: 2),
                    new LineResult('P-M', '500', 500, LineStatus::InsufficientStock, [2]),
                ], true, null, $mode);
            }
        }), product: $product);
        $component->quantities = [MatrixOrderHandler::key($s) => '2', MatrixOrderHandler::key($m) => '500'];

        $component->add();

        self::assertSame([MatrixOrderHandler::key($s) => '', MatrixOrderHandler::key($m) => '500'], $component->quantities);
        self::assertSame(['M'], array_map(static fn (MatrixCell $cell): string => $cell->label, $component->getFailedCells()));
    }

    private function component(QuantityRounding $rounding = QuantityRounding::Reject, ?MatrixOrderHandler $adder = null, ?ProductInterface $product = null): MatrixOrderComponent
    {
        $test = $this;
        $component = new MatrixOrderComponent(
            $this->createStub(RepositoryInterface::class),
            new class($this->channel) implements ChannelContextInterface {
                public function __construct(private readonly ChannelInterface $channel)
                {
                }

                public function getChannel(): BaseChannelInterface
                {
                    return $this->channel;
                }
            },
            new class($this->cart) implements CartContextInterface {
                public function __construct(private readonly Order $cart)
                {
                }

                public function getCart(): BaseOrderInterface
                {
                    return $this->cart;
                }
            },
            new class() implements MatrixActivationCheckerInterface {
                public function isMatrixProduct(ProductInterface $product): bool
                {
                    return true;
                }
            },
            new class($test) implements MatrixBuilderInterface {
                public function __construct(private readonly self|MatrixOrderComponentTest $test)
                {
                }

                public function build(ProductInterface $product, ChannelInterface $channel): Matrix
                {
                    \assert($this->test instanceof MatrixOrderComponentTest);

                    return new Matrix(Matrix::LAYOUT_LIST, $this->test->cells());
                }
            },
            $adder ?? new MatrixOrderHandler(new RecordingBulkCartAdder()),
            'partial',
            20000,
            null,
            9999,
            $rounding,
        );
        $component->product = $product ?? MatrixFixtures::product('P', ['size' => ['S']], $this->channel);
        $component->setLiveResponder(new \Symfony\UX\LiveComponent\LiveResponder());

        return $component;
    }

    private static function id(ProductVariantInterface $variant): int
    {
        $id = $variant->getId();
        self::assertIsInt($id);

        return $id;
    }

    /** @return list<MatrixCell> */
    public function cells(): array
    {
        return $this->cells;
    }

    private function addToCart(ProductVariantInterface $variant, int $quantity): void
    {
        $item = new OrderItem();
        $item->setVariant($variant);
        (new OrderItemQuantityModifier(new OrderItemUnitFactory(OrderItemUnit::class)))->modify($item, $quantity);
        $this->cart->addItem($item);
    }
}
