<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Unit\Quantity;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusQuickOrderPlugin\Quantity\QuantityConstraint;

final class QuantityConstraintTest extends TestCase
{
    public function testItChecksMinimumMultipleAndMaximum(): void
    {
        $constraint = new QuantityConstraint(12, 6, 60);

        self::assertFalse($constraint->isSatisfiedBy(6));
        self::assertTrue($constraint->isSatisfiedBy(12));
        self::assertFalse($constraint->isSatisfiedBy(13));
        self::assertTrue($constraint->isSatisfiedBy(60));
        self::assertFalse($constraint->isSatisfiedBy(66));
    }

    public function testItRoundsUp(): void
    {
        $constraint = new QuantityConstraint(1, 6, 24);

        self::assertSame(6, $constraint->roundUp(1));
        self::assertSame(12, $constraint->roundUp(7));
        self::assertSame(24, $constraint->roundUp(24));
        self::assertNull($constraint->roundUp(25));
        self::assertSame(10, (new QuantityConstraint(10))->roundUp(3));
        // No integer overflow.
        self::assertNull((new QuantityConstraint(1, \PHP_INT_MAX))->roundUp(2));
    }

    public function testInvalidConstraintsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new QuantityConstraint(5, 1, 4);
    }
}
