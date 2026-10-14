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
final readonly class MatrixCell
{
    public function __construct(
        public int $variantId,
        public string $code,
        public string $label,
        public CellStatus $status,
        public ?int $price = null,
        public ?int $struckPrice = null,
        public int $minimum = 1,
        public int $step = 1,
        public ?int $maximum = null,
        public ?int $lowestPriceBeforeDiscount = null,
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
