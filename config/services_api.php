<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Tmonier\SyliusQuickOrderPlugin\Api\BulkAddItemsProcessor;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services
        ->set(BulkAddItemsProcessor::class)
        ->args([
            service('sylius.repository.order'),
            service('tmonier_quick_order.bulk_cart_adder'),
            service('sylius_api.context.user.token_based'),
            param('sylius.security.api_shop_route'),
            param('tmonier_quick_order.default_mode'),
            param('tmonier_quick_order.max_lines'),
            param('tmonier_quick_order.max_total_quantity'),
            service('limiter.tmonier_quick_order_ip')->nullOnInvalid(),
            service('request_stack'),
        ])
        ->tag('api_platform.state_processor')
    ;
};
