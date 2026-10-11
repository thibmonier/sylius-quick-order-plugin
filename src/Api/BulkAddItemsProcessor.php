<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Api;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Sylius\Bundle\ApiBundle\Context\UserContextInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddMode;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkCartAdderInterface;
use Tmonier\SyliusQuickOrderPlugin\Adder\LineStatus;
use Tmonier\SyliusQuickOrderPlugin\Api\Resource\BulkAddItemsToCart;
use Tmonier\SyliusQuickOrderPlugin\Input\LineCollector;

/** @implements ProcessorInterface<BulkAddItemsToCart, BulkAddItemsResult> */
final class BulkAddItemsProcessor implements ProcessorInterface
{
    /** @param OrderRepositoryInterface<OrderInterface> $orderRepository */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly BulkCartAdderInterface $bulkCartAdder,
        private readonly UserContextInterface $userContext,
        private readonly string $apiShopRoute,
        private readonly string $defaultMode,
        private readonly int $maxLines,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BulkAddItemsResult
    {
        $tokenValue = $uriVariables['tokenValue'] ?? null;

        $cart = \is_string($tokenValue) ? $this->orderRepository->findCartByTokenValue($tokenValue) : null;
        if (!$cart instanceof OrderInterface || !$this->isCartAccessible($cart)) {
            throw new NotFoundHttpException('Cart not found.');
        }

        $mode = null === $data->mode ? BulkAddMode::from($this->defaultMode) : BulkAddMode::tryFrom($data->mode);
        if (null === $mode) {
            throw new UnprocessableEntityHttpException('mode: expected "partial" or "all_or_nothing".');
        }

        $collector = new LineCollector($this->maxLines);
        foreach (array_values($data->lines) as $index => $line) {
            $code = \is_array($line) ? ($line['code'] ?? null) : null;
            $quantity = \is_array($line) ? ($line['quantity'] ?? null) : null;
            if (!\is_string($code) || !(null === $quantity || \is_int($quantity) || \is_string($quantity))) {
                $collector->addInvalid($index + 1, \is_string($code) ? $code : '', \is_scalar($quantity) ? (string) $quantity : '', LineStatus::InvalidLine);

                continue;
            }
            $collector->add($index + 1, $code, $quantity);
        }

        $result = $this->bulkCartAdder->add($cart, $collector->getInput(), $mode);
        if (null !== $result->refusal && [] === $result->lines) {
            throw new UnprocessableEntityHttpException(\sprintf('Refused: %s.', $result->refusal));
        }

        return BulkAddItemsResult::fromResult($this->apiShopRoute . '/orders/' . $cart->getTokenValue(), $result);
    }

    /**
     * Same rule as the core AddItemToCartHandler::assertCartAccessible() (private there): a
     * cart of a registered customer is only reachable by that customer.
     */
    private function isCartAccessible(OrderInterface $cart): bool
    {
        if ($cart->isCreatedByGuest()) {
            return true;
        }

        $cartCustomer = $cart->getCustomer();
        if (!$cartCustomer instanceof CustomerInterface || null === $cartCustomer->getUser()) {
            return true;
        }

        $user = $this->userContext->getUser();

        return $user instanceof ShopUserInterface && $user->getCustomer()?->getId() === $cartCustomer->getId();
    }
}
