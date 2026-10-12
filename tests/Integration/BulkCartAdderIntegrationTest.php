<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\Tmonier\SyliusQuickOrderPlugin\TestCatalog;
use Tests\Tmonier\SyliusQuickOrderPlugin\Unit\Adder\CountingOrderProcessor;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddMode;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkCartAdder;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkCartAdderInterface;
use Tmonier\SyliusQuickOrderPlugin\Adder\LineResult;
use Tmonier\SyliusQuickOrderPlugin\Input\PasteParser;

/** The real Sylius services and database, in a transaction rolled back after each test. */
final class BulkCartAdderIntegrationTest extends KernelTestCase
{
    private TestCatalog $catalog;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection()->beginTransaction();
        $this->catalog = new TestCatalog(self::getContainer());
    }

    protected function tearDown(): void
    {
        if ($this->connection()->isTransactionActive()) {
            $this->connection()->rollBack();
        }
        parent::tearDown();
    }

    public function testTwoHundredLinesRunTheOrderProcessingOnce(): void
    {
        $text = '';
        for ($i = 1; $i <= 200; ++$i) {
            $code = sprintf('QO-%s-%03d', $this->catalog->suffix, $i);
            $this->catalog->variant($code, 1000 + $i);
            $text .= $code . ";2\n";
        }
        $this->entityManager()->clear();
        $cart = $this->catalog->cart();

        $processor = new CountingOrderProcessor(function (\Sylius\Component\Order\Model\OrderInterface $order): void {
            $this->service('sylius.order_processing.order_processor', OrderProcessorInterface::class)->process($order);
        });
        $result = $this->adder($processor)->add($cart, (new PasteParser(200))->parse($text), BulkAddMode::Partial);

        self::assertSame(200, $result->countAdded());
        self::assertSame(1, $processor->calls);

        $id = $cart->getId();
        $this->entityManager()->clear();
        $reloaded = $this->entityManager()->find(OrderInterface::class, $id);
        self::assertInstanceOf(OrderInterface::class, $reloaded);
        self::assertCount(200, $reloaded->getItems());
        self::assertSame(400, $reloaded->getTotalQuantity());
        // Unit prices from the channel pricings (item totals may carry random fixture promotions).
        $subtotal = 0;
        foreach ($reloaded->getItems() as $item) {
            $subtotal += $item->getUnitPrice() * $item->getQuantity();
        }
        self::assertSame(2 * (200 * 1000 + 200 * 201 / 2), $subtotal);
    }

    public function testBulkAndOneByOneGiveTheSameTotals(): void
    {
        $channelCode = (string) $this->catalog->channel()->getCode();
        // Only the two test promotions, in a fixed order: no dependency on the fixture promotions.
        $this->catalog->archiveOtherPromotions();
        $this->catalog->promotion('cart_quantity', ['count' => 5], 'order_percentage_discount', ['percentage' => 0.1], 1001);
        $this->catalog->promotion('item_total', [$channelCode => ['amount' => 5000]], 'order_fixed_discount', [$channelCode => ['amount' => 700]], 1000);
        $lines = [];
        foreach ([[1999, 2], [4550, 1], [999, 3], [12000, 1]] as $index => [$price, $quantity]) {
            $code = sprintf('QO-%s-T%d', $this->catalog->suffix, $index);
            $lines[] = [$this->catalog->variant($code, $price), $quantity];
        }

        $bulk = $this->catalog->cart();
        $text = implode("\n", array_map(static fn (array $line): string => $line[0]->getCode() . ';' . $line[1], $lines));
        $result = $this->service('tmonier_quick_order.bulk_cart_adder', BulkCartAdderInterface::class)->add($bulk, (new PasteParser(200))->parse($text), BulkAddMode::Partial);
        self::assertSame(4, $result->countAdded());

        $oneByOne = $this->catalog->cart();
        $itemFactory = $this->service('sylius.factory.cart_item', FactoryInterface::class);
        foreach ($lines as [$variant, $quantity]) {
            $item = $itemFactory->createNew();
            \assert($item instanceof OrderItemInterface);
            $item->setVariant($variant);
            $this->service('sylius.modifier.order_item_quantity', OrderItemQuantityModifierInterface::class)->modify($item, $quantity);
            $this->service('sylius.modifier.order', OrderModifierInterface::class)->addToOrder($oneByOne, $item);
        }

        self::assertSame($oneByOne->getItemsTotal(), $bulk->getItemsTotal());
        self::assertSame($oneByOne->getOrderPromotionTotal(), $bulk->getOrderPromotionTotal());
        self::assertSame($oneByOne->getTaxTotal(), $bulk->getTaxTotal());
        self::assertSame($oneByOne->getShippingTotal(), $bulk->getShippingTotal());
        self::assertSame($oneByOne->getTotal(), $bulk->getTotal());
        // Both test promotions applied: 10% of 23545, then 700.
        $labels = array_map(static fn (\Sylius\Component\Order\Model\AdjustmentInterface $adjustment): string => (string) $adjustment->getLabel(), $bulk->getAdjustmentsRecursively(AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT)->toArray());
        self::assertContains('Quick order test cart_quantity', $labels, 'Applied: ' . implode(', ', array_unique($labels)));
        self::assertContains('Quick order test item_total', $labels, 'Applied: ' . implode(', ', array_unique($labels)));
        self::assertSame(-(2355 + 700), $bulk->getOrderPromotionTotal());
    }

    public function testTheMatrixProductLinesGetTheirStatuses(): void
    {
        $variants = $this->catalog->teeMatrix();
        $prefix = 'QO-' . $this->catalog->suffix;
        // Detailed statuses: cart of a registered customer. The lowercase code matches on every
        // database (exact match first, then a unique case-insensitive match).
        $cart = $this->catalog->cart($this->catalog->customer());
        $text = implode("\n", [$prefix . '-S-RED;2', $prefix . '-XL-BLACK;1', $prefix . '-XL-BLUE;1', $prefix . '-XL-RED;1', $prefix . '-M-BLUE;150', strtolower($prefix . '-L-black') . ';3', 'QO-NOPE;1']);

        $result = $this->service('tmonier_quick_order.bulk_cart_adder', BulkCartAdderInterface::class)->add($cart, (new PasteParser(200))->parse($text), BulkAddMode::Partial);

        self::assertSame(
            ['added', 'insufficient_stock', 'disabled', 'no_channel_pricing', 'insufficient_stock', 'added', 'unknown_code'],
            array_map(static fn (LineResult $line): string => $line->status->value, $result->lines),
        );
        self::assertNull($result->lines[4]->available, 'Stock levels are not disclosed by default.');
        self::assertCount(2, $cart->getItems());
        self::assertCount(12, $variants);
    }

    public function testTheNumberOfQueriesDoesNotGrowWithTheNumberOfLines(): void
    {
        $customerId = $this->catalog->customer()->getId();
        $texts = [];
        foreach ([10, 50] as $count) {
            $text = '';
            for ($i = 1; $i <= $count; ++$i) {
                $code = sprintf('QO-%s-%d-%03d', $this->catalog->suffix, $count, $i);
                $this->catalog->variant($code);
                $text .= $code . ";1\n";
            }
            $texts[$count] = $text;
        }

        $queries = [];
        foreach ($texts as $count => $text) {
            $customer = $this->entityManager()->find(\Sylius\Component\Core\Model\CustomerInterface::class, $customerId);
            self::assertInstanceOf(\Sylius\Component\Core\Model\CustomerInterface::class, $customer);
            $cart = $this->catalog->cart($customer);
            $id = $cart->getId();
            $this->entityManager()->clear();
            $cart = $this->entityManager()->find(OrderInterface::class, $id);
            self::assertInstanceOf(OrderInterface::class, $cart);

            $before = $this->queryCount();
            $result = $this->service('tmonier_quick_order.bulk_cart_adder', BulkCartAdderInterface::class)->validate($cart, (new PasteParser(200))->parse($text));
            $queries[$count] = $this->queryCount() - $before;
            self::assertSame($count, $result->countAddable());
            // Names come from the batched translations, not from lazy loading.
            self::assertNotNull($result->lines[0]->productName);
            $this->entityManager()->clear();
        }

        self::assertSame($queries[10], $queries[50], 'Queries for 10 lines vs 50 lines');
        self::assertLessThanOrEqual(10, $queries[50]);
    }

    private function queryCount(): int
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(\Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder::class, $holder);
        $count = 0;
        foreach ($holder->getData() as $queries) {
            $count += \is_array($queries) ? \count($queries) : 0;
        }

        return $count;
    }

    private function adder(OrderProcessorInterface $processor): BulkCartAdder
    {
        return new BulkCartAdder(
            $this->service('tmonier_quick_order.variant_lookup', \Tmonier\SyliusQuickOrderPlugin\Adder\VariantLookupInterface::class),
            $this->service('sylius.checker.inventory.availability', \Sylius\Component\Inventory\Checker\AvailabilityCheckerInterface::class),
            $this->service('tmonier_quick_order.quantity_constraint_provider', \Tmonier\SyliusQuickOrderPlugin\Quantity\QuantityConstraintProviderInterface::class),
            $this->service('tmonier_quick_order.locked_cart_checker', \Tmonier\SyliusQuickOrderPlugin\Cart\LockedCartCheckerInterface::class),
            $this->service('sylius.factory.cart_item', FactoryInterface::class),
            $this->service('sylius.modifier.order_item_quantity', OrderItemQuantityModifierInterface::class),
            $processor,
            $this->entityManager(),
            $this->service('event_dispatcher', \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
            self::intParameter('sylius.order_item_quantity_modifier.limit'),
        );
    }

    private static function intParameter(string $name): int
    {
        $value = self::getContainer()->getParameter($name);
        self::assertIsInt($value);

        return $value;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $id, string $class): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    private function entityManager(): EntityManagerInterface
    {
        return $this->service('doctrine.orm.entity_manager', EntityManagerInterface::class);
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }
}
