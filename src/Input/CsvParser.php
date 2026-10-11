<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Input;

/**
 * CSV upload: UTF-8 (with or without BOM) or Windows-1252, separator detected among ",", ";"
 * and TAB on the first line. Optional header row: SKU / code and QTY / quantity columns, in
 * any order; without a header, the first column is the code and the second the quantity.
 */
final class CsvParser
{
    private const CODE_HEADERS = ['sku', 'code', 'product_code', 'productcode', 'variant_code', 'variantcode', 'product code', 'variant code', 'reference', 'ref'];

    private const QUANTITY_HEADERS = ['qty', 'quantity', 'quantité', 'quantite', 'menge', 'cantidad', 'quantità', 'aantal', 'ilość', 'ilosc'];

    public function __construct(private readonly int $maxLines, private readonly int $maxFileSize)
    {
    }

    public function parseFile(string $path): QuickOrderInput
    {
        $size = @filesize($path);
        if (false === $size || $size > $this->maxFileSize) {
            return QuickOrderInput::fileRejected();
        }
        $content = @file_get_contents($path);
        if (false === $content) {
            return QuickOrderInput::fileRejected();
        }

        return $this->parse($content);
    }

    public function parse(string $content): QuickOrderInput
    {
        if (\strlen($content) > $this->maxFileSize || str_contains(substr($content, 0, 1024), "\0")) {
            return QuickOrderInput::fileRejected();
        }
        if (str_starts_with($content, "\u{FEFF}")) {
            $content = substr($content, 3);
        }

        $handle = fopen('php://temp', 'r+');
        if (false === $handle) {
            return QuickOrderInput::fileRejected();
        }
        fwrite($handle, $content);
        rewind($handle);

        $separator = self::detectSeparator(strtok($content, "\r\n") ?: '');
        $collector = new LineCollector($this->maxLines);
        $codeColumn = 0;
        $quantityColumn = 1;
        $lineNumber = 0;
        while (false !== ($row = fgetcsv($handle, null, $separator, '"', ''))) {
            ++$lineNumber;
            /** @var list<string|null> $row */
            if ([null] === $row || '' === trim(implode('', array_map('strval', $row)))) {
                continue;
            }
            if (1 === $lineNumber && null !== ($columns = self::headerColumns($row))) {
                [$codeColumn, $quantityColumn] = $columns;

                continue;
            }
            $collector->add($lineNumber, (string) ($row[$codeColumn] ?? ''), null === $quantityColumn ? null : ($row[$quantityColumn] ?? null));
        }
        fclose($handle);

        return $collector->getInput();
    }

    private static function detectSeparator(string $firstLine): string
    {
        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);
        $separator = array_key_first($counts);

        return 0 === $counts[$separator] ? ',' : $separator;
    }

    /**
     * @param list<string|null> $row
     *
     * @return array{0: int, 1: int|null}|null
     */
    private static function headerColumns(array $row): ?array
    {
        $codeColumn = null;
        $quantityColumn = null;
        foreach ($row as $index => $cell) {
            $name = mb_strtolower(trim((string) $cell, " \t\"\u{FEFF}"));
            if (null === $codeColumn && \in_array($name, self::CODE_HEADERS, true)) {
                $codeColumn = $index;
            } elseif (null === $quantityColumn && \in_array($name, self::QUANTITY_HEADERS, true)) {
                $quantityColumn = $index;
            }
        }

        if (null === $codeColumn) {
            return PasteParser::isHeader((string) ($row[0] ?? ''), $row[1] ?? null) ? [0, 1] : null;
        }

        return [$codeColumn, $quantityColumn];
    }
}
