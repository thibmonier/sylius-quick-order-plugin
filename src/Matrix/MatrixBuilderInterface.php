<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Matrix;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;

interface MatrixBuilderInterface
{
    public function build(ProductInterface $product, ChannelInterface $channel): Matrix;
}
