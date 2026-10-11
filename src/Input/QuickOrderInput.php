<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Input;

use Tmonier\SyliusQuickOrderPlugin\Adder\LineStatus;

final class QuickOrderInput
{
    /**
     * @param list<QuickOrderLine> $lines
     * @param list<InvalidLine> $invalidLines
     */
    public function __construct(
        public readonly array $lines,
        public readonly array $invalidLines = [],
        public readonly bool $tooManyLines = false,
        public readonly bool $fileRejected = false,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->lines && [] === $this->invalidLines;
    }

    /** Whether the input as a whole has to be refused (too many lines, unreadable file). */
    public function isRejected(): bool
    {
        return $this->tooManyLines || $this->fileRejected;
    }

    public static function fileRejected(): self
    {
        return new self([], [], false, true);
    }

    /** Compact form carried by the preview page to the "add" step. */
    public function toJson(): string
    {
        return json_encode([
            'l' => array_map(static fn (QuickOrderLine $line): array => [$line->code, $line->quantity, $line->lineNumbers], $this->lines),
            'i' => array_map(static fn (InvalidLine $line): array => [$line->lineNumber, $line->code, $line->quantity, $line->status->value], $this->invalidLines),
        ], \JSON_THROW_ON_ERROR);
    }

    /** Re-validates the payload posted back by the client: it is as untrusted as the original input. */
    public static function fromJson(string $json, int $maxLines): self
    {
        try {
            $data = json_decode($json, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::fileRejected();
        }
        if (!\is_array($data) || !\is_array($data['l'] ?? null) || !\is_array($data['i'] ?? null)) {
            return self::fileRejected();
        }
        if (\count($data['l']) + \count($data['i']) > $maxLines) {
            return new self([], [], true);
        }

        $lines = [];
        foreach ($data['l'] as $line) {
            if (!\is_array($line) || !\is_string($line[0] ?? null) || !\is_int($line[1] ?? null) || !\is_array($line[2] ?? null)) {
                return self::fileRejected();
            }
            $lineNumbers = array_values(array_filter($line[2], 'is_int'));
            $code = trim($line[0]);
            if ('' === $code || mb_strlen($code) > LineCollector::MAX_CODE_LENGTH || $line[1] < 1 || $line[1] > 999999999 || [] === $lineNumbers) {
                return self::fileRejected();
            }
            $lines[] = new QuickOrderLine($code, $line[1], $lineNumbers);
        }

        $invalidLines = [];
        foreach ($data['i'] as $line) {
            if (!\is_array($line) || !\is_int($line[0] ?? null) || !\is_string($line[1] ?? null) || !\is_string($line[2] ?? null) || !\is_string($line[3] ?? null)) {
                return self::fileRejected();
            }
            $status = LineStatus::tryFrom($line[3]);
            if (!\in_array($status, [LineStatus::InvalidLine, LineStatus::InvalidQuantity, LineStatus::UnknownCode], true)) {
                return self::fileRejected();
            }
            $invalidLines[] = new InvalidLine($line[0], mb_substr($line[1], 0, LineCollector::MAX_CODE_LENGTH), mb_substr($line[2], 0, 32), $status);
        }

        return new self($lines, $invalidLines);
    }
}
