<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Unit\Adder;

use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricing;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\OrderItemUnit;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShopUser;
use Sylius\Component\Inventory\Checker\AvailabilityChecker;
use Sylius\Component\Order\Factory\OrderItemUnitFactory;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifier;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddMode;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkCartAdder;
use Tmonier\SyliusQuickOrderPlugin\Adder\LineResult;
use Tmonier\SyliusQuickOrderPlugin\Adder\LineStatus;
use Tmonier\SyliusQuickOrderPlugin\Cart\LockedCartCheckerInterface;
use Tmonier\SyliusQuickOrderPlugin\Event\PostBulkAddEvent;
use Tmonier\SyliusQuickOrderPlugin\Event\PreBulkAddEvent;
use Tmonier\SyliusQuickOrderPlugin\Event\QuickOrderEvents;
use Tmonier\SyliusQuickOrderPlugin\Input\PasteParser;
use Tmonier\SyliusQuickOrderPlugin\Input\QuickOrderInput;
use Tmonier\SyliusQuickOrderPlugin\Quantity\QuantityConstraint;
use Tmonier\SyliusQuickOrderPlugin\Quantity\QuantityConstraintProviderInterface;
use Tmonier\SyliusQuickOrderPlugin\Quantity\QuantityRounding;

final class BulkCartAdderTest extends TestCase
{
    private ChannelInterface $channel;

    private InMemoryVariantLookup $lookup;

    private CountingOrderProcessor $processor;

    private EventDispatcher $dispatcher;

    private ObjectManager&MockObject $manager;

    private bool $locked = false;

    private ?QuantityConstraint $constraint = null;

    protected function setUp(): void
    {
        $this->channel = new Channel();
        $this->channel->setCode('WEB');
        $this->lookup = new InMemoryVariantLookup();
        $this->processor = new CountingOrderProcessor();
        $this->dispatcher = new EventDispatcher();
        $this->manager = $this->createMock(ObjectManager::class);
    }

    public function testTwoHundredLinesAreProcessedAndFlushedOnce(): void
    {
        $text = '';
        for ($i = 1; $i <= 200; ++$i) {
            $this->variant('SKU-' . $i);
            $text .= sprintf("SKU-%d;%d\n", $i, $i % 5 + 1);
        }
        $this->manager->expects(self::once())->method('flush');

        $cart = $this->cart();
        $result = $this->adder()->add($cart, $this->parse($text, 200), BulkAddMode::Partial);

        self::assertTrue($result->applied);
        self::assertSame(200, $result->countAdded());
        self::assertSame(1, $this->processor->calls);
        self::assertSame(1, $this->lookup->calls);
        self::assertCount(200, $cart->getItems());
        self::assertSame(3, $this->item($cart, 'SKU-12')->getQuantity());
    }

    public function testExistingItemsAreMergedLikeTheCoreDoes(): void
    {
        $variant = $this->variant('ABC');
        $cart = $this->cart();
        $existing = $this->cartItem($variant, 2);
        $cart->addItem($existing);
        $this->lookup->alias('abc', $variant);

        $result = $this->adder()->add($cart, $this->parse("ABC;3\nabc;1"), BulkAddMode::Partial);

        self::assertCount(1, $cart->getItems());
        self::assertSame($existing, $cart->getItems()->first());
        self::assertSame(6, $existing->getQuantity());
        self::assertSame([[1, 2]], array_map(static fn (LineResult $line): array => $line->lineNumbers, $result->lines));
        self::assertSame(LineStatus::Added, $result->lines[0]->status);
        self::assertSame(4, $result->lines[0]->addedQuantity);
    }

