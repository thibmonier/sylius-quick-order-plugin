<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Event;

use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Contracts\EventDispatcher\Event;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult;

final class PostBulkAddEvent extends Event
{
    public function __construct(
        public readonly OrderInterface $cart,
        public readonly BulkAddResult $result,
    ) {
    }
}
