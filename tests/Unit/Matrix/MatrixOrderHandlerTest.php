<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Unit\Matrix;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddMode;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult;
use Tmonier\SyliusQuickOrderPlugin\Adder\LineStatus;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixOrderHandler;

final class MatrixOrderHandlerTest extends TestCase
{
    public function testQuantitiesOfTheProductVariantsBecomeLines(): void
    {
        $product = MatrixFixtures::product('TEE', ['size' => ['S', 'M', 'L']], MatrixFixtures::channel());
        $s = MatrixFixtures::variant($product, ['S']);
        MatrixFixtures::variant($product, ['M']);
        $l = MatrixFixtures::variant($product, ['L']);

        // Another product's variant is ignored; empty cells are not posted by the browser.
        $input = (new MatrixOrderHandler($this->adder()))->input($product, [
            MatrixOrderHandler::key($s) => '3',
            MatrixOrderHandler::key($l) => '1.000',
            'v999999' => '5',
        ]);

        self::assertSame([['TEE-S', 3, [1]]], array_map(static fn ($line): array => [$line->code, $line->quantity, $line->lineNumbers], $input->lines));
        self::assertSame(LineStatus::AmbiguousQuantity, $input->invalidLines[0]->status);
        self::assertSame('TEE-L', $input->invalidLines[0]->code);
    }

    public function testZerosAndNonStringsAreIgnoredAndTheArrayIsBounded(): void
    {
        $product = MatrixFixtures::product('TEE', ['size' => ['S']], MatrixFixtures::channel());
        $s = MatrixFixtures::variant($product, ['S']);
        $handler = new MatrixOrderHandler($this->adder());

        self::assertTrue($handler->input($product, [MatrixOrderHandler::key($s) => '0'])->isEmpty());
        self::assertTrue($handler->input($product, [MatrixOrderHandler::key($s) => ['nested']])->isEmpty());
        self::assertSame(2, $handler->input($product, [MatrixOrderHandler::key($s) => 2])->lines[0]->quantity);
        self::assertSame(BulkAddResult::REFUSED_TOO_MANY_LINES, $handler->input($product, ['v1' => '1', 'v2' => '1'])->rejection);
        self::assertSame(BulkAddResult::REFUSED_TOO_MANY_LINES, $handler->add($product, ['v1' => '1', 'v2' => '1'], new Order(), BulkAddMode::Partial)->refusal);
    }

    public function testTheBulkAdderDoesTheRest(): void
    {
        $product = MatrixFixtures::product('TEE', ['size' => ['S']], MatrixFixtures::channel());
        $s = MatrixFixtures::variant($product, ['S']);
        $adder = $this->adder();

        $result = (new MatrixOrderHandler($adder))->add($product, [MatrixOrderHandler::key($s) => '4'], new Order(), BulkAddMode::AllOrNothing);

        self::assertTrue($result->applied);
        self::assertSame(BulkAddMode::AllOrNothing, $adder->mode);
        self::assertSame(4, $adder->input?->lines[0]->quantity);
    }

    private function adder(): RecordingBulkCartAdder
    {
        return new RecordingBulkCartAdder();
    }
}
