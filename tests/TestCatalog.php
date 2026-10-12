<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\PromotionInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Product\Model\ProductOptionInterface;
use Sylius\Component\Product\Model\ProductOptionValueInterface;
use Sylius\Component\Promotion\Model\PromotionActionInterface;
use Sylius\Component\Promotion\Model\PromotionRuleInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Webmozart\Assert\Assert;

/**
 * Data created by the tests themselves (inside their rolled-back transaction), so that no test
 * depends on the random Sylius fixtures: products, the T-shirt matrix, promotions, customers,
 * placed orders. Codes get a random suffix.
 */
final class TestCatalog
{
    public readonly string $suffix;

    public function __construct(private readonly ContainerInterface $container)
    {
        $this->suffix = strtoupper(bin2hex(random_bytes(3)));
    }

    public function channel(): ChannelInterface
    {
        $repository = $this->entityManager()->getRepository(ChannelInterface::class);
        $channel = $repository->findOneBy(['hostname' => 'localhost']) ?? $repository->findOneBy(['enabled' => true]);
        Assert::isInstanceOf($channel, ChannelInterface::class);

        return $channel;
    }

    /**
     * An enabled variant of a new product of the channel; $price null = no channel pricing,
     * $onHand null = stock not tracked.
     */
    public function variant(string $code, ?int $price = 1000, ?int $onHand = null, bool $enabled = true, ?ChannelInterface $channel = null): ProductVariantInterface
    {
        $channel ??= $this->channel();
        $product = $this->product('P_' . $code, 'Product ' . $code, $channel);
        $variant = $this->newVariant($product, $code, 'Variant ' . $code, $price, $onHand, $enabled, $channel);
        $this->entityManager()->persist($product);
        $this->entityManager()->flush();

        return $variant;
    }

    /**
     * The T-shirt of the spec: sizes S/M/L/XL x colours RED/BLUE/BLACK (12 variants,
     * QO-<suffix>-<SIZE>-<COLOUR>), XL-BLACK out of stock, XL-BLUE disabled, XL-RED without price.
     *
     * @return array<string, ProductVariantInterface> variants by code
     */
    public function teeMatrix(): array
    {
        $channel = $this->channel();
        $product = $this->product('QO_TEE_' . $this->suffix, 'Matrix T-shirt', $channel);
        $size = $this->option('SIZE_' . $this->suffix, ['S', 'M', 'L', 'XL']);
        $colour = $this->option('COLOUR_' . $this->suffix, ['RED', 'BLUE', 'BLACK']);
        $product->addOption($size);
        $product->addOption($colour);

        $variants = [];
        foreach ($size->getValues() as $sizeIndex => $sizeValue) {
            foreach ($colour->getValues() as $colourValue) {
                $sizeCode = (string) $sizeValue->getValue();
                $colourCode = (string) $colourValue->getValue();
                $code = sprintf('QO-%s-%s-%s', $this->suffix, $sizeCode, $colourCode);
                $variant = $this->newVariant(
                    $product,
                    $code,
                    $sizeCode . ' / ' . $colourCode,
                    'XL-RED' === $sizeCode . '-' . $colourCode ? null : 1500 + 100 * (int) $sizeIndex,
                    'XL-BLACK' === $sizeCode . '-' . $colourCode ? 0 : 100,
                    'XL-BLUE' !== $sizeCode . '-' . $colourCode,
                    $channel,
                );
                $variant->addOptionValue($sizeValue);
                $variant->addOptionValue($colourValue);
                $variants[$code] = $variant;
            }
        }
        $this->entityManager()->persist($product);
        $this->entityManager()->flush();

        return $variants;
    }