    public function testEveryLineGetsAStatusBeforeAnyChange(): void
    {
        $this->variant('OK');
        $this->variant('OFF')->setEnabled(false);
        $disabledProduct = $this->variant('OFF-PRODUCT');
        $disabledProduct->getProduct()?->setEnabled(false);
        $otherChannel = new Channel();
        $otherChannel->setCode('OTHER');
        $elsewhere = $this->variant('ELSEWHERE')->getProduct();
        self::assertInstanceOf(ProductInterface::class, $elsewhere);
        $elsewhere->removeChannel($this->channel);
        $elsewhere->addChannel($otherChannel);
        $this->variant('NO-PRICE', null);
        $this->variant('LOW', 1000, 5);

        $cart = $this->cart();
        $result = $this->adder()->validate($cart, $this->parse("OK;1\nOFF;1\nOFF-PRODUCT;1\nELSEWHERE;1\nNO-PRICE;1\nLOW;6\nNOPE;1\nOK;x"));

        self::assertSame(
            ['valid', 'disabled', 'disabled', 'not_in_channel', 'no_channel_pricing', 'insufficient_stock', 'unknown_code', 'invalid_quantity'],
            array_map(static fn (LineResult $line): string => $line->status->value, $result->lines),
        );
        self::assertNull($result->lines[5]->available, 'Stock levels are not disclosed by default.');
        self::assertSame('Product OK', $result->lines[0]->productName);
        self::assertSame('Variant OK', $result->lines[0]->variantName);
        self::assertSame(0, $this->processor->calls);
        self::assertCount(0, $cart->getItems());
    }

    public function testStockCountsWhatIsAlreadyInTheCart(): void
    {
        $variant = $this->variant('LOW', 1000, 5);
        $variant->setOnHold(1);
        $cart = $this->cart();
        $cart->addItem($this->cartItem($variant, 2));

        $result = $this->adder(exposeAvailableStock: true)->validate($cart, $this->parse('LOW;3'));

        self::assertSame(LineStatus::InsufficientStock, $result->lines[0]->status);
        self::assertSame(2, $result->lines[0]->available);
        self::assertSame('tmonier_quick_order.status.insufficient_stock_available', $result->lines[0]->getMessageKey());
        self::assertNull($this->adder()->validate($cart, $this->parse('LOW;3'))->lines[0]->available);
        self::assertSame(LineStatus::Valid, $this->adder()->validate($cart, $this->parse('LOW;2'))->lines[0]->status);
    }

    public function testNamesOfUnpublishedProductsAreNeverShown(): void
    {
        $this->variant('OFF')->setEnabled(false);
        $otherChannel = new Channel();
        $otherChannel->setCode('OTHER');
        $elsewhere = $this->variant('ELSEWHERE')->getProduct();
        self::assertInstanceOf(ProductInterface::class, $elsewhere);
        $elsewhere->removeChannel($this->channel);
        $elsewhere->addChannel($otherChannel);

        $result = $this->adder()->validate($this->cart(), $this->parse("OFF;1\nELSEWHERE;1"));

        self::assertSame([[null, null], [null, null]], array_map(static fn (LineResult $line): array => [$line->productName, $line->variantName], $result->lines));
    }

    public function testVisitorsCannotTellUnavailableCodesFromUnknownOnes(): void
    {
        $this->variant('OK');
        $this->variant('OFF')->setEnabled(false);
        $this->variant('NO-PRICE', null);

        $result = $this->adder()->validate($this->cart(false), $this->parse("OK;1\nOFF;1\nNO-PRICE;1\nNOPE;1"));

        self::assertSame(['valid', 'not_available', 'not_available', 'not_available'], array_map(static fn (LineResult $line): string => $line->status->value, $result->lines));
    }

    public function testCodesDifferingInCaseAreMergedOnlyWhenTheyAreTheSameVariant(): void
    {
        // Two variants whose codes differ in case (possible on PostgreSQL) stay two lines.
        $this->variant('abc');
        $this->variant('ABC');
        $cart = $this->cart();

        $result = $this->adder()->add($cart, $this->parse("abc;1\nABC;5"), BulkAddMode::Partial);

        self::assertSame([['abc', 1], ['ABC', 5]], array_map(static fn (LineResult $line): array => [$line->code, $line->addedQuantity], $result->lines));
        self::assertSame(1, $this->item($cart, 'abc')->getQuantity());
        self::assertSame(5, $this->item($cart, 'ABC')->getQuantity());
    }

    public function testCodesResolvedToTheSameVariantShareOneLine(): void
    {
        $variant = $this->variant('ABC', 1000, 5);
        $this->lookup->alias('abc', $variant);

        $result = $this->adder()->validate($this->cart(), $this->parse("ABC;3\nabc;3"));

        self::assertCount(1, $result->lines);
        self::assertSame([1, 2], $result->lines[0]->lineNumbers);
        self::assertSame(LineStatus::InsufficientStock, $result->lines[0]->status);
    }

