<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Unit;

use PHPUnit\Framework\TestCase;

/** Functional and integration tests create the data they need (TestCatalog) instead of skipping on random fixtures. */
final class NoSkippedTestsTest extends TestCase
{
    public function testNoTestSkipsOnFixtures(): void
    {
        $offenders = [];
        foreach (['Functional', 'Integration'] as $suite) {
            foreach (glob(sprintf('%s/../%s/*.php', __DIR__, $suite)) ?: [] as $file) {
                if (str_contains((string) file_get_contents($file), 'markTestSkipped')) {
                    $offenders[] = basename($file);
                }
            }
        }

        self::assertSame([], $offenders);
    }
}
