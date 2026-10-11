<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Adder;

use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusQuickOrderPlugin\Input\QuickOrderInput;

interface BulkCartAdderInterface
{
    /** Checks every line against the cart without changing it (preview). */
    public function validate(OrderInterface $cart, QuickOrderInput $input): BulkAddResult;

    /**
     * Adds the lines with a single order processing and a single flush, then reports the
     * status of every line as computed after the processing.
     */
    public function add(OrderInterface $cart, QuickOrderInput $input, BulkAddMode $mode): BulkAddResult;
}
