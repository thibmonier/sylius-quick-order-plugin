<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Quantity;

enum QuantityRounding: string
{
    /** The line is reported as invalid. */
    case Reject = 'reject';

    /** The quantity is rounded up to the next valid quantity. */
    case RoundUp = 'round_up';
}
