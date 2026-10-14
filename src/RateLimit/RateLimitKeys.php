<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\RateLimit;

/**
 * Rate limiter keys that the sender cannot multiply for free.
 */
final class RateLimitKeys
{
    /** IPv4 as is (also when mapped in IPv6), IPv6 grouped by /56. */
    public static function ip(?string $ip): string
    {
        if (null === $ip || '' === $ip) {
            return 'unknown';
        }
        $packed = @inet_pton($ip);
        if (false === $packed || 16 !== strlen($packed)) {
            return $ip;
        }
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 7) . str_repeat("\0", 9)) . '/56';
    }

    private function __construct()
    {
    }
}
