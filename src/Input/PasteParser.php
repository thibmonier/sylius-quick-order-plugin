<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Input;

/**
 * Pasted text: one "CODE;qty", "CODE,qty" or "CODE<TAB>qty" (spreadsheet copy) per line. A space
 * separates the quantity only before the last token, since codes may contain spaces. A first
 * line that looks like a header (SKU, code...) is skipped.
 */
final class PasteParser
{
    private const HEADER_CODES = '/^(sku|code|codes|product[ _-]?code|variant[ _-]?code|item|reference|ref|référence|artikel|artikelnummer|codice|kod)$/iu';

    public function __construct(private readonly int $maxLines)
    {
    }

    public function parse(string $text): QuickOrderInput
    {
        $collector = new LineCollector($this->maxLines);
        $first = true;
        foreach (preg_split('/\R/u', $text) ?: [$text] as $index => $line) {
            if ('' === trim($line, " \t\u{A0}\u{FEFF}")) {
                continue;
            }
            [$code, $quantity] = self::split($line);
            if ($first && self::isHeader($code, $quantity)) {
                $first = false;

                continue;
            }
            $first = false;
            $collector->add($index + 1, $code, $quantity);
        }

        return $collector->getInput();
    }

    public static function isHeader(string $code, ?string $quantity): bool
    {
        return 1 === preg_match(self::HEADER_CODES, trim($code, " \t\"\u{FEFF}"))
            && (null === $quantity || 1 !== preg_match('/^\s*\d/', $quantity));
    }

    /** @return array{0: string, 1: string|null} */
    private static function split(string $line): array
    {
        foreach (["\t", ';', ','] as $separator) {
            if (str_contains($line, $separator)) {
                $cells = explode($separator, $line);

                return [$cells[0], $cells[1] ?? null];
            }
        }

        if (1 === preg_match('/^(.*\S)\s+([-+]?\d+(?:[.,]\d+)?)\s*$/u', $line, $matches)) {
            return [$matches[1], $matches[2]];
        }

        return [$line, null];
    }
}
