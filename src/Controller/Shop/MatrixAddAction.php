<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Controller\Shop;

use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddMode;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixActivationCheckerInterface;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixOrderHandler;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixOrderHandlerInterface;
use Tmonier\SyliusQuickOrderPlugin\RateLimit\RateLimitKeys;
use Tmonier\SyliusQuickOrderPlugin\Twig\Component\MatrixOrderComponent;

final class MatrixAddAction extends AbstractController
{
    /**
     * @param RepositoryInterface<ProductInterface> $productRepository
     */
    public function __construct(
        private readonly CartContextInterface $cartContext,
        private readonly ChannelContextInterface $channelContext,
        private readonly RepositoryInterface $productRepository,
        private readonly MatrixActivationCheckerInterface $matrixActivationChecker,
        private readonly MatrixOrderHandlerInterface $matrixOrderHandler,
        private readonly ?RateLimiterFactoryInterface $ipLimiter = null,
    ) {
    }

    public function __invoke(Request $request, int $productId): RedirectResponse
    {
        $product = $this->productRepository->find($productId);
        $channel = $this->channelContext->getChannel();
        if (!$product instanceof ProductInterface || !$product->isEnabled() ||
            !$channel instanceof ChannelInterface || !$product->hasChannel($channel) ||
            true !== $this->matrixActivationChecker->isMatrixProduct($product)) {
            throw new NotFoundHttpException('Product not found.');
        }
        $productPage = $this->generateUrl('sylius_shop_product_show', ['slug' => $product->getSlug()]) . '#tmonier-quick-order-matrix';
        if (!$this->isCsrfTokenValid(QuickOrderController::CSRF_ID, (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'tmonier_quick_order.invalid_csrf_token');

            return $this->redirect($productPage);
        }

        if ($this->isRateLimited($request)) {
            $this->addFlash('error', 'tmonier_quick_order.too_many_requests');

            return $this->redirect($productPage);
        }

        $cart = $this->cartContext->getCart();
        \assert($cart instanceof OrderInterface);
        $quantities = array_filter($request->request->all('quantities'), \is_string(...));
        $allOrNothing = $request->request->getBoolean('all_or_nothing');
        $result = $this->matrixOrderHandler->add($product, $quantities, $cart, $allOrNothing ? BulkAddMode::AllOrNothing : BulkAddMode::Partial);
        if ($result->applied && !$result->hasErrors()) {
            $this->addFlash('success', ['message' => 'tmonier_quick_order.lines_added', 'parameters' => ['%count%' => $result->countAdded()]]);

            return $this->redirectToRoute('sylius_shop_cart_summary');
        }

        foreach ($product->getVariants() as $variant) {
            if ($variant instanceof ProductVariantInterface) {
                foreach ($result->lines as $line) {
                    if ($line->code === $variant->getCode() && $line->addedQuantity > 0) {
                        unset($quantities[MatrixOrderHandler::key($variant)]);
                    }
                }
            }
        }
        $request->getSession()->set(MatrixOrderComponent::SESSION_RESULT_KEY . $productId, [
            'result' => $result->toArray(),
            'quantities' => $quantities,
            'all_or_nothing' => $allOrNothing,
        ]);

        return $this->redirect($productPage);
    }

    private function isRateLimited(Request $request): bool
    {
        if (!$this->ipLimiter instanceof RateLimiterFactoryInterface) {
            return false;
        }

        return !$this->ipLimiter->create(RateLimitKeys::ip($request->getClientIp()))->consume()->isAccepted();
    }
}