    /**
     * @param array<string, mixed> $ruleConfiguration
     * @param array<string, mixed> $actionConfiguration
     */
    public function promotion(string $ruleType, array $ruleConfiguration, string $actionType, array $actionConfiguration, int $priority = 1000): PromotionInterface
    {
        /** @var FactoryInterface<PromotionInterface> $promotionFactory */
        $promotionFactory = $this->container->get('sylius.factory.promotion');
        /** @var FactoryInterface<PromotionRuleInterface> $ruleFactory */
        $ruleFactory = $this->container->get('sylius.factory.promotion_rule');
        /** @var FactoryInterface<PromotionActionInterface> $actionFactory */
        $actionFactory = $this->container->get('sylius.factory.promotion_action');

        $promotion = $promotionFactory->createNew();
        $promotion->setCode('QO_PROMO_' . $ruleType . '_' . $this->suffix);
        $promotion->setName('Quick order test ' . $ruleType);
        $promotion->setPriority($priority);
        $promotion->addChannel($this->channel());
        $rule = $ruleFactory->createNew();
        $rule->setType($ruleType);
        $rule->setConfiguration($ruleConfiguration);
        $promotion->addRule($rule);
        $action = $actionFactory->createNew();
        $action->setType($actionType);
        $action->setConfiguration($actionConfiguration);
        $promotion->addAction($action);
        $this->entityManager()->persist($promotion);
        $this->entityManager()->flush();

        return $promotion;
    }

    /** Archives the other promotions of the channel (fixture promotions), in the test transaction. */
    public function archiveOtherPromotions(): void
    {
        $channel = $this->channel();
        /** @var list<PromotionInterface> $promotions */
        $promotions = $this->entityManager()->getRepository(PromotionInterface::class)->findAll();
        foreach ($promotions as $promotion) {
            if ($promotion->hasChannel($channel) && !str_ends_with((string) $promotion->getCode(), '_' . $this->suffix)) {
                $promotion->setArchivedAt(new \DateTime());
            }
        }
        $this->entityManager()->flush();
    }

    /** A customer with an enabled shop account. */
    public function customer(string $name = 'buyer'): CustomerInterface
    {
        /** @var FactoryInterface<CustomerInterface> $customerFactory */
        $customerFactory = $this->container->get('sylius.factory.customer');
        /** @var FactoryInterface<ShopUserInterface> $userFactory */
        $userFactory = $this->container->get('sylius.factory.shop_user');

        $customer = $customerFactory->createNew();
        $customer->setEmail(strtolower(sprintf('%s-%s@quick-order.test', $name, $this->suffix)));
        $customer->setFirstName('Quick');
        $customer->setLastName('Order');
        $user = $userFactory->createNew();
        $user->setPlainPassword('sylius');
        $user->setEnabled(true);
        $customer->setUser($user);
        $this->entityManager()->persist($customer);
        $this->entityManager()->flush();

        return $customer;
    }

    /** An empty cart of the channel (for a customer, or a guest cart). */
    public function cart(?CustomerInterface $customer = null): OrderInterface
    {
        /** @var FactoryInterface<OrderInterface> $orderFactory */
        $orderFactory = $this->container->get('sylius.factory.order');
        $channel = $this->channel();
        $cart = $orderFactory->createNew();
        $cart->setChannel($channel);
        $cart->setCurrencyCode((string) $channel->getBaseCurrency()?->getCode());
        $cart->setLocaleCode((string) $channel->getDefaultLocale()?->getCode());
        $cart->setTokenValue('qo_' . bin2hex(random_bytes(10)));
        if (null !== $customer) {
            $cart->setCustomerWithAuthorization($customer);
        }
        $this->entityManager()->persist($cart);
        $this->entityManager()->flush();

        return $cart;
    }

