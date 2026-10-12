<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Unit\Input;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult;
use Tmonier\SyliusQuickOrderPlugin\Adder\LineStatus;
use Tmonier\SyliusQuickOrderPlugin\Input\PasteParser;

final class PasteParserTest extends TestCase
{
    /** @return iterable<string, array{string, string, int}> */
    public static function separators(): iterable
    {
        yield 'semicolon' => ['ABC-1;3', 'ABC-1', 3];
        yield 'comma' => ['ABC-1,3', 'ABC-1', 3];
        yield 'tab (spreadsheet copy)' => ["ABC-1\t3", 'ABC-1', 3];
        yield 'tab with extra columns' => ["ABC-1\t3\tBlue T-shirt", 'ABC-1', 3];
        yield 'space before the quantity' => ['ABC-1 3', 'ABC-1', 3];
        yield 'code with spaces, space before the quantity' => ['BOX OF 100 SCREWS 4', 'BOX OF 100 SCREWS', 4];
        yield 'code with spaces and no quantity' => ['BOX OF SCREWS', 'BOX OF SCREWS', 1];
        yield 'no quantity' => ['ABC-1', 'ABC-1', 1];
        yield 'empty quantity' => ['ABC-1;', 'ABC-1', 1];
        yield 'leading zeros' => ['ABC-1;007', 'ABC-1', 7];
        yield 'quoted cells' => ["\"ABC-1\"\t\"3\"", 'ABC-1', 3];
        yield 'surrounding blanks and non-breaking space' => ["  ABC-1 ;\u{A0}3  ", 'ABC-1', 3];
        yield 'Windows line endings' => ["ABC-1;3\r\n", 'ABC-1', 3];
    }

    #[DataProvider('separators')]
    public function testItReadsALine(string $text, string $code, int $quantity): void
    {
        $input = (new PasteParser(200))->parse($text);

        self::assertSame([], $input->invalidLines);
        self::assertCount(1, $input->lines);
        self::assertSame($code, $input->lines[0]->code);
        self::assertSame($quantity, $input->lines[0]->quantity);
    }

    public function testItSkipsBlankLinesAndAHeaderAndKeepsLineNumbers(): void
    {
        $input = (new PasteParser(200))->parse("SKU\tQTY\n\nABC-1\t2\n   \nABC-2\t5\n");

        self::assertSame(['ABC-1', 'ABC-2'], array_map(static fn ($line) => $line->code, $input->lines));
        self::assertSame([3], $input->lines[0]->lineNumbers);
        self::assertSame([5], $input->lines[1]->lineNumbers);
    }

    public function testAHeaderIsOnlySkippedOnTheFirstLine(): void
    {
        $input = (new PasteParser(200))->parse("ABC-1;1\nSKU;QTY");

        self::assertCount(1, $input->lines);
        self::assertSame(LineStatus::InvalidQuantity, $input->invalidLines[0]->status);
    }

    public function testACodeCalledLikeAHeaderWithAQuantityIsKept(): void
    {
        $input = (new PasteParser(200))->parse('CODE;4');

        self::assertSame('CODE', $input->lines[0]->code);
        self::assertSame(4, $input->lines[0]->quantity);
    }

    public function testItMergesExactDuplicatesOnly(): void
    {
        $input = (new PasteParser(200))->parse("ABC-1;2\nXYZ;1\nABC-1;3\nabc-1;4");

        // Codes differing in case are merged later, if they resolve to the same variant.
        self::assertSame([['ABC-1', 5, [1, 3]], ['XYZ', 1, [2]], ['abc-1', 4, [4]]], array_map(static fn ($line): array => [$line->code, $line->quantity, $line->lineNumbers], $input->lines));
    }

    /** @return iterable<string, array{string, int}> */
    public static function spreadsheetDecimals(): iterable
    {
        yield 'one zero' => ['ABC;5.0', 5];
        yield 'two zeros, comma' => ['ABC;12,00', 12];
        yield 'two zeros, tab' => ["ABC\t10.00", 10];
    }

    #[DataProvider('spreadsheetDecimals')]
    public function testZeroFractionsAreWholeNumbers(string $text, int $quantity): void
    {
        self::assertSame($quantity, (new PasteParser(200))->parse($text)->lines[0]->quantity);
    }

