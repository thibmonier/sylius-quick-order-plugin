<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Adder;

use Doctrine\Persistence\ObjectManager;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Inventory\Checker\AvailabilityCheckerInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tmonier\SyliusQuickOrderPlugin\Cart\LockedCartCheckerInterface;
use Tmonier\SyliusQuickOrderPlugin\Event\PostBulkAddEvent;
use Tmonier\SyliusQuickOrderPlugin\Event\PreBulkAddEvent;
use Tmonier\SyliusQuickOrderPlugin\Event\QuickOrderEvents;
use Tmonier\SyliusQuickOrderPlugin\Input\QuickOrderInput;
use Tmonier\SyliusQuickOrderPlugin\Quantity\QuantityConstraintProviderInterface;
use Tmonier\SyliusQuickOrderPlugin\Quantity\QuantityRounding;

/**
 * Adds many lines at once. OrderModifier::addToOrder() processes the whole order on every
 * call (O(N²) for N lines); here the items are merged with the OrderItemQuantityModifier and
 * OrderItem::equals() like the core does, then the order is processed once and flushed once.
 * Line statuses are computed after the processing, which may remove or change items
 * (unavailable variants, other plugins' processors).
 */
final class BulkCartAdder implements BulkCartAdderInterface
{
    /** @param FactoryInterface<OrderItemInterface> $cartItemFactory */
    public function __construct(
        private readonly VariantLookupInterface $variantLookup,
        private readonly AvailabilityCheckerInterface $availabilityChecker,
        private readonly QuantityConstraintProviderInterface $quantityConstraintProvider,
        private readonly LockedCartCheckerInterface $lockedCartChecker,
        private readonly FactoryInterface $cartItemFactory,
        private readonly OrderItemQuantityModifierInterface $orderItemQuantityModifier,
        private readonly OrderProcessorInterface $orderProcessor,
        private readonly ObjectManager $orderManager,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly int $quantityLimit,
        private readonly QuantityRounding $quantityRounding = QuantityRounding::Reject,
    ) {
    }

    public function validate(OrderInterface $cart, QuickOrderInput $input): BulkAddResult
    {
        return $this->check($cart, $input, BulkAddMode::Partial)[0];
    }

    public function add(OrderInterface $cart, QuickOrderInput $input, BulkAddMode $mode): BulkAddResult
    {
        [$result, $variants] = $this->check($cart, $input, $mode);
        if (null !== $result->refusal) {
            return $result;
        }

        $event = new PreBulkAddEvent($cart, $result->lines, $mode);
        $this->eventDispatcher->dispatch($event, QuickOrderEvents::PRE_BULK_ADD);
        if (null !== $event->getRefusal()) {
            return new BulkAddResult(self::skipValidLines($result->lines), false, $event->getRefusal(), $mode);
        }

        if ([] === $result->getLinesWithStatus(LineStatus::Valid)) {
            return $result;
        }
        if (BulkAddMode::AllOrNothing === $mode && [] !== $result->getErrors()) {
            return new BulkAddResult(self::skipValidLines($result->lines), false, null, $mode);
        }

        $before = self::quantitiesByVariant($cart);
        /** @var list<OrderItemInterface> $newItems */
        $newItems = [];
        /** @var list<array{OrderItemInterface, int}> $changedItems */
        $changedItems = [];
        foreach ($result->lines as $line) {
            if (LineStatus::Valid !== $line->status || null === $line->quantity) {
                continue;
            }
            $variant = $variants[$line->code];

            $item = $this->cartItemFactory->createNew();
            $item->setVariant($variant);
            $this->orderItemQuantityModifier->modify($item, $line->quantity);

            $existing = self::findEqualItem($cart, $item);
            if (null === $existing) {
                $cart->addItem($item);
                $newItems[] = $item;

                continue;
            }
            $changedItems[] = [$existing, $existing->getQuantity()];
            $this->orderItemQuantityModifier->modify($existing, $existing->getQuantity() + $line->quantity);
        }

        $this->orderProcessor->process($cart);

        $after = self::quantitiesByVariant($cart);
        $lines = [];
        $discrepancy = false;
        foreach ($result->lines as $line) {
            if (LineStatus::Valid !== $line->status || null === $line->quantity) {
                $lines[] = $line;

                continue;
            }
            $key = (string) $variants[$line->code]->getCode();
            $previous = $before[$key] ?? 0;
            $actual = $after[$key] ?? 0;
            if ($actual >= $previous + $line->quantity) {
                $lines[] = $line->withStatus(LineStatus::Added, $line->quantity);
            } elseif ($actual > $previous) {
                $lines[] = $line->withStatus(LineStatus::Adjusted, $actual - $previous);
                $discrepancy = true;
            } else {
                $lines[] = $line->withStatus(LineStatus::Removed);
                $discrepancy = true;
            }
        }

        if (BulkAddMode::AllOrNothing === $mode && $discrepancy) {
            $this->revert($cart, $newItems, $changedItems);

            $lines = array_map(static fn (LineResult $line): LineResult => match ($line->status) {
                LineStatus::Added => $line->withStatus(LineStatus::Skipped),
                LineStatus::Adjusted => $line->withStatus(LineStatus::Removed),
                default => $line,
            }, $lines);

            return new BulkAddResult($lines, false, null, $mode);
        }

        $this->orderManager->persist($cart);
        $this->orderManager->flush();

        $result = new BulkAddResult($lines, true, null, $mode);
        $this->eventDispatcher->dispatch(new PostBulkAddEvent($cart, $result), QuickOrderEvents::POST_BULK_ADD);

        return $result;
    }

