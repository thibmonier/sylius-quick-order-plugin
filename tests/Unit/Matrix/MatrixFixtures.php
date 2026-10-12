<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Unit\Matrix;

use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricing;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Product\Model\ProductOption;
use Sylius\Component\Product\Model\ProductOptionInterface;
use Sylius\Component\Product\Model\ProductOptionValue;

/** In-memory products with options and variants (ids set by reflection). */
final class MatrixFixtures
{
    private static int $id = 100;

    public static function channel(): ChannelInterface
    {
        $channel = new Channel();
        $channel->setCode('WEB');

        return $channel;
    }

    /** @param array<string, list<string>> $options option code => value codes */
    public static function product(string $code, array $options, ChannelInterface $channel): ProductInterface
    {
        $product = new Product();
        $product->setCurrentLocale('en_US');
        $product->setFallbackLocale('en_US');
        $product->setCode($code);
        $product->setName($code);
        $product->addChannel($channel);
        foreach ($options as $optionCode => $values) {
            $option = new ProductOption();
            $option->setCurrentLocale('en_US');
            $option->setFallbackLocale('en_US');
            $option->setCode($optionCode);
            $option->setName(ucfirst($optionCode));
            foreach ($values as $valueCode) {
                $value = new ProductOptionValue();
                $value->setCurrentLocale('en_US');
                $value->setFallbackLocale('en_US');
                $value->setCode($optionCode . '_' . $valueCode);
                $value->setValue($valueCode);
                $option->addValue($value);
            }
            $product->addOption($option);
        }

        return $product;
    }

    /** @param list<string> $valueCodes one value code per option, in option order */
    public static function variant(ProductInterface $product, array $valueCodes, ?int $price = 1000, bool $enabled = true, ?int $onHand = null): ProductVariantInterface
    {
        $variant = new ProductVariant();
        (new \ReflectionProperty($variant, 'id'))->setValue($variant, ++self::$id);
        $variant->setCurrentLocale('en_US');
        $variant->setFallbackLocale('en_US');
        $variant->setCode($product->getCode() . '-' . implode('-', $valueCodes));
        $variant->setName(implode(' / ', $valueCodes));
        $variant->setEnabled($enabled);
        if (null !== $onHand) {
            $variant->setTracked(true);
            $variant->setOnHand($onHand);
        }
        $index = 0;
        foreach ($product->getOptions() as $option) {
            \assert($option instanceof ProductOptionInterface);
            $code = $valueCodes[$index++] ?? null;
            foreach ($option->getValues() as $value) {
                if ($option->getCode() . '_' . $code === $value->getCode()) {
                    $variant->addOptionValue($value);
                }
            }
        }
        if (null !== $price) {
            $pricing = new ChannelPricing();
            $pricing->setChannelCode('WEB');
            $pricing->setPrice($price);
            $variant->addChannelPricing($pricing);
        }
        $product->addVariant($variant);

        return $variant;
    }
}
