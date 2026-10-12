<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Tmonier\SyliusQuickOrderPlugin\DependencyInjection\Configuration;

final class ConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        self::assertSame([
            'max_lines' => 200,
            'max_total_quantity' => 20000,
            'expose_available_stock' => false,
            'max_file_size' => 1048576,
            'default_mode' => 'partial',
            'allow_guests' => true,
            'quantity_rounding' => 'reject',
        ], (new Processor())->processConfiguration(new Configuration(), []));
    }

    public function testInvalidValuesAreRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [['default_mode' => 'everything']]);
    }
}
