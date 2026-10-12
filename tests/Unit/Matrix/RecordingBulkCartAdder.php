<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Unit\Matrix;

use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddMode;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkCartAdderInterface;
use Tmonier\SyliusQuickOrderPlugin\Input\QuickOrderInput;

final class RecordingBulkCartAdder implements BulkCartAdderInterface
{
    public ?QuickOrderInput $input = null;

    public ?BulkAddMode $mode = null;

    public function validate(OrderInterface $cart, QuickOrderInput $input): BulkAddResult
    {
        return new BulkAddResult([]);
    }

    public function add(OrderInterface $cart, QuickOrderInput $input, BulkAddMode $mode): BulkAddResult
    {
        $this->input = $input;
        $this->mode = $mode;

        return new BulkAddResult([], true, null, $mode);
    }
}