    /** @return array{BulkAddResult, array<string, ProductVariantInterface>} */
    private function check(OrderInterface $cart, QuickOrderInput $input, BulkAddMode $mode): array
    {
        if ($input->tooManyLines) {
            return [BulkAddResult::refused(BulkAddResult::REFUSED_TOO_MANY_LINES, $mode), []];
        }
        if ($input->fileRejected) {
            return [BulkAddResult::refused(BulkAddResult::REFUSED_FILE, $mode), []];
        }
        if ($input->isEmpty()) {
            return [BulkAddResult::refused(BulkAddResult::REFUSED_EMPTY, $mode), []];
        }
        if ($this->lockedCartChecker->isLocked($cart)) {
            return [BulkAddResult::refused(BulkAddResult::REFUSED_CART_LOCKED, $mode), []];
        }

        $channel = $cart->getChannel();
        $codes = array_map(static fn ($line): string => $line->code, $input->lines);
        $variants = $this->variantLookup->findByCodes($codes);

        $lines = [];
        foreach ($input->invalidLines as $invalidLine) {
            $lines[] = new LineResult($invalidLine->code, $invalidLine->quantity, null, $invalidLine->status, [$invalidLine->lineNumber]);
        }

        // Lines resolved to the same variant (codes differing in case only) share the cart quantity.
        $pending = [];
        foreach ($input->lines as $line) {
            $variant = $variants[$line->code] ?? null;
            if (null === $variant || !$channel instanceof ChannelInterface) {
                $lines[] = new LineResult($line->code, (string) $line->quantity, $line->quantity, LineStatus::UnknownCode, $line->lineNumbers);

                continue;
            }

            $key = (string) $variant->getCode();
            $existing = self::quantityInCart($cart, $variant) + ($pending[$key] ?? 0);
            $line = $this->checkLine($line->code, $line->quantity, $line->lineNumbers, $variant, $channel, $existing);
            if (LineStatus::Valid === $line->status && null !== $line->quantity) {
                $pending[$key] = ($pending[$key] ?? 0) + $line->quantity;
            }
            $lines[] = $line;
        }

        usort($lines, static fn (LineResult $a, LineResult $b): int => $a->getFirstLineNumber() <=> $b->getFirstLineNumber());

        return [new BulkAddResult($lines, false, null, $mode), $variants];
    }

