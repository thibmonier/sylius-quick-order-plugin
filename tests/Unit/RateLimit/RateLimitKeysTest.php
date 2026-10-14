<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Unit\RateLimit;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusQuickOrderPlugin\RateLimit\RateLimitKeys;

final class RateLimitKeysTest extends TestCase
{
    public function testAnIpv6NetworkIsOneKey(): void
    {
        self::assertSame(RateLimitKeys::ip('2001:db8:1:1::1'), RateLimitKeys::ip('2001:db8:1:ff::1'), 'Same /56.');
        self::assertNotSame(RateLimitKeys::ip('2001:db8:1:1::1'), RateLimitKeys::ip('2001:db8:1:100::1'));
        self::assertSame('2001:db8:1::/56', RateLimitKeys::ip('2001:db8:1:1::1'));
        self::assertSame('203.0.113.7', RateLimitKeys::ip('203.0.113.7'));
        self::assertSame('203.0.113.5', RateLimitKeys::ip('::ffff:203.0.113.5'));
        self::assertSame('unknown', RateLimitKeys::ip(null));
        self::assertSame('unknown', RateLimitKeys::ip(''));
    }
}
