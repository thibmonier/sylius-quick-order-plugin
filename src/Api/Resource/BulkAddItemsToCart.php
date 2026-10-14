<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use Symfony\Component\Serializer\Attribute\Groups;
use Tmonier\SyliusQuickOrderPlugin\Api\BulkAddItemsProcessor;
use Tmonier\SyliusQuickOrderPlugin\Api\BulkAddItemsResult;

/** Input of POST /api/v2/shop/orders/{tokenValue}/bulk-items. */
#[ApiResource(
    shortName: 'QuickOrderBulkAdd',
    operations: [
        new Post(
            uriTemplate: '/shop/orders/{tokenValue}/bulk-items',
            uriVariables: ['tokenValue'],
            status: 200,
            description: 'Adds many lines (variant code + quantity) to a cart with a single order processing; returns the status of every line.',
            normalizationContext: ['groups' => [self::READ_GROUP]],
            denormalizationContext: ['groups' => [self::WRITE_GROUP]],
            output: BulkAddItemsResult::class,
            read: false,
            name: 'tmonier_quick_order_shop_bulk_add',
            processor: BulkAddItemsProcessor::class,
        ),
    ],
)]
final class BulkAddItemsToCart
{
    public const READ_GROUP = 'tmonier:quick_order:bulk_add:read';

    public const WRITE_GROUP = 'tmonier:quick_order:bulk_add:write';

    /** Filled from the URI. */
    public ?string $tokenValue = null;

    /** @var array<mixed> list of {"code": "SKU", "quantity": 2} */
    #[Groups([self::WRITE_GROUP])]
    public array $lines = [];

    /** "partial" (default of the shop) or "all_or_nothing". */
    #[Groups([self::WRITE_GROUP])]
    public ?string $mode = null;
}
