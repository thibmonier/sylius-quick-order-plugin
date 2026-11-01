<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Controller\Shop;

use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddMode;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkCartAdderInterface;
use Tmonier\SyliusQuickOrderPlugin\RateLimit\RateLimitKeys;
use Tmonier\SyliusQuickOrderPlugin\Reorder\ReorderInputFactory;

final class ReorderAction extends AbstractController
{
    /**
     * @param OrderRepositoryInterface<OrderInterface> $orderRepository
     */
    public function __construct(
        private readonly CartContextInterface $cartContext,
        private readonly BulkCartAdderInterface $bulkCartAdder,
        private readonly ReorderInputFactory $reorderInputFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CustomerContextInterface $customerContext,
        private readonly ChannelContextInterface $channelContext,
        private readonly QuickOrderSessionStorage $sessionStorage,
        private readonly ?RateLimiterFactoryInterface $ipLimiter = null,
    ) {
    }

    public function __invoke(Request $request, string $number): Response
    {
        $customer = $this->customerContext->getCustomer();
        if (!$customer instanceof CustomerInterface) {
            return $this->redirectToRoute('sylius_shop_login');
        }
        if (!$this->isCsrfTokenValid(QuickOrderController::CSRF_ID, (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'tmonier_quick_order.invalid_csrf_token');

            return $this->redirectToRoute('tmonier_quick_order_shop_index');
        }

        $order = $this->orderRepository->findOneByNumberAndCustomer($number, $customer);
        if (!$order instanceof OrderInterface || $order->getChannel() !== $this->channelContext->getChannel()) {
            throw new NotFoundHttpException('Order not found.');
        }
        if ($this->isRateLimited($request)) {
            $this->addFlash('error', 'tmonier_quick_order.too_many_requests');

            return $this->redirectToRoute('tmonier_quick_order_shop_index');
        }

        $cart = $this->cartContext->getCart();
        \assert($cart instanceof OrderInterface);
        $input = $this->reorderInputFactory->create($order);
        $result = $this->bulkCartAdder->add($cart, $input, BulkAddMode::Partial);
        if (null !== $result->refusal && [] === $result->lines) {
            $this->addFlash('error', ['message' => 'tmonier_quick_order.refusal.' . $result->refusal, 'parameters' => []]);

            return $this->redirectToRoute('tmonier_quick_order_shop_index');
        }

        $this->sessionStorage->storeResult($request, $result);
        if ($result->applied && !$result->hasErrors()) {
            $this->addFlash('success', ['message' => 'tmonier_quick_order.lines_added', 'parameters' => ['%count%' => $result->countAdded()]]);

            return $this->redirectToRoute('sylius_shop_cart_summary');
        }

        return $this->redirectToRoute('tmonier_quick_order_shop_result');
    }

    private function isRateLimited(Request $request): bool
    {
        if (!$this->ipLimiter instanceof RateLimiterFactoryInterface) {
            return false;
        }

        return !$this->ipLimiter->create(RateLimitKeys::ip($request->getClientIp()))->consume()->isAccepted();
    }
}