    /** @param list<int> $lineNumbers */
    private function checkLine(string $code, int $quantity, array $lineNumbers, ProductVariantInterface $variant, ChannelInterface $channel, int $existing): LineResult
    {
        $product = $variant->getProduct();
        $productName = $product?->getName();
        $variantName = $variant->getName();
        $result = static fn (LineStatus $status, ?int $finalQuantity = null, ?int $available = null): LineResult => new LineResult(
            $code,
            (string) $quantity,
            $finalQuantity ?? $quantity,
            $status,
            $lineNumbers,
            $productName,
            $variantName,
            0,
            $available,
        );

        if (null === $product || !$product->isEnabled() || !$variant->isEnabled()) {
            return $result(LineStatus::Disabled);
        }
        if (!self::isSoldIn($product, $channel)) {
            return $result(LineStatus::NotInChannel);
        }
        if (null === $variant->getChannelPricingForChannel($channel)?->getPrice()) {
            return $result(LineStatus::NoChannelPricing);
        }

        $constraint = $this->quantityConstraintProvider->getConstraint($variant, $channel);
        if (null !== $constraint && !$constraint->isSatisfiedBy($quantity)) {
            $rounded = QuantityRounding::RoundUp === $this->quantityRounding ? $constraint->roundUp($quantity) : null;
            if (null === $rounded) {
                return $result(LineStatus::QuantityConstraint);
            }
            $quantity = $rounded;
        }

        if ($existing + $quantity > $this->quantityLimit) {
            return $result(LineStatus::QuantityLimitExceeded, $quantity, max(0, $this->quantityLimit - $existing));
        }
        if (!$this->availabilityChecker->isStockSufficient($variant, $existing + $quantity)) {
            $available = $variant->isTracked() ? max(0, (int) $variant->getOnHand() - (int) $variant->getOnHold() - $existing) : null;

            return $result(LineStatus::InsufficientStock, $quantity, $available);
        }

        return $result(LineStatus::Valid, $quantity);
    }

    /**
     * @param list<OrderItemInterface> $newItems
     * @param list<array{OrderItemInterface, int}> $changedItems
     */
    private function revert(OrderInterface $cart, array $newItems, array $changedItems): void
    {
        foreach ($newItems as $item) {
            if ($cart->hasItem($item)) {
                $cart->removeItem($item);
            }
        }
        foreach ($changedItems as [$item, $quantity]) {
            if ($cart->hasItem($item)) {
                $this->orderItemQuantityModifier->modify($item, $quantity);
            }
        }

        $this->orderProcessor->process($cart);
    }

    /**
     * @param list<LineResult> $lines
     *
     * @return list<LineResult>
     */
    private static function skipValidLines(array $lines): array
    {
        return array_map(static fn (LineResult $line): LineResult => LineStatus::Valid === $line->status ? $line->withStatus(LineStatus::Skipped) : $line, $lines);
    }

    /** By code: the channel of the cart may be another instance than the product's (cleared entity manager). */
    private static function isSoldIn(ProductInterface $product, ChannelInterface $channel): bool
    {
        foreach ($product->getChannels() as $productChannel) {
            if ($productChannel->getCode() === $channel->getCode()) {
                return true;
            }
        }

        return false;
    }

    private static function findEqualItem(OrderInterface $cart, OrderItemInterface $item): ?OrderItemInterface
    {
        foreach ($cart->getItems() as $existing) {
            if ($item->equals($existing)) {
                /** @var OrderItemInterface $existing */
                return $existing;
            }
        }

        return null;
    }

    private static function quantityInCart(OrderInterface $cart, ProductVariantInterface $variant): int
    {
        $quantity = 0;
        foreach ($cart->getItems() as $item) {
            /** @var OrderItemInterface $item */
            if ($item->getVariant()?->getCode() === $variant->getCode()) {
                $quantity += $item->getQuantity();
            }
        }

        return $quantity;
    }

    /** @return array<string, int> quantities indexed by variant code */
    private static function quantitiesByVariant(OrderInterface $cart): array
    {
        $quantities = [];
        foreach ($cart->getItems() as $item) {
            /** @var OrderItemInterface $item */
            $variant = $item->getVariant();
            if (null !== $variant) {
                $key = (string) $variant->getCode();
                $quantities[$key] = ($quantities[$key] ?? 0) + $item->getQuantity();
            }
        }

        return $quantities;
    }
}
