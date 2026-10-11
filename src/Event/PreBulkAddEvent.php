<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Event;

use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Contracts\EventDispatcher\Event;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddMode;
use Tmonier\SyliusQuickOrderPlugin\Adder\LineResult;

final class PreBulkAddEvent extends Event
{
    private ?string $refusal = null;

    /** @param list<LineResult> $lines validated lines, before any change to the cart */
    public function __construct(
        public readonly OrderInterface $cart,
        public readonly array $lines,
        public readonly BulkAddMode $mode,
    ) {
    }

    /** Refuses the whole addition; $reason is a translation key (domain "messages") or a message. */
    public function refuse(string $reason): void
    {
        $this->refusal = $reason;
        $this->stopPropagation();
    }

    public function getRefusal(): ?string
    {
        return $this->refusal;
    }
}