    public function testTheTotalNumberOfUnitsPerRequestIsCapped(): void
    {
        $this->variant('A');
        $this->variant('B');
        $cart = $this->cart();

        self::assertSame(BulkAddResult::REFUSED_TOO_MANY_UNITS, $this->adder(maxTotalQuantity: 100)->add($cart, $this->parse("A;60\nB;41"), BulkAddMode::Partial)->refusal);
        self::assertCount(0, $cart->getItems());
        self::assertTrue($this->adder(maxTotalQuantity: 100)->add($cart, $this->parse("A;60\nB;40"), BulkAddMode::Partial)->applied);
    }

    public function testTheQuantityLimitIsReportedInsteadOfSilentlyCapped(): void
    {
        $variant = $this->variant('BIG');
        $cart = $this->cart();
        $cart->addItem($this->cartItem($variant, 9000));

        $result = $this->adder()->add($cart, $this->parse("BIG;1000\nBIG;0"), BulkAddMode::Partial);

        self::assertSame(LineStatus::QuantityLimitExceeded, $result->lines[0]->status);
        self::assertSame(999, $result->lines[0]->available);
        self::assertSame(9000, $this->item($cart, 'BIG')->getQuantity());
    }

    public function testQuantityConstraintsRejectOrRoundUp(): void
    {
        $this->variant('CASE');
        $this->constraint = new QuantityConstraint(1, 6);

        $rejected = $this->adder()->validate($this->cart(), $this->parse('CASE;7'));
        self::assertSame(LineStatus::QuantityConstraint, $rejected->lines[0]->status);

        $cart = $this->cart();
        $rounded = $this->adder(QuantityRounding::RoundUp)->add($cart, $this->parse('CASE;7'), BulkAddMode::Partial);
        self::assertSame(LineStatus::Added, $rounded->lines[0]->status);
        self::assertSame('7', $rounded->lines[0]->requestedQuantity);
        self::assertSame(12, $rounded->lines[0]->quantity);
        self::assertTrue($rounded->lines[0]->isRounded());
        self::assertSame(12, $this->item($cart, 'CASE')->getQuantity());
    }

    public function testQuantityConstraintsApplyToTheCartQuantity(): void
    {
        $variant = $this->variant('CASE');
        $cart = $this->cart();
        $cart->addItem($this->cartItem($variant, 12));

        $this->constraint = new QuantityConstraint(12, 6, 30);
        // 12 + 6 = 18: a valid total, even though 6 alone is below the minimum.
        self::assertSame(LineStatus::Valid, $this->adder()->validate($cart, $this->parse('CASE;6'))->lines[0]->status);
        // 12 + 5 = 17: refused, or rounded to a total of 18.
        self::assertSame(LineStatus::QuantityConstraint, $this->adder()->validate($cart, $this->parse('CASE;5'))->lines[0]->status);
        self::assertSame(6, $this->adder(QuantityRounding::RoundUp)->validate($cart, $this->parse('CASE;5'))->lines[0]->quantity);
        // 12 + 24 = 36 > 30: refused, even with rounding.
        self::assertSame(LineStatus::QuantityConstraint, $this->adder(QuantityRounding::RoundUp)->validate($cart, $this->parse('CASE;24'))->lines[0]->status);
    }

    public function testPartialModeAddsTheValidLines(): void
    {
        $this->variant('A');
        $this->manager->expects(self::once())->method('flush');
        $cart = $this->cart();

        $result = $this->adder()->add($cart, $this->parse("A;2\nNOPE;1"), BulkAddMode::Partial);

        self::assertTrue($result->applied);
        self::assertSame(['added', 'unknown_code'], array_map(static fn (LineResult $line): string => $line->status->value, $result->lines));
        self::assertCount(1, $cart->getItems());
    }

    public function testAllOrNothingAddsNothingWhenALineIsInvalid(): void
    {
        $this->variant('A');
        $this->manager->expects(self::never())->method('flush');
        $cart = $this->cart();

        $result = $this->adder()->add($cart, $this->parse("A;2\nNOPE;1"), BulkAddMode::AllOrNothing);

        self::assertFalse($result->applied);
        self::assertSame(['skipped', 'unknown_code'], array_map(static fn (LineResult $line): string => $line->status->value, $result->lines));
        self::assertCount(0, $cart->getItems());
        self::assertSame(0, $this->processor->calls);
    }

