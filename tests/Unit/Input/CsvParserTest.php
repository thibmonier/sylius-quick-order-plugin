<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Unit\Input;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult;
use Tmonier\SyliusQuickOrderPlugin\Adder\LineStatus;
use Tmonier\SyliusQuickOrderPlugin\Input\CsvParser;
use Tmonier\SyliusQuickOrderPlugin\Input\QuickOrderInput;

final class CsvParserTest extends TestCase
{
    public function testAdobeStyleHeaderWithBom(): void
    {
        $input = $this->parse("\u{FEFF}SKU,QTY\r\nABC-1,2\r\nABC-2,10\r\n");

        self::assertSame([['ABC-1', 2, [2]], ['ABC-2', 10, [3]]], $this->summary($input));
    }

    public function testSemicolonSeparatorAndColumnsInAnyOrder(): void
    {
        $input = $this->parse("Quantité;Description;Référence\n3;\"Blue; large\";ABC-1\n");

        self::assertSame([['ABC-1', 3, [2]]], $this->summary($input));
    }

    public function testTabSeparatorWithoutHeader(): void
    {
        $input = $this->parse("ABC-1\t4\nABC-2\t1\n");

        self::assertSame([['ABC-1', 4, [1]], ['ABC-2', 1, [2]]], $this->summary($input));
    }

    public function testACodeColumnWithoutQuantityColumnRefusesTheFile(): void
    {
        self::assertSame(BulkAddResult::REFUSED_CSV_COLUMNS, $this->parse("sku\nABC-1\nABC-2\n")->rejection);
        self::assertSame(BulkAddResult::REFUSED_CSV_COLUMNS, $this->parse("Description;SKU;Notes\nShirt;ABC;x\n")->rejection);
    }

    public function testAnEmptyQuantityIsAnErrorInAFile(): void
    {
        $input = $this->parse("SKU,QTY\nABC,\nDEF,2\nGHI\n");

        self::assertSame([['DEF', 2]], array_map(static fn (\Tmonier\SyliusQuickOrderPlugin\Input\QuickOrderLine $line): array => [$line->code, $line->quantity], $input->lines));
        self::assertSame([[2, LineStatus::InvalidQuantity], [4, LineStatus::InvalidQuantity]], array_map(static fn (\Tmonier\SyliusQuickOrderPlugin\Input\InvalidLine $line): array => [$line->lineNumber, $line->status], $input->invalidLines));
    }

    public function testTheHeaderIsTheFirstNonBlankRow(): void
    {
        self::assertSame([['ABC', 3, [4]]], $this->summary($this->parse("\n\nQTY;SKU\n3;ABC\n")));
    }

    public function testAnUnquotedThousandsGroupIsNeverReadAsTheQuantity(): void
    {
        $input = $this->parse("SKU,QTY\nABC,1,000\nDEF,2\n");

        self::assertSame([['DEF', 2]], array_map(static fn (\Tmonier\SyliusQuickOrderPlugin\Input\QuickOrderLine $line): array => [$line->code, $line->quantity], $input->lines));
        self::assertSame(LineStatus::AmbiguousQuantity, $input->invalidLines[0]->status);
        // Without header, code then quantity: the same.
        self::assertSame(LineStatus::AmbiguousQuantity, $this->parse("ABC,1,000\n")->invalidLines[0]->status);
        // A third column announced by the header is a column, not a group.
        self::assertSame([['ABC', 1, [2]]], $this->summary($this->parse("SKU,QTY,Price\nABC,1,000\n")));
    }

    public function testQuotedCodesWithSeparatorsAndBlankRows(): void
    {
        $input = $this->parse("SKU,QTY\n\"CODE, WITH COMMA\",2\n,\n\nABC,1\n");

        self::assertSame([['CODE, WITH COMMA', 2, [2]], ['ABC', 1, [5]]], $this->summary($input));
    }

