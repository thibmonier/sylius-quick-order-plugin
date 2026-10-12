<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Adder;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Sylius\Component\Core\Model\ProductVariantInterface;

/**
 * Loads the variants of a batch of codes with everything the checks and the preview read
 * (product, product channels, channel pricings, then product and variant translations in two
 * more queries, to avoid a cartesian product): a constant number of queries, no lazy
 * loading per line.
 *
 * Matching is the same on every database: exact code first; otherwise a case-insensitive
 * match, only when it designates a single variant. The case-insensitive query only runs
 * for the codes without exact match.
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
        $codes = array_values(array_unique($codes));
        if ([] === $codes) {
            return [];
        }

        $found = [];
        $byCode = [];
        foreach ($this->fetch($this->query()->andWhere('variant.code IN (:codes)')->setParameter('codes', $codes)) as $variant) {
            $byCode[(string) $variant->getCode()] = $variant;
        }

        $missing = [];
        foreach ($codes as $code) {
            if (isset($byCode[$code])) {
                $found[$code] = $byCode[$code];
            } else {
                $missing[mb_strtolower($code)][] = $code;
            }
        }
        if ([] === $missing) {
            return $this->withTranslations($found);
        }

        $byLowerCode = [];
        $lowerCodes = array_map('strval', array_keys($missing));
        foreach ($this->fetch($this->query()->andWhere('LOWER(variant.code) IN (:lowerCodes)')->setParameter('lowerCodes', $lowerCodes)) as $variant) {
            $byLowerCode[mb_strtolower((string) $variant->getCode())][] = $variant;
        }
        foreach ($missing as $lowerCode => $requestedCodes) {
            $candidates = $byLowerCode[(string) $lowerCode] ?? [];
            if (1 === \count($candidates)) {
                foreach ($requestedCodes as $code) {
                    $found[$code] = $candidates[0];
                }
            }
        }

        return $this->withTranslations($found);
    }

    /** @return list<ProductVariantInterface> */
    private function fetch(QueryBuilder $queryBuilder): array
    {
        $result = $queryBuilder->getQuery()->getResult();

        return \is_array($result) ? array_values(array_filter($result, static fn (mixed $variant): bool => $variant instanceof ProductVariantInterface)) : [];
    }

    private function query(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('variant', 'product', 'channelPricing', 'productChannel')
            ->from($this->variantClass, 'variant')
            ->innerJoin('variant.product', 'product')
            ->leftJoin('variant.channelPricings', 'channelPricing')
            ->leftJoin('product.channels', 'productChannel')
        ;
    }

    /**
     * Fetch-joining the translations of entities already in the identity map initializes their
     * collections.
     *
     * @param array<string, ProductVariantInterface> $variants
     *
     * @return array<string, ProductVariantInterface>
     */
    private function withTranslations(array $variants): array
    {
        if ([] === $variants) {
            return $variants;
        }

        $this->entityManager->createQueryBuilder()
            ->select('variant', 'translation')
            ->from($this->variantClass, 'variant')
            ->leftJoin('variant.translations', 'translation')
            ->andWhere('variant IN (:variants)')
            ->setParameter('variants', array_values($variants))
            ->getQuery()
            ->getResult()
        ;
        $products = [];
        foreach ($variants as $variant) {
            $product = $variant->getProduct();
            if (null !== $product) {
                $products[spl_object_id($product)] = $product;
            }
        }
        $this->entityManager->createQueryBuilder()
            ->select('product', 'translation')
            ->from((string) $this->entityManager->getClassMetadata($this->variantClass)->getAssociationTargetClass('product'), 'product')
            ->leftJoin('product.translations', 'translation')
            ->andWhere('product IN (:products)')
            ->setParameter('products', array_values($products))
            ->getQuery()
            ->getResult()
        ;

        return $variants;
    }
}
