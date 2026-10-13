<?php

declare(strict_types=1);

namespace SilverShop\Seo\StructuredData\Provider;

use SilverShop\Page\Product;
use SilverShop\Seo\StructuredData\SeoContext;
use SilverStripe\ORM\DataObject;

/**
 * Guarded, seo-side adapter for {@code silvershop/reviews}. Reads the reviews module's aggregate data (without
 * editing it) and contributes:
 *
 *  - an {@code aggregateRating} + {@code review[]} fragment merged INTO the Product node (by {@code @id}).
 *
 * The shop-level rating is intentionally NOT emitted on the Organization node — Google does not support
 * aggregateRating on Organization for rich results, and a native-review figure there is self-serving and can
 * disagree with the external shop rating shown in the footer.
 *
 * Dormant unless the reviews module is installed.
 *
 * NB (double-emit caveat): the reviews module still prints its own standalone {@code <script>} JSON-LD on the product
 * page and shop pages. Until a coordinated reviews release makes it implement this contract and self-suppress that
 * markup, a page with both modules emits the rating twice (once in this graph, once standalone). Google tolerates
 * duplicate aggregateRating, but the clean fix is the documented reviews follow-up — see the README.
 */
class ReviewsAdapterProvider extends AbstractStructuredDataProvider
{
    private const PRODUCT_EXTENSION = 'SilverShop\\Reviews\\Extension\\ReviewableProductExtension';

    public function getName(): string
    {
        return 'reviews';
    }

    public function appliesTo(SeoContext $context): bool
    {
        return class_exists(self::PRODUCT_EXTENSION);
    }

    public function getNodes(SeoContext $context): array
    {
        $nodes = [];

        // Product rating + reviews → Product node (product pages only). The shop-level rating is intentionally NOT
        // emitted on the Organization node (see the class docblock): unsupported on Organization for rich results
        // and inconsistent with the external shop rating in the footer.
        $product = $context->getRecord();
        if ($product instanceof Product && $this->callMethod($product, 'HasReviews', false)) {
            $nodes[] = $this->prune([
                '@id' => $context->nodeId('product'),
                'aggregateRating' => $this->aggregate(
                    (float) $this->callMethod($product, 'AverageRating', 0.0),
                    (int) $this->callMethod($product, 'RatingCount', 0)
                ),
                'review' => $this->reviews($product),
            ]);
        }

        return $nodes;
    }

    /**
     * @return array<string, mixed>
     */
    private function aggregate(float $value, int $count): array
    {
        return [
            '@type' => 'AggregateRating',
            'ratingValue' => (string) $value,
            'reviewCount' => $count,
            'bestRating' => '5',
            'worstRating' => '1',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function reviews(Product $product): array
    {
        $list = $this->callMethod($product, 'ApprovedReviews');
        if (!is_iterable($list)) {
            return [];
        }

        $out = [];
        $count = 0;
        foreach ($list as $review) {
            if (!$review instanceof DataObject) {
                continue;
            }
            $created = strtotime((string) $review->getField('Created'));
            $out[] = $this->prune([
                '@type' => 'Review',
                'reviewRating' => [
                    '@type' => 'Rating',
                    'ratingValue' => (string) (int) $review->getField('Rating'),
                    'bestRating' => '5',
                    'worstRating' => '1',
                ],
                'author' => [
                    '@type' => 'Person',
                    'name' => (string) ($review->getField('AuthorName') ?: 'Anonymous'),
                ],
                'name' => (string) $review->getField('Title'),
                'reviewBody' => (string) $review->getField('Content'),
                'datePublished' => $created !== false ? date('Y-m-d', $created) : '',
            ]);

            if (++$count >= 10) {
                break;
            }
        }

        return $out;
    }
}
