<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Input;

use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult;
use Tmonier\SyliusQuickOrderPlugin\Adder\LineStatus;

/**
 * Validates raw lines (paste, CSV, reorder, API) into a QuickOrderInput. Lines with exactly the
 * same code are merged; codes differing in case are merged later, once resolved to the same
 * variant. Quantities are whole numbers, optionally with a zero fraction of one or two digits
 * as spreadsheets export them ("12.0", "12,00"). Thousands groups ("1,000", "1.000", "1 000")
 * are ambiguous and other fractions invalid: both refused, never guessed. An empty quantity
 * means 1 unless the caller requires one (CSV).
 */
final class LineCollector
{
    public const MAX_CODE_LENGTH = 255;

    /** Larger quantities are refused at once (no integer overflow when lines are merged). */
    public const MAX_QUANTITY = 999999999;

    /** @var array<string, QuickOrderLine> */
    private array $lines = [];

    /** @var list<InvalidLine> */
    private array $invalidLines = [];

    private int $count = 0;

    public function __construct(private readonly int $maxLines)
    {
    }

    public function isFull(): bool
    {
        return $this->count > $this->maxLines;
    }

    public function add(int $lineNumber, string $code, int|string|null $quantity, bool $quantityRequired = false): void
    {
        if (++$this->count > $this->maxLines) {
            return;
        }

        $code = self::clean($code);
        $rawQuantity = \is_string($quantity) ? self::clean($quantity) : (string) ($quantity ?? '');
        if ('' === $code || mb_strlen($code) > self::MAX_CODE_LENGTH) {
            $this->invalidLines[] = new InvalidLine($lineNumber, mb_substr($code, 0, self::MAX_CODE_LENGTH), mb_substr($rawQuantity, 0, 32), LineStatus::InvalidLine);

            return;
        }

        $parsedQuantity = $quantityRequired && '' === $rawQuantity ? null : self::parseQuantity($quantity);
        if (null === $parsedQuantity) {
            $status = \is_string($quantity) && self::isAmbiguous($quantity) ? LineStatus::AmbiguousQuantity : LineStatus::InvalidQuantity;
            $this->invalidLines[] = new InvalidLine($lineNumber, $code, mb_substr($rawQuantity, 0, 32), $status);

            return;
        }

        $this->lines[$code] = isset($this->lines[$code])
            ? $this->lines[$code]->withAddedQuantity($parsedQuantity, $lineNumber)
            : new QuickOrderLine($code, $parsedQuantity, [$lineNumber]);
    }

    /** A line known to be unusable, e.g. a reordered item whose variant no longer exists. */
    public function addInvalid(int $lineNumber, string $label, string $quantity, LineStatus $status): void
    {
        if (++$this->count > $this->maxLines) {
            return;
        }

        $this->invalidLines[] = new InvalidLine($lineNumber, mb_substr(self::clean($label), 0, self::MAX_CODE_LENGTH), mb_substr($quantity, 0, 32), $status);
    }

    public function getInput(): QuickOrderInput
    {
        if ($this->isFull()) {
            return QuickOrderInput::rejected(BulkAddResult::REFUSED_TOO_MANY_LINES);
        }

        return new QuickOrderInput(array_values($this->lines), $this->invalidLines);
    }

    public static function parseQuantity(int|string|null $quantity): ?int
    {
        if (null === $quantity) {
            return 1;
        }
        if (\is_int($quantity)) {
            return $quantity > 0 && $quantity <= self::MAX_QUANTITY ? $quantity : null;
        }

        $quantity = self::clean($quantity);
        if ('' === $quantity) {
            return 1;
        }
        // A zero fraction of 1-2 digits is a spreadsheet decimal; "1.000" (3 digits) is a thousands group.
        if (1 !== preg_match('/^0*(\d{1,9})(?:[.,]0{1,2})?$/', $quantity, $matches)) {
            return null;
        }
        $value = (int) $matches[1];

        return $value > 0 ? $value : null;
    }

    /** Thousands groups: "1,000", "1.000", "1 000", "12.345.678" mean different numbers in different countries. */
    public static function isAmbiguous(string $quantity): bool
    {
        return 1 === preg_match('/^\d{1,3}(?:([.,\x{A0}\x{202F} \'])\d{3})+$/u', self::clean($quantity));
    }

    public static function clean(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            // Spreadsheet exports in the legacy Windows encoding.
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }
        $value = trim($value, " \t\n\r\0\x0B\u{A0}\u{FEFF}");
        if (\strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return trim(str_replace('""', '"', substr($value, 1, -1)));
        }

        return $value;
    }
}
