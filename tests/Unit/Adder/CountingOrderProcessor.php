<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Unit\Adder;

use Sylius\Component\Order\Model\OrderInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;

/** Counts the order processing runs; an optional callback simulates other processors. */
final class CountingOrderProcessor implements OrderProcessorInterface
{
    public int $calls = 0;

    /** @param (\Closure(OrderInterface): void)|null $callback */
    public function __construct(private readonly ?\Closure $callback = null)
    {
    }

    public function process(OrderInterface $order): void
    {
        ++$this->calls;
        if ($this->callback instanceof \Closure) {
            ($this->callback)($order);
        }
    }
}
