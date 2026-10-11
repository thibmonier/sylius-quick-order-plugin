<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Tests\Tmonier\SyliusQuickOrderPlugin\Cart\FakeQuoteCartChecker;

final class ShopQuickOrderTest extends WebTestCase
{
    use QuickOrderTestTrait;

    public function testGuestsPreviewThenAddTheLines(): void
    {
        $a = $this->catalog->variant('QO-A-' . $this->catalog->suffix, 1250);
        $b = $this->catalog->variant('QO-B-' . $this->catalog->suffix, 990, 50);

        $crawler = $this->client->request('GET', $this->url('/quick-order'));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-test-tmonier-quick-order-form]');

        $crawler = $this->client->submit($crawler->filter('[data-test-tmonier-quick-order-preview]')->form(), [
            'lines' => sprintf("SKU\tQTY\n%s\t3\n%s 2\n%s;1", $a->getCode(), $b->getCode(), $a->getCode()),
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(['valid', 'valid'], $this->statuses($crawler));
        self::assertStringContainsString('Product ' . $a->getCode(), $crawler->filter('[data-test-tmonier-quick-order-preview-result]')->text());
        self::assertNull($this->sessionCart(), 'The preview does not change the cart.');

        $addButton = $crawler->filter('[data-test-tmonier-quick-order-add]');
        self::assertStringContainsString('2', $addButton->text());
        $this->client->submit($addButton->form());

        self::assertResponseRedirects();
        self::assertStringContainsString('/cart', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame([(string) $a->getCode() => 4, (string) $b->getCode() => 2], self::quantities($this->sessionCart()));
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test-flash-messages]', '2 line(s) added');
    }

    public function testErrorsAreReportedWithADownloadableReport(): void
    {
        $a = $this->catalog->variant('QO-A-' . $this->catalog->suffix);
        $this->catalog->variant('QO-OFF-' . $this->catalog->suffix, enabled: false);

        $crawler = $this->preview(sprintf("%s;2\nQO-OFF-%s;1\n=HYPERLINK(\"http://evil\");1\n%s;zero", $a->getCode(), $this->catalog->suffix, $a->getCode()));
        self::assertSame(['valid', 'disabled', 'unknown_code', 'invalid_quantity'], $this->statuses($crawler));
        self::assertSelectorExists('[data-test-tmonier-quick-order-report]');

        $this->client->submit($crawler->filter('[data-test-tmonier-quick-order-add]')->form());
        self::assertResponseRedirects($this->url('/quick-order/result'));
        $crawler = $this->client->followRedirect();
        self::assertSame(['added', 'disabled', 'unknown_code', 'invalid_quantity'], $this->statuses($crawler));
        self::assertSelectorTextContains('[data-test-tmonier-quick-order-result]', '1 line(s) added to your cart');
        self::assertSame([(string) $a->getCode() => 2], self::quantities($this->sessionCart()));

        $this->client->request('GET', $this->url('/quick-order/report.csv'));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        $csv = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('"\'=HYPERLINK(""http://evil"")"', $csv);
        self::assertStringContainsString('This product is not available.', $csv);
        self::assertStringNotContainsString($a->getCode() . ',2,added', $csv);
    }

    public function testCsvUpload(): void
    {
        $a = $this->catalog->variant('QO-A-' . $this->catalog->suffix);
        $b = $this->catalog->variant('QO-B-' . $this->catalog->suffix);
        $path = tempnam(sys_get_temp_dir(), 'qo') . '.csv';
        file_put_contents($path, "\u{FEFF}Quantity;SKU\r\n5;" . $b->getCode() . "\r\n1;" . $a->getCode() . "\r\n");

        try {
            $crawler = $this->client->request('GET', $this->url('/quick-order'));
            $form = $crawler->filter('[data-test-tmonier-quick-order-preview]')->form();
            $field = $form['file'];
            self::assertInstanceOf(FileFormField::class, $field);
            $field->upload($path);
            $crawler = $this->client->submit($form);
        } finally {
            unlink($path);
        }

        self::assertResponseIsSuccessful();
        self::assertSame(['valid', 'valid'], $this->statuses($crawler));
        self::assertSame([2, 3], $crawler->filter('[data-test-tmonier-quick-order-line]')->each(static fn (Crawler $row): int => (int) $row->filter('td')->first()->text()));
        $this->client->submit($crawler->filter('[data-test-tmonier-quick-order-add]')->form());
        self::assertSame([(string) $a->getCode() => 1, (string) $b->getCode() => 5], self::quantities($this->sessionCart()));
    }

    public function testAllOrNothingAddsNothingWhenALineFails(): void
    {
        $a = $this->catalog->variant('QO-A-' . $this->catalog->suffix);
        $crawler = $this->preview($a->getCode() . ";1\nQO-NOPE-" . $this->catalog->suffix . ';1', 'all_or_nothing');

        self::assertSelectorNotExists('[data-test-tmonier-quick-order-add]');

        // Even a forged "add" request adds nothing.
        $this->client->request('POST', $this->url('/quick-order/add'), [
            '_csrf_token' => $crawler->filter('input[name="_csrf_token"]')->attr('value'),
            'payload' => json_encode(['l' => [[$a->getCode(), 1, [1]], ['QO-NOPE', 1, [2]]], 'i' => []]),
            'mode' => 'all_or_nothing',
        ]);
        $crawler = $this->client->followRedirect();
        self::assertSame(['skipped', 'unknown_code'], $this->statuses($crawler));
        self::assertSelectorTextContains('[data-test-tmonier-quick-order-result]', 'Nothing was added');
        self::assertSame([], self::quantities($this->sessionCart()));
    }

    public function testInputsRefusedAsAWhole(): void
    {
        $crawler = $this->client->request('GET', $this->url('/quick-order'));
        $form = $crawler->filter('[data-test-tmonier-quick-order-preview]')->form();

        $this->client->submit($form, ['lines' => str_repeat("QO-X;1\n", 201)]);
        self::assertResponseRedirects($this->url('/quick-order'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-flash-messages]', 'Too many lines');

        $this->client->submit($form, ['lines' => "  \n"]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-flash-messages]', 'Paste at least one line');

        $this->client->request('POST', $this->url('/quick-order/preview'), ['_csrf_token' => 'forged', 'lines' => 'QO-X;1']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-flash-messages]', 'Your session has expired');

        $this->client->request('POST', $this->url('/quick-order/add'), ['_csrf_token' => $crawler->filter('input[name="_csrf_token"]')->attr('value'), 'payload' => '{"l": [["X", -1, [1]]], "i": []}']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-flash-messages]', 'The file could not be read');
    }

    public function testQuoteCartsAreRefused(): void
    {
        $a = $this->catalog->variant('QO-A-' . $this->catalog->suffix);
        $crawler = $this->preview($a->getCode() . ';1');
        $this->client->submit($crawler->filter('[data-test-tmonier-quick-order-add]')->form());
        $cart = $this->sessionCart();
        self::assertNotNull($cart);
        $cart->setNotes(FakeQuoteCartChecker::NOTES);
        $this->entityManager()->flush();

        $crawler = $this->client->request('GET', $this->url('/quick-order'));
        $this->client->submit($crawler->filter('[data-test-tmonier-quick-order-preview]')->form(), ['lines' => $a->getCode() . ';5']);

        self::assertResponseRedirects($this->url('/quick-order'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-flash-messages]', 'cannot be changed');
        self::assertSame([(string) $a->getCode() => 1], self::quantities($this->sessionCart()));
    }

    public function testTemplateAndEntryPoints(): void
    {
        $this->client->request('GET', $this->url('/quick-order/template.csv'));
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith("\u{FEFF}SKU,QTY", (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('attachment; filename=quick-order-template.csv', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        $this->client->request('GET', $this->url('/quick-order/report.csv'));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $this->url('/quick-order/result'));
        self::assertResponseRedirects($this->url('/quick-order'));

        $this->client->request('GET', $this->url('/cart/'));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-test-tmonier-quick-order-cart-link] a[href="' . $this->url('/quick-order') . '"]');

        $this->loginShopUser($this->catalog->customer());
        $this->client->request('GET', $this->url('/account/dashboard'));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="' . $this->url('/quick-order') . '"]');
    }

    private function preview(string $lines, string $mode = 'partial'): Crawler
    {
        $crawler = $this->client->request('GET', $this->url('/quick-order'));
        $crawler = $this->client->submit($crawler->filter('[data-test-tmonier-quick-order-preview]')->form(), ['lines' => $lines, 'mode' => $mode]);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** @return list<string> */
    private function statuses(Crawler $crawler): array
    {
        return $crawler->filter('[data-test-tmonier-quick-order-line]')->each(static fn (Crawler $row): string => (string) $row->attr('data-status'));
    }
}
