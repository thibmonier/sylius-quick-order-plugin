<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Quantity;

/** Quantity rules of a variant (minimum, pack size, maximum), e.g. from a logistics plugin. */
final class QuantityConstraint
{
    public function __construct(
        public readonly int $minimum = 1,
        public readonly int $multipleOf = 1,
        public readonly ?int $maximum = null,
    ) {
        if ($minimum < 1 || $multipleOf < 1 || (null !== $maximum && $maximum < $minimum)) {
            throw new \InvalidArgumentException('Invalid quantity constraint.');
        }
    }

    public function isSatisfiedBy(int $quantity): bool
    {
        return $quantity >= $this->minimum &&
            0 === $quantity % $this->multipleOf &&
            (null === $this->maximum || $quantity <= $this->maximum);
    }

    /** The smallest valid quantity greater than or equal to $quantity, null when there is none. */
    public function roundUp(int $quantity): ?int
    {
        $rounded = max($quantity, $this->minimum);
        $remainder = $rounded % $this->multipleOf;
        if (0 !== $remainder) {
            if ($rounded > \PHP_INT_MAX - $this->multipleOf) {
                return null;
            }
            $rounded += $this->multipleOf - $remainder;
        }

        return $this->isSatisfiedBy($rounded) ? $rounded : null;
    }
}
