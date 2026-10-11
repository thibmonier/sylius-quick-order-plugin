<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Event;

final class QuickOrderEvents
{
    /** PreBulkAddEvent: before the lines are added; listeners may refuse the whole addition. */
    public const PRE_BULK_ADD = 'tmonier_quick_order.pre_bulk_add';

    /** PostBulkAddEvent: after the cart was processed and flushed. */
    public const POST_BULK_ADD = 'tmonier_quick_order.post_bulk_add';

    private function __construct()
    {
    }
}
