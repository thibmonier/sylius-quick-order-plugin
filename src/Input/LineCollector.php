<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Input;

use Tmonier\SyliusQuickOrderPlugin\Adder\LineStatus;

/**
 * Validates and merges raw lines (paste, CSV, reorder, API) into a QuickOrderInput.
 * Codes are compared case-insensitively; an empty quantity means 1.
 */
final class LineCollector
{
    public const MAX_CODE_LENGTH = 255;

    /** @var array<string, QuickOrderLine> */
    private array $lines = [];

    /** @var list<InvalidLine> */
    private array $invalidLines = [];

    private int $count = 0;

    private bool $tooManyLines = false;

    public function __construct(private readonly int $maxLines)
    {
    }

    public function add(int $lineNumber, string $code, int|string|null $quantity): void
    {
        if (++$this->count > $this->maxLines) {
            $this->tooManyLines = true;

            return;
        }

        $code = self::clean($code);
        $rawQuantity = \is_string($quantity) ? self::clean($quantity) : (string) ($quantity ?? '');
        if ('' === $code || mb_strlen($code) > self::MAX_CODE_LENGTH) {
            $this->invalidLines[] = new InvalidLine($lineNumber, mb_substr($code, 0, self::MAX_CODE_LENGTH), mb_substr($rawQuantity, 0, 32), LineStatus::InvalidLine);

            return;
        }

        $parsedQuantity = self::parseQuantity($quantity);
        if (null === $parsedQuantity) {
            $this->invalidLines[] = new InvalidLine($lineNumber, $code, mb_substr($rawQuantity, 0, 32), LineStatus::InvalidQuantity);

            return;
        }

        $key = mb_strtolower($code);
        $this->lines[$key] = isset($this->lines[$key])
            ? $this->lines[$key]->withAddedQuantity($parsedQuantity, $lineNumber)
            : new QuickOrderLine($code, $parsedQuantity, [$lineNumber]);
    }

    /** A line known to be unusable, e.g. a reordered item whose variant no longer exists. */
    public function addInvalid(int $lineNumber, string $label, string $quantity, LineStatus $status): void
    {
        if (++$this->count > $this->maxLines) {
            $this->tooManyLines = true;

            return;
        }

        $this->invalidLines[] = new InvalidLine($lineNumber, mb_substr(self::clean($label), 0, self::MAX_CODE_LENGTH), $quantity, $status);
    }

    public function getInput(): QuickOrderInput
    {
        if ($this->tooManyLines) {
            return new QuickOrderInput([], [], true);
        }

        return new QuickOrderInput(array_values($this->lines), $this->invalidLines);
    }

    private static function parseQuantity(int|string|null $quantity): ?int
    {
        if (null === $quantity) {
            return 1;
        }
        if (\is_int($quantity)) {
            return $quantity > 0 ? $quantity : null;
        }

        $quantity = self::clean($quantity);
        if ('' === $quantity) {
            return 1;
        }
        // "12.0" or "12,00" as exported by spreadsheets; no other decimals.
        if (1 !== preg_match('/^0*(\d{1,9})(?:[.,]0+)?$/', $quantity, $matches)) {
            return null;
        }
        $value = (int) $matches[1];

        return $value > 0 ? $value : null;
    }

    private static function clean(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            // Spreadsheet exports in the legacy Windows encoding.
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }
        $value = trim($value, " \t\n\r\0\x0B\u{A0}\u{FEFF}");
        if (\strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $value = trim(str_replace('""', '"', substr($value, 1, -1)));
        }

        return $value;
    }
}
