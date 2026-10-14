<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Input;

use Tmonier\SyliusQuickOrderPlugin\Adder\LineStatus;

/** A source line that could not be read (no code, bad quantity). */
final readonly class InvalidLine
{
    public function __construct(
        public int $lineNumber,
        public string $code,
        public string $quantity,
        public LineStatus $status,
    ) {
    }
}
