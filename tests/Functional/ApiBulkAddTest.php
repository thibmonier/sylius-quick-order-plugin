<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Functional;

use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Tmonier\SyliusQuickOrderPlugin\Cart\FakeQuoteCartChecker;

final class ApiBulkAddTest extends WebTestCase
{
    use QuickOrderTestTrait;

    public function testGuestCartBulkAdd(): void
    {
        $a = $this->catalog->variant('QO-A-' . $this->catalog->suffix, 1500);
        $b = $this->catalog->variant('QO-B-' . $this->catalog->suffix, 900, 3);
        $cart = $this->catalog->cart();

        $data = $this->bulkAdd($cart, ['lines' => [
            ['code' => $a->getCode(), 'quantity' => 2],
            ['code' => $b->getCode(), 'quantity' => '5'],
            ['code' => 'QO-NOPE', 'quantity' => 1],
            ['code' => $a->getCode()],
            ['quantity' => 3],
        ]]);

        self::assertResponseIsSuccessful();
        self::assertSame('/api/v2/shop/orders/' . $cart->getTokenValue(), $data['cart']);
        self::assertTrue($data['applied']);
        self::assertSame('partial', $data['mode']);
        self::assertSame(1, $data['addedCount']);
        /** @var list<array{status: string, lineNumbers: list<int>, addedQuantity: int, available: int|null}> $lines */
        $lines = $data['lines'];
        self::assertSame(
            [['added', [1, 4], 3], ['insufficient_stock', [2], 0], ['unknown_code', [3], 0], ['invalid_line', [5], 0]],
            array_map(static fn (array $line): array => [$line['status'], $line['lineNumbers'], $line['addedQuantity']], $lines),
        );
        self::assertSame(3, $lines[1]['available']);

        $this->entityManager()->clear();
        $reloaded = $this->entityManager()->find(OrderInterface::class, $cart->getId());
        self::assertSame([(string) $a->getCode() => 3], self::quantities($reloaded));
    }

    public function testAllOrNothing(): void
    {
        $a = $this->catalog->variant('QO-A-' . $this->catalog->suffix);
        $cart = $this->catalog->cart();

        $data = $this->bulkAdd($cart, ['mode' => 'all_or_nothing', 'lines' => [['code' => $a->getCode(), 'quantity' => 1], ['code' => 'QO-NOPE', 'quantity' => 1]]]);

        self::assertResponseIsSuccessful();
        self::assertFalse($data['applied']);
        self::assertSame(0, $data['addedCount']);
        $this->entityManager()->clear();
        self::assertSame([], self::quantities($this->entityManager()->find(OrderInterface::class, $cart->getId())));
    }

    public function testCustomerCartsAreOnlyReachableByTheirOwner(): void
    {
        $a = $this->catalog->variant('QO-A-' . $this->catalog->suffix);
        $owner = $this->catalog->customer('owner');
        $cart = $this->catalog->cart($owner);
        $body = ['lines' => [['code' => $a->getCode(), 'quantity' => 1]]];

        $this->bulkAdd($cart, $body);
        self::assertResponseStatusCodeSame(404);

        $this->bulkAdd($cart, $body, $this->catalog->customer('other'));
        self::assertResponseStatusCodeSame(404);

        $data = $this->bulkAdd($cart, $body, $owner);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $data['addedCount']);

        $this->client->request('POST', '/api/v2/shop/orders/unknown-token/bulk-items', server: $this->headers(), content: json_encode($body, \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);
    }

    public function testRefusals(): void
    {
        $a = $this->catalog->variant('QO-A-' . $this->catalog->suffix);
        $cart = $this->catalog->cart();

        $this->bulkAdd($cart, ['mode' => 'everything', 'lines' => [['code' => $a->getCode(), 'quantity' => 1]]]);
        self::assertResponseStatusCodeSame(422);

        $this->bulkAdd($cart, ['lines' => array_fill(0, 201, ['code' => $a->getCode(), 'quantity' => 1])]);
        self::assertResponseStatusCodeSame(422);

        $this->bulkAdd($cart, ['lines' => []]);
        self::assertResponseStatusCodeSame(422);

        // The entity manager is cleared between requests: reload before changing.
        $cart = $this->entityManager()->find(OrderInterface::class, $cart->getId());
        self::assertInstanceOf(OrderInterface::class, $cart);
        $cart->setNotes(FakeQuoteCartChecker::NOTES);
        $this->entityManager()->flush();
        $this->bulkAdd($cart, ['lines' => [['code' => $a->getCode(), 'quantity' => 1]]]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('cart_locked', (string) $this->client->getResponse()->getContent());
    }

    public function testTheOperationIsDocumented(): void
    {
        $this->client->request('GET', '/api/v2/docs.jsonopenapi', server: ['HTTP_ACCEPT' => 'application/vnd.openapi+json']);

        self::assertResponseIsSuccessful();
        $docs = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($docs);
        self::assertIsArray($docs['paths'] ?? null);
        self::assertArrayHasKey('/api/v2/shop/orders/{tokenValue}/bulk-items', $docs['paths']);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function bulkAdd(OrderInterface $cart, array $body, ?CustomerInterface $as = null): array
    {
        $this->client->request(
            'POST',
            sprintf('/api/v2/shop/orders/%s/bulk-items', $cart->getTokenValue()),
            server: $this->headers($as),
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        if (!\is_array($data)) {
            return [];
        }

        /** @var array<string, mixed> $typed */
        $typed = $data;

        return $typed;
    }

    /** @return array<string, string> */
    private function headers(?CustomerInterface $as = null): array
    {
        $headers = ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'];
        if (null !== $as) {
            $user = $as->getUser();
            self::assertInstanceOf(ShopUserInterface::class, $user);
            $manager = self::getContainer()->get('lexik_jwt_authentication.jwt_manager');
            self::assertInstanceOf(JWTTokenManagerInterface::class, $manager);
            $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $manager->create($user);
        }

        return $headers;
    }
}
