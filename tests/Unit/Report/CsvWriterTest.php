<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Unit\Report;

use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult;
use Tmonier\SyliusQuickOrderPlugin\Adder\LineResult;
use Tmonier\SyliusQuickOrderPlugin\Adder\LineStatus;
use Tmonier\SyliusQuickOrderPlugin\Report\CsvWriter;

final class CsvWriterTest extends TestCase
{
    public function testFormulasAreNeutralised(): void
    {
        foreach (['=1+1', '+1', '-1', '@SUM(A1)', "\tX", "\rX"] as $value) {
            self::assertSame("'" . $value, CsvWriter::escape($value));
        }
        self::assertSame('ABC-1', CsvWriter::escape('ABC-1'));
        self::assertSame('', CsvWriter::escape(''));
    }

    public function testTheReportListsLinesThatWereNotAdded(): void
    {
        $result = new BulkAddResult([
            new LineResult('OK-1', '2', 2, LineStatus::Added, [1], addedQuantity: 2),
            new LineResult('=HYPERLINK("http://evil")', '1', 1, LineStatus::UnknownCode, [2]),
            new LineResult('LOW', '5', 5, LineStatus::InsufficientStock, [3, 4], available: 3),
            new LineResult('HALF', '4', 4, LineStatus::Adjusted, [5], addedQuantity: 2),
        ], true);

        $csv = (new CsvWriter($this->translator()))->report($result);
        $rows = array_map(static fn (string $row): array => str_getcsv($row, ',', '"', ''), explode("\n", trim(substr($csv, 3))));

        self::assertStringStartsWith("\u{FEFF}", $csv);
        self::assertSame(['line', 'SKU', 'QTY', 'status', 'message'], $rows[0]);
        self::assertSame(['2', '\'=HYPERLINK("http://evil")', '1', 'unknown_code', 'tmonier_quick_order.status.unknown_code'], $rows[1]);
        self::assertSame(['3 4', 'LOW', '5', 'insufficient_stock', 'tmonier_quick_order.status.insufficient_stock|3'], $rows[2]);
        self::assertSame('adjusted', $rows[3][3]);
        self::assertCount(4, $rows);
    }

    public function testTheTemplateHasTheAdobeStyleHeader(): void
    {
        self::assertStringStartsWith("\u{FEFF}SKU,QTY\n", (new CsvWriter($this->translator()))->template());
    }

    private function translator(): TranslatorInterface
    {
        return new class() implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                $available = $parameters['%available%'] ?? '';

                return 'tmonier_quick_order.status.insufficient_stock' === $id && \is_string($available) ? $id . '|' . $available : $id;
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };
    }
}
