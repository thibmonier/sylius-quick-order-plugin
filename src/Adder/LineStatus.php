<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Adder;

enum LineStatus: string
{
    /** Preview: the line can be added. */
    case Valid = 'valid';
    case Added = 'added';
    /** Added, but the cart processing kept a smaller quantity. */
    case Adjusted = 'adjusted';
    /** Valid, but not added: another line failed in all-or-nothing mode, or the cart is locked. */
    case Skipped = 'skipped';
    /** Added, then removed by the cart processing. */
    case Removed = 'removed';
    case InvalidLine = 'invalid_line';
    case InvalidQuantity = 'invalid_quantity';
    case UnknownCode = 'unknown_code';
    case Disabled = 'disabled';
    case NotInChannel = 'not_in_channel';
    case NoChannelPricing = 'no_channel_pricing';
    case InsufficientStock = 'insufficient_stock';
    case QuantityLimitExceeded = 'quantity_limit_exceeded';
    case QuantityConstraint = 'quantity_constraint';

    public function isError(): bool
    {
        return !\in_array($this, [self::Valid, self::Added, self::Adjusted, self::Skipped], true);
    }
}
