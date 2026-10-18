<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Controller\Shop;

use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddMode;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkCartAdderInterface;
use Tmonier\SyliusQuickOrderPlugin\Input\CsvParser;
use Tmonier\SyliusQuickOrderPlugin\Input\PasteParser;
use Tmonier\SyliusQuickOrderPlugin\Input\QuickOrderInput;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixActivationCheckerInterface;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixOrderHandlerInterface;
use Tmonier\SyliusQuickOrderPlugin\RateLimit\RateLimitKeys;
use Tmonier\SyliusQuickOrderPlugin\Reorder\ReorderInputFactory;
use Tmonier\SyliusQuickOrderPlugin\Report\CsvWriter;
use Tmonier\SyliusQuickOrderPlugin\Twig\Component\MatrixOrderComponent;

/**
 * Quick order page: paste or CSV upload, preview, "add N lines", result with a downloadable
 * error report; reorder of a past order from the account. No JavaScript needed.
 */
final class QuickOrderController extends AbstractController
{
    public const CSRF_ID = 'tmonier_quick_order';

    private const RESULT_KEY = '_tmonier_quick_order.result';

    /** The previewed inputs stay on the server; each "add" form only carries a one-time nonce. */
    private const PREVIEW_KEY = '_tmonier_quick_order.previews';

    /** Previews kept per session (one per browser tab, roughly), oldest dropped first. */
    private const MAX_PREVIEWS = 5;

    /**
     * @param OrderRepositoryInterface<OrderInterface> $orderRepository
     * @param RepositoryInterface<ProductInterface>|null $productRepository
     */
    public function __construct(
        private readonly CartContextInterface $cartContext,
        private readonly BulkCartAdderInterface $bulkCartAdder,
        private readonly PasteParser $pasteParser,
        private readonly CsvParser $csvParser,
        private readonly CsvWriter $csvWriter,
        private readonly ReorderInputFactory $reorderInputFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CustomerContextInterface $customerContext,
        private readonly ChannelContextInterface $channelContext,
        private readonly bool $allowGuests,
        private readonly string $defaultMode,
        private readonly int $maxLines,
        private readonly int $maxFileSize,
        private readonly int $maxTotalQuantity = 20000,
        private readonly ?RepositoryInterface $productRepository = null,
        private readonly ?MatrixActivationCheckerInterface $matrixActivationChecker = null,
        private readonly ?MatrixOrderHandlerInterface $matrixOrderHandler = null,
        private readonly ?RateLimiterFactory $ipLimiter = null,
        private readonly ?string $exampleLines = null,
    ) {
    }

    public function indexAction(): Response
    {
        if (($denied = $this->denyGuests()) instanceof \Symfony\Component\HttpFoundation\Response) {
            return $denied;
        }

        return $this->render('@TmonierSyliusQuickOrderPlugin/shop/quick_order/index.html.twig', $this->viewParameters());
    }

    public function previewAction(Request $request): Response
    {
        if (($denied = $this->denyGuests()) instanceof \Symfony\Component\HttpFoundation\Response) {
            return $denied;
        }
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_csrf_token'))) {
            return $this->redirectWithError('tmonier_quick_order.invalid_csrf_token');
        }
        if ($this->isRateLimited($request)) {
            return $this->render('@TmonierSyliusQuickOrderPlugin/shop/quick_order/index.html.twig', $this->viewParameters([
                'error' => 'tmonier_quick_order.too_many_requests',
            ]), new Response(null, Response::HTTP_TOO_MANY_REQUESTS));
        }

        $text = (string) $request->request->get('lines', '');
        $file = $request->files->get('file');
        if ($file instanceof UploadedFile) {
            $input = $file->isValid() ? $this->csvParser->parseFile($file->getPathname()) : QuickOrderInput::rejected(BulkAddResult::REFUSED_FILE);
        } else {
            $input = $this->pasteParser->parse($text);
        }

        $result = $this->bulkCartAdder->validate($this->getCart(), $input);
        if (null !== $result->refusal) {
            return $this->redirectWithRefusal($result->refusal);
        }
        $this->storeResult($request, $result);
        $nonce = $this->storePreview($request, $input);

