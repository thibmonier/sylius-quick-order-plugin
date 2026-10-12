<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Twig;

use Sylius\Component\Core\Model\ProductInterface;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixActivationCheckerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class QuickOrderExtension extends AbstractExtension
{
    public function __construct(private readonly MatrixActivationCheckerInterface $matrixActivationChecker)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('tmonier_quick_order_is_matrix', fn (mixed $product): bool => $product instanceof ProductInterface && $this->matrixActivationChecker->isMatrixProduct($product)),
        ];
    }
}
