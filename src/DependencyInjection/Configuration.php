<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddMode;
use Tmonier\SyliusQuickOrderPlugin\Quantity\QuantityRounding;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('tmonier_sylius_quick_order');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->integerNode('max_lines')
                    ->info('Maximum number of lines per paste, CSV file, reorder or API call.')
                    ->defaultValue(200)
                    ->min(1)
                ->end()
                ->integerNode('max_total_quantity')
                    ->info('Maximum number of units added by one paste, CSV file, reorder or API call.')
                    ->defaultValue(20000)
                    ->min(1)
                ->end()
                ->booleanNode('expose_available_stock')
                    ->info('Whether "insufficient stock" lines tell how many units are available (stock levels become public).')
                    ->defaultFalse()
                ->end()
                ->integerNode('max_file_size')
                    ->info('Maximum CSV file size and pasted text length, in bytes.')
                    ->defaultValue(1048576)
                    ->min(1024)
                ->end()
                ->enumNode('default_mode')
                    ->info('partial: add the valid lines and report the others; all_or_nothing: add nothing unless every line is valid.')
                    ->values(array_map(static fn (BulkAddMode $mode): string => $mode->value, BulkAddMode::cases()))
                    ->defaultValue(BulkAddMode::Partial->value)
                ->end()
                ->booleanNode('allow_guests')
                    ->info('Whether visitors who are not logged in can use the quick order page.')
                    ->defaultTrue()
                ->end()
                ->enumNode('quantity_rounding')
                    ->info('What to do with a quantity that is not a multiple of the pack size given by a quantity constraint provider.')
                    ->values(array_map(static fn (QuantityRounding $rounding): string => $rounding->value, QuantityRounding::cases()))
                    ->defaultValue(QuantityRounding::Reject->value)
                ->end()
                ->arrayNode('matrix')
                    ->info('Order form with one quantity per variant on product pages (instead of the core add to cart form).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->booleanNode('auto')
                            ->info('Products with 1 or 2 options and at most max_variants variants get the matrix.')
                            ->defaultTrue()
                        ->end()
                        ->integerNode('max_variants')
                            ->info('Above this number of variants, the automatic rule keeps the core form.')
                            ->defaultValue(100)
                            ->min(1)
                        ->end()
                        ->integerNode('max_grid_cells')
                            ->info('A 2-option product with more cells (rows x columns) is shown as a list.')
                            ->defaultValue(60)
                            ->min(1)
                        ->end()
                        ->arrayNode('products')
                            ->info('Product codes that always get the matrix (any number of options: a list beyond 2).')
                            ->scalarPrototype()->end()
                        ->end()
                        ->arrayNode('excluded_products')
                            ->info('Product codes that never get the matrix.')
                            ->scalarPrototype()->end()
                        ->end()
                        ->scalarNode('attribute')
                            ->info('Code of a checkbox product attribute: checked = matrix, unchecked = core form, absent = the rules above.')
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
