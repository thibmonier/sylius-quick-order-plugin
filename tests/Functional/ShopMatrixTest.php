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
        $inputs = $grid->filter('input[data-model]');
        self::assertCount(9, $inputs);
        self::assertSame('S / RED', $inputs->first()->attr('aria-label'));
        $prefix = 'QO-' . $this->catalog->suffix;
        self::assertSelectorTextContains('[data-test-tmonier-quick-order-matrix-unavailable="' . $prefix . '-XL-BLACK"]', 'Out of stock');
        self::assertSelectorTextContains('[data-test-tmonier-quick-order-matrix-unavailable="' . $prefix . '-XL-RED"]', 'Not available');
        self::assertSelectorNotExists('[data-test-tmonier-quick-order-matrix-input="' . $prefix . '-XL-BLUE"]');
        self::assertSelectorTextContains('[data-test-tmonier-quick-order-matrix-estimate]', 'taxes not included in prices');
        // Text inputs: an unreadable "1,000" reaches the server instead of being emptied by the browser.
        self::assertSame('text', $inputs->first()->attr('type'));
        self::assertSame('numeric', $inputs->first()->attr('inputmode'));
        // Prices (catalog calculator) and never a stock count (onHand is 100): cells only.
        self::assertSelectorTextContains('[data-test-tmonier-quick-order-matrix-price="' . $prefix . '-S-RED"]', '15.00');
        self::assertSelectorTextContains('[data-test-tmonier-quick-order-matrix-price="' . $prefix . '-L-RED"]', '17.00');
        // Body cells only: the header cell holds the option names (random suffix).
        foreach ($grid->filter('tbody td')->each(static fn (Crawler $td): string => $td->text()) as $cell) {
            self::assertStringNotContainsString('100', $cell);
        }
        // A disabled variant is announced, not only drawn as a dash.
        self::assertStringContainsString('Not available', $grid->filter('td.bg-body-tertiary')->text());
        self::assertSame('true', $grid->filter('td.bg-body-tertiary span')->first()->attr('aria-hidden'));
        // The "all or nothing" box is explained and has no fixed value (it can be unchecked with UX Live).
        $box = $crawler->filter('[data-test-tmonier-quick-order-matrix-all-or-nothing]');
        self::assertNull($box->attr('value'));
        self::assertStringContainsString('Nothing is added unless every line can be added.', $crawler->filter('#' . $box->attr('aria-describedby'))->text());
        // The absolute hooks of the matrix page work, with their context.
        $productCode = (string) $this->first($variants)->getProduct()?->getCode();
        self::assertSelectorTextContains('[data-test-matrix-extra="' . $productCode . '"]', 'grid');
        self::assertSelectorExists('[data-test-add-to-cart-extra]');
    }

    public function testStruckAndOmnibusPricesFollowTheCoreRules(): void
    {
        $variants = $this->catalog->teeMatrix();
        $prefix = 'QO-' . $this->catalog->suffix;
        $channel = $this->catalog->channel();
        $channel->getChannelPriceHistoryConfig()?->setLowestPriceForDiscountedProductsVisible(true);
        $channel->getChannelPriceHistoryConfig()?->clearTaxonsExcludedFromShowingLowestPrice();
        $discounted = $variants[$prefix . '-S-RED']->getChannelPricingForChannel($channel);
        $discounted?->setOriginalPrice(2000);
        $discounted?->setLowestPriceBeforeDiscount(1400);
        // A lowest price without discount is never shown.
        $variants[$prefix . '-M-RED']->getChannelPricingForChannel($channel)?->setLowestPriceBeforeDiscount(1200);
        $this->entityManager()->flush();

        $this->client->request('GET', $this->productUrl($variants));

        self::assertSelectorTextContains('[data-test-tmonier-quick-order-matrix-price="' . $prefix . '-S-RED"] del', '20.00');
        // The core price history recomputes the lowest price on flush: only its presence is checked.
        self::assertSelectorTextContains('[data-test-tmonier-quick-order-matrix-lowest-price="' . $prefix . '-S-RED"]', 'lowest price');
        self::assertSelectorNotExists('[data-test-tmonier-quick-order-matrix-price="' . $prefix . '-M-RED"] del');
        self::assertSelectorNotExists('[data-test-tmonier-quick-order-matrix-lowest-price="' . $prefix . '-M-RED"]');
    }

    public function testAMatrixWithoutAnyOrderableVariantIsNotCalledOutOfStock(): void
    {
        // Forced by matrix.products (test configuration), every variant disabled.
        $variants = $this->catalog->teeMatrix('QO_TEE_FORCED');
        foreach ($variants as $variant) {
            $variant->setEnabled(false);
        }
        $this->entityManager()->flush();

        $this->client->request('GET', $this->productUrl($variants));

        self::assertSelectorTextContains('[data-test-tmonier-quick-order-matrix-empty]', 'Not available');
        self::assertSelectorTextNotContains('[data-test-tmonier-quick-order-matrix-empty]', 'Out of stock');
    }

    public function testProductsAboveMaxVariantsKeepTheCoreForm(): void
    {
        $variants = $this->catalog->teeMatrix();
        $prefix = 'QO-' . $this->catalog->suffix;
        // 12 enabled variants > matrix.max_variants (11 in the test application).
        $variants[$prefix . '-XL-BLUE']->setEnabled(true);
        $pricing = self::getContainer()->get('sylius.factory.channel_pricing');
        self::assertInstanceOf(\Sylius\Resource\Factory\FactoryInterface::class, $pricing);
        $price = $pricing->createNew();
        self::assertInstanceOf(\Sylius\Component\Core\Model\ChannelPricingInterface::class, $price);
        $price->setChannelCode($this->catalog->channel()->getCode());
        $price->setPrice(1800);
        $variants[$prefix . '-XL-RED']->addChannelPricing($price);
        $this->entityManager()->flush();

        $this->client->request('GET', $this->productUrl($variants));

        self::assertSelectorExists('[data-test-product-selecting-variant]');
        self::assertSelectorNotExists('[data-test-tmonier-quick-order-matrix]');
    }

    public function testTheLiveComponentAddsNothingOnceTheProductIsDisabled(): void
    {
        $variants = $this->catalog->teeMatrix();
        $prefix = 'QO-' . $this->catalog->suffix;
        $this->client->request('GET', $this->productUrl($variants));
        $product = $this->first($variants)->getProduct();
        $component = $this->createLiveComponent('tmonier_quick_order:product:matrix', ['product' => $product], $this->client)
            ->setRouteLocale((string) $this->catalog->channel()->getDefaultLocale()?->getCode())
            ->set('quantities.' . MatrixOrderHandler::key($variants[$prefix . '-S-RED']), '2');

        $reloaded = $this->entityManager()->find(\Sylius\Component\Core\Model\ProductInterface::class, $product?->getId());
        self::assertNotNull($reloaded);
        $reloaded->setEnabled(false);
        $this->entityManager()->flush();
        $component->call('add');

        $this->assertComponentNotEmitEvent($component, 'sylius:shop:cart_changed');
        self::assertStringNotContainsString('tmonier-quick-order-matrix-form', $component->render()->toString());
        self::assertNull($this->sessionCart());
    }

    public function testACorrectedQuantityDropsItsMessage(): void
    {
        $variants = $this->catalog->teeMatrix();
        $prefix = 'QO-' . $this->catalog->suffix;
        $this->client->request('GET', $this->productUrl($variants));
        $component = $this->createLiveComponent('tmonier_quick_order:product:matrix', ['product' => $this->first($variants)->getProduct()], $this->client)
            ->setRouteLocale((string) $this->catalog->channel()->getDefaultLocale()?->getCode());
        $key = MatrixOrderHandler::key($variants[$prefix . '-M-RED']);
        $selector = '[data-test-tmonier-quick-order-matrix-input="' . $prefix . '-M-RED"]';

        $component->set('quantities.' . $key, '500')->call('add');
        self::assertSame('true', (new Crawler($component->render()->toString()))->filter($selector)->attr('aria-invalid'));

        // The browser updates the nested path "quantities.v<id>".
        $component->set('quantities.' . $key, '2');
        $rendered = new Crawler($component->render()->toString());
        self::assertNull($rendered->filter($selector)->attr('aria-invalid'));
        self::assertCount(0, $rendered->filter('[data-test-tmonier-quick-order-matrix-result]'));
    }

    public function testExcludedProductsKeepTheCoreForm(): void
    {
        $variants = $this->catalog->teeMatrix('QO_TEE_EXCLUDED');
        // The core variant table cannot render an enabled variant without price.
        foreach ($variants as $variant) {
            if (null === $variant->getChannelPricingForChannel($this->catalog->channel())) {
                $variant->setEnabled(false);
            }
        }
        $this->entityManager()->flush();

        $this->client->request('GET', $this->productUrl($variants));

        self::assertSelectorExists('[data-test-product-selecting-variant]');
        self::assertSelectorNotExists('[data-test-tmonier-quick-order-matrix]');
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

        // Errors go back to the product page, where the matrix shows them (once), never to the
        // quick order pages.
        $crawler = $this->client->request('GET', $this->productUrl($variants));
        $form = $crawler->filter('[data-test-tmonier-quick-order-matrix-form]')->form();
        $form['quantities[' . MatrixOrderHandler::key($variants[$prefix . '-M-RED']) . ']'] = '1,000';
        $form['quantities[' . MatrixOrderHandler::key($variants[$prefix . '-L-RED']) . ']'] = '1';
        $this->client->submit($form);
        self::assertResponseRedirects($this->productUrl($variants) . '#tmonier-quick-order-matrix');
        $crawler = $this->client->followRedirect();
        $input = $crawler->filter('[data-test-tmonier-quick-order-matrix-input="' . $prefix . '-M-RED"]');
        self::assertSame('1,000', $input->attr('value'));
        self::assertSame('true', $input->attr('aria-invalid'));
        self::assertStringContainsString('Ambiguous quantity', $crawler->filter('#' . explode(' ', (string) $input->attr('aria-describedby'))[0])->text());
        self::assertSame('', $crawler->filter('[data-test-tmonier-quick-order-matrix-input="' . $prefix . '-L-RED"]')->attr('value'), 'Added: cleared');
        self::assertSelectorTextContains('[data-test-tmonier-quick-order-matrix-failed] a', 'M / RED');
        $this->client->request('GET', $this->productUrl($variants));
        self::assertSelectorNotExists('[data-test-tmonier-quick-order-matrix-result]');
    }

    public function testFallbackEmptyAndAllOrNothingSubmissionsStayOnTheProductPage(): void
    {
        $variants = $this->catalog->teeMatrix();
        $prefix = 'QO-' . $this->catalog->suffix;

        $crawler = $this->client->request('GET', $this->productUrl($variants));
        $this->client->submit($crawler->filter('[data-test-tmonier-quick-order-matrix-form]')->form());
        self::assertResponseRedirects($this->productUrl($variants) . '#tmonier-quick-order-matrix');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-tmonier-quick-order-matrix-result]', 'Enter a quantity for at least one variant.');

        $crawler = $this->client->request('GET', $this->productUrl($variants));
        $form = $crawler->filter('[data-test-tmonier-quick-order-matrix-form]')->form();
        $form['quantities[' . MatrixOrderHandler::key($variants[$prefix . '-S-RED']) . ']'] = '1';
        $form['quantities[' . MatrixOrderHandler::key($variants[$prefix . '-M-RED']) . ']'] = '500';
        $box = $form['all_or_nothing'];
        self::assertInstanceOf(\Symfony\Component\DomCrawler\Field\ChoiceFormField::class, $box);
        $box->tick();
        $this->client->submit($form);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-tmonier-quick-order-matrix-result]', 'Nothing was added');
        self::assertNotNull($crawler->filter('[data-test-tmonier-quick-order-matrix-all-or-nothing]')->attr('checked'));
        self::assertNull($this->sessionCart());
    }

    public function testTheQuickOrderResultPageIsGatedForGuests(): void
    {
        $_SERVER['TMONIER_QUICK_ORDER_ALLOW_GUESTS'] = $_ENV['TMONIER_QUICK_ORDER_ALLOW_GUESTS'] = '0';

        try {
            // Read at runtime when the controller is created: no request was sent yet.
            foreach (['/quick-order', '/quick-order/result', '/quick-order/report.csv'] as $path) {
                $this->client->request('GET', $this->url($path));
                self::assertResponseRedirects();
                self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'), $path);
            }
        } finally {
            unset($_SERVER['TMONIER_QUICK_ORDER_ALLOW_GUESTS'], $_ENV['TMONIER_QUICK_ORDER_ALLOW_GUESTS']);
        }
    }

    public function testTheFallbackRouteOnlyServesMatrixProducts(): void
    {
        $simple = $this->catalog->variant('QO-SIMPLE-' . $this->catalog->suffix);
        $this->client->request('POST', $this->url('/quick-order/products/' . $this->id($simple->getProduct()?->getId()) . '/matrix'), ['quantities' => [MatrixOrderHandler::key($simple) => '1']]);
        self::assertResponseStatusCodeSame(404);

        $variants = $this->catalog->teeMatrix();
        $product = $this->first($variants)->getProduct();
        self::assertNotNull($product);
        $this->client->request('POST', $this->url('/quick-order/products/' . $this->id($product->getId()) . '/matrix'), ['_csrf_token' => 'forged', 'quantities' => []]);
        self::assertResponseRedirects($this->productUrl($variants) . '#tmonier-quick-order-matrix');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-flash-messages]', 'Your session has expired');

        // Disabled product, product of another channel: no matrix route.
        $product = $this->entityManager()->find(\Sylius\Component\Core\Model\ProductInterface::class, $product->getId());
        self::assertNotNull($product);
        $product->setEnabled(false);
        $this->entityManager()->flush();
        $this->client->request('POST', $this->url('/quick-order/products/' . $this->id($product->getId()) . '/matrix'), ['quantities' => []]);
        self::assertResponseStatusCodeSame(404);

        $other = $this->catalog->teeMatrix();
        $otherProduct = $this->first($other)->getProduct();
        self::assertInstanceOf(\Sylius\Component\Core\Model\ProductInterface::class, $otherProduct);
        $otherProduct->removeChannel($this->catalog->channel());
        $this->entityManager()->flush();
        $this->client->request('POST', $this->url('/quick-order/products/' . $this->id($otherProduct->getId()) . '/matrix'), ['quantities' => []]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheLiveComponentAddsEverythingAndEmitsCartChanged(): void
    {
        $variants = $this->catalog->teeMatrix();
        $prefix = 'QO-' . $this->catalog->suffix;
        $this->client->request('GET', $this->productUrl($variants));
        $component = $this->createLiveComponent('tmonier_quick_order:product:matrix', ['product' => $this->first($variants)->getProduct()], $this->client)
            ->setRouteLocale((string) $this->catalog->channel()->getDefaultLocale()?->getCode());

        $component
            ->set('quantities.' . MatrixOrderHandler::key($variants[$prefix . '-S-RED']), '4')
            ->set('quantities.' . MatrixOrderHandler::key($variants[$prefix . '-M-BLUE']), '2')
            // A variant of another product, posted by a tampered browser, is ignored.
            ->set('quantities.' . MatrixOrderHandler::key($this->catalog->variant('QO-OTHER-' . $this->catalog->suffix)), '9')
            ->call('add');

        $this->assertComponentEmitEvent($component, 'sylius:shop:cart_changed');
        $html = $component->render()->toString();
        self::assertStringContainsString('2 line(s) added to your cart', $html);
        self::assertSame([$prefix . '-M-BLUE' => 2, $prefix . '-S-RED' => 4], self::quantities($this->sessionCart()));

        // Added cells are cleared: a second click adds nothing more.
        $rendered = new Crawler($html);
        self::assertSame('', $rendered->filter('[data-test-tmonier-quick-order-matrix-input="' . $prefix . '-S-RED"]')->attr('value'));
        $component->call('add');
        self::assertStringContainsString('Enter a quantity for at least one variant.', $component->render()->toString());
        self::assertSame([$prefix . '-M-BLUE' => 2, $prefix . '-S-RED' => 4], self::quantities($this->sessionCart()));
    }

    public function testTheLiveEstimateCountsWhatTheCartWouldTake(): void
    {
        $variants = $this->catalog->teeMatrix();
        $prefix = 'QO-' . $this->catalog->suffix;
        $this->client->request('GET', $this->productUrl($variants));
        $component = $this->createLiveComponent('tmonier_quick_order:product:matrix', ['product' => $this->first($variants)->getProduct()], $this->client)
            ->setRouteLocale((string) $this->catalog->channel()->getDefaultLocale()?->getCode());

        $component
            ->set('quantities.' . MatrixOrderHandler::key($variants[$prefix . '-S-RED']), '4')
            ->set('quantities.' . MatrixOrderHandler::key($variants[$prefix . '-M-BLUE']), '2.0')
            ->set('quantities.' . MatrixOrderHandler::key($variants[$prefix . '-L-RED']), '1.5');

        // 4 x 15.00 + 2 x 16.00; "1.5" is not a quantity.
        $estimate = (new Crawler($component->render()->toString()))->filter('[data-test-tmonier-quick-order-matrix-estimate] [data-units]');
        self::assertSame(['6', '9200'], [$estimate->attr('data-units'), $estimate->attr('data-total')]);
        self::assertStringContainsString('6 unit(s), estimated $92.00', $estimate->text());
    }

    public function testTheAllOrNothingBoxCanBeUncheckedAgain(): void
    {
        $variants = $this->catalog->teeMatrix();
        $prefix = 'QO-' . $this->catalog->suffix;
        $this->client->request('GET', $this->productUrl($variants));
        $component = $this->createLiveComponent('tmonier_quick_order:product:matrix', ['product' => $this->first($variants)->getProduct()], $this->client)
            ->setRouteLocale((string) $this->catalog->channel()->getDefaultLocale()?->getCode());

        $component
            ->set('allOrNothing', true)
            ->set('allOrNothing', false)
            ->set('quantities.' . MatrixOrderHandler::key($variants[$prefix . '-S-RED']), '1')
            ->set('quantities.' . MatrixOrderHandler::key($variants[$prefix . '-M-RED']), '500')
            ->call('add');

        // Partial mode: the valid line went in, the other one keeps its value and its error.
        self::assertSame([$prefix . '-S-RED' => 1], self::quantities($this->sessionCart()));
        $rendered = new Crawler($component->render()->toString());
        $failed = $rendered->filter('[data-test-tmonier-quick-order-matrix-input="' . $prefix . '-M-RED"]');
        self::assertSame('500', $failed->attr('value'));
        self::assertSame('true', $failed->attr('aria-invalid'));
        self::assertNull($rendered->filter('[data-test-tmonier-quick-order-matrix-all-or-nothing]')->attr('checked'));
    }

    public function testTheLiveComponentReportsLineErrorsWithoutEmitting(): void
    {
        $variants = $this->catalog->teeMatrix();
        $prefix = 'QO-' . $this->catalog->suffix;
        $this->client->request('GET', $this->productUrl($variants));
        $component = $this->createLiveComponent('tmonier_quick_order:product:matrix', ['product' => $this->first($variants)->getProduct()], $this->client)
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
    private function first(array $variants): ProductVariantInterface
    {
        $variant = reset($variants);
        self::assertInstanceOf(ProductVariantInterface::class, $variant);

        return $variant;
    }

    private function id(mixed $id): string
    {
        self::assertIsInt($id);

        return (string) $id;
    }

    /** @param array<string, ProductVariantInterface> $variants */
    private function productUrl(array $variants): string
    {
        return $this->url('/products/' . $this->first($variants)->getProduct()?->getSlug());
    }
}
