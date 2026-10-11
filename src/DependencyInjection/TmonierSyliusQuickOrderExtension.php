<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class TmonierSyliusQuickOrderExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{max_lines: int, max_file_size: int, default_mode: string, allow_guests: bool, quantity_rounding: string} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        foreach ($config as $key => $value) {
            $container->setParameter('tmonier_quick_order.' . $key, $value);
        }

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');
        if (self::hasApiPlatform($container)) {
            $loader->load('services_api.php');
        }
    }

    public function prepend(ContainerBuilder $container): void
    {
        if (self::hasApiPlatform($container)) {
            $container->prependExtensionConfig('api_platform', [
                'mapping' => ['paths' => [\dirname(__DIR__) . '/Api/Resource']],
            ]);
        }
    }

    /** Extensions are not registered in the container given to load(): check the bundles. */
    private static function hasApiPlatform(ContainerBuilder $container): bool
    {
        $bundles = $container->hasParameter('kernel.bundles') ? $container->getParameter('kernel.bundles') : [];

        return \is_array($bundles) && isset($bundles['ApiPlatformBundle']) && interface_exists(\ApiPlatform\State\ProcessorInterface::class);
    }

    public function getAlias(): string
    {
        return 'tmonier_sylius_quick_order';
    }
}