    /** @return iterable<string, array{string, LineStatus}> */
    public static function unreadableQuantities(): iterable
    {
        yield 'German thousands' => ['ABC;1.000', LineStatus::AmbiguousQuantity];
        yield 'English thousands, quoted' => ['ABC;"1,000"', LineStatus::AmbiguousQuantity];
        yield 'English thousands, comma separator' => ['ABC,1,000', LineStatus::AmbiguousQuantity];
        yield 'code, quantity, 3-digit price' => ['ABC-1,2,100', LineStatus::AmbiguousQuantity];
        yield 'space thousands' => ["ABC\t1 000", LineStatus::AmbiguousQuantity];
        yield 'millions' => ['ABC;1.000.000', LineStatus::AmbiguousQuantity];
        yield 'non-zero fraction' => ['ABC;12.5', LineStatus::InvalidQuantity];
        yield 'three zeros fraction is a group' => ['ABC;12,000', LineStatus::AmbiguousQuantity];
    }

    #[DataProvider('unreadableQuantities')]
    public function testQuantitiesWithSeparatorsAreRefusedNotGuessed(string $text, LineStatus $status): void
    {
        $input = (new PasteParser(200))->parse($text);

        self::assertSame([], $input->lines);
        self::assertSame($status, $input->invalidLines[0]->status);
    }

    public function testExtraCellsAfterTheQuantityAreIgnored(): void
    {
        $input = (new PasteParser(200))->parse("ABC-1,2,19.90\nABC-2,3,Blue shirt\nABC-3;4;100");

        self::assertSame([['ABC-1', 2], ['ABC-2', 3], ['ABC-3', 4]], array_map(static fn ($line): array => [$line->code, $line->quantity], $input->lines));
    }

    public function testCrOnlyLineEndings(): void
    {
        self::assertSame([1, 2, 4], array_map(static fn ($line): int => $line->lineNumbers[0], (new PasteParser(200))->parse("A;1\rB;2\r\rC;3")->lines));
    }

    public function testTooLongTextsAreRefusedBeforeParsing(): void
    {
        self::assertSame(BulkAddResult::REFUSED_TEXT_TOO_LONG, (new PasteParser(1000, 1000))->parse(str_repeat("ab\n", 400))->rejection);
        self::assertNull((new PasteParser(1000, 1000))->parse(str_repeat("ab\n", 300))->rejection);
    }

    public function testHugeQuantitiesAreRefused(): void
    {
        $input = (new PasteParser(200))->parse("A;999999999\nB;1000000000");

        self::assertSame(999999999, $input->lines[0]->quantity);
        self::assertSame(LineStatus::InvalidQuantity, $input->invalidLines[0]->status);
    }

    /** @return iterable<string, array{string, LineStatus}> */
    public static function invalidLines(): iterable
    {
        yield 'zero' => ['ABC;0', LineStatus::InvalidQuantity];
        yield 'negative' => ['ABC;-2', LineStatus::InvalidQuantity];
        yield 'decimal' => ['ABC;2.5', LineStatus::InvalidQuantity];
        yield 'text' => ['ABC;two', LineStatus::InvalidQuantity];
        yield 'too big' => ['ABC;12345678901', LineStatus::InvalidQuantity];
        yield 'negative after a space' => ['ABC -2', LineStatus::InvalidQuantity];
        yield 'no code' => [';3', LineStatus::InvalidLine];
        yield 'code too long' => [str_repeat('A', 256) . ';1', LineStatus::InvalidLine];
    }

    #[DataProvider('invalidLines')]
    public function testItReportsInvalidLines(string $text, LineStatus $status): void
    {
        $input = (new PasteParser(200))->parse("OK;1\n" . $text);

        self::assertCount(1, $input->lines);
        self::assertCount(1, $input->invalidLines);
        self::assertSame($status, $input->invalidLines[0]->status);
        self::assertSame(2, $input->invalidLines[0]->lineNumber);
    }

    public function testItRefusesTooManyLines(): void
    {
        $parser = new PasteParser(3);

        self::assertNull($parser->parse("A;1\nB;1\nC;1")->rejection);
        $input = $parser->parse("A;1\nB;1\nC;1\nD;1" . str_repeat("\nE;1", 1000));
        self::assertSame(BulkAddResult::REFUSED_TOO_MANY_LINES, $input->rejection);
        self::assertSame([], $input->lines);
    }

    public function testEmptyText(): void
    {
        self::assertTrue((new PasteParser(200))->parse(" \n\n ")->isEmpty());
    }
}
