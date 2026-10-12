<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Matrix;

use Sylius\Component\Core\Model\ProductInterface;

/** Whether a product page shows the order matrix instead of the core add to cart form. */
interface MatrixActivationCheckerInterface
{
    public function isMatrixProduct(ProductInterface $product): bool;
}
