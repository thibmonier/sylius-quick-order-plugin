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
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddMode;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkCartAdderInterface;
use Tmonier\SyliusQuickOrderPlugin\Input\CsvParser;
use Tmonier\SyliusQuickOrderPlugin\Input\PasteParser;
use Tmonier\SyliusQuickOrderPlugin\Input\QuickOrderInput;
use Tmonier\SyliusQuickOrderPlugin\Reorder\ReorderInputFactory;
use Tmonier\SyliusQuickOrderPlugin\Report\CsvWriter;

/**
 * Quick order page: paste or CSV upload, preview, "add N lines", result with a downloadable
 * error report; reorder of a past order from the account. No JavaScript needed.
 */
final class QuickOrderController extends AbstractController
{
    public const CSRF_ID = 'tmonier_quick_order';

    private const RESULT_KEY = '_tmonier_quick_order.result';

    /** The previewed input stays on the server; the form only carries its nonce. */
    private const PREVIEW_KEY = '_tmonier_quick_order.preview';

    /** @param OrderRepositoryInterface<OrderInterface> $orderRepository */
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
    ) {
    }

    public function indexAction(): Response
    {
        if (null !== $denied = $this->denyGuests()) {
            return $denied;
        }

        return $this->render('@TmonierSyliusQuickOrderPlugin/shop/quick_order/index.html.twig', $this->viewParameters());
    }

    public function previewAction(Request $request): Response
    {
        if (null !== $denied = $this->denyGuests()) {
            return $denied;
        }
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_csrf_token'))) {
            return $this->redirectWithError('tmonier_quick_order.invalid_csrf_token');
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
            return $this->redirectWithError('tmonier_quick_order.refusal.' . $result->refusal);
        }
        $this->storeResult($request, $result);
        $nonce = bin2hex(random_bytes(16));
        // One preview per session (bounded by max_lines like any parsed input).
        $request->getSession()->set(self::PREVIEW_KEY, ['nonce' => $nonce, 'input' => $input->toJson()]);

        return $this->render('@TmonierSyliusQuickOrderPlugin/shop/quick_order/index.html.twig', $this->viewParameters([
            'preview' => $result,
            'preview_nonce' => $nonce,
            'lines' => $file instanceof UploadedFile ? '' : $text,
            'mode' => $this->readMode($request)->value,
        ]));
    }

    public function addAction(Request $request): Response
    {
        if (null !== $denied = $this->denyGuests()) {
            return $denied;
        }
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_csrf_token'))) {
            return $this->redirectWithError('tmonier_quick_order.invalid_csrf_token');
        }

        $session = $request->getSession();
        $preview = $session->get(self::PREVIEW_KEY);
        $nonce = (string) $request->request->get('preview', '');
        if (!\is_array($preview) || !\is_string($preview['nonce'] ?? null) || !\is_string($preview['input'] ?? null) || '' === $nonce || !hash_equals($preview['nonce'], $nonce)) {
            return $this->redirectWithError('tmonier_quick_order.refusal.' . BulkAddResult::REFUSED_PREVIEW_EXPIRED);
        }
        $session->remove(self::PREVIEW_KEY);

        return $this->addAndRedirect($request, QuickOrderInput::fromJson($preview['input'], $this->maxLines), $this->readMode($request));
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

        return $this->addAndRedirect($request, $this->reorderInputFactory->create($order), BulkAddMode::Partial);
    }

    public function resultAction(Request $request): Response
    {
        $result = $this->loadResult($request);
        if (null === $result) {
            return $this->redirectToRoute('tmonier_quick_order_shop_index');
        }

        return $this->render('@TmonierSyliusQuickOrderPlugin/shop/quick_order/index.html.twig', $this->viewParameters(['result' => $result]));
    }

    public function templateAction(): Response
    {
        return self::csvResponse($this->csvWriter->template(), 'quick-order-template.csv');
    }

    public function reportAction(Request $request): Response
    {
        $result = $this->loadResult($request);
        if (null === $result) {
            throw new NotFoundHttpException('No quick order result.');
        }

        return self::csvResponse($this->csvWriter->report($result), 'quick-order-report.csv');
    }

    private function addAndRedirect(Request $request, QuickOrderInput $input, BulkAddMode $mode): Response
    {
        $result = $this->bulkCartAdder->add($this->getCart(), $input, $mode);
        if (null !== $result->refusal && [] === $result->lines) {
            return $this->redirectWithError('tmonier_quick_order.refusal.' . $result->refusal);
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
        if ($this->allowGuests || null !== $this->getUser()) {
            return null;
        }

        return $this->redirectToRoute('sylius_shop_login');
    }

    private function redirectWithError(string $message): Response
    {
        $this->addFlash('error', $message);

        return $this->redirectToRoute('tmonier_quick_order_shop_index');
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
            'lines' => '',
            'mode' => $this->defaultMode,
            'max_lines' => $this->maxLines,
            'max_file_size' => $this->maxFileSize,
            'csrf_id' => self::CSRF_ID,
        ];
    }

    private static function csvResponse(string $csv, string $filename): Response
    {
        return new Response($csv, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
