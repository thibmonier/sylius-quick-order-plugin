<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Input;

/** A variant code and a quantity; lines with the same code are merged into one. */
final class QuickOrderLine
{
    /** @param non-empty-list<int> $lineNumbers source line numbers (1-based) */
    public function __construct(
        public readonly string $code,
        public readonly int $quantity,
        public readonly array $lineNumbers,
    ) {
    }

    /** Quantities are at most LineCollector::MAX_QUANTITY each and lines at most max_lines: no overflow. */
    public function withAddedQuantity(int $quantity, int ...$lineNumbers): self
    {
        return new self($this->code, $this->quantity + $quantity, array_values([...$this->lineNumbers, ...$lineNumbers]));
    }
}
