<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Input;

use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult;
use Tmonier\SyliusQuickOrderPlugin\Adder\LineStatus;

final class QuickOrderInput
{
    /**
     * @param list<QuickOrderLine> $lines
     * @param list<InvalidLine> $invalidLines
     * @param string|null $rejection why the input is refused as a whole (BulkAddResult::INPUT_REFUSALS)
     */
    public function __construct(
        public readonly array $lines,
        public readonly array $invalidLines = [],
        public readonly ?string $rejection = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->lines && [] === $this->invalidLines;
    }

    public static function rejected(string $rejection): self
    {
        return new self([], [], $rejection);
    }

    /** Compact form kept in the session between the preview and the "add" step. */
    public function toJson(): string
    {
        return json_encode([
            'l' => array_map(static fn (QuickOrderLine $line): array => [$line->code, $line->quantity, $line->lineNumbers], $this->lines),
            'i' => array_map(static fn (InvalidLine $line): array => [$line->lineNumber, $line->code, $line->quantity, $line->status->value], $this->invalidLines),
        ], \JSON_THROW_ON_ERROR);
    }

    /** Re-validates a stored payload: lines, quantities and line numbers stay within the limits of a parsed input. */
    public static function fromJson(string $json, int $maxLines): self
    {
        $invalid = self::rejected(BulkAddResult::REFUSED_FILE);
        // A parsed input of max_lines lines never needs more than this.
        if (\strlen($json) > 64 + $maxLines * (LineCollector::MAX_CODE_LENGTH * 6 + 64)) {
            return $invalid;
        }

        try {
            $data = json_decode($json, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $invalid;
        }
        if (!\is_array($data) || !\is_array($data['l'] ?? null) || !\is_array($data['i'] ?? null)) {
            return $invalid;
        }

        $lineNumberCount = 0;
        $lines = [];
        foreach ($data['l'] as $line) {
            if (!\is_array($line) || !\is_string($line[0] ?? null) || !\is_int($line[1] ?? null) || !\is_array($line[2] ?? null)) {
                return $invalid;
            }
            $lineNumbers = array_values(array_filter($line[2], static fn (mixed $number): bool => \is_int($number) && $number > 0));
            $lineNumberCount += \count($line[2]);
            $code = trim($line[0]);
            if ('' === $code || mb_strlen($code) > LineCollector::MAX_CODE_LENGTH || $line[1] < 1 || $line[1] > LineCollector::MAX_QUANTITY || [] === $lineNumbers || \count($lineNumbers) !== \count($line[2])) {
                return $invalid;
            }
            $lines[] = new QuickOrderLine($code, $line[1], $lineNumbers);
        }

        $invalidLines = [];
        foreach ($data['i'] as $line) {
            if (!\is_array($line) || !\is_int($line[0] ?? null) || !\is_string($line[1] ?? null) || !\is_string($line[2] ?? null) || !\is_string($line[3] ?? null)) {
                return $invalid;
            }
            $status = LineStatus::tryFrom($line[3]);
            if (!\in_array($status, [LineStatus::InvalidLine, LineStatus::InvalidQuantity, LineStatus::UnknownCode], true)) {
                return $invalid;
            }
            ++$lineNumberCount;
            $invalidLines[] = new InvalidLine($line[0], mb_substr($line[1], 0, LineCollector::MAX_CODE_LENGTH), mb_substr($line[2], 0, 32), $status);
        }

        if ($lineNumberCount > $maxLines) {
            return self::rejected(BulkAddResult::REFUSED_TOO_MANY_LINES);
        }

        return new self($lines, $invalidLines);
    }
}