    public function testStatusesAreComputedAfterTheOrderProcessing(): void
    {
        $this->variant('KEPT');
        $this->variant('HALVED');
        $this->variant('DROPPED');
        // Another processor (e.g. the price lock of a quote plugin) changes the cart.
        $this->processor = new CountingOrderProcessor(function (\Sylius\Component\Order\Model\OrderInterface $order): void {
            \assert($order instanceof OrderInterface);
            $modifier = new OrderItemQuantityModifier(new OrderItemUnitFactory(OrderItemUnit::class));
            $modifier->modify($this->item($order, 'HALVED'), 2);
            $order->removeItem($this->item($order, 'DROPPED'));
        });
        $cart = $this->cart();

        $result = $this->adder()->add($cart, $this->parse("KEPT;4\nHALVED;4\nDROPPED;4"), BulkAddMode::Partial);

        self::assertTrue($result->applied);
        self::assertSame(['added', 'adjusted', 'removed'], array_map(static fn (LineResult $line): string => $line->status->value, $result->lines));
        self::assertSame(2, $result->lines[1]->addedQuantity);
        self::assertSame(2, $result->countAdded());
        self::assertTrue($result->hasErrors());
    }

    public function testAllOrNothingRevertsWhenTheProcessingRefusesALine(): void
    {
        $kept = $this->variant('KEPT');
        $this->variant('DROPPED');
        $this->processor = new CountingOrderProcessor(function (\Sylius\Component\Order\Model\OrderInterface $order): void {
            foreach ($order->getItems() as $item) {
                \assert($item instanceof OrderItemInterface);
                if ('DROPPED' === $item->getVariant()?->getCode()) {
                    $order->removeItem($item);
                }
            }
        });
        $this->manager->expects(self::never())->method('flush');
        $cart = $this->cart();
        $existing = $this->cartItem($kept, 1);
        $cart->addItem($existing);

        $result = $this->adder()->add($cart, $this->parse("KEPT;4\nDROPPED;4"), BulkAddMode::AllOrNothing);

        self::assertFalse($result->applied);
        self::assertSame(['skipped', 'removed'], array_map(static fn (LineResult $line): string => $line->status->value, $result->lines));
        self::assertCount(1, $cart->getItems());
        self::assertSame(1, $existing->getQuantity());
        self::assertSame(2, $this->processor->calls);
    }

    public function testLockedCartsAreRefused(): void
    {
        $this->variant('A');
        $this->locked = true;
        $cart = $this->cart();

        $result = $this->adder()->add($cart, $this->parse('A;1'), BulkAddMode::Partial);

        self::assertSame(BulkAddResult::REFUSED_CART_LOCKED, $result->refusal);
        self::assertCount(0, $cart->getItems());
        self::assertSame(0, $this->processor->calls);
    }

    public function testRejectedInputsAreRefused(): void
    {
        $cart = $this->cart();

        self::assertSame(BulkAddResult::REFUSED_EMPTY, $this->adder()->add($cart, new QuickOrderInput([]), BulkAddMode::Partial)->refusal);
        self::assertSame(BulkAddResult::REFUSED_TOO_MANY_LINES, $this->adder()->validate($cart, $this->parse("A;1\nB;1", 1))->refusal);
        self::assertSame(BulkAddResult::REFUSED_FILE, $this->adder()->validate($cart, QuickOrderInput::rejected(BulkAddResult::REFUSED_FILE))->refusal);
    }

    public function testEventsAroundTheAddition(): void
    {
        $this->variant('A');
        $events = [];
        $this->dispatcher->addListener(QuickOrderEvents::PRE_BULK_ADD, function (PreBulkAddEvent $event) use (&$events): void {
            $events[] = 'pre:' . \count($event->lines) . ':' . \count($event->cart->getItems());
        });
        $this->dispatcher->addListener(QuickOrderEvents::POST_BULK_ADD, function (PostBulkAddEvent $event) use (&$events): void {
            $events[] = 'post:' . $event->result->countAdded() . ':' . \count($event->cart->getItems());
        });

        $this->adder()->add($this->cart(), $this->parse('A;1'), BulkAddMode::Partial);

        self::assertSame(['pre:1:0', 'post:1:1'], $events);
    }

