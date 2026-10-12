<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Matrix;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddMode;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult;
use Tmonier\SyliusQuickOrderPlugin\Input\QuickOrderInput;

interface MatrixOrderHandlerInterface
{
    /** @param array<mixed> $quantities quantities indexed by "v" + variant id, as posted */
    public function add(ProductInterface $product, array $quantities, OrderInterface $cart, BulkAddMode $mode): BulkAddResult;

    /** @param array<mixed> $quantities */
    public function input(ProductInterface $product, array $quantities): QuickOrderInput;
}
