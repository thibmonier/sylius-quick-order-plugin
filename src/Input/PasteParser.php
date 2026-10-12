<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Input;

use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult;

/**
 * Pasted text: one "CODE;qty", "CODE,qty" or "CODE<TAB>qty" (spreadsheet copy) per line. A space
 * separates the quantity only before the last token, since codes may contain spaces. A first
 * line that looks like a header (SKU, code...) is skipped. Texts longer than $maxLength bytes
 * are refused before parsing; lines are read one by one and reading stops past max_lines.
 */
final class PasteParser
{
    private const HEADER_CODES = '/^(sku|code|codes|product[ _-]?code|variant[ _-]?code|item|reference|ref|référence|artikel|artikelnummer|codice|código|codigo|kod)$/iu';

    public function __construct(private readonly int $maxLines, private readonly int $maxLength = 1048576)
    {
    }

    public function parse(string $text): QuickOrderInput
    {
        if (\strlen($text) > $this->maxLength) {
            return QuickOrderInput::rejected(BulkAddResult::REFUSED_TEXT_TOO_LONG);
        }

        $collector = new LineCollector($this->maxLines);
        $first = true;
        $lineNumber = 0;
        $offset = 0;
        $length = \strlen($text);
        while ($offset < $length && !$collector->isFull()) {
            ++$lineNumber;
            $end = strcspn($text, "\r\n", $offset);
            $line = substr($text, $offset, $end);
            $offset += $end;
            // "\r\n", "\n" or "\r" ends the line.
            $offset += "\r\n" === substr($text, $offset, 2) ? 2 : 1;

            if ('' === trim($line, " \t\u{A0}\u{FEFF}")) {
                continue;
            }
            [$code, $quantity] = self::split($line);
            if ($first && self::isHeader($code, $quantity)) {
                $first = false;

                continue;
            }
            $first = false;
            $collector->add($lineNumber, $code, $quantity);
        }

        return $collector->getInput();
    }

    public static function isHeader(string $code, ?string $quantity): bool
    {
        return 1 === preg_match(self::HEADER_CODES, LineCollector::clean($code)) &&
            (null === $quantity || 1 !== preg_match('/^\s*\d/', $quantity));
    }

    /** @return array{0: string, 1: string|null} */
    private static function split(string $line): array
    {
        foreach (["\t", ';', ','] as $separator) {
            if (str_contains($line, $separator)) {
                $cells = explode($separator, $line, 3);
                // "ABC,1,000": a thousands group, not an extra column; refused as a quantity.
                if (',' === $separator && isset($cells[1], $cells[2]) && 1 === preg_match('/^\d{3}(\D|$)/', trim($cells[2]))) {
                    return [$cells[0], $cells[1] . ',' . $cells[2]];
                }

                return [$cells[0], $cells[1] ?? null];
            }
        }

        if (1 === preg_match('/^(.*\S)\s+([-+]?\d+(?:[.,]\d+)?)\s*$/u', $line, $matches)) {
            return [$matches[1], $matches[2]];
        }

        return [$line, null];
    }
}
