<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Api;

use Symfony\Component\Serializer\Attribute\Groups;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult;
use Tmonier\SyliusQuickOrderPlugin\Adder\LineResult;
use Tmonier\SyliusQuickOrderPlugin\Api\Resource\BulkAddItemsToCart;

final readonly class BulkAddItemsResult
{
    /** @param list<array{lineNumbers: list<int>, code: string, requestedQuantity: string, quantity: int|null, addedQuantity: int, status: string, available: int|null}> $lines */
    public function __construct(
        #[Groups([BulkAddItemsToCart::READ_GROUP])]
        public string $cart,
        #[Groups([BulkAddItemsToCart::READ_GROUP])]
        public bool $applied,
        #[Groups([BulkAddItemsToCart::READ_GROUP])]
        public string $mode,
        #[Groups([BulkAddItemsToCart::READ_GROUP])]
        public int $addedCount,
        #[Groups([BulkAddItemsToCart::READ_GROUP])]
        public array $lines,
        #[Groups([BulkAddItemsToCart::READ_GROUP])]
        public ?string $refusal = null,
    ) {
    }

    public static function fromResult(string $cartIri, BulkAddResult $result): self
    {
        return new self(
            $cartIri,
            $result->applied,
            $result->mode->value,
            $result->countAdded(),
            array_map(static fn (LineResult $line): array => [
                'lineNumbers' => $line->lineNumbers,
                'code' => $line->code,
                'requestedQuantity' => $line->requestedQuantity,
                'quantity' => $line->quantity,
                'addedQuantity' => $line->addedQuantity,
                'status' => $line->status->value,
                'available' => $line->available,
            ], $result->lines),
            $result->refusal,
        );
    }
}
