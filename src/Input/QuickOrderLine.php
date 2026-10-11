<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Input;

/** A variant code and a quantity; duplicates of the same code are merged into one line. */
final class QuickOrderLine
{
    /** @param non-empty-list<int> $lineNumbers source line numbers (1-based) */
    public function __construct(
        public readonly string $code,
        public readonly int $quantity,
        public readonly array $lineNumbers,
    ) {
    }

    public function withAddedQuantity(int $quantity, int $lineNumber): self
    {
        return new self($this->code, $this->quantity + $quantity, [...$this->lineNumbers, $lineNumber]);
    }
}
