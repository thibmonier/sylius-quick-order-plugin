<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Tests\Tmonier\SyliusQuickOrderPlugin\Unit\Matrix\MatrixFixtures;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixActivationCheckerInterface;
use Tmonier\SyliusQuickOrderPlugin\Twig\QuickOrderExtension;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/** The tier price table under the matrix only when it is the same for every variant. */
final class MatrixTierPricesTemplateTest extends TestCase
{
    public function testTheTableIsShownWhenEveryVariantHasTheSameTiers(): void
    {
        $html = $this->render(['S' => [[1, 1000], [10, 900]], 'M' => [[1, 1000], [10, 900]]]);

        self::assertStringContainsString('TIER TABLE', $html);
        self::assertStringNotContainsString('tier_prices_vary', $html);
    }

    public function testANoteReplacesTheTableWhenTiersDiffer(): void
    {
        // Percentage tiers of different variant prices, or variant rules.
        $html = $this->render(['S' => [[1, 1500], [10, 1350]], 'M' => [[1, 1700], [10, 1530]]]);

        self::assertStringNotContainsString('TIER TABLE', $html);
        self::assertStringContainsString('tmonier_quick_order.ui.matrix.tier_prices_vary', $html);

        // Tiers on some variants only: the note too.
        self::assertStringContainsString('tier_prices_vary', $this->render(['S' => [[1, 1000], [10, 900]], 'M' => null]));
    }

    public function testNothingIsShownWithoutTiersOrWithoutTheTierPlugin(): void
    {
        self::assertSame('', trim($this->render(['S' => null, 'M' => null])));
        self::assertSame('', trim($this->render(['S' => [[1, 1000], [10, 900]]], false)));
    }

    /** @param array<string, list<array{int, int}>|null> $tiers tiers by size value code */
    private function render(array $tiers, bool $tierPlugin = true): string
    {
        $product = MatrixFixtures::product('P', ['size' => array_keys($tiers)], MatrixFixtures::channel());
        foreach (array_keys($tiers) as $size) {
            MatrixFixtures::variant($product, [$size]);
        }

        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 3) . '/templates', 'TmonierSyliusQuickOrderPlugin');
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['@TmonierSyliusTierPricingPlugin/shop/product/tier_prices.html.twig' => 'TIER TABLE']),
            $loader,
        ]));
        $twig->addExtension(new TranslationExtension());
        $twig->addExtension(new QuickOrderExtension(new class() implements MatrixActivationCheckerInterface {
            public function isMatrixProduct(ProductInterface $product): bool
            {
                return true;
            }
        }));
        $twig->addFunction(new TwigFunction('sylius_test_html_attribute', static fn (): string => '', ['is_safe' => ['html']]));
        if (!$tierPlugin) {
            return $twig->render('@TmonierSyliusQuickOrderPlugin/shop/product/matrix/tier_prices.html.twig', [
                'hookable_metadata' => ['context' => ['product' => $product, 'form' => null]],
            ]);
        }
        $twig->addFunction(new TwigFunction('tmonier_tier_pricing_table', static function (ProductVariantInterface $variant) use ($tiers): ?object {
            $size = substr((string) $variant->getCode(), \strlen('P-'));
            $rows = $tiers[$size] ?? null;

            return null === $rows ? null : (object) ['rows' => array_map(static fn (array $row): object => (object) ['minQuantity' => $row[0], 'unitPrice' => $row[1]], $rows)];
        }));

        return $twig->render('@TmonierSyliusQuickOrderPlugin/shop/product/matrix/tier_prices.html.twig', [
            'hookable_metadata' => ['context' => ['product' => $product, 'form' => null]],
        ]);
    }
}
