<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Unit\Input;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tmonier\SyliusQuickOrderPlugin\Adder\LineStatus;
use Tmonier\SyliusQuickOrderPlugin\Input\PasteParser;

final class PasteParserTest extends TestCase
{
    /** @return iterable<string, array{string, string, int}> */
    public static function separators(): iterable
    {
        yield 'semicolon' => ["ABC-1;3", 'ABC-1', 3];
        yield 'comma' => ["ABC-1,3", 'ABC-1', 3];
        yield 'tab (spreadsheet copy)' => ["ABC-1\t3", 'ABC-1', 3];
        yield 'tab with extra columns' => ["ABC-1\t3\tBlue T-shirt", 'ABC-1', 3];
        yield 'space before the quantity' => ['ABC-1 3', 'ABC-1', 3];
        yield 'code with spaces, space before the quantity' => ['BOX OF 100 SCREWS 4', 'BOX OF 100 SCREWS', 4];
        yield 'code with spaces and no quantity' => ['BOX OF SCREWS', 'BOX OF SCREWS', 1];
        yield 'no quantity' => ['ABC-1', 'ABC-1', 1];
        yield 'empty quantity' => ['ABC-1;', 'ABC-1', 1];
        yield 'spreadsheet decimal' => ['ABC-1;3,00', 'ABC-1', 3];
        yield 'spreadsheet decimal with dot' => ["ABC-1\t3.0", 'ABC-1', 3];
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

    public function testItMergesDuplicatesCaseInsensitively(): void
    {
        $input = (new PasteParser(200))->parse("ABC-1;2\nXYZ;1\nabc-1;3");

        self::assertCount(2, $input->lines);
        self::assertSame('ABC-1', $input->lines[0]->code);
        self::assertSame(5, $input->lines[0]->quantity);
        self::assertSame([1, 3], $input->lines[0]->lineNumbers);
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

        self::assertFalse($parser->parse("A;1\nB;1\nC;1")->isRejected());
        $input = $parser->parse("A;1\nB;1\nC;1\nD;1");
        self::assertTrue($input->tooManyLines);
        self::assertSame([], $input->lines);
    }

    public function testEmptyText(): void
    {
        self::assertTrue((new PasteParser(200))->parse(" \n\n ")->isEmpty());
    }
}
