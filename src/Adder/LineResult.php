<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Adder;

/** Outcome of one line (preview or addition). */
final class LineResult
{
    /**
     * @param list<int> $lineNumbers source line numbers (1-based)
     * @param int|null $quantity quantity to add, after rounding to a pack size; null when unreadable
     * @param int|null $available units that could still be added (stock or quantity limit), if relevant
     */
    public function __construct(
        public readonly string $code,
        public readonly string $requestedQuantity,
        public readonly ?int $quantity,
        public readonly LineStatus $status,
        public readonly array $lineNumbers,
        public readonly ?string $productName = null,
        public readonly ?string $variantName = null,
        public readonly int $addedQuantity = 0,
        public readonly ?int $available = null,
    ) {
    }

    public function withStatus(LineStatus $status, int $addedQuantity = 0): self
    {
        return new self($this->code, $this->requestedQuantity, $this->quantity, $status, $this->lineNumbers, $this->productName, $this->variantName, $addedQuantity, $this->available);
    }

    public function isRounded(): bool
    {
        return null !== $this->quantity && (string) $this->quantity !== $this->requestedQuantity;
    }

    /** @return array{c: string, r: string, q: int|null, s: string, l: list<int>, p: string|null, v: string|null, a: int, av: int|null} */
    public function toArray(): array
    {
        return ['c' => $this->code, 'r' => $this->requestedQuantity, 'q' => $this->quantity, 's' => $this->status->value, 'l' => $this->lineNumbers, 'p' => $this->productName, 'v' => $this->variantName, 'a' => $this->addedQuantity, 'av' => $this->available];
    }

    /** @param array{c: string, r: string, q: int|null, s: string, l: list<int>, p: string|null, v: string|null, a: int, av: int|null} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['c'], $data['r'], $data['q'], LineStatus::from($data['s']), $data['l'], $data['p'], $data['v'], $data['a'], $data['av']);
    }

    public function getFirstLineNumber(): int
    {
        return $this->lineNumbers[0] ?? 0;
    }
}
