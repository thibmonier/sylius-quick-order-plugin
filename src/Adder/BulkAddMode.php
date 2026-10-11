<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Adder;

enum BulkAddMode: string
{
    /** Valid lines are added, the others are reported. */
    case Partial = 'partial';

    /** Nothing is added unless every line can be added. */
    case AllOrNothing = 'all_or_nothing';
}
