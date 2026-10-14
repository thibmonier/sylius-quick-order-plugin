<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusQuickOrderPlugin\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Tests\Tmonier\SyliusQuickOrderPlugin\TestCatalog;

/**
 * Tests run against the test application loaded with the Sylius fixtures. The kernel is not
 * rebooted between requests and every test runs in a transaction rolled back at the end.
 * Fixtures are random: each test creates the products, customers and orders it needs.
 */
trait QuickOrderTestTrait
{
    private KernelBrowser $client;

    private TestCatalog $catalog;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->connection()->beginTransaction();
        $this->catalog = new TestCatalog(self::getContainer());

        // Rate limiter state of the previous tests.
        $pool = self::getContainer()->get('cache.tmonier_quick_order_limiter');
        self::assertInstanceOf(\Psr\Cache\CacheItemPoolInterface::class, $pool);
        $pool->clear();
    }

    protected function tearDown(): void
    {
        if ($this->connection()->isTransactionActive()) {
            $this->connection()->rollBack();
        }
        parent::tearDown();
    }

    private function loginShopUser(CustomerInterface $customer): void
    {
        $user = $customer->getUser();
        self::assertInstanceOf(ShopUserInterface::class, $user);
        $this->client->loginUser($user, 'shop');
    }

    private function url(string $path): string
    {
        return '/' . $this->catalog->channel()->getDefaultLocale()?->getCode() . $path;
    }

    /** The cart stored in the session of the client, reloaded from the database. */
    private function sessionCart(): ?OrderInterface
    {
        $session = $this->client->getRequest()->getSession();
        $id = $session->get('_sylius.cart.' . $this->catalog->channel()->getCode());
        if (null === $id) {
            return null;
        }
        $this->entityManager()->clear();
        $cart = $this->entityManager()->find(OrderInterface::class, $id);
        self::assertInstanceOf(OrderInterface::class, $cart);

        return $cart;
    }

    /** @return array<string, int> quantities by variant code */
    private static function quantities(?OrderInterface $cart): array
    {
        $quantities = [];
        foreach ($cart?->getItems() ?? [] as $item) {
            $quantities[(string) $item->getVariant()?->getCode()] = $item->getQuantity();
        }
        ksort($quantities);

        return $quantities;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }
}
