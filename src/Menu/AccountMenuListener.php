<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

final class AccountMenuListener
{
    public function __invoke(MenuBuilderEvent $event): void
    {
        $event->getMenu()
            ->addChild('tmonier_quick_order', ['route' => 'tmonier_quick_order_shop_index'])
            ->setLabel('tmonier_quick_order.ui.quick_order')
            ->setLabelAttribute('icon', 'tabler:list-numbers')
        ;
    }
}