    public function testWindows1252Headers(): void
    {
        $input = $this->parse(mb_convert_encoding("Référence;Quantité\nABC;12\n", 'Windows-1252', 'UTF-8'));

        self::assertSame([['ABC', 12, [2]]], $this->summary($input));
    }

    public function testHeadersWithUnknownNamesUsePositionalColumns(): void
    {
        foreach (["Product;Quantity\n", "EAN;Units\n", "Bestellnummer;Menge\n", "SKU;Pieces\n", "Item no;Pieces\n", "Pieces;QTY\n"] as $header) {
            self::assertSame([['ABC', 12, [2]]], $this->summary($this->parse($header . "ABC;12\n")), $header);
        }
        self::assertSame([['ABC', 12, [2]]], $this->summary($this->parse("Anzahl;Artikelnummer\n12;ABC\n")));
    }

    public function testCrOnlyLineEndings(): void
    {
        self::assertSame([['ABC', 1, [2]], ['DEF', 2, [3]], ['GHI', 3, [4]]], $this->summary($this->parse("SKU,QTY\rABC,1\rDEF,2\rGHI,3\r")));
    }

    public function testThousandsSeparatorsAreRefused(): void
    {
        $input = $this->parse("SKU,QTY\nABC,\"1,000\"\n");

        self::assertSame(LineStatus::AmbiguousQuantity, $input->invalidLines[0]->status);
    }

    public function testWindows1252Codes(): void
    {
        $input = $this->parse("SKU;QTY\n" . mb_convert_encoding('CAFÉ-1', 'Windows-1252', 'UTF-8') . ";2\n");

        self::assertSame('CAFÉ-1', $input->lines[0]->code);
    }

    public function testInvalidRowsAreReported(): void
    {
        $input = $this->parse("SKU,QTY\nABC,abc\n,3\n");

        self::assertSame([LineStatus::InvalidQuantity, LineStatus::InvalidLine], array_map(static fn (\Tmonier\SyliusQuickOrderPlugin\Input\InvalidLine $line): \Tmonier\SyliusQuickOrderPlugin\Adder\LineStatus => $line->status, $input->invalidLines));
        self::assertSame([2, 3], array_map(static fn (\Tmonier\SyliusQuickOrderPlugin\Input\InvalidLine $line): int => $line->lineNumber, $input->invalidLines));
    }

    public function testTooBigOrBinaryFilesAreRejected(): void
    {
        self::assertSame(BulkAddResult::REFUSED_FILE, (new CsvParser(200, 1024))->parse(str_repeat("ABC,1\n", 200))->rejection);
        self::assertSame(BulkAddResult::REFUSED_FILE, $this->parse("PK\x03\x04\0\0binary")->rejection);
        self::assertSame(BulkAddResult::REFUSED_FILE, (new CsvParser(200, 1024))->parseFile('/nonexistent/file.csv')->rejection);
    }

    public function testTooManyRows(): void
    {
        self::assertSame(BulkAddResult::REFUSED_TOO_MANY_LINES, (new CsvParser(2, 4096))->parse("SKU,QTY\nA,1\nB,1\nC,1\n")->rejection);
        self::assertNull((new CsvParser(2, 4096))->parse("SKU,QTY\nA,1\nB,1\n")->rejection);
    }

    public function testParseFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qo');
        self::assertIsString($path);
        file_put_contents($path, "SKU,QTY\nABC,2\n");

        try {
            self::assertSame([['ABC', 2, [2]]], $this->summary((new CsvParser(200, 4096))->parseFile($path)));
        } finally {
            unlink($path);
        }
    }

    private function parse(string $content): QuickOrderInput
    {
        return (new CsvParser(200, 1048576))->parse($content);
    }

    /** @return list<array{string, int, list<int>}> */
    private function summary(QuickOrderInput $input): array
    {
        self::assertSame([], $input->invalidLines);

        return array_map(static fn (\Tmonier\SyliusQuickOrderPlugin\Input\QuickOrderLine $line): array => [$line->code, $line->quantity, $line->lineNumbers], $input->lines);
    }
}
