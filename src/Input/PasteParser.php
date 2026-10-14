<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Input;

use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult;
use Tmonier\SyliusQuickOrderPlugin\Adder\LineStatus;

/**
 * Pasted text: one "CODE;qty", "CODE,qty" or "CODE<TAB>qty" (spreadsheet copy) per line. A space
 * separates the quantity only before the last token, since codes may contain spaces. A first
 * line that looks like a header (SKU, code...) is skipped. Texts longer than $maxLength bytes
 * are refused before parsing; lines are read one by one and reading stops past max_lines.
 */
final readonly class PasteParser
{
    private const HEADER_CODES = '/^(sku|code|codes|product[ _-]?code|variant[ _-]?code|item|reference|ref|référence|artikel|artikelnummer|codice|código|codigo|kod)$/iu';

    public function __construct(private int $maxLines, private int $maxLength = 1048576)
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
            [$code, $quantity, $ambiguous] = $this->split($line);
            if ($first && self::isHeader($code, $quantity)) {
                $first = false;

                continue;
            }
            $first = false;
            if ($ambiguous) {
                $collector->addInvalid($lineNumber, $code, (string) $quantity, LineStatus::AmbiguousQuantity);

                continue;
            }
            $collector->add($lineNumber, $code, $quantity);
        }

        return $collector->getInput();
    }

    public static function isHeader(string $code, ?string $quantity): bool
    {
        return 1 === preg_match(self::HEADER_CODES, LineCollector::clean($code)) &&
            (null === $quantity || 1 !== preg_match('/^\s*\d/', $quantity));
    }

    /**
     * Extra cells after the quantity (descriptions, prices) are ignored, except "ABC,1,000"
     * with commas: a 1-3 digit quantity followed by exactly 3 digits may be "1,000" as well
     * as "1" and a price of "000"; such lines are refused as ambiguous (use ";" or a tab).
     *
     * @return array{0: string, 1: string|null, 2: bool} code, quantity, ambiguous
     */
    private function split(string $line): array
    {
        foreach (["\t", ';', ','] as $separator) {
            if (str_contains($line, $separator)) {
                $cells = explode($separator, $line);
                $ambiguous = ',' === $separator && \count($cells) >= 3 &&
                    1 === preg_match('/^\d{1,3}$/', trim($cells[1])) && 1 === preg_match('/^\d{3}$/', trim($cells[2]));

                return [$cells[0], $ambiguous ? trim($cells[1]) . ',' . trim($cells[2]) : ($cells[1] ?? null), $ambiguous];
            }
        }

        if (1 === preg_match('/^(.*\S)\s+([-+]?\d+(?:[.,]\d+)?)\s*$/u', $line, $matches)) {
            return [$matches[1], $matches[2], false];
        }

        return [$line, null, false];
    }
}
