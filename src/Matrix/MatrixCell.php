<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Matrix;

use Tmonier\SyliusQuickOrderPlugin\Quantity\QuantityConstraint;

/**
 * One variant of the matrix. Prices are catalog prices of the channel base currency:
 * $struckPrice is the original price when greater than the price (core rule), and
 * $lowestPriceBeforeDiscount the Omnibus price shown for discounted variants when the channel
 * shows it.
 */
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
        public readonly ?int $maximum = null,
        public readonly ?int $lowestPriceBeforeDiscount = null,
    ) {
    }

    /** The quantity rule of the cell, null without rule. */
    public function getConstraint(): ?QuantityConstraint
    {
        return 1 === $this->minimum && 1 === $this->step && null === $this->maximum
            ? null
            : new QuantityConstraint($this->minimum, $this->step, $this->maximum);
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
