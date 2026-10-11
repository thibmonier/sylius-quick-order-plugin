<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Reorder;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Tmonier\SyliusQuickOrderPlugin\Adder\LineStatus;
use Tmonier\SyliusQuickOrderPlugin\Input\LineCollector;
use Tmonier\SyliusQuickOrderPlugin\Input\QuickOrderInput;

/**
 * The items of a past order as quick order lines (current catalog prices, not the old ones).
 * Items whose variant is gone are reported as unknown codes.
 */
final class ReorderInputFactory
{
    public function __construct(private readonly int $maxLines)
    {
    }

    public function create(OrderInterface $order): QuickOrderInput
    {
        $collector = new LineCollector($this->maxLines);
        $lineNumber = 0;
        foreach ($order->getItems() as $item) {
            /** @var OrderItemInterface $item */
            ++$lineNumber;
            $code = $item->getVariant()?->getCode();
            if (null === $code) {
                $collector->addInvalid($lineNumber, (string) ($item->getVariantName() ?? $item->getProductName()), (string) $item->getQuantity(), LineStatus::UnknownCode);

                continue;
            }
            $collector->add($lineNumber, $code, $item->getQuantity());
        }

        return $collector->getInput();
    }
}
