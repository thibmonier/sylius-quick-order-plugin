<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Tmonier\SyliusQuickOrderPlugin\Adder\BulkCartAdder;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkCartAdderInterface;
use Tmonier\SyliusQuickOrderPlugin\Adder\VariantLookup;
use Tmonier\SyliusQuickOrderPlugin\Adder\VariantLookupInterface;
use Tmonier\SyliusQuickOrderPlugin\Cart\CompositeLockedCartChecker;
use Tmonier\SyliusQuickOrderPlugin\Cart\LockedCartCheckerInterface;
use Tmonier\SyliusQuickOrderPlugin\Controller\Shop\QuickOrderController;
use Tmonier\SyliusQuickOrderPlugin\Fixture\QuickOrderDemoCatalogFixture;
use Tmonier\SyliusQuickOrderPlugin\Input\CsvParser;
use Tmonier\SyliusQuickOrderPlugin\Input\PasteParser;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixActivationChecker;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixActivationCheckerInterface;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixBuilder;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixBuilderInterface;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixOrderHandler;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixOrderHandlerInterface;
use Tmonier\SyliusQuickOrderPlugin\Menu\AccountMenuListener;
use Tmonier\SyliusQuickOrderPlugin\Twig\Component\MatrixOrderComponent;
use Tmonier\SyliusQuickOrderPlugin\Twig\QuickOrderExtension;
use Tmonier\SyliusQuickOrderPlugin\Quantity\NullQuantityConstraintProvider;
use Tmonier\SyliusQuickOrderPlugin\Quantity\QuantityConstraintProviderInterface;
use Tmonier\SyliusQuickOrderPlugin\Quantity\QuantityRounding;
use Tmonier\SyliusQuickOrderPlugin\Reorder\ReorderInputFactory;
use Tmonier\SyliusQuickOrderPlugin\Report\CsvWriter;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services
        ->set('tmonier_quick_order.quantity_constraint_provider', NullQuantityConstraintProvider::class)
    ;
    $services->alias(QuantityConstraintProviderInterface::class, 'tmonier_quick_order.quantity_constraint_provider');

    $services
        ->set('tmonier_quick_order.locked_cart_checker', CompositeLockedCartChecker::class)
        ->args([tagged_iterator('tmonier_quick_order.locked_cart_checker')])
    ;
    $services->alias(LockedCartCheckerInterface::class, 'tmonier_quick_order.locked_cart_checker');

    $services
        ->set('tmonier_quick_order.variant_lookup', VariantLookup::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            param('sylius.model.product_variant.class'),
        ])
    ;
    $services->alias(VariantLookupInterface::class, 'tmonier_quick_order.variant_lookup');

    $services
        ->set('tmonier_quick_order.bulk_cart_adder', BulkCartAdder::class)
        ->args([
            service('tmonier_quick_order.variant_lookup'),
            service('sylius.checker.inventory.availability'),
            service('tmonier_quick_order.quantity_constraint_provider'),
            service('tmonier_quick_order.locked_cart_checker'),
            service('sylius.factory.cart_item'),
            service('sylius.modifier.order_item_quantity'),
            service('sylius.order_processing.order_processor'),
            service('sylius.manager.order'),
            service('event_dispatcher'),
            param('sylius.order_item_quantity_modifier.limit'),
            inline_service(QuantityRounding::class)
                ->factory([QuantityRounding::class, 'from'])
                ->args([param('tmonier_quick_order.quantity_rounding')]),
            param('tmonier_quick_order.max_total_quantity'),
            param('tmonier_quick_order.expose_available_stock'),
        ])
        ->public()
    ;
    $services->alias(BulkCartAdderInterface::class, 'tmonier_quick_order.bulk_cart_adder')->public();

    $services
        ->set('tmonier_quick_order.paste_parser', PasteParser::class)
        ->args([param('tmonier_quick_order.max_lines'), param('tmonier_quick_order.max_file_size')])
    ;
    $services
        ->set('tmonier_quick_order.csv_parser', CsvParser::class)
        ->args([param('tmonier_quick_order.max_lines'), param('tmonier_quick_order.max_file_size')])
    ;
    $services
        ->set('tmonier_quick_order.csv_writer', CsvWriter::class)
        ->args([service('translator')])
    ;
    $services
        ->set('tmonier_quick_order.reorder_input_factory', ReorderInputFactory::class)
        ->args([param('tmonier_quick_order.max_lines')])
    ;

    $services
        ->set('tmonier_quick_order.controller.shop', QuickOrderController::class)
        ->args([
            service('sylius.context.cart'),
            service('tmonier_quick_order.bulk_cart_adder'),
            service('tmonier_quick_order.paste_parser'),
            service('tmonier_quick_order.csv_parser'),
            service('tmonier_quick_order.csv_writer'),
            service('tmonier_quick_order.reorder_input_factory'),
            service('sylius.repository.order'),
            service('sylius.context.customer'),
            service('sylius.context.channel'),
            param('tmonier_quick_order.allow_guests'),
            param('tmonier_quick_order.default_mode'),
            param('tmonier_quick_order.max_lines'),
            param('tmonier_quick_order.max_file_size'),
            param('tmonier_quick_order.max_total_quantity'),
            service('sylius.repository.product'),
            service('tmonier_quick_order.matrix.activation_checker'),
            service('tmonier_quick_order.matrix.order_handler'),
            service('limiter.tmonier_quick_order_ip')->nullOnInvalid(),
        ])
        ->call('setContainer', [service(\Psr\Container\ContainerInterface::class)])
        ->tag('controller.service_arguments')
        ->tag('container.service_subscriber')
        ->public()
    ;

    $services
        ->set('tmonier_quick_order.matrix.activation_checker', MatrixActivationChecker::class)
        ->args([
            param('tmonier_quick_order.matrix.enabled'),
            param('tmonier_quick_order.matrix.auto'),
            param('tmonier_quick_order.matrix.max_variants'),
            param('tmonier_quick_order.matrix.products'),
            param('tmonier_quick_order.matrix.excluded_products'),
            param('tmonier_quick_order.matrix.attribute'),
        ])
    ;
    $services->alias(MatrixActivationCheckerInterface::class, 'tmonier_quick_order.matrix.activation_checker');

    $services
        ->set('tmonier_quick_order.matrix.builder', MatrixBuilder::class)
        ->args([
            service('tmonier_quick_order.catalog_price_calculator'),
            service('sylius.checker.inventory.availability'),
            service('tmonier_quick_order.quantity_constraint_provider'),
            param('tmonier_quick_order.matrix.max_grid_cells'),
        ])
    ;
    $services->alias(MatrixBuilderInterface::class, 'tmonier_quick_order.matrix.builder');

    $services
        ->set('tmonier_quick_order.matrix.order_handler', MatrixOrderHandler::class)
        ->args([service('tmonier_quick_order.bulk_cart_adder')])
    ;
    $services->alias(MatrixOrderHandlerInterface::class, 'tmonier_quick_order.matrix.order_handler');

    $services
        ->set('tmonier_quick_order.twig.component.matrix', MatrixOrderComponent::class)
        ->args([
            service('sylius.repository.product'),
            service('sylius.context.channel'),
            service('sylius.context.cart'),
            service('tmonier_quick_order.matrix.activation_checker'),
            service('tmonier_quick_order.matrix.builder'),
            service('tmonier_quick_order.matrix.order_handler'),
            param('tmonier_quick_order.default_mode'),
            param('tmonier_quick_order.max_total_quantity'),
            service('request_stack'),
            param('sylius.order_item_quantity_modifier.limit'),
            inline_service(QuantityRounding::class)
                ->factory([QuantityRounding::class, 'from'])
                ->args([param('tmonier_quick_order.quantity_rounding')]),
        ])
        ->call('setLiveResponder', [service('ux.live_component.live_responder')])
        ->tag('sylius.live_component.shop', [
            'key' => 'tmonier_quick_order:product:matrix',
            'template' => '@TmonierSyliusQuickOrderPlugin/shop/product/matrix.html.twig',
        ])
    ;

    $services
        ->set('tmonier_quick_order.twig.extension', QuickOrderExtension::class)
        ->args([service('tmonier_quick_order.matrix.activation_checker')])
        ->tag('twig.extension')
    ;

    $services
        ->set('tmonier_quick_order.menu.account', AccountMenuListener::class)
        ->tag('kernel.event_listener', ['event' => 'sylius.menu.shop.account'])
    ;

    $services
        ->set('tmonier_quick_order.fixture.demo_catalog', QuickOrderDemoCatalogFixture::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('sylius.repository.channel'),
            service('sylius.factory.product'),
            service('sylius.factory.product_variant'),
            service('sylius.factory.product_option'),
            service('sylius.factory.product_option_value'),
            service('sylius.factory.channel_pricing'),
            service('sylius.repository.product'),
        ])
        ->tag('sylius_fixtures.fixture')
    ;
};
