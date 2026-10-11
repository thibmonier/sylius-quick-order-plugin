<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult;
use Tmonier\SyliusQuickOrderPlugin\Adder\LineStatus;

final class TranslationsTest extends TestCase
{
    private const LOCALES = ['en', 'fr', 'de', 'es', 'it', 'nl', 'pl'];

    private const DOMAINS = ['messages', 'flashes'];

    /** @return iterable<string, array{string, string}> */
    public static function catalogues(): iterable
    {
        foreach (self::DOMAINS as $domain) {
            foreach (self::LOCALES as $locale) {
                yield sprintf('%s.%s', $domain, $locale) => [$domain, $locale];
            }
        }
    }

    #[DataProvider('catalogues')]
    public function testEveryLocaleDefinesTheSameKeys(string $domain, string $locale): void
    {
        $reference = $this->flatten($this->parse($domain, 'en'));
        $catalogue = $this->flatten($this->parse($domain, $locale));

        self::assertSame([], array_values(array_diff(array_keys($reference), array_keys($catalogue))), 'Missing keys');
        self::assertSame([], array_values(array_diff(array_keys($catalogue), array_keys($reference))), 'Unknown keys');
        foreach ($catalogue as $key => $value) {
            self::assertNotSame('', trim($value), sprintf('Empty translation "%s"', $key));
            preg_match_all('/%\w+%/', $reference[$key], $expected);
            preg_match_all('/%\w+%/', $value, $actual);
            self::assertEqualsCanonicalizing($expected[0], $actual[0], sprintf('Placeholders of "%s"', $key));
        }
    }

    public function testEveryStatusAndRefusalHasTexts(): void
    {
        $messages = $this->flatten($this->parse('messages', 'en'));
        $flashes = $this->flatten($this->parse('flashes', 'en'));

        foreach (LineStatus::cases() as $status) {
            self::assertArrayHasKey('tmonier_quick_order.status.' . $status->value, $messages);
            self::assertArrayHasKey('tmonier_quick_order.status_label.' . $status->value, $messages);
        }
        foreach ([BulkAddResult::REFUSED_CART_LOCKED, BulkAddResult::REFUSED_TOO_MANY_LINES, BulkAddResult::REFUSED_FILE, BulkAddResult::REFUSED_EMPTY] as $refusal) {
            self::assertArrayHasKey('tmonier_quick_order.refusal.' . $refusal, $flashes);
        }
    }

    public function testEveryKeyUsedInTheCodeExists(): void
    {
        $known = array_keys($this->flatten($this->parse('messages', 'en')) + $this->flatten($this->parse('flashes', 'en')));
        $root = dirname(__DIR__, 2);
        $used = [];
        foreach (['src', 'templates', 'config'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || !in_array($file->getExtension(), ['php', 'twig', 'yaml'], true)) {
                    continue;
                }
                preg_match_all('/tmonier_quick_order\.(?:ui|status|status_label|refusal|lines_added|invalid_csrf_token)(?:\.[a-z_.]*[a-z_])?/', (string) file_get_contents($file->getPathname()), $matches);
                foreach ($matches[0] as $key) {
                    $used[$key] = true;
                }
            }
        }

        // Prefixes completed at runtime ('tmonier_quick_order.status.' ~ value) are skipped.
        $missing = array_filter(
            array_diff(array_keys($used), $known),
            static fn (string $key): bool => [] === array_filter($known, static fn (string $knownKey): bool => str_starts_with($knownKey, $key . '.')),
        );

        self::assertGreaterThan(25, count($used));
        self::assertSame([], array_values($missing));
    }

    /** @return array<mixed> */
    private function parse(string $domain, string $locale): array
    {
        $data = Yaml::parseFile(sprintf('%s/../../translations/%s.%s.yaml', __DIR__, $domain, $locale));
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, string>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $flat += $this->flatten($value, $prefix . $key . '.');

                continue;
            }

            $flat[$prefix . $key] = is_scalar($value) ? (string) $value : '';
        }

        return $flat;
    }
}
