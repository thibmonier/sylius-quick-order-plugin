<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Fixture;

use Doctrine\Persistence\ObjectManager;
use Sylius\Bundle\FixturesBundle\Fixture\AbstractFixture;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Component\Product\Model\ProductOptionInterface;
use Sylius\Component\Product\Model\ProductOptionValueInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

/**
 * Demo catalog for quick order: a T-shirt with 4 sizes x 3 colours (codes QO-TEE-<SIZE>-<COLOUR>),
 * one variant out of stock (QO-TEE-XL-BLACK), one disabled (QO-TEE-XL-BLUE) and one without
 * price (QO-TEE-XL-RED). Idempotent.
 */
final class QuickOrderDemoCatalogFixture extends AbstractFixture
{
    public const PRODUCT_CODE = 'QO_TEE';

    public const SIZES = ['S', 'M', 'L', 'XL'];

    public const COLOURS = ['RED' => 'Red', 'BLUE' => 'Blue', 'BLACK' => 'Black'];

    public const OUT_OF_STOCK = 'QO-TEE-XL-BLACK';

    public const DISABLED = 'QO-TEE-XL-BLUE';

    public const WITHOUT_PRICE = 'QO-TEE-XL-RED';

    /**
     * @param RepositoryInterface<ChannelInterface> $channelRepository
     * @param FactoryInterface<ProductInterface> $productFactory
     * @param FactoryInterface<ProductVariantInterface> $variantFactory
     * @param FactoryInterface<ProductOptionInterface> $optionFactory
     * @param FactoryInterface<ProductOptionValueInterface> $optionValueFactory
     * @param FactoryInterface<ChannelPricingInterface> $channelPricingFactory
     * @param RepositoryInterface<ProductInterface> $productRepository
     */
    public function __construct(
        private readonly ObjectManager $manager,
        private readonly RepositoryInterface $channelRepository,
        private readonly FactoryInterface $productFactory,
        private readonly FactoryInterface $variantFactory,
        private readonly FactoryInterface $optionFactory,
        private readonly FactoryInterface $optionValueFactory,
        private readonly FactoryInterface $channelPricingFactory,
        private readonly RepositoryInterface $productRepository,
    ) {
    }

    public function getName(): string
    {
        return 'tmonier_quick_order_demo_catalog';
    }

    public function load(array $options): void
    {
        if (null !== $this->productRepository->findOneBy(['code' => self::PRODUCT_CODE])) {
            return;
        }

        /** @var list<ChannelInterface> $channels */
        $channels = $this->channelRepository->findAll();
        $locales = [];
        foreach ($channels as $channel) {
            foreach ($channel->getLocales() as $locale) {
                /** @var LocaleInterface $locale */
                $locales[(string) $locale->getCode()] = true;
            }
        }
        $locales = array_keys($locales) ?: ['en_US'];

        $size = $this->createOption('QO_TEE_SIZE', 'Size', array_combine(self::SIZES, self::SIZES), $locales);
        $colour = $this->createOption('QO_TEE_COLOUR', 'Colour', self::COLOURS, $locales);

        $product = $this->productFactory->createNew();
        $product->setCode(self::PRODUCT_CODE);
        $product->setEnabled(true);
        foreach ($locales as $locale) {
            $product->setCurrentLocale($locale);
            $product->setFallbackLocale($locale);
            $product->setName('Quick order T-shirt');
            $product->setSlug('quick-order-t-shirt');
            $product->setShortDescription('Demo product of the quick order plugin: 12 variants, order them by code.');
        }
        $product->addOption($size);
        $product->addOption($colour);
        foreach ($channels as $channel) {
            $product->addChannel($channel);
        }

        $position = 0;
        foreach ($size->getValues() as $sizeIndex => $sizeValue) {
            foreach ($colour->getValues() as $colourValue) {
                $code = sprintf('QO-TEE-%s-%s', substr((string) $sizeValue->getCode(), \strlen('QO_TEE_SIZE_')), substr((string) $colourValue->getCode(), \strlen('QO_TEE_COLOUR_')));
                $variant = $this->variantFactory->createNew();
                $variant->setCode($code);
                $variant->setPosition($position++);
                $variant->addOptionValue($sizeValue);
                $variant->addOptionValue($colourValue);
                $variant->setTracked(true);
                $variant->setOnHand(self::OUT_OF_STOCK === $code ? 0 : 500);
                $variant->setEnabled(self::DISABLED !== $code);
                foreach ($locales as $locale) {
                    $variant->setCurrentLocale($locale);
                    $variant->setFallbackLocale($locale);
                    $variant->setName(sprintf('%s / %s', $sizeValue->getValue(), $colourValue->getValue()));
                }
                if (self::WITHOUT_PRICE !== $code) {
                    foreach ($channels as $channel) {
                        $pricing = $this->channelPricingFactory->createNew();
                        $pricing->setChannelCode($channel->getCode());
                        $pricing->setPrice(1490 + 100 * (int) $sizeIndex);
                        $variant->addChannelPricing($pricing);
                    }
                }
                $product->addVariant($variant);
            }
        }

        $this->manager->persist($product);
        $this->manager->flush();
    }

    protected function configureOptionsNode(ArrayNodeDefinition $optionsNode): void
    {
    }

    /**
     * @param array<string, string> $values code => label
     * @param list<string> $locales
     */
    private function createOption(string $code, string $name, array $values, array $locales): ProductOptionInterface
    {
        $option = $this->optionFactory->createNew();
        $option->setCode($code);
        foreach ($locales as $locale) {
            $option->setCurrentLocale($locale);
            $option->setFallbackLocale($locale);
            $option->setName($name);
        }
        foreach ($values as $valueCode => $label) {
            $value = $this->optionValueFactory->createNew();
            $value->setCode($code . '_' . $valueCode);
            foreach ($locales as $locale) {
                $value->setCurrentLocale($locale);
                $value->setFallbackLocale($locale);
                $value->setValue($label);
            }
            $option->addValue($value);
        }
        $this->manager->persist($option);

        return $option;
    }
}
