<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Symfony 8 only autowires RateLimiterFactoryInterface: the concrete class must never be a type.
 */
final class RateLimiterTypeTest extends TestCase
{
    public function testSourcesNeverReferenceTheConcreteRateLimiterFactory(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/src'));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            if (1 === preg_match('/RateLimiterFactory(?!Interface)\b/', (string) file_get_contents($file->getPathname()))) {
                $offenders[] = $file->getPathname();
            }
        }

        self::assertSame([], $offenders);
    }
}