    public function testAPreListenerCanRefuseTheAddition(): void
    {
        $this->variant('A');
        $this->dispatcher->addListener(QuickOrderEvents::PRE_BULK_ADD, static fn (PreBulkAddEvent $event) => $event->refuse('budget_exceeded'));
        $cart = $this->cart();

        $result = $this->adder()->add($cart, $this->parse('A;1'), BulkAddMode::Partial);

        self::assertSame('budget_exceeded', $result->refusal);
        self::assertSame(LineStatus::Skipped, $result->lines[0]->status);
        self::assertCount(0, $cart->getItems());
    }

    public function testTheResultSurvivesTheSession(): void
    {
        $this->variant('A');
        $result = $this->adder()->add($this->cart(), $this->parse("A;2\nB;1\n;3"), BulkAddMode::AllOrNothing);

        self::assertEquals($result, BulkAddResult::fromArray($result->toArray()));
    }

    private function adder(QuantityRounding $rounding = QuantityRounding::Reject, int $maxTotalQuantity = 1000000, bool $exposeAvailableStock = false): BulkCartAdder
    {
        $test = $this;

        return new BulkCartAdder(
            $this->lookup,
            new AvailabilityChecker(),
            new class($test) implements QuantityConstraintProviderInterface {
                public function __construct(private readonly BulkCartAdderTest $test)
                {
                }

                public function getConstraint(ProductVariantInterface $variant, ChannelInterface $channel): ?QuantityConstraint
                {
                    return $this->test->constraint();
                }
            },
            new class($test) implements LockedCartCheckerInterface {
                public function __construct(private readonly BulkCartAdderTest $test)
                {
                }

                public function isLocked(OrderInterface $cart): bool
                {
                    return $this->test->locked();
                }
            },
            self::cartItemFactory(),
            new OrderItemQuantityModifier(new OrderItemUnitFactory(OrderItemUnit::class)),
            $this->processor,
            $this->manager,
            $this->dispatcher,
            9999,
            $rounding,
            $maxTotalQuantity,
            $exposeAvailableStock,
        );
    }

    /** @return FactoryInterface<OrderItemInterface> */
    private static function cartItemFactory(): FactoryInterface
    {
        return new
        /** @implements FactoryInterface<OrderItemInterface> */
        class() implements FactoryInterface {
            public function createNew(): OrderItemInterface
            {
                return new OrderItem();
            }
        };
    }

    public function constraint(): ?QuantityConstraint
    {
        return $this->constraint;
    }

    public function locked(): bool
    {
        return $this->locked;
    }

    private function parse(string $text, int $maxLines = 200): QuickOrderInput
    {
        return (new PasteParser($maxLines))->parse($text);
    }

    /** A cart of a registered customer (detailed statuses), or of a visitor. */
    private function cart(bool $registered = true): OrderInterface
    {
        $cart = new Order();
        $cart->setChannel($this->channel);
        if ($registered) {
            $customer = new Customer();
            $customer->setUser(new ShopUser());
            $cart->setCustomer($customer);
        }

        return $cart;
    }

    private function variant(string $code, ?int $price = 1000, ?int $onHand = null): ProductVariantInterface
    {
        $product = new Product();
        $product->setCurrentLocale('en_US');
        $product->setFallbackLocale('en_US');
        $product->setCode('P-' . $code);
        $product->setName('Product ' . $code);
        $product->addChannel($this->channel);

        $variant = new ProductVariant();
        $variant->setCurrentLocale('en_US');
        $variant->setFallbackLocale('en_US');
        $variant->setCode($code);
        $variant->setName('Variant ' . $code);
        if (null !== $onHand) {
            $variant->setTracked(true);
            $variant->setOnHand($onHand);
        }
        $pricing = new ChannelPricing();
        $pricing->setChannelCode('WEB');
        $pricing->setPrice($price);
        $variant->addChannelPricing($pricing);
        $product->addVariant($variant);
        $this->lookup->add($variant);

        return $variant;
    }

    private function cartItem(ProductVariantInterface $variant, int $quantity): OrderItemInterface
    {
        $item = new OrderItem();
        $item->setVariant($variant);
        (new OrderItemQuantityModifier(new OrderItemUnitFactory(OrderItemUnit::class)))->modify($item, $quantity);

        return $item;
    }

    private function item(\Sylius\Component\Order\Model\OrderInterface $cart, string $code): OrderItemInterface
    {
        foreach ($cart->getItems() as $item) {
            \assert($item instanceof OrderItemInterface);
            if ($code === $item->getVariant()?->getCode()) {
                return $item;
            }
        }

        self::fail('No item ' . $code);
    }
}
