<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Twig\Component;

use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Context\CartNotFoundException;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\TwigHooks\LiveComponent\HookableLiveComponentTrait;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddMode;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult;
use Tmonier\SyliusQuickOrderPlugin\Adder\LineResult;
use Tmonier\SyliusQuickOrderPlugin\Input\LineCollector;
use Tmonier\SyliusQuickOrderPlugin\Matrix\Matrix;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixActivationCheckerInterface;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixBuilderInterface;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixCell;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixOrderHandlerInterface;
use Tmonier\SyliusQuickOrderPlugin\Quantity\QuantityRounding;

/**
 * Order matrix of a product page: one quantity per variant, an estimate, one "add" for all
 * lines (bulk cart adder: one order processing). Emits "sylius:shop:cart_changed" so that the
 * cart widget and other cart components refresh. The template also works without JavaScript
 * (plain POST to tmonier_quick_order_shop_matrix_add, which brings the result back here).
 */
#[AsLiveComponent]
final class MatrixOrderComponent
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use HookableLiveComponentTrait;

    public const CART_CHANGED = 'sylius:shop:cart_changed';

    /** Session key prefix of a result of the no-JavaScript route, shown once by the matrix. */
    public const SESSION_RESULT_KEY = '_tmonier_quick_order.matrix_result.';

    #[LiveProp(hydrateWith: 'hydrateProduct', dehydrateWith: 'dehydrateProduct')]
    public ?ProductInterface $product = null;

    /** @var array<mixed> quantities by "v" + variant id, as typed (validated on the server) */
    #[LiveProp(writable: true)]
    public array $quantities = [];

    /**
     * @var array<mixed> quantities when lastResult was computed: a cell whose quantity differs
     *                   has been corrected since, its message is dropped (the browser updates
     *                   "quantities.v<id>" paths, which the onUpdated hook of the whole prop never sees)
     */
    #[LiveProp]
    public array $resultQuantities = [];

    #[LiveProp(writable: true)]
    public bool $allOrNothing;

    /** @var array<mixed>|null last result (BulkAddResult::toArray()); read it with getResult() */
    #[LiveProp]
    public ?array $lastResult = null;

    private ?Matrix $matrix = null;

    /** @param RepositoryInterface<ProductInterface> $productRepository */
    public function __construct(
        private readonly RepositoryInterface $productRepository,
        private readonly ChannelContextInterface $channelContext,
        private readonly CartContextInterface $cartContext,
        private readonly MatrixActivationCheckerInterface $activationChecker,
        private readonly MatrixBuilderInterface $matrixBuilder,
        private readonly MatrixOrderHandlerInterface $orderHandler,
        private readonly string $defaultMode = 'partial',
        private readonly int $maxTotalQuantity = 20000,
        private readonly ?RequestStack $requestStack = null,
        private readonly int $quantityLimit = 9999,
        private readonly QuantityRounding $quantityRounding = QuantityRounding::Reject,
    ) {
        $this->allOrNothing = BulkAddMode::AllOrNothing->value === $defaultMode;
    }

    public function getMaxTotalQuantity(): int
    {
        return $this->maxTotalQuantity;
    }

    public function hydrateProduct(mixed $value): ?ProductInterface
    {
        $product = null === $value ? null : $this->productRepository->find($value);

        return $product instanceof ProductInterface ? $product : null;
    }

    public function dehydrateProduct(?ProductInterface $product): mixed
    {
        return $product?->getId();
    }

    public function getMatrix(): ?Matrix
    {
        if ($this->matrix instanceof \Tmonier\SyliusQuickOrderPlugin\Matrix\Matrix || !$this->product instanceof \Sylius\Component\Core\Model\ProductInterface || !$this->activationChecker->isMatrixProduct($this->product)) {
            return $this->matrix;
        }
        $channel = $this->channelContext->getChannel();
        if (!$channel instanceof ChannelInterface || !$this->product->isEnabled() || !$this->product->hasChannel($channel)) {
            return null;
        }

        return $this->matrix = $this->matrixBuilder->build($this->product, $channel);
    }

    /** The result of the no-JavaScript route, if any, is shown once (failed quantities kept). */
    #[PostMount]
    public function restoreFallbackResult(): void
    {
        $session = $this->requestStack?->getMainRequest()?->hasSession() ? $this->requestStack->getMainRequest()->getSession() : null;
        $key = self::SESSION_RESULT_KEY . (string) (\is_scalar($id = $this->product?->getId()) ? $id : '');
        $stored = $session?->get($key);
        if (\is_array($stored) && \is_array($stored['result'] ?? null) && \is_array($stored['quantities'] ?? null)) {
            $session->remove($key);
            $this->lastResult = $stored['result'];
            $this->quantities = array_filter($stored['quantities'], \is_string(...));
            $this->allOrNothing = true === ($stored['all_or_nothing'] ?? false);
            $this->resultQuantities = $this->quantities;
        }
        $this->initializeQuantities();
    }

    /**
     * Every orderable cell has a key: the browser only sets existing model paths. Also before
     * re-renders, for cells that became orderable since the mount.
     */
    public function initializeQuantities(): void
    {
        foreach ($this->getMatrix()->cells ?? [] as $cell) {
            if ($cell->isOrderable()) {
                $this->quantities[$cell->getKey()] ??= '';
            }
        }
    }

    #[PreReRender]
    public function beforeReRender(): void
    {
        $this->initializeQuantities();
        $this->dropCorrectedLines();
    }

    /**
     * A quantity changed since the result drops the result line of its cell (its message quoted
     * the old value); the banner goes when no failed line is left.
     */
    public function dropCorrectedLines(): void
    {
        $result = $this->getResult();
        if (!$result instanceof \Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult) {
            return;
        }
        $changed = [];
        foreach ($this->getMatrix()->cells ?? [] as $cell) {
            $key = $cell->getKey();
            if ($this->normalized($this->resultQuantities[$key] ?? '') !== $this->normalized($this->quantities[$key] ?? '')) {
                $changed[] = $cell->code;
            }
        }
        if ([] === $changed) {
            return;
        }

        $lines = array_values(array_filter($result->lines, static fn (LineResult $line): bool => !\in_array($line->code, $changed, true)));
        $remaining = new BulkAddResult($lines, $result->applied, $result->refusal, $result->mode);
        $this->lastResult = [] === $remaining->getErrors() ? null : $remaining->toArray();
        $this->resultQuantities = null === $this->lastResult ? [] : $this->quantities;
    }

    private function normalized(mixed $quantity): string
    {
        return \is_string($quantity) || \is_int($quantity) ? (string) $quantity : '';
    }

    /** Typed quantity of a cell, as shown in its input. */
    public function getQuantity(string $key): string
    {
        $quantity = $this->quantities[$key] ?? '';

        return \is_scalar($quantity) ? (string) $quantity : '';
    }

    /**
     * Catalog prices x the quantities the cart would take, read like the bulk adder reads them
     * (whole numbers, zero fractions, quantity rules with the cart content and rounding, cart
     * line limit). An estimate: cart promotions, tier prices, taxes not included in prices and
     * shipping are the cart's.
     *
     * @return array{units: int, total: int}
     */
    public function getEstimate(): array
    {
        $inCart = $this->quantitiesInCart();
        $units = 0;
        $total = 0;
        foreach ($this->getMatrix()->cells ?? [] as $cell) {
            $quantity = $this->estimatedQuantity($cell, $inCart[$cell->variantId] ?? 0);
            if (null !== $quantity && null !== $cell->price) {
                $units += $quantity;
                $total += $quantity * $cell->price;
            }
        }

        return ['units' => $units, 'total' => $total];
    }

    public function getResult(): ?BulkAddResult
    {
        if (null === $this->lastResult) {
            return null;
        }

        try {
            /** @var array{lines: list<array{c: string, r: string, q: int|null, s: string, l: list<int>, p: string|null, v: string|null, a: int, av: int|null}>, applied: bool, refusal: string|null, mode: string} $data */
            $data = $this->lastResult;

            return BulkAddResult::fromArray($data);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Result line of a cell, by variant code. */
    public function getLineResult(string $code): ?LineResult
    {
        foreach ($this->getResult()->lines ?? [] as $line) {
            if ($line->code === $code) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Cells whose line failed, for the list of links of the result banner.
     *
     * @return list<MatrixCell>
     */
    public function getFailedCells(): array
    {
        $failed = [];
        foreach ($this->getMatrix()->cells ?? [] as $cell) {
            if (true === $this->getLineResult($cell->code)?->status->isError()) {
                $failed[] = $cell;
            }
        }

        return $failed;
    }

    #[LiveAction]
    public function add(): void
    {
        if (!$this->product instanceof \Sylius\Component\Core\Model\ProductInterface || !$this->getMatrix() instanceof \Tmonier\SyliusQuickOrderPlugin\Matrix\Matrix) {
            return;
        }
        $cart = $this->cartContext->getCart();
        \assert($cart instanceof OrderInterface);

        $result = $this->orderHandler->add($this->product, $this->quantities, $cart, $this->allOrNothing ? BulkAddMode::AllOrNothing : BulkAddMode::Partial);
        $this->lastResult = $result->toArray();
        // Cell statuses (stock, prices) may have changed with the cart.
        $this->matrix = null;

        if ($result->applied) {
            // Lines added are cleared (a second click must not add them again); the others stay.
            foreach ($this->getMatrix()->cells ?? [] as $cell) {
                if (($this->getLineResult($cell->code)->addedQuantity ?? 0) > 0) {
                    $this->quantities[$cell->getKey()] = '';
                }
            }
            $this->emit(self::CART_CHANGED, ['cartId' => $cart->getId()]);
        }
        $this->resultQuantities = $this->quantities;
    }

    private function estimatedQuantity(MatrixCell $cell, int $inCart): ?int
    {
        // Read like MatrixOrderHandler::input() and LineCollector: strings and integers only,
        // cleaned ('""', no-break spaces, BOM) before the empty check.
        $raw = $this->quantities[$cell->getKey()] ?? null;
        if (!$cell->isOrderable() || !\is_string($raw) && !\is_int($raw) || '' === LineCollector::clean((string) $raw)) {
            return null;
        }
        $quantity = LineCollector::parseQuantity((string) $raw);
        if (null === $quantity) {
            return null;
        }

        $constraint = $cell->getConstraint();
        if ($constraint instanceof \Tmonier\SyliusQuickOrderPlugin\Quantity\QuantityConstraint && !$constraint->isSatisfiedBy($inCart + $quantity)) {
            $rounded = QuantityRounding::RoundUp === $this->quantityRounding ? $constraint->roundUp($inCart + $quantity) : null;
            if (null === $rounded) {
                return null;
            }
            $quantity = $rounded - $inCart;
        }

        return $inCart + $quantity > $this->quantityLimit ? null : $quantity;
    }

    /** @return array<int, int> quantities already in the cart, by variant id */
    private function quantitiesInCart(): array
    {
        try {
            $cart = $this->cartContext->getCart();
        } catch (CartNotFoundException) {
            return [];
        }

        $quantities = [];
        foreach ($cart->getItems() as $item) {
            $variant = $item instanceof \Sylius\Component\Core\Model\OrderItemInterface ? $item->getVariant() : null;
            $id = $variant?->getId();
            if (\is_int($id)) {
                $quantities[$id] = ($quantities[$id] ?? 0) + $item->getQuantity();
            }
        }

        return $quantities;
    }
}
