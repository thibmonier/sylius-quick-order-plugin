<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Controller\Shop;

use Symfony\Component\HttpFoundation\Request;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult;
use Tmonier\SyliusQuickOrderPlugin\Input\QuickOrderInput;

final class QuickOrderSessionStorage
{
    private const RESULT_KEY = '_tmonier_quick_order.result';
    private const PREVIEW_KEY = '_tmonier_quick_order.previews';
    private const MAX_PREVIEWS = 5;

    public function __construct(
        private readonly int $maxFileSize,
    ) {
    }

    public function storePreview(Request $request, QuickOrderInput $input): string
    {
        $session = $request->getSession();
        $previews = $session->get(self::PREVIEW_KEY);
        $previews = \is_array($previews) ? array_filter($previews, is_string(...)) : [];
        $nonce = bin2hex(random_bytes(16));
        $previews[$nonce] = $input->toJson();
        while (\count($previews) > self::MAX_PREVIEWS || array_sum(array_map(strlen(...), $previews)) > 4 * $this->maxFileSize) {
            array_shift($previews);
        }
        $session->set(self::PREVIEW_KEY, $previews);

        return $nonce;
    }

    public function takePreview(Request $request, string $nonce): ?string
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

    public function storeResult(Request $request, BulkAddResult $result): void
    {
        $request->getSession()->set(self::RESULT_KEY, $result->toArray());
    }

    public function loadResult(Request $request): ?BulkAddResult
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
}
