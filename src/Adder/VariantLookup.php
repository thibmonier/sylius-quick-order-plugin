<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Adder;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

/**
 * Loads the variants of a batch of codes in one query, with their product, product channels
 * and channel pricings (no lazy loading per line).
 */
final class VariantLookup implements VariantLookupInterface
{
    /** @param class-string<ProductVariantInterface> $variantClass */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $variantClass,
    ) {
    }

    public function findByCodes(array $codes): array
    {
        if ([] === $codes) {
            return [];
        }

        /** @var list<ProductVariantInterface> $variants */
        $variants = $this->entityManager->createQueryBuilder()
            ->select('variant', 'product', 'channelPricing', 'productChannel')
            ->from($this->variantClass, 'variant')
            ->innerJoin('variant.product', 'product')
            ->leftJoin('variant.channelPricings', 'channelPricing')
            ->leftJoin('product.channels', 'productChannel')
            ->andWhere('variant.code IN (:codes)')
            ->setParameter('codes', array_values(array_unique($codes)))
            ->getQuery()
            ->getResult()
        ;

        $byCode = [];
        $byLowerCode = [];
        foreach ($variants as $variant) {
            $code = (string) $variant->getCode();
            $byCode[$code] = $variant;
            $byLowerCode[mb_strtolower($code)] ??= $variant;
        }

        $found = [];
        foreach ($codes as $code) {
            // Exact match first; the case-insensitive fallback only sees what the database
            // returned (case-insensitive collations on MySQL/MariaDB, exact on PostgreSQL).
            $variant = $byCode[$code] ?? $byLowerCode[mb_strtolower($code)] ?? null;
            if (null !== $variant) {
                $found[$code] = $variant;
            }
        }

        return $found;
    }
}
