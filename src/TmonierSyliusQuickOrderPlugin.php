<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin;

use Sylius\Bundle\CoreBundle\Application\SyliusPluginTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Tmonier\SyliusQuickOrderPlugin\DependencyInjection\Compiler\QuoteRequestBridgePass;

final class TmonierSyliusQuickOrderPlugin extends Bundle
{
    use SyliusPluginTrait;

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new QuoteRequestBridgePass());
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
