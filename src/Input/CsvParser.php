<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Input;

use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult;
use Tmonier\SyliusQuickOrderPlugin\Adder\LineStatus;

/**
 * CSV upload: UTF-8 (with or without BOM) or Windows-1252, separator detected among ",", ";"
 * and TAB on the first line. Optional header row: SKU / code and QTY / quantity columns, in
 * any order (cells read as UTF-8 or Windows-1252); a header with unknown names means code
 * first, then quantity; a named code column without quantity column refuses the file.
 * Without a header, the first column is the code and the second the quantity. Every row needs
 * a quantity. Line endings: LF, CRLF or CR.
 */
final readonly class CsvParser
{
    private const CODE_HEADERS = ['sku', 'code', 'codes', 'product_code', 'productcode', 'variant_code', 'variantcode', 'product code', 'variant code', 'item', 'item code', 'item number', 'part number', 'reference', 'référence', 'ref', 'réf', 'réf.', 'article', 'artikel', 'artikelnummer', 'artikelnr', 'artikelnr.', 'art.-nr.', 'código', 'codigo', 'referencia', 'codice', 'articolo', 'kod', 'indeks'];

    private const QUANTITY_HEADERS = ['qty', 'qty.', 'quantity', 'quantities', 'quantité', 'quantite', 'qté', 'qte', 'menge', 'anzahl', 'stück', 'stueck', 'cantidad', 'cant.', 'quantità', 'quantita', 'qtà', 'aantal', 'ilość', 'ilosc'];

    public function __construct(private int $maxLines, private int $maxFileSize)
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

        $separator = $this->detectSeparator(strtok($content, "\n") ?: '');
        $collector = new LineCollector($this->maxLines);
        $codeColumn = 0;
        $quantityColumn = 1;
        // Cells of the layout (header or code + quantity): more cells may be a split "1,000".
        $width = 2;
        $lineNumber = 0;
        $first = true;
        while (!$collector->isFull() && false !== ($row = fgetcsv($handle, null, $separator, '"', ''))) {
            ++$lineNumber;
            if ([null] === $row || '' === trim(implode('', array_map(strval(...), $row)))) {
                continue;
            }
            if ($first) {
                $first = false;
                $columns = $this->headerColumns($row);
                if (false === $columns) {
                    fclose($handle);

                    return QuickOrderInput::rejected(BulkAddResult::REFUSED_CSV_COLUMNS);
                }
                if (null !== $columns) {
                    [$codeColumn, $quantityColumn] = $columns;
                    $width = \count($row);

                    continue;
                }
            }

            $code = $row[$codeColumn] ?? '';
            $quantity = $row[$quantityColumn] ?? '';
            $next = trim($row[$quantityColumn + 1] ?? '');
            if (\count($row) > $width && 1 === preg_match('/^\d{1,3}$/', trim($quantity)) && 1 === preg_match('/^\d{3}$/', $next)) {
                // "ABC,1,000" unquoted: 1000 or 1? Never guess.
                $collector->addInvalid($lineNumber, $code, trim($quantity) . $separator . $next, LineStatus::AmbiguousQuantity);

                continue;
            }
            // In a file, an empty quantity is a mistake, not "1".
            $collector->add($lineNumber, $code, $quantity, true);
        }
        fclose($handle);

        return $collector->getInput();
    }

    private function detectSeparator(string $firstLine): string
    {
        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);
        $separator = array_key_first($counts);

        return 0 === $counts[$separator] ? ',' : $separator;
    }

    /**
     * Columns of the first non-blank row when it is a header, null when it is data, false when
     * the file cannot be read safely (a code column is named but no quantity column can be
     * found).
     *
     * Named columns win (any order, extra columns ignored). Otherwise a row whose second cell
     * is not a quantity is a header with unknown names ("Product;Quantity", "EAN;Units"): the
     * columns are positional, code first, then quantity.
     *
     * @param list<string|null> $row
     *
     * @return array{0: int, 1: int}|false|null
     */
    private function headerColumns(array $row): array|false|null
    {
        $codeColumn = null;
        $quantityColumn = null;
        foreach ($row as $index => $cell) {
            $name = mb_strtolower(LineCollector::clean((string) $cell));
            if (null === $codeColumn && \in_array($name, self::CODE_HEADERS, true)) {
                $codeColumn = $index;
            } elseif (null === $quantityColumn && \in_array($name, self::QUANTITY_HEADERS, true)) {
                $quantityColumn = $index;
            }
        }

        if (null !== $codeColumn && null !== $quantityColumn) {
            return [$codeColumn, $quantityColumn];
        }
        if (null !== $quantityColumn) {
            return [0 === $quantityColumn ? 1 : 0, $quantityColumn];
        }
        if (null !== $codeColumn) {
            // The only other column, if there is exactly one, holds the quantities.
            $others = array_values(array_diff(array_keys($row), [$codeColumn]));

            return 1 === \count($others) ? [$codeColumn, $others[0]] : false;
        }

        $second = LineCollector::clean((string) ($row[1] ?? ''));
        $isQuantity = '' === $second || null !== LineCollector::parseQuantity($second) || LineCollector::isAmbiguous($second) || 1 === preg_match('/^[-+]?\d/', $second);

        return $isQuantity ? null : [0, 1];
    }
}
