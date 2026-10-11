<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Input;

use Tmonier\SyliusQuickOrderPlugin\Adder\LineStatus;

/** A source line that could not be read (no code, bad quantity). */
final class InvalidLine
{
    public function __construct(
        public readonly int $lineNumber,
        public readonly string $code,
        public readonly string $quantity,
        public readonly LineStatus $status,
    ) {
    }
}
