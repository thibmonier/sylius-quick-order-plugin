<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Adder;

final readonly class BulkAddResult
{
    public const REFUSED_CART_LOCKED = 'cart_locked';

    public const REFUSED_TOO_MANY_LINES = 'too_many_lines';

    public const REFUSED_FILE = 'file_rejected';

    public const REFUSED_EMPTY = 'empty';

    /** CSV header row whose code or quantity column is not recognised. */
    public const REFUSED_CSV_COLUMNS = 'csv_columns';

    public const REFUSED_TEXT_TOO_LONG = 'text_too_long';

    /** More units than max_total_quantity in one request. */
    public const REFUSED_TOO_MANY_UNITS = 'too_many_units';

    /** The "add" step does not match the preview kept in the session. */
    public const REFUSED_PREVIEW_EXPIRED = 'preview_expired';

    /** @var list<string> */
    public const INPUT_REFUSALS = [self::REFUSED_TOO_MANY_LINES, self::REFUSED_FILE, self::REFUSED_CSV_COLUMNS, self::REFUSED_TEXT_TOO_LONG];

    /** @param list<LineResult> $lines sorted by source line number */
    public function __construct(
        public array $lines,
        public bool $applied = false,
        public ?string $refusal = null,
        public BulkAddMode $mode = BulkAddMode::Partial,
    ) {
    }

    public static function refused(string $refusal, BulkAddMode $mode = BulkAddMode::Partial): self
    {
        return new self([], false, $refusal, $mode);
    }

    /** @return array{lines: list<array{c: string, r: string, q: int|null, s: string, l: list<int>, p: string|null, v: string|null, a: int, av: int|null}>, applied: bool, refusal: string|null, mode: string} */
    public function toArray(): array
    {
        return [
            'lines' => array_map(static fn (LineResult $line): array => $line->toArray(), $this->lines),
            'applied' => $this->applied,
            'refusal' => $this->refusal,
            'mode' => $this->mode->value,
        ];
    }

    /** @param array{lines: list<array{c: string, r: string, q: int|null, s: string, l: list<int>, p: string|null, v: string|null, a: int, av: int|null}>, applied: bool, refusal: string|null, mode: string} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            array_map(LineResult::fromArray(...), $data['lines']),
            $data['applied'],
            $data['refusal'],
            BulkAddMode::from($data['mode']),
        );
    }

    /** @return list<LineResult> */
    public function getLinesWithStatus(LineStatus ...$statuses): array
    {
        return array_values(array_filter($this->lines, static fn (LineResult $line): bool => \in_array($line->status, $statuses, true)));
    }

    /** @return list<LineResult> */
    public function getErrors(): array
    {
        return array_values(array_filter($this->lines, static fn (LineResult $line): bool => $line->status->isError()));
    }

    public function hasErrors(): bool
    {
        return null !== $this->refusal || [] !== $this->getErrors();
    }

    /** Lines that can be added (preview) or were added. */
    public function countAddable(): int
    {
        return \count($this->getLinesWithStatus(LineStatus::Valid, LineStatus::Added, LineStatus::Adjusted));
    }

    public function countAdded(): int
    {
        return \count($this->getLinesWithStatus(LineStatus::Added, LineStatus::Adjusted));
    }
}
