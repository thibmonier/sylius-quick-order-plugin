<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class TmonierSyliusQuickOrderExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{max_lines: int, max_total_quantity: int, expose_available_stock: bool, max_file_size: int, default_mode: string, allow_guests: bool, quantity_rounding: string, rate_limit: array{enabled: bool, per_ip: int, interval: string, cache_pool: string}, matrix: array{enabled: bool, auto: bool, max_variants: int, max_grid_cells: int, products: list<string>, excluded_products: list<string>, attribute: string|null}} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        foreach ($config as $key => $value) {
            $container->setParameter('tmonier_quick_order.' . $key, $value);
        }
        foreach ($config['matrix'] as $key => $value) {
            $container->setParameter('tmonier_quick_order.matrix.' . $key, $value);
        }

        if ($config['rate_limit']['enabled'] && class_exists(RateLimiterFactory::class)) {
            $limiter = [
                'policy' => 'sliding_window',
                'limit' => $config['rate_limit']['per_ip'],
                'interval' => $config['rate_limit']['interval'],
                'cache_pool' => $config['rate_limit']['cache_pool'],
                // Without a lock, parallel requests read the same count and all get through.
                'lock_factory' => 'lock.factory',
            ];
            // The default lock store (flock) unless the application configures the lock component.
            $container->prependExtensionConfig('framework', ['lock' => ['enabled' => true, 'resources' => ['default' => ['flock']]]]);
            $container->prependExtensionConfig('framework', ['rate_limiter' => [
                'tmonier_quick_order_ip' => $limiter,
            ]]);
        }

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');
        if ($this->hasApiPlatform($container)) {
            $loader->load('services_api.php');
        }
    }

    public function prepend(ContainerBuilder $container): void
    {
        // The tier price table of tmonier/sylius-tier-pricing-plugin is a child of the core add
        // to cart form, which matrix products do not show: render it there when every variant has
        // the same tiers, else a note (see the template).
        $bundles = $container->hasParameter('kernel.bundles') ? $container->getParameter('kernel.bundles') : [];
        if (\is_array($bundles) && isset($bundles['TmonierSyliusTierPricingPlugin'])) {
            $container->prependExtensionConfig('sylius_twig_hooks', ['hooks' => [
                'tmonier_quick_order.shop.product.add_to_cart' => [
                    'tmonier_tier_pricing' => [
                        'template' => '@TmonierSyliusQuickOrderPlugin/shop/product/matrix/tier_prices.html.twig',
                        'priority' => 50,
                    ],
                ],
            ]]);
        }

        if ($this->hasApiPlatform($container)) {
            $container->prependExtensionConfig('api_platform', [
                'mapping' => ['paths' => [__DIR__ . '/../Api/Resource']],
            ]);
        }
    }

    /** Extensions are not registered in the container given to load(): check the bundles. */
    private function hasApiPlatform(ContainerBuilder $container): bool
    {
        $bundles = $container->hasParameter('kernel.bundles') ? $container->getParameter('kernel.bundles') : [];

        return \is_array($bundles) && isset($bundles['ApiPlatformBundle']) && interface_exists(\ApiPlatform\State\ProcessorInterface::class);
    }

    public function getAlias(): string
    {
        return 'tmonier_sylius_quick_order';
    }
}
