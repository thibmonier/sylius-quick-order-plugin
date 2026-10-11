<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class ShopReorderTest extends WebTestCase
{
    use QuickOrderTestTrait;

    public function testReorderFromTheOrderListAndTheOrderPage(): void
    {
        $customer = $this->catalog->customer();
        $a = $this->catalog->variant('QO-A-' . $this->catalog->suffix, 1200);
        $b = $this->catalog->variant('QO-B-' . $this->catalog->suffix, 800);
        $order = $this->catalog->placedOrder($customer, [[$a, 2], [$b, 5]]);
        $this->loginShopUser($customer);

        $crawler = $this->client->request('GET', $this->url('/account/orders/'));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-test-tmonier-quick-order-reorder-' . $order->getNumber() . ']');

        $crawler = $this->client->request('GET', $this->url('/account/orders/' . $order->getNumber()));
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('[data-test-tmonier-quick-order-reorder]')->form());

        self::assertResponseRedirects();
        self::assertStringContainsString('/cart', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame([(string) $a->getCode() => 2, (string) $b->getCode() => 5], self::quantities($this->sessionCart()));

        // From the list, the same lines again: merged into the cart lines.
        $crawler = $this->client->request('GET', $this->url('/account/orders/'));
        $this->client->submit($crawler->filter('[data-test-tmonier-quick-order-reorder-' . $order->getNumber() . ']')->form());
        self::assertSame([(string) $a->getCode() => 4, (string) $b->getCode() => 10], self::quantities($this->sessionCart()));
    }

    public function testItemsThatCannotBeReorderedAreReported(): void
    {
        $customer = $this->catalog->customer();
        $kept = $this->catalog->variant('QO-KEPT-' . $this->catalog->suffix);
        $gone = $this->catalog->variant('QO-GONE-' . $this->catalog->suffix);
        $unpriced = $this->catalog->variant('QO-UNPRICED-' . $this->catalog->suffix);
        $order = $this->catalog->placedOrder($customer, [[$kept, 1], [$gone, 1], [$unpriced, 3]]);
        $gone->setEnabled(false);
        foreach ($unpriced->getChannelPricings() as $pricing) {
            $pricing->setPrice(null);
        }
        $this->entityManager()->flush();
        $this->loginShopUser($customer);

        $crawler = $this->client->request('GET', $this->url('/account/orders/' . $order->getNumber()));
        $this->client->submit($crawler->filter('[data-test-tmonier-quick-order-reorder]')->form());

        self::assertResponseRedirects($this->url('/quick-order/result'));
        $crawler = $this->client->followRedirect();
        self::assertSame(['added', 'disabled', 'no_channel_pricing'], $crawler->filter('[data-test-tmonier-quick-order-line]')->each(static fn (Crawler $row): string => (string) $row->attr('data-status')));
        self::assertSame([(string) $kept->getCode() => 1], self::quantities($this->sessionCart()));
    }

    public function testOnlyTheOwnerCanReorder(): void
    {
        $owner = $this->catalog->customer('owner');
        $order = $this->catalog->placedOrder($owner, [[$this->catalog->variant('QO-A-' . $this->catalog->suffix), 1]]);

        $this->client->request('POST', $this->url('/account/orders/' . $order->getNumber() . '/reorder'));
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));

        $this->loginShopUser($this->catalog->customer('other'));
        $crawler = $this->client->request('GET', $this->url('/account/orders/'));
        $token = $crawler->filter('input[name="_csrf_token"]')->count() > 0 ? $crawler->filter('input[name="_csrf_token"]')->attr('value') : $this->csrfToken();
        $this->client->request('POST', $this->url('/account/orders/' . $order->getNumber() . '/reorder'), ['_csrf_token' => $token]);
        self::assertResponseStatusCodeSame(404);

        $this->loginShopUser($owner);
        $this->client->request('POST', $this->url('/account/orders/' . $order->getNumber() . '/reorder'), ['_csrf_token' => 'forged']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-flash-messages]', 'Your session has expired');
    }

    private function csrfToken(): string
    {
        $crawler = $this->client->request('GET', $this->url('/quick-order'));

        return (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');
    }
}
