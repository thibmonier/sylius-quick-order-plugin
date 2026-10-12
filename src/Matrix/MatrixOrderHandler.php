<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Matrix;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddMode;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkCartAdderInterface;
use Tmonier\SyliusQuickOrderPlugin\Input\LineCollector;
use Tmonier\SyliusQuickOrderPlugin\Input\QuickOrderInput;

/**
 * Turns the quantities posted by the matrix (live component or plain form) into quick order
 * lines and adds them with the bulk cart adder. Quantities come from the browser: only variants
 * of the product are taken, the array is bounded by the number of variants, every other check
 * (enabled, channel, price, stock, quantity rules) is the bulk adder's.
 */
final class MatrixOrderHandler implements MatrixOrderHandlerInterface
{
    public function __construct(private readonly BulkCartAdderInterface $bulkCartAdder)
    {
    }

    public function add(ProductInterface $product, array $quantities, OrderInterface $cart, BulkAddMode $mode): BulkAddResult
    {
        $input = $this->input($product, $quantities);
        if (null !== $input->rejection) {
            return BulkAddResult::refused($input->rejection, $mode);
        }

        return $this->bulkCartAdder->add($cart, $input, $mode);
    }

    public function input(ProductInterface $product, array $quantities): QuickOrderInput
    {
        $variants = [];
        foreach ($product->getVariants() as $variant) {
            if ($variant instanceof ProductVariantInterface && null !== $variant->getId()) {
                $variants[self::key($variant)] = $variant;
            }
        }
        if (\count($quantities) > \count($variants)) {
            return QuickOrderInput::rejected(BulkAddResult::REFUSED_TOO_MANY_LINES);
        }

        $collector = new LineCollector(max(1, \count($variants)));
        $lineNumber = 0;
        foreach ($variants as $key => $variant) {
            ++$lineNumber;
            $quantity = $quantities[$key] ?? null;
            if (\is_int($quantity)) {
                $quantity = (string) $quantity;
            }
            if (!\is_string($quantity) || '' === trim($quantity) || 1 === preg_match('/^\s*0+\s*$/', $quantity)) {
                continue;
            }
            $collector->add($lineNumber, (string) $variant->getCode(), $quantity, true);
        }

        return $collector->getInput();
    }

    /** Key of a variant in the quantities array: "v" + id (never a list, whatever the ids). */
    public static function key(ProductVariantInterface $variant): string
    {
        $id = $variant->getId();

        return 'v' . (\is_scalar($id) ? (string) $id : '');
    }
}