        return $this->render('@TmonierSyliusQuickOrderPlugin/shop/quick_order/index.html.twig', $this->viewParameters([
            'preview' => $result,
            'preview_nonce' => $nonce,
            'lines' => $file instanceof UploadedFile ? '' : $text,
            'mode' => $this->readMode($request)->value,
        ]));
    }

    public function addAction(Request $request): Response
    {
        if (($denied = $this->denyGuests()) instanceof \Symfony\Component\HttpFoundation\Response) {
            return $denied;
        }
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_csrf_token'))) {
            return $this->redirectWithError('tmonier_quick_order.invalid_csrf_token');
        }
        if ($this->isRateLimited($request)) {
            $text = (string) $request->request->get('lines', '');

            return $this->render('@TmonierSyliusQuickOrderPlugin/shop/quick_order/index.html.twig', $this->viewParameters([
                'error' => 'tmonier_quick_order.too_many_requests',
                'lines' => \strlen($text) <= $this->maxFileSize ? $text : '',
            ]), new Response(null, Response::HTTP_TOO_MANY_REQUESTS));
        }

        $json = $this->takePreview($request, (string) $request->request->get('preview', ''));
        if (null === $json) {
            // Already added (back button) or too old: show the page again with the pasted text.
            $text = (string) $request->request->get('lines', '');

            return $this->render('@TmonierSyliusQuickOrderPlugin/shop/quick_order/index.html.twig', $this->viewParameters([
                'error' => 'tmonier_quick_order.refusal.' . BulkAddResult::REFUSED_PREVIEW_EXPIRED,
                'lines' => \strlen($text) <= $this->maxFileSize ? $text : '',
                'mode' => $this->readMode($request)->value,
            ]));
        }

        return $this->addAndRedirect($request, QuickOrderInput::fromJson($json, $this->maxLines), $this->readMode($request));
    }

    public function reorderAction(Request $request, string $number): Response
    {
        $customer = $this->customerContext->getCustomer();
        if (!$customer instanceof CustomerInterface) {
            return $this->redirectToRoute('sylius_shop_login');
        }
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_csrf_token'))) {
            return $this->redirectWithError('tmonier_quick_order.invalid_csrf_token');
        }

        $order = $this->orderRepository->findOneByNumberAndCustomer($number, $customer);
        if (!$order instanceof OrderInterface || $order->getChannel() !== $this->channelContext->getChannel()) {
            throw new NotFoundHttpException('Order not found.');
        }
        if ($this->isRateLimited($request)) {
            // reorderAction always redirects; a 429 render is not possible here. The flash
            // communicates the refusal; the redirect goes to the quick order index.
            $this->addFlash('error', 'tmonier_quick_order.too_many_requests');

            return $this->redirectToRoute('tmonier_quick_order_shop_index');
        }

        return $this->addAndRedirect($request, $this->reorderInputFactory->create($order), BulkAddMode::Partial);
    }

    /**
     * The order matrix without JavaScript: a plain form post of the quantities. Everything but
     * a complete success goes back to the product page, where the matrix shows the result.
     */
    public function matrixAddAction(Request $request, int $productId): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $product = $this->productRepository?->find($productId);
        $channel = $this->channelContext->getChannel();
        if (!$product instanceof ProductInterface || !$this->matrixOrderHandler instanceof \Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixOrderHandlerInterface || !$product->isEnabled() ||
            !$channel instanceof ChannelInterface || !$product->hasChannel($channel) ||
            true !== $this->matrixActivationChecker?->isMatrixProduct($product)) {
            throw new NotFoundHttpException('Product not found.');
        }
        $productPage = $this->generateUrl('sylius_shop_product_show', ['slug' => $product->getSlug()]) . '#tmonier-quick-order-matrix';
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'tmonier_quick_order.invalid_csrf_token');

            return $this->redirect($productPage);
        }

        if ($this->isRateLimited($request)) {
            // matrixAddAction always returns RedirectResponse; a 429 render is not possible here.
            // The flash communicates the refusal; the redirect goes back to the product page.
            $this->addFlash('error', 'tmonier_quick_order.too_many_requests');

            return $this->redirect($productPage);
        }

        $quantities = array_filter($request->request->all('quantities'), \is_string(...));
        $allOrNothing = $request->request->getBoolean('all_or_nothing');
        $result = $this->matrixOrderHandler->add($product, $quantities, $this->getCart(), $allOrNothing ? BulkAddMode::AllOrNothing : BulkAddMode::Partial);
        if ($result->applied && !$result->hasErrors()) {
            $this->addFlash('success', ['message' => 'tmonier_quick_order.lines_added', 'parameters' => ['%count%' => $result->countAdded()]]);

            return $this->redirectToRoute('sylius_shop_cart_summary');
        }

        // Added lines are cleared, the others are shown again with their message.
        foreach ($product->getVariants() as $variant) {
            if ($variant instanceof \Sylius\Component\Core\Model\ProductVariantInterface) {
                foreach ($result->lines as $line) {
                    if ($line->code === $variant->getCode() && $line->addedQuantity > 0) {
                        unset($quantities[\Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixOrderHandler::key($variant)]);
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

    public function resultAction(Request $request): Response
    {
        if (($denied = $this->denyGuests()) instanceof \Symfony\Component\HttpFoundation\Response) {
            return $denied;
        }
        $result = $this->loadResult($request);
        if (!$result instanceof \Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult) {
            return $this->redirectToRoute('tmonier_quick_order_shop_index');
        }

        return $this->render('@TmonierSyliusQuickOrderPlugin/shop/quick_order/index.html.twig', $this->viewParameters(['result' => $result]));
    }

    public function templateAction(): Response
    {
        return $this->csvResponse($this->csvWriter->template(), 'quick-order-template.csv');
    }

    public function reportAction(Request $request): Response
    {
        if (($denied = $this->denyGuests()) instanceof \Symfony\Component\HttpFoundation\Response) {
            return $denied;
        }
        $result = $this->loadResult($request);
        if (!$result instanceof \Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult) {
            throw new NotFoundHttpException('No quick order result.');
        }

        return $this->csvResponse($this->csvWriter->report($result), 'quick-order-report.csv');
    }

    private function addAndRedirect(Request $request, QuickOrderInput $input, BulkAddMode $mode): Response
    {
        $result = $this->bulkCartAdder->add($this->getCart(), $input, $mode);
        if (null !== $result->refusal && [] === $result->lines) {
            return $this->redirectWithRefusal($result->refusal);
        }

        $this->storeResult($request, $result);
        if ($result->applied && !$result->hasErrors()) {
            $this->addFlash('success', ['message' => 'tmonier_quick_order.lines_added', 'parameters' => ['%count%' => $result->countAdded()]]);

            return $this->redirectToRoute('sylius_shop_cart_summary');
        }

        return $this->redirectToRoute('tmonier_quick_order_shop_result');
    }

    private function getCart(): OrderInterface
    {
        $cart = $this->cartContext->getCart();
        \assert($cart instanceof OrderInterface);

        return $cart;
    }

    private function readMode(Request $request): BulkAddMode
    {
        return BulkAddMode::tryFrom((string) $request->request->get('mode', $this->defaultMode)) ?? BulkAddMode::from($this->defaultMode);
    }

    private function denyGuests(): ?Response
    {
        if ($this->allowGuests || $this->getUser() instanceof \Symfony\Component\Security\Core\User\UserInterface) {
            return null;
        }

        return $this->redirectToRoute('sylius_shop_login');
    }

    private function redirectWithError(string $message): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->addFlash('error', $message);

        return $this->redirectToRoute('tmonier_quick_order_shop_index');
    }

    private function redirectWithRefusal(string $refusal): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->addFlash('error', ['message' => 'tmonier_quick_order.refusal.' . $refusal, 'parameters' => ['%max%' => $this->maxTotalQuantity, '%lines%' => $this->maxLines]]);

        return $this->redirectToRoute('tmonier_quick_order_shop_index');
    }

    /** Keeps the input for the "add" step; the session holds at most MAX_PREVIEWS of them. */
    private function storePreview(Request $request, QuickOrderInput $input): string
    {
        $session = $request->getSession();
        $previews = $session->get(self::PREVIEW_KEY);
        $previews = \is_array($previews) ? array_filter($previews, is_string(...)) : [];
        $nonce = bin2hex(random_bytes(16));
        $previews[$nonce] = $input->toJson();
        // Each input is bounded by max_lines; the whole list by the count and a size cap.
        while (\count($previews) > self::MAX_PREVIEWS || array_sum(array_map(strlen(...), $previews)) > 4 * $this->maxFileSize) {
            array_shift($previews);
        }
        $session->set(self::PREVIEW_KEY, $previews);

        return $nonce;
    }

    /** The input of the preview, removed from the session: a preview is added once. */
    private function takePreview(Request $request, string $nonce): ?string
    {
        $session = $request->getSession();
        $previews = $session->get(self::PREVIEW_KEY);
        if ('' === $nonce || !\is_array($previews) || !\is_string($previews[$nonce] ?? null)) {
            return null;
        }
        $json = $previews[$nonce];
        unset($previews[$nonce]);
        $session->set(self::PREVIEW_KEY, $previews);

        return $json;
    }

    private function storeResult(Request $request, BulkAddResult $result): void
    {
        $request->getSession()->set(self::RESULT_KEY, $result->toArray());
    }

    private function loadResult(Request $request): ?BulkAddResult
    {
        $data = $request->getSession()->get(self::RESULT_KEY);
        if (!\is_array($data)) {
            return null;
        }

        try {
            /** @var array{lines: list<array{c: string, r: string, q: int|null, s: string, l: list<int>, p: string|null, v: string|null, a: int, av: int|null}>, applied: bool, refusal: string|null, mode: string} $stored written by storeResult(); anything else throws */
            $stored = $data;

            return BulkAddResult::fromArray($stored);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    private function viewParameters(array $parameters = []): array
    {
        return $parameters + [
            'preview' => null,
            'result' => null,
            'preview_nonce' => null,
            'error' => null,
            'lines' => '',
            'mode' => $this->defaultMode,
            'max_lines' => $this->maxLines,
            'max_file_size' => $this->maxFileSize,
            'example_lines' => $this->exampleLines,
            'csrf_id' => self::CSRF_ID,
        ];
    }

    /** Consumes one token from the IP limiter; returns true when the request must be rejected. */
    private function isRateLimited(Request $request): bool
    {
        if (!$this->ipLimiter instanceof RateLimiterFactory) {
            return false;
        }

        return !$this->ipLimiter->create(RateLimitKeys::ip($request->getClientIp()))->consume()->isAccepted();
    }

    private function csvResponse(string $csv, string $filename): Response
    {
        return new Response($csv, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