    /**
     * A placed order of the customer (checkout completed) with the given quantities.
     *
     * @param array<int, array{ProductVariantInterface, int}> $lines
     */
    public function placedOrder(CustomerInterface $customer, array $lines): OrderInterface
    {
        $order = $this->cart($customer);
        /** @var FactoryInterface<OrderItemInterface> $itemFactory */
        $itemFactory = $this->container->get('sylius.factory.order_item');
        /** @var OrderItemQuantityModifierInterface $quantityModifier */
        $quantityModifier = $this->container->get('sylius.modifier.order_item_quantity');
        foreach ($lines as [$variant, $quantity]) {
            $item = $itemFactory->createNew();
            $item->setVariant($variant);
            $item->setUnitPrice(1000);
            $item->setProductName((string) $variant->getProduct()?->getName());
            $item->setVariantName($variant->getName());
            $quantityModifier->modify($item, $quantity);
            $order->addItem($item);
        }
        /** @var FactoryInterface<AddressInterface> $addressFactory */
        $addressFactory = $this->container->get('sylius.factory.address');
        foreach (['setShippingAddress', 'setBillingAddress'] as $setter) {
            $address = $addressFactory->createNew();
            $address->setFirstName('Quick');
            $address->setLastName('Order');
            $address->setStreet('1 Bulk Street');
            $address->setCity('Lyon');
            $address->setPostcode('69001');
            $address->setCountryCode('FR');
            $order->{$setter}($address);
        }
        $order->setNumber('QO' . $this->suffix . random_int(100, 999));
        $order->setState(OrderInterface::STATE_NEW);
        $order->setCheckoutState('completed');
        $order->setPaymentState('awaiting_payment');
        $order->setCheckoutCompletedAt(new \DateTime());
        $this->entityManager()->flush();

        return $order;
    }

    private function product(string $code, string $name, ChannelInterface $channel): ProductInterface
    {
        /** @var FactoryInterface<ProductInterface> $productFactory */
        $productFactory = $this->container->get('sylius.factory.product');
        $locale = (string) $channel->getDefaultLocale()?->getCode();

        $product = $productFactory->createNew();
        $product->setCode($code . '_' . $this->suffix);
        $product->setCurrentLocale($locale);
        $product->setFallbackLocale($locale);
        $product->setName($name);
        $product->setSlug(strtolower(str_replace('_', '-', $code . '-' . $this->suffix)));
        $product->setEnabled(true);
        $product->addChannel($channel);

        return $product;
    }

    private function newVariant(ProductInterface $product, string $code, string $name, ?int $price, ?int $onHand, bool $enabled, ChannelInterface $channel): ProductVariantInterface
    {
        /** @var FactoryInterface<ProductVariantInterface> $variantFactory */
        $variantFactory = $this->container->get('sylius.factory.product_variant');
        /** @var FactoryInterface<ChannelPricingInterface> $pricingFactory */
        $pricingFactory = $this->container->get('sylius.factory.channel_pricing');
        $locale = (string) $channel->getDefaultLocale()?->getCode();

        $variant = $variantFactory->createNew();
        $variant->setCode($code);
        $variant->setCurrentLocale($locale);
        $variant->setFallbackLocale($locale);
        $variant->setName($name);
        $variant->setEnabled($enabled);
        $variant->setTracked(null !== $onHand);
        $variant->setOnHand($onHand ?? 0);
        if (null !== $price) {
            $pricing = $pricingFactory->createNew();
            $pricing->setChannelCode($channel->getCode());
            $pricing->setPrice($price);
            $variant->addChannelPricing($pricing);
        }
        $product->addVariant($variant);

        return $variant;
    }

    /** @param list<string> $values */
    private function option(string $code, array $values): ProductOptionInterface
    {
        /** @var FactoryInterface<ProductOptionInterface> $optionFactory */
        $optionFactory = $this->container->get('sylius.factory.product_option');
        /** @var FactoryInterface<ProductOptionValueInterface> $valueFactory */
        $valueFactory = $this->container->get('sylius.factory.product_option_value');
        $locale = (string) $this->channel()->getDefaultLocale()?->getCode();

        $option = $optionFactory->createNew();
        $option->setCode($code);
        $option->setCurrentLocale($locale);
        $option->setFallbackLocale($locale);
        $option->setName($code);
        foreach ($values as $value) {
            $optionValue = $valueFactory->createNew();
            $optionValue->setCode($code . '_' . $value);
            $optionValue->setCurrentLocale($locale);
            $optionValue->setFallbackLocale($locale);
            $optionValue->setValue($value);
            $option->addValue($optionValue);
        }
        $this->entityManager()->persist($option);

        return $option;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = $this->container->get('doctrine.orm.entity_manager');
        Assert::isInstanceOf($entityManager, EntityManagerInterface::class);

        return $entityManager;
    }
}
