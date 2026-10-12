<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Input;

use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult;

/**
 * CSV upload: UTF-8 (with or without BOM) or Windows-1252, separator detected among ",", ";"
 * and TAB on the first line. Optional header row: SKU / code and QTY / quantity columns, in
 * any order (cells read as UTF-8 or Windows-1252); a header whose columns are not recognised
 * refuses the file. Without a header, the first column is the code and the second the
 * quantity. Line endings: LF, CRLF or CR.
 */
final class CsvParser
{
    private const CODE_HEADERS = ['sku', 'code', 'codes', 'product_code', 'productcode', 'variant_code', 'variantcode', 'product code', 'variant code', 'item', 'item code', 'item number', 'part number', 'reference', 'référence', 'ref', 'réf', 'réf.', 'article', 'artikel', 'artikelnummer', 'artikelnr', 'artikelnr.', 'art.-nr.', 'código', 'codigo', 'referencia', 'codice', 'articolo', 'kod', 'indeks'];

    private const QUANTITY_HEADERS = ['qty', 'qty.', 'quantity', 'quantities', 'quantité', 'quantite', 'qté', 'qte', 'menge', 'anzahl', 'stück', 'stueck', 'cantidad', 'cant.', 'quantità', 'quantita', 'qtà', 'aantal', 'ilość', 'ilosc'];

    public function __construct(private readonly int $maxLines, private readonly int $maxFileSize)
    {
    }

    public function parseFile(string $path): QuickOrderInput
    {
        $size = @filesize($path);
        if (false === $size || $size > $this->maxFileSize) {
            return QuickOrderInput::rejected(BulkAddResult::REFUSED_FILE);
        }
        $content = @file_get_contents($path);
        if (false === $content) {
            return QuickOrderInput::rejected(BulkAddResult::REFUSED_FILE);
        }

        return $this->parse($content);
    }

    public function parse(string $content): QuickOrderInput
    {
        if (\strlen($content) > $this->maxFileSize || str_contains(substr($content, 0, 1024), "\0")) {
            return QuickOrderInput::rejected(BulkAddResult::REFUSED_FILE);
        }
        if (str_starts_with($content, "\u{FEFF}")) {
            $content = substr($content, 3);
        }
        // Old Mac and some ERP exports end lines with a bare CR, which fgetcsv() does not split on.
        $content = (string) preg_replace('/\r\n?/', "\n", $content);

        $handle = fopen('php://temp', 'r+');
        if (false === $handle) {
            return QuickOrderInput::rejected(BulkAddResult::REFUSED_FILE);
        }
        fwrite($handle, $content);
        rewind($handle);

        $separator = self::detectSeparator(strtok($content, "\n") ?: '');
        $collector = new LineCollector($this->maxLines);
        $codeColumn = 0;
        $quantityColumn = 1;
        $lineNumber = 0;
        while (!$collector->isFull() && false !== ($row = fgetcsv($handle, null, $separator, '"', ''))) {
            ++$lineNumber;
            if ([null] === $row || '' === trim(implode('', array_map('strval', $row)))) {
                continue;
            }
            if (1 === $lineNumber) {
                $columns = self::headerColumns($row);
                if (false === $columns) {
                    fclose($handle);

                    return QuickOrderInput::rejected(BulkAddResult::REFUSED_CSV_COLUMNS);
                }
                if (null !== $columns) {
                    [$codeColumn, $quantityColumn] = $columns;

                    continue;
                }
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
     * Columns of a header row; null when the row is data; false when it is a header whose code
     * or quantity column is not recognised (better refuse the file than add 1 of everything).
     *
     * @param list<string|null> $row
     *
     * @return array{0: int, 1: int|null}|false|null
     */
    private static function headerColumns(array $row): array|false|null
    {
        $codeColumn = null;
        $quantityColumn = null;
        $filled = 0;
        foreach ($row as $index => $cell) {
            $name = mb_strtolower(LineCollector::clean((string) $cell));
            if ('' !== $name) {
                ++$filled;
            }
            if (null === $codeColumn && \in_array($name, self::CODE_HEADERS, true)) {
                $codeColumn = $index;
            } elseif (null === $quantityColumn && \in_array($name, self::QUANTITY_HEADERS, true)) {
                $quantityColumn = $index;
            }
        }

        if (null === $codeColumn && null === $quantityColumn) {
            // A header made of unknown names: the second cell is not a quantity.
            $second = LineCollector::clean((string) ($row[1] ?? ''));

            return $filled > 1 && null === LineCollector::parseQuantity($second) && 1 !== preg_match('/^[-+]?\d/', $second) ? false : null;
        }
        if (null === $codeColumn || (null === $quantityColumn && $filled > 1)) {
            return false;
        }

        return [$codeColumn, $quantityColumn];
    }
}
