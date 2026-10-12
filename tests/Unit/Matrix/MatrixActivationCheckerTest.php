<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Unit\Matrix;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Product\Model\ProductAttribute;
use Sylius\Component\Product\Model\ProductAttributeValue;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixActivationChecker;

final class MatrixActivationCheckerTest extends TestCase
{
    public function testTheAutomaticRuleTakesOneOrTwoOptionsAndFewVariants(): void
    {
        $checker = new MatrixActivationChecker(true, true, 3);

        self::assertTrue($checker->isMatrixProduct($this->product(['size' => ['S', 'M']], 2)));
        self::assertTrue($checker->isMatrixProduct($this->product(['size' => ['S'], 'colour' => ['RED', 'BLUE', 'BLACK']], 3)));
        self::assertFalse($checker->isMatrixProduct($this->product(['size' => ['S', 'M', 'L', 'XL']], 4)), 'Too many variants');
        self::assertFalse($checker->isMatrixProduct($this->product(['a' => ['1'], 'b' => ['1'], 'c' => ['1']], 1)), 'Three options');
        self::assertFalse($checker->isMatrixProduct($this->product([], 1)), 'Simple product');
    }

    public function testDisabledVariantsDoNotCount(): void
    {
        $product = $this->product(['size' => ['S', 'M', 'L', 'XL']], 4);
        $product->getVariants()->last()?->setEnabled(false);

        self::assertTrue((new MatrixActivationChecker(true, true, 3))->isMatrixProduct($product));
    }

    public function testListsWin(): void
    {
        $threeOptions = $this->product(['a' => ['1'], 'b' => ['1'], 'c' => ['1']], 1, 'FORCED');
        $small = $this->product(['size' => ['S']], 1, 'EXCLUDED');

        $checker = new MatrixActivationChecker(true, true, 10, ['FORCED'], ['EXCLUDED']);
        self::assertTrue($checker->isMatrixProduct($threeOptions));
        self::assertFalse($checker->isMatrixProduct($small));
        self::assertFalse((new MatrixActivationChecker(false, true, 10, ['FORCED']))->isMatrixProduct($threeOptions), 'Feature off');
        self::assertFalse((new MatrixActivationChecker(true, false, 10))->isMatrixProduct($this->product(['size' => ['S']], 1)), 'Automatic rule off');
    }

    public function testACheckboxAttributeDecidesBeforeTheRule(): void
    {
        $checker = new MatrixActivationChecker(true, true, 1, [], [], 'order_matrix');

        $on = $this->product(['size' => ['S', 'M']], 2);
        $this->attribute($on, 'order_matrix', true);
        $off = $this->product(['size' => ['S']], 1);
        $this->attribute($off, 'order_matrix', false);

        self::assertTrue($checker->isMatrixProduct($on), 'Checked: matrix despite 2 variants > 1');
        self::assertFalse($checker->isMatrixProduct($off), 'Unchecked: core form');
        self::assertTrue($checker->isMatrixProduct($this->product(['size' => ['S']], 1)), 'Absent: the rule');
    }

    /** @param array<string, list<string>> $options */
    private function product(array $options, int $variants, string $code = 'P'): ProductInterface
    {
        $product = MatrixFixtures::product($code, $options, MatrixFixtures::channel());
        $values = array_values($options);
        for ($i = 0; $i < $variants; ++$i) {
            MatrixFixtures::variant($product, array_map(static fn (array $codes): string => $codes[$i % \count($codes)], $values));
        }

        return $product;
    }

    private function attribute(ProductInterface $product, string $code, bool $value): void
    {
        $attribute = new ProductAttribute();
        $attribute->setCode($code);
        $attribute->setType('checkbox');
        $attribute->setStorageType('boolean');
        $attributeValue = new ProductAttributeValue();
        $attributeValue->setAttribute($attribute);
        $attributeValue->setValue($value);
        $product->addAttribute($attributeValue);
    }
}
