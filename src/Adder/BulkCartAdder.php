<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Adder;

use Doctrine\Persistence\ObjectManager;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
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
use Tmonier\SyliusQuickOrderPlugin\Input\QuickOrderLine;
use Tmonier\SyliusQuickOrderPlugin\Quantity\QuantityConstraintProviderInterface;
use Tmonier\SyliusQuickOrderPlugin\Quantity\QuantityRounding;

/**
 * Adds many lines at once. OrderModifier::addToOrder() processes the whole order on every
 * call (O(N²) for N lines); here the items are merged with the OrderItemQuantityModifier and
 * OrderItem::equals() like the core does, then the order is processed once and flushed once.
 * Line statuses are computed after the processing, which may remove or change items
 * (unavailable variants, other plugins' processors).
 */
final readonly class BulkCartAdder implements BulkCartAdderInterface
{
    /** @param FactoryInterface<OrderItemInterface> $cartItemFactory */
    public function __construct(
        private VariantLookupInterface $variantLookup,
        private AvailabilityCheckerInterface $availabilityChecker,
        private QuantityConstraintProviderInterface $quantityConstraintProvider,
        private LockedCartCheckerInterface $lockedCartChecker,
        private FactoryInterface $cartItemFactory,
        private OrderItemQuantityModifierInterface $orderItemQuantityModifier,
        private OrderProcessorInterface $orderProcessor,
        private ObjectManager $orderManager,
        private EventDispatcherInterface $eventDispatcher,
        private int $quantityLimit,
        private QuantityRounding $quantityRounding = QuantityRounding::Reject,
        private int $maxTotalQuantity = 20000,
        private bool $exposeAvailableStock = false,
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
            return new BulkAddResult($this->skipValidLines($result->lines), false, $event->getRefusal(), $mode);
        }

        if ([] === $result->getLinesWithStatus(LineStatus::Valid)) {
            return $result;
        }
        if (BulkAddMode::AllOrNothing === $mode && [] !== $result->getErrors()) {
            return new BulkAddResult($this->skipValidLines($result->lines), false, null, $mode);
        }

        $before = $this->quantitiesByVariant($cart);
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

            $existing = $this->findEqualItem($cart, $item);
            if (!$existing instanceof \Sylius\Component\Core\Model\OrderItemInterface) {
                $cart->addItem($item);
                $newItems[] = $item;

                continue;
            }
            $changedItems[] = [$existing, $existing->getQuantity()];
            $this->orderItemQuantityModifier->modify($existing, $existing->getQuantity() + $line->quantity);
        }

        $this->orderProcessor->process($cart);

        $after = $this->quantitiesByVariant($cart);
        $lines = [];
        $discrepancy = false;
        foreach ($result->lines as $line) {
            if (LineStatus::Valid !== $line->status || null === $line->quantity) {
                $lines[] = $line;

                continue;
            }
            $key = self::variantKey($variants[$line->code]);
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
        if (null !== $input->rejection) {
            return [BulkAddResult::refused($input->rejection, $mode), []];
        }
        if ($input->isEmpty()) {
            return [BulkAddResult::refused(BulkAddResult::REFUSED_EMPTY, $mode), []];
        }
        if ($this->lockedCartChecker->isLocked($cart)) {
            return [BulkAddResult::refused(BulkAddResult::REFUSED_CART_LOCKED, $mode), []];
        }

        $channel = $cart->getChannel();
        $variants = $this->variantLookup->findByCodes(array_map(static fn (QuickOrderLine $line): string => $line->code, $input->lines));
        // Visitors without an account cannot tell an unpublished or unpriced code from a typo.
        $customer = $cart->getCustomer();
        $detailed = $customer instanceof CustomerInterface && $customer->getUser() instanceof \Sylius\Component\User\Model\UserInterface;

        $lines = [];
        foreach ($input->invalidLines as $invalidLine) {
            $status = LineStatus::UnknownCode === $invalidLine->status && !$detailed ? LineStatus::NotAvailable : $invalidLine->status;
            $lines[] = new LineResult($invalidLine->code, $invalidLine->quantity, null, $status, [$invalidLine->lineNumber]);
        }

        // Lines resolved to the same variant (codes differing in case only) become one line.
        /** @var array<string, QuickOrderLine> $resolved */
        $resolved = [];
        foreach ($input->lines as $line) {
            $variant = $variants[$line->code] ?? null;
            if (null === $variant || !$channel instanceof ChannelInterface) {
                $lines[] = new LineResult($line->code, (string) $line->quantity, $line->quantity, $detailed ? LineStatus::UnknownCode : LineStatus::NotAvailable, $line->lineNumbers);

                continue;
            }
            $key = self::variantKey($variant);
            $resolved[$key] = isset($resolved[$key]) ? $resolved[$key]->withAddedQuantity($line->quantity, ...$line->lineNumbers) : $line;
        }

        $total = 0;
        foreach ($resolved as $line) {
            \assert($channel instanceof ChannelInterface);
            $variant = $variants[$line->code];
            $result = $this->checkLine($line, $variant, $channel, $this->quantityInCart($cart, $variant));
            if (!$detailed && \in_array($result->status, [LineStatus::Disabled, LineStatus::NotInChannel, LineStatus::NoChannelPricing], true)) {
                // Same line as for an unknown code: no names either.
                $result = new LineResult($result->code, $result->requestedQuantity, $result->quantity, LineStatus::NotAvailable, $result->lineNumbers);
            }
            if (LineStatus::Valid === $result->status) {
                $total += (int) $result->quantity;
            }
            $lines[] = $result;
        }
        if ($total > $this->maxTotalQuantity) {
            return [BulkAddResult::refused(BulkAddResult::REFUSED_TOO_MANY_UNITS, $mode), []];
        }

        usort($lines, static fn (LineResult $a, LineResult $b): int => $a->getFirstLineNumber() <=> $b->getFirstLineNumber());

        return [new BulkAddResult($lines, false, null, $mode), $variants];
    }

    private function checkLine(QuickOrderLine $line, ProductVariantInterface $variant, ChannelInterface $channel, int $existing): LineResult
    {
        $product = $variant->getProduct();
        $quantity = $line->quantity;
        $sellable = $product instanceof ProductInterface && $product->isEnabled() && $variant->isEnabled() && $this->isSoldIn($product, $channel);
        $result = static fn (LineStatus $status, ?int $finalQuantity = null, ?int $available = null): LineResult => new LineResult(
            $line->code,
            (string) $line->quantity,
            $finalQuantity ?? $quantity,
            $status,
            $line->lineNumbers,
            // Names of unpublished products are never shown.
            $sellable ? $product->getName() : null,
            $sellable ? $variant->getName() : null,
            0,
            $available,
        );

        if (!$product instanceof ProductInterface || !$product->isEnabled() || !$variant->isEnabled()) {
            return $result(LineStatus::Disabled);
        }
        if (!$sellable) {
            return $result(LineStatus::NotInChannel);
        }
        if (null === $variant->getChannelPricingForChannel($channel)?->getPrice()) {
            return $result(LineStatus::NoChannelPricing);
        }

        // Constraints apply to the quantity the cart will hold, not to the added quantity.
        $constraint = $this->quantityConstraintProvider->getConstraint($variant, $channel);
        if ($constraint instanceof \Tmonier\SyliusQuickOrderPlugin\Quantity\QuantityConstraint && !$constraint->isSatisfiedBy($existing + $quantity)) {
            $rounded = QuantityRounding::RoundUp === $this->quantityRounding ? $constraint->roundUp($existing + $quantity) : null;
            if (null === $rounded) {
                return $result(LineStatus::QuantityConstraint);
            }
            $quantity = $rounded - $existing;
        }

        if ($existing + $quantity > $this->quantityLimit) {
            return $result(LineStatus::QuantityLimitExceeded, $quantity, max(0, $this->quantityLimit - $existing));
        }
        if (!$this->availabilityChecker->isStockSufficient($variant, $existing + $quantity)) {
            $available = $this->exposeAvailableStock && $variant->isTracked() ? max(0, (int) $variant->getOnHand() - (int) $variant->getOnHold() - $existing) : null;

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
    private function skipValidLines(array $lines): array
    {
        return array_map(static fn (LineResult $line): LineResult => LineStatus::Valid === $line->status ? $line->withStatus(LineStatus::Skipped) : $line, $lines);
    }

    /** By code: the channel of the cart may be another instance than the product's (cleared entity manager). */
    private function isSoldIn(ProductInterface $product, ChannelInterface $channel): bool
    {
        foreach ($product->getChannels() as $productChannel) {
            if ($productChannel->getCode() === $channel->getCode()) {
                return true;
            }
        }

        return false;
    }

    private function findEqualItem(OrderInterface $cart, OrderItemInterface $item): ?OrderItemInterface
    {
        foreach ($cart->getItems() as $existing) {
            if ($item->equals($existing)) {
                return $existing;
            }
        }

        return null;
    }

    private function quantityInCart(OrderInterface $cart, ProductVariantInterface $variant): int
    {
        $quantity = 0;
        foreach ($cart->getItems() as $item) {
            $itemVariant = $item->getVariant();
            if (null !== $itemVariant && self::variantKey($itemVariant) === self::variantKey($variant)) {
                $quantity += $item->getQuantity();
            }
        }

        return $quantity;
    }

    /**
     * The id when persisted (no lazy loading of cart item variants, and stable across entity
     * manager clears), the code otherwise.
     */
    private static function variantKey(ProductVariantInterface $variant): string
    {
        $id = $variant->getId();

        return null !== $id ? 'id:' . (\is_scalar($id) ? (string) $id : '') : 'code:' . $variant->getCode();
    }

    /** @return array<string, int> quantities indexed by variant key */
    private function quantitiesByVariant(OrderInterface $cart): array
    {
        $quantities = [];
        foreach ($cart->getItems() as $item) {
            $variant = $item->getVariant();
            if (null !== $variant) {
                $key = self::variantKey($variant);
                $quantities[$key] = ($quantities[$key] ?? 0) + $item->getQuantity();
            }
        }

        return $quantities;
    }
}
