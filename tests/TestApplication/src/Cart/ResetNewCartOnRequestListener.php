<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Cart;

use Sylius\Component\Order\Context\ResettableCartContextInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Functional tests send several requests to one kernel (no reboot, one transaction): the
 * entity manager is cleared between requests, but the shop cart context keeps the unsaved
 * cart of the previous request (with a detached channel). Real requests start with a new kernel.
 */
final class ResetNewCartOnRequestListener
{
    public function __construct(private readonly ResettableCartContextInterface $cartContext)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->cartContext->reset();
        }
    }
}
