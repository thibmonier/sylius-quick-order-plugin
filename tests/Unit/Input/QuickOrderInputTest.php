<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Unit\Input;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tmonier\SyliusQuickOrderPlugin\Adder\LineStatus;
use Tmonier\SyliusQuickOrderPlugin\Input\PasteParser;
use Tmonier\SyliusQuickOrderPlugin\Input\QuickOrderInput;

final class QuickOrderInputTest extends TestCase
{
    public function testTheJsonPayloadRoundTrips(): void
    {
        $input = (new PasteParser(200))->parse("ABC;2\nabc;1\nXYZ;zero\n;4");
        $copy = QuickOrderInput::fromJson($input->toJson(), 200);

        self::assertEquals($input, $copy);
        self::assertSame(LineStatus::InvalidQuantity, $copy->invalidLines[0]->status);
    }

    /** @return iterable<string, array{string}> */
    public static function tamperedPayloads(): iterable
    {
        yield 'not json' => ['{'];
        yield 'not an object' => ['"x"'];
        yield 'missing keys' => ['{"l": []}'];
        yield 'zero quantity' => ['{"l": [["ABC", 0, [1]]], "i": []}'];
        yield 'string quantity' => ['{"l": [["ABC", "2", [1]]], "i": []}'];
        yield 'empty code' => ['{"l": [[" ", 2, [1]]], "i": []}'];
        yield 'no line number' => ['{"l": [["ABC", 2, []]], "i": []}'];
        yield 'invalid line with a success status' => ['{"l": [], "i": [[1, "ABC", "2", "added"]]}'];
        yield 'deeply nested' => [str_repeat('[', 20) . str_repeat(']', 20)];
    }

    #[DataProvider('tamperedPayloads')]
    public function testTamperedPayloadsAreRejected(string $json): void
    {
        self::assertTrue(QuickOrderInput::fromJson($json, 200)->fileRejected);
    }

    public function testThePayloadIsLimitedLikeTheInput(): void
    {
        $json = (new PasteParser(200))->parse("A;1\nB;1\nC;1")->toJson();

        self::assertTrue(QuickOrderInput::fromJson($json, 2)->tooManyLines);
        self::assertCount(3, QuickOrderInput::fromJson($json, 3)->lines);
    }
}
