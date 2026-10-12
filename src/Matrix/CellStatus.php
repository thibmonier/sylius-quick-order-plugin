<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Matrix;

enum CellStatus: string
{
    case Orderable = 'orderable';

    /** Disabled variant: greyed in a grid, left out of a list. */
    case Disabled = 'disabled';

    case NoPrice = 'no_price';

    /** Tracked stock and nothing available; the count is never shown. */
    case OutOfStock = 'out_of_stock';
}
