<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Functional;

use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Tmonier\SyliusQuickOrderPlugin\Matrix\MatrixOrderHandler;

final class ShopMatrixTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use QuickOrderTestTrait;

    public function testAMatrixProductShowsTheGridInsteadOfTheCoreForm(): void
    {
        $variants = $this->catalog->teeMatrix();
        $crawler = $this->client->request('GET', $this->productUrl($variants));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-test-tmonier-quick-order-matrix][data-layout="grid"]');
        self::assertSelectorNotExists('[data-test-product-selecting-variant]');
        $grid = $crawler->filter('[data-test-tmonier-quick-order-matrix-grid]');
        self::assertSame(['RED', 'BLUE', 'BLACK'], $grid->filter('thead th[scope="col"]')->each(static fn (Crawler $th): string => $th->text()));
        self::assertSame(['S', 'M', 'L', 'XL'], $grid->filter('tbody th[scope="row"]')->each(static fn (Crawler $th): string => $th->text()));
        // 9 orderable cells (XL: no price, disabled, out of stock), each with an accessible name.
        $inputs = $grid->filter('input[type="number"]');
        self::assertCount(9, $inputs);
        self::assertSame('S / RED', $inputs->first()->attr('aria-label'));
        $prefix = 'QO-' . $this->catalog->suffix;
        self::assertSelectorTextContains('[data-test-tmonier-quick-order-matrix-unavailable="' . $prefix . '-XL-BLACK"]', 'Out of stock');
        self::assertSelectorTextContains('[data-test-tmonier-quick-order-matrix-unavailable="' . $prefix . '-XL-RED"]', 'Not available');
        self::assertSelectorNotExists('[data-test-tmonier-quick-order-matrix-input="' . $prefix . '-XL-BLUE"]');
        self::assertSelectorTextContains('[data-test-tmonier-quick-order-matrix-estimate]', 'Estimate, before cart promotions and shipping');
        // Never a stock count.
        self::assertStringNotContainsString('100', $grid->text());
    }

    public function testOtherProductsKeepTheCoreForm(): void
    {
        $variant = $this->catalog->variant('QO-SIMPLE-' . $this->catalog->suffix);

        $this->client->request('GET', $this->url('/products/' . $variant->getProduct()?->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-test-product-selecting-variant]');
        self::assertSelectorNotExists('[data-test-tmonier-quick-order-matrix]');
    }

    public function testWithoutJavascriptTheFormPostsToTheFallbackRoute(): void
    {
        $variants = $this->catalog->teeMatrix();
        $prefix = 'QO-' . $this->catalog->suffix;
        $crawler = $this->client->request('GET', $this->productUrl($variants));
        $form = $crawler->filter('[data-test-tmonier-quick-order-matrix-form]')->form();
        $form['quantities[' . MatrixOrderHandler::key($variants[$prefix . '-S-RED']) . ']'] = '3';
        $form['quantities[' . MatrixOrderHandler::key($variants[$prefix . '-L-BLACK']) . ']'] = '2';

        $this->client->submit($form);

        self::assertResponseRedirects();
        self::assertStringContainsString('/cart', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame([$prefix . '-L-BLACK' => 2, $prefix . '-S-RED' => 3], self::quantities($this->sessionCart()));

        // Errors go to the quick order result page.
        $crawler = $this->client->request('GET', $this->productUrl($variants));
        $form = $crawler->filter('[data-test-tmonier-quick-order-matrix-form]')->form();
        $form['quantities[' . MatrixOrderHandler::key($variants[$prefix . '-M-RED']) . ']'] = '1.000';
        $this->client->submit($form);
        self::assertResponseRedirects($this->url('/quick-order/result'));
        $this->client->followRedirect();
        self::assertSelectorExists('[data-test-tmonier-quick-order-line][data-status="ambiguous_quantity"]');
    }

    public function testTheFallbackRouteOnlyServesMatrixProducts(): void
    {
        $simple = $this->catalog->variant('QO-SIMPLE-' . $this->catalog->suffix);
        $this->client->request('POST', $this->url('/quick-order/products/' . self::id($simple->getProduct()?->getId()) . '/matrix'), ['quantities' => [MatrixOrderHandler::key($simple) => '1']]);
        self::assertResponseStatusCodeSame(404);

        $variants = $this->catalog->teeMatrix();
        $product = self::first($variants)->getProduct();
        $this->client->request('POST', $this->url('/quick-order/products/' . self::id($product?->getId()) . '/matrix'), ['_csrf_token' => 'forged', 'quantities' => []]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-flash-messages]', 'Your session has expired');
    }

    public function testTheLiveComponentAddsEverythingAndEmitsCartChanged(): void
    {
        $variants = $this->catalog->teeMatrix();
        $prefix = 'QO-' . $this->catalog->suffix;
        $this->client->request('GET', $this->productUrl($variants));
        $component = $this->createLiveComponent('tmonier_quick_order:product:matrix', ['product' => self::first($variants)->getProduct()], $this->client)
            ->setRouteLocale((string) $this->catalog->channel()->getDefaultLocale()?->getCode());

        $component
            ->set('quantities.' . MatrixOrderHandler::key($variants[$prefix . '-S-RED']), '4')
            ->set('quantities.' . MatrixOrderHandler::key($variants[$prefix . '-M-BLUE']), '2')
            // A variant of another product, posted by a tampered browser, is ignored.
            ->set('quantities.' . MatrixOrderHandler::key($this->catalog->variant('QO-OTHER-' . $this->catalog->suffix)), '9')
            ->call('add');

        $this->assertComponentEmitEvent($component, 'sylius:shop:cart_changed');
        self::assertStringContainsString('2 line(s) added to your cart', $component->render()->toString());
        self::assertSame([$prefix . '-M-BLUE' => 2, $prefix . '-S-RED' => 4], self::quantities($this->sessionCart()));
    }

    public function testTheLiveComponentReportsLineErrorsWithoutEmitting(): void
    {
        $variants = $this->catalog->teeMatrix();
        $prefix = 'QO-' . $this->catalog->suffix;
        $this->client->request('GET', $this->productUrl($variants));
        $component = $this->createLiveComponent('tmonier_quick_order:product:matrix', ['product' => self::first($variants)->getProduct()], $this->client)
            ->setRouteLocale((string) $this->catalog->channel()->getDefaultLocale()?->getCode());

        $component
            ->set('allOrNothing', true)
            ->set('quantities.' . MatrixOrderHandler::key($variants[$prefix . '-S-RED']), '1')
            ->set('quantities.' . MatrixOrderHandler::key($variants[$prefix . '-M-RED']), '500')
            ->call('add');

        $this->assertComponentNotEmitEvent($component, 'sylius:shop:cart_changed');
        $html = $component->render()->toString();
        self::assertStringContainsString('Nothing was added to your cart', $html);
        self::assertStringContainsString('Not enough stock for this quantity.', $html);
        self::assertNull($this->sessionCart());
    }

    /** @param array<string, ProductVariantInterface> $variants */
    private static function first(array $variants): ProductVariantInterface
    {
        $variant = reset($variants);
        self::assertInstanceOf(ProductVariantInterface::class, $variant);

        return $variant;
    }

    private static function id(mixed $id): string
    {
        self::assertIsInt($id);

        return (string) $id;
    }

    /** @param array<string, ProductVariantInterface> $variants */
    private function productUrl(array $variants): string
    {
        return $this->url('/products/' . self::first($variants)->getProduct()?->getSlug());
    }
}
