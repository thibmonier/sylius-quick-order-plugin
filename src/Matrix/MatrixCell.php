<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Matrix;

/** One variant of the matrix. Prices are catalog prices of the channel base currency. */
final class MatrixCell
{
    public function __construct(
        public readonly int $variantId,
        public readonly string $code,
        public readonly string $label,
        public readonly CellStatus $status,
        public readonly ?int $price = null,
        public readonly ?int $struckPrice = null,
        public readonly int $minimum = 1,
        public readonly int $step = 1,
    ) {
    }

    /** Key of the cell in the posted quantities (see MatrixOrderHandler::key()). */
    public function getKey(): string
    {
        return 'v' . $this->variantId;
    }

    public function isOrderable(): bool
    {
        return CellStatus::Orderable === $this->status;
    }
}
