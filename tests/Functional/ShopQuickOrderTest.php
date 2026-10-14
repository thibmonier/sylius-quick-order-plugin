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
        $this->catalog->variant('QO-UNPRICED-' . $this->catalog->suffix, null);

        $crawler = $this->preview(sprintf("%s;2\nQO-OFF-%s;1\n=HYPERLINK(\"http://evil\");1\n%s;zero\nQO-UNPRICED-%s;1", $a->getCode(), $this->catalog->suffix, $a->getCode(), $this->catalog->suffix));
        // Visitors cannot tell a disabled or unpriced product from an unknown code.
        self::assertSame(['valid', 'not_available', 'not_available', 'invalid_quantity', 'not_available'], $this->statuses($crawler));
        $preview = $crawler->filter('[data-test-tmonier-quick-order-preview-result]')->text();
        self::assertStringNotContainsString('Product QO-OFF', $preview);
        self::assertStringNotContainsString('Product QO-UNPRICED', $preview);
        self::assertSelectorExists('[data-test-tmonier-quick-order-report]');

        $this->client->submit($crawler->filter('[data-test-tmonier-quick-order-add]')->form());
        self::assertResponseRedirects($this->url('/quick-order/result'));
        $crawler = $this->client->followRedirect();
        self::assertSame(['added', 'not_available', 'not_available', 'invalid_quantity', 'not_available'], $this->statuses($crawler));
        self::assertSelectorTextContains('[data-test-tmonier-quick-order-result]', '1 line(s) added to your cart');
        self::assertSame([(string) $a->getCode() => 2], self::quantities($this->sessionCart()));

        $this->client->request('GET', $this->url('/quick-order/report.csv'));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        $csv = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('"\'=HYPERLINK(""http://evil"")"', $csv);
        self::assertStringContainsString('This code is not available.', $csv);
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
        $b = $this->catalog->variant('QO-B-' . $this->catalog->suffix);
        $this->preview($a->getCode() . ";1\nQO-NOPE-" . $this->catalog->suffix . ';1', 'all_or_nothing');
        self::assertSelectorNotExists('[data-test-tmonier-quick-order-add]');

        // Valid at preview time, disabled before the "add" step.
        $crawler = $this->preview($a->getCode() . ";1\n" . $b->getCode() . ';1', 'all_or_nothing');
        $form = $crawler->filter('[data-test-tmonier-quick-order-add]')->form();
        $reloaded = $this->entityManager()->find(\Sylius\Component\Core\Model\ProductVariantInterface::class, $b->getId());
        self::assertNotNull($reloaded);
        $reloaded->setEnabled(false);
        $this->entityManager()->flush();
        $this->client->submit($form);

        $crawler = $this->client->followRedirect();
        self::assertSame(['skipped', 'not_available'], $this->statuses($crawler));
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

        $this->client->submit($form, ['lines' => str_repeat('X', 1048577)]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-flash-messages]', 'The pasted text is too long');

        $path = tempnam(sys_get_temp_dir(), 'qo') . '.csv';
        file_put_contents($path, "Description;SKU;Notes\nShirt;QO-X;12\n");
        $field = $form['file'];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload($path);
        $this->client->submit($form);
        unlink($path);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-flash-messages]', 'quantity column of the CSV file was not found');
    }

    public function testTheAddStepOnlyAcceptsPreviewsKeptInTheSession(): void
    {
        $a = $this->catalog->variant('QO-A-' . $this->catalog->suffix);
        $crawler = $this->preview($a->getCode() . ';1');
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        // The client cannot post its own lines: only the nonce of the preview kept in the session.
        $this->client->request('POST', $this->url('/quick-order/add'), ['_csrf_token' => $token, 'preview' => 'forged', 'payload' => '{"l": [["X", 5, [1]]], "i": []}']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test-tmonier-quick-order-error]', 'already added or has expired');
        self::assertNull($this->sessionCart());

        $this->client->submit($crawler->filter('[data-test-tmonier-quick-order-add]')->form());
        self::assertSame([(string) $a->getCode() => 1], self::quantities($this->sessionCart()));
        $this->client->followRedirect();

        // A preview is used once (back button): the page comes back with the pasted text.
        $crawler = $this->client->submit($crawler->filter('[data-test-tmonier-quick-order-add]')->form());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test-tmonier-quick-order-error]', 'already added or has expired');
        self::assertSame($a->getCode() . ';1', $crawler->filter('#tmonier-quick-order-lines')->text());
        self::assertSame([(string) $a->getCode() => 1], self::quantities($this->sessionCart()));
    }

    public function testEachTabCanAddItsOwnPreview(): void
    {
        $a = $this->catalog->variant('QO-A-' . $this->catalog->suffix);
        $b = $this->catalog->variant('QO-B-' . $this->catalog->suffix);
        $tabA = $this->preview($a->getCode() . ';2')->filter('[data-test-tmonier-quick-order-add]')->form();
        $tabB = $this->preview($b->getCode() . ';3')->filter('[data-test-tmonier-quick-order-add]')->form();

        $this->client->submit($tabA);
        $this->client->followRedirect();
        $this->client->submit($tabB);

        self::assertSame([(string) $a->getCode() => 2, (string) $b->getCode() => 3], self::quantities($this->sessionCart()));
    }

    public function testOnlyTheLastFivePreviewsAreKept(): void
    {
        $a = $this->catalog->variant('QO-A-' . $this->catalog->suffix);
        $forms = [];
        for ($i = 1; $i <= 6; ++$i) {
            $forms[] = $this->preview($a->getCode() . ';' . $i)->filter('[data-test-tmonier-quick-order-add]')->form();
        }

        $this->client->submit($forms[0]);
        self::assertSelectorTextContains('[data-test-tmonier-quick-order-error]', 'already added or has expired');
        $this->client->submit($forms[1]);
        self::assertSame([(string) $a->getCode() => 2], self::quantities($this->sessionCart()));
    }

    public function testTheUnitLimitIsExplained(): void
    {
        $lines = [];
        foreach (['A', 'B', 'C'] as $name) {
            $lines[] = $this->catalog->variant('QO-' . $name . '-' . $this->catalog->suffix)->getCode() . ';7000';
        }
        $crawler = $this->client->request('GET', $this->url('/quick-order'));
        $this->client->submit($crawler->filter('[data-test-tmonier-quick-order-preview]')->form(), ['lines' => implode("\n", $lines)]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('[data-test-flash-messages]', 'more than 20000');
        self::assertSelectorTextContains('[data-test-flash-messages]', 'The shop can change this limit');
    }

    public function testAmbiguousQuantitiesAreReportedNotGuessed(): void
    {
        $a = $this->catalog->variant('QO-A-' . $this->catalog->suffix);

        $crawler = $this->preview($a->getCode() . ';1.000');

        self::assertSame(['ambiguous_quantity'], $this->statuses($crawler));
        self::assertStringContainsString('write 1000 without separator', $crawler->filter('[data-test-tmonier-quick-order-preview-result]')->text());

        $crawler = $this->preview($a->getCode() . ';12,00');
        self::assertSame(['valid'], $this->statuses($crawler));
        self::assertStringContainsString('12', $crawler->filter('[data-test-tmonier-quick-order-line]')->text());
    }

    public function testRegisteredCustomersGetDetailedStatusesButNoStockLevels(): void
    {
        $this->loginShopUser($this->catalog->customer());
        $this->catalog->variant('QO-OFF-' . $this->catalog->suffix, enabled: false);
        $low = $this->catalog->variant('QO-LOW-' . $this->catalog->suffix, 1000, 3);

        $crawler = $this->preview(sprintf("QO-OFF-%s;1\nQO-NOPE;1\n%s;5", $this->catalog->suffix, $low->getCode()));

        self::assertSame(['disabled', 'unknown_code', 'insufficient_stock'], $this->statuses($crawler));
        $text = $crawler->filter('[data-test-tmonier-quick-order-preview-result]')->text();
        self::assertStringContainsString('Not enough stock for this quantity.', $text);
        self::assertStringNotContainsString('Product QO-OFF', $text);
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

    public function testSubmissionsAreLimitedPerIp(): void
    {
        // The test application allows 15 submissions per IP (shared across all POST endpoints).
        $code = (string) $this->catalog->variant('QO-A-' . $this->catalog->suffix)->getCode();
        $crawler = $this->client->request('GET', $this->url('/quick-order'));
        $form = $crawler->filter('[data-test-tmonier-quick-order-preview]')->form();

        for ($i = 1; $i <= 15; ++$i) {
            $this->client->submit($form, ['lines' => $code . ';1']);
            self::assertResponseIsSuccessful(\sprintf('Preview request %d should succeed.', $i));
        }

        // The 16th preview is rate-limited: 429 with the quick order page, nothing added.
        $this->client->submit($form, ['lines' => $code . ';1']);
        self::assertResponseStatusCodeSame(429);
        self::assertNull($this->sessionCart(), 'Nothing is added to the cart when rate-limited.');
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
