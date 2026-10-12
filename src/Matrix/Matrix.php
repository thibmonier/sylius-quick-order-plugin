<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Matrix;

/**
 * A grid (2 options: rows x columns) or a list (1 option, more than 2 options, or too many
 * cells) of the variants of a product.
 */
final class Matrix
{
    public const LAYOUT_GRID = 'grid';

    public const LAYOUT_LIST = 'list';

    /**
     * @param list<array{code: string, label: string}> $rows option values of the first option (grid)
     * @param list<array{code: string, label: string}> $columns option values of the second option (grid)
     * @param array<string, array<string, MatrixCell>> $grid cells by row and column value code (grid)
     * @param list<MatrixCell> $cells every cell, in display order
     */
    public function __construct(
        public readonly string $layout,
        public readonly array $cells,
        public readonly ?string $rowLabel = null,
        public readonly ?string $columnLabel = null,
        public readonly array $rows = [],
        public readonly array $columns = [],
        public readonly array $grid = [],
    ) {
    }

    public function isGrid(): bool
    {
        return self::LAYOUT_GRID === $this->layout;
    }

    public function getCell(int $variantId): ?MatrixCell
    {
        foreach ($this->cells as $cell) {
            if ($cell->variantId === $variantId) {
                return $cell;
            }
        }

        return null;
    }

    /** Cells shown as a list: disabled variants are left out (a grid greys them to keep its shape). */
    public function getListedCells(): array
    {
        return array_values(array_filter($this->cells, static fn (MatrixCell $cell): bool => CellStatus::Disabled !== $cell->status));
    }

    /** Nothing orderable because of stock (as opposed to prices or disabled variants). */
    public function isOutOfStock(): bool
    {
        if ($this->hasOrderableCell()) {
            return false;
        }
        foreach ($this->cells as $cell) {
            if (CellStatus::OutOfStock === $cell->status) {
                return true;
            }
        }

        return false;
    }

    public function hasOrderableCell(): bool
    {
        foreach ($this->cells as $cell) {
            if ($cell->isOrderable()) {
                return true;
            }
        }

        return false;
    }
}
