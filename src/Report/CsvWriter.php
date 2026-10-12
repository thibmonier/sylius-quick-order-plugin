<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Report;

use Symfony\Contracts\Translation\TranslatorInterface;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult;
use Tmonier\SyliusQuickOrderPlugin\Adder\LineResult;

/**
 * CSV template and error report. Cells starting with "=", "+", "-", "@", TAB or CR are
 * prefixed with a quote so that spreadsheets do not run them as formulas.
 */
final class CsvWriter
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function template(): string
    {
        return self::write([['SKU', 'QTY'], ['EXAMPLE-SKU-1', '2'], ['EXAMPLE-SKU-2', '10']]);
    }

    /** Lines that were not added (errors and skipped lines), in their original order. */
    public function report(BulkAddResult $result): string
    {
        $rows = [['line', 'SKU', 'QTY', 'status', 'message']];
        foreach ($result->lines as $line) {
            if ($line->status->isError() || $line->addedQuantity < (int) $line->quantity) {
                $rows[] = [
                    implode(' ', $line->lineNumbers),
                    $line->code,
                    $line->requestedQuantity,
                    $line->status->value,
                    $this->translator->trans(...self::message($line)),
                ];
            }
        }

        return self::write($rows);
    }

    /** @return array{0: string, 1: array<string, string|int>, 2: string} */
    public static function message(LineResult $line): array
    {
        return [
            $line->getMessageKey(),
            ['%quantity%' => (string) $line->quantity, '%available%' => (string) ($line->available ?? 0), '%added%' => $line->addedQuantity],
            'messages',
        ];
    }

    public static function escape(string $value): string
    {
        return '' !== $value && \in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }

    /** @param list<list<string>> $rows */
    private static function write(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        \assert(false !== $handle);
        // UTF-8 BOM: spreadsheet applications detect the encoding.
        fwrite($handle, "\u{FEFF}");
        foreach ($rows as $row) {
            fputcsv($handle, array_map(self::escape(...), $row), ',', '"', '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
