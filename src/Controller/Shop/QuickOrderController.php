<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Controller\Shop;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddMode;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkCartAdderInterface;
use Tmonier\SyliusQuickOrderPlugin\Input\CsvParser;
use Tmonier\SyliusQuickOrderPlugin\Input\PasteParser;
use Tmonier\SyliusQuickOrderPlugin\Input\QuickOrderInput;
use Tmonier\SyliusQuickOrderPlugin\RateLimit\RateLimitKeys;

final class QuickOrderController extends AbstractController
{
    public const CSRF_ID = 'tmonier_quick_order';

    public function __construct(
        private readonly CartContextInterface $cartContext,
        private readonly BulkCartAdderInterface $bulkCartAdder,
        private readonly PasteParser $pasteParser,
        private readonly CsvParser $csvParser,
        private readonly QuickOrderSessionStorage $sessionStorage,
        private readonly bool $allowGuests,
        private readonly string $defaultMode,
        private readonly int $maxLines,
        private readonly int $maxFileSize,
        private readonly int $maxTotalQuantity = 20000,
        private readonly ?RateLimiterFactoryInterface $ipLimiter = null,
        private readonly ?string $exampleLines = null,
    ) {
    }

    public function indexAction(): Response
    {
        if (($denied = $this->denyGuests()) instanceof Response) {
            return $denied;
        }

        return $this->render('@TmonierSyliusQuickOrderPlugin/shop/quick_order/index.html.twig', $this->viewParameters());
    }

    public function previewAction(Request $request): Response
    {
        if (($denied = $this->denyGuests()) instanceof Response) {
            return $denied;
        }
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'tmonier_quick_order.invalid_csrf_token');

            return $this->redirectToRoute('tmonier_quick_order_shop_index');
        }
        if ($this->isRateLimited($request)) {
            return $this->render('@TmonierSyliusQuickOrderPlugin/shop/quick_order/index.html.twig', $this->viewParameters([
                'error' => 'tmonier_quick_order.too_many_requests',
            ]), new Response(null, Response::HTTP_TOO_MANY_REQUESTS));
        }

        $text = (string) $request->request->get('lines', '');
        $file = $request->files->get('file');
        $input = $file instanceof UploadedFile
            ? ($file->isValid() ? $this->csvParser->parseFile($file->getPathname()) : QuickOrderInput::rejected(BulkAddResult::REFUSED_FILE))
            : $this->pasteParser->parse($text);

        $cart = $this->cartContext->getCart();
        \assert($cart instanceof OrderInterface);
        $result = $this->bulkCartAdder->validate($cart, $input);
        if (null !== $result->refusal) {
            return $this->redirectWithRefusal($result->refusal);
        }
        $this->sessionStorage->storeResult($request, $result);
        $nonce = $this->sessionStorage->storePreview($request, $input);

        return $this->render('@TmonierSyliusQuickOrderPlugin/shop/quick_order/index.html.twig', $this->viewParameters([
            'preview' => $result,
            'preview_nonce' => $nonce,
            'lines' => $file instanceof UploadedFile ? '' : $text,
            'mode' => $this->readMode($request)->value,
        ]));
    }

    public function addAction(Request $request): Response
    {
        if (($denied = $this->denyGuests()) instanceof Response) {
            return $denied;
        }
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'tmonier_quick_order.invalid_csrf_token');

            return $this->redirectToRoute('tmonier_quick_order_shop_index');
        }
        if ($this->isRateLimited($request)) {
            return $this->render('@TmonierSyliusQuickOrderPlugin/shop/quick_order/index.html.twig', $this->viewParameters([
                'error' => 'tmonier_quick_order.too_many_requests',
                'lines' => \strlen((string) $request->request->get('lines', '')) <= $this->maxFileSize ? (string) $request->request->get('lines', '') : '',
            ]), new Response(null, Response::HTTP_TOO_MANY_REQUESTS));
        }

        $json = $this->sessionStorage->takePreview($request, (string) $request->request->get('preview', ''));
        if (null === $json) {
            $text = (string) $request->request->get('lines', '');

            return $this->render('@TmonierSyliusQuickOrderPlugin/shop/quick_order/index.html.twig', $this->viewParameters([
                'error' => 'tmonier_quick_order.refusal.' . BulkAddResult::REFUSED_PREVIEW_EXPIRED,
                'lines' => \strlen($text) <= $this->maxFileSize ? $text : '',
                'mode' => $this->readMode($request)->value,
            ]));
        }

        return $this->addAndRedirect($request, QuickOrderInput::fromJson($json, $this->maxLines), $this->readMode($request));
    }

    public function resultAction(Request $request): Response
    {
        if (($denied = $this->denyGuests()) instanceof Response) {
            return $denied;
        }
        $result = $this->sessionStorage->loadResult($request);
        if (!$result instanceof BulkAddResult) {
            return $this->redirectToRoute('tmonier_quick_order_shop_index');
        }

        return $this->render('@TmonierSyliusQuickOrderPlugin/shop/quick_order/index.html.twig', $this->viewParameters(['result' => $result]));
    }

    private function addAndRedirect(Request $request, QuickOrderInput $input, BulkAddMode $mode): Response
    {
        $cart = $this->cartContext->getCart();
        \assert($cart instanceof OrderInterface);
        $result = $this->bulkCartAdder->add($cart, $input, $mode);
        if (null !== $result->refusal && [] === $result->lines) {
            return $this->redirectWithRefusal($result->refusal);
        }
        $this->sessionStorage->storeResult($request, $result);
        if ($result->applied && !$result->hasErrors()) {
            $this->addFlash('success', ['message' => 'tmonier_quick_order.lines_added', 'parameters' => ['%count%' => $result->countAdded()]]);

            return $this->redirectToRoute('sylius_shop_cart_summary');
        }

        return $this->redirectToRoute('tmonier_quick_order_shop_result');
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

    private function redirectWithRefusal(string $refusal): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->addFlash('error', ['message' => 'tmonier_quick_order.refusal.' . $refusal, 'parameters' => ['%max%' => $this->maxTotalQuantity, '%lines%' => $this->maxLines]]);

        return $this->redirectToRoute('tmonier_quick_order_shop_index');
    }

    /** @param array<string, mixed> $parameters @return array<string, mixed> */
    private function viewParameters(array $parameters = []): array
    {
        return $parameters + [
            'preview' => null, 'result' => null, 'preview_nonce' => null, 'error' => null,
            'lines' => '', 'mode' => $this->defaultMode, 'max_lines' => $this->maxLines,
            'max_file_size' => $this->maxFileSize, 'example_lines' => $this->exampleLines, 'csrf_id' => self::CSRF_ID,
        ];
    }

    private function isRateLimited(Request $request): bool
    {
        return $this->ipLimiter instanceof RateLimiterFactoryInterface
            && !$this->ipLimiter->create(RateLimitKeys::ip($request->getClientIp()))->consume()->isAccepted();
    }
}
