<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Twig\Component;

use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\TwigHooks\LiveComponent\HookableLiveComponentTrait;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddMode;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult;
use Tmonier\SyliusQuickOrderPlugin\Adder\LineResult;
use Tmonier\SyliusQuickOrderPlugin\Matrix\Matrix;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixActivationCheckerInterface;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixBuilderInterface;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixOrderHandlerInterface;

/**
 * Order matrix of a product page: one quantity per variant, an estimate, one "add" for all
 * lines (bulk cart adder: one order processing). Emits "sylius:shop:cart_changed" so that the
 * cart widget and other cart components refresh. The template also works without JavaScript
 * (plain POST to tmonier_quick_order_shop_matrix_add).
 */
#[AsLiveComponent]
final class MatrixOrderComponent
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use HookableLiveComponentTrait;

    public const CART_CHANGED = 'sylius:shop:cart_changed';

    #[LiveProp(hydrateWith: 'hydrateProduct', dehydrateWith: 'dehydrateProduct')]
    public ?ProductInterface $product = null;

    /** @var array<mixed> quantities by variant id, as typed (validated on the server) */
    #[LiveProp(writable: true)]
    public array $quantities = [];

    #[LiveProp(writable: true)]
    public bool $allOrNothing = false;

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
        if (null !== $this->matrix || null === $this->product || !$this->activationChecker->isMatrixProduct($this->product)) {
            return $this->matrix;
        }
        $channel = $this->channelContext->getChannel();
        if (!$channel instanceof ChannelInterface || !$this->product->isEnabled() || !$this->product->hasChannel($channel)) {
            return null;
        }

        return $this->matrix = $this->matrixBuilder->build($this->product, $channel);
    }

    /** Every orderable cell has a key from the start: the browser only sets existing model paths. */
    #[PostMount]
    public function initializeQuantities(): void
    {
        foreach ($this->getMatrix()->cells ?? [] as $cell) {
            if ($cell->isOrderable()) {
                $this->quantities[$cell->getKey()] ??= '';
            }
        }
    }

    /** Typed quantity of a cell, as shown in its input. */
    public function getQuantity(string $key): string
    {
        $quantity = $this->quantities[$key] ?? '';

        return \is_scalar($quantity) ? (string) $quantity : '';
    }

    /**
     * Catalog prices x typed whole quantities of orderable cells. An estimate: cart promotions,
     * tier prices, taxes and shipping are applied by the cart.
     *
     * @return array{units: int, total: int}
     */
    public function getEstimate(): array
    {
        $units = 0;
        $total = 0;
        foreach ($this->getMatrix()->cells ?? [] as $cell) {
            $quantity = $this->getQuantity($cell->getKey());
            if ($cell->isOrderable() && null !== $cell->price && 1 === preg_match('/^\d{1,9}$/', trim($quantity))) {
                $units += (int) $quantity;
                $total += (int) $quantity * $cell->price;
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

    #[LiveAction]
    public function add(): void
    {
        if (null === $this->product || null === $this->getMatrix()) {
            return;
        }
        $cart = $this->cartContext->getCart();
        \assert($cart instanceof OrderInterface);

        $result = $this->orderHandler->add($this->product, $this->quantities, $cart, $this->allOrNothing ? BulkAddMode::AllOrNothing : BulkAddMode::Partial);
        $this->lastResult = $result->toArray();
        // Cell statuses (stock, prices) may have changed with the cart.
        $this->matrix = null;

        if ($result->applied) {
            // Lines added are cleared; the others stay to be fixed.
            foreach ($result->lines as $line) {
                if ($line->addedQuantity > 0) {
                    $cell = null;
                    foreach ($this->getMatrix()->cells ?? [] as $candidate) {
                        $cell = $candidate->code === $line->code ? $candidate : $cell;
                    }
                    if (null !== $cell) {
                        $this->quantities[$cell->getKey()] = '';
                    }
                }
            }
            $this->emit(self::CART_CHANGED, ['cartId' => $cart->getId()]);
        }
    }
}
