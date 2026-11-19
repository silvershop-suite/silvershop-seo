<?php

namespace SilverShop\Seo\Tests;

use SilverShop\Page\Product;
use SilverShop\Seo\StructuredData\Provider\ReviewsAdapterProvider;
use SilverShop\Seo\StructuredData\SeoContext;
use SilverShop\Seo\Tests\Stub\StubReviewableExtension;
use SilverStripe\Dev\SapphireTest;

/**
 * The guarded reviews adapter: dormant when the reviews module is absent, and — when product rating data is present
 * (simulated by {@link StubReviewableExtension} so the test needs no dependency on silvershop-suite/reviews) —
 * contributes an aggregateRating fragment keyed to the Product node's @id (no @type, so it merges in rather than
 * duplicating).
 */
class ReviewsAdapterTest extends SapphireTest
{
    protected $usesDatabase = true;

    private const REVIEWS_EXTENSION = 'SilverShop\\Reviews\\Extension\\ReviewableProductExtension';

    private bool $stubApplied = false;

    protected function setUp(): void
    {
        parent::setUp();

        // In the module's own (clean-room) test deps the reviews module is absent, so stand its aggregate helpers in.
        // When reviews IS installed (e.g. running from the dev site) leave the real behaviour alone.
        if (!class_exists(self::REVIEWS_EXTENSION)) {
            Product::add_extension(StubReviewableExtension::class);
            $this->stubApplied = true;
        }
    }

    protected function tearDown(): void
    {
        if ($this->stubApplied) {
            Product::remove_extension(StubReviewableExtension::class);
            $this->stubApplied = false;
        }

        parent::tearDown();
    }

    public function testDormantWithoutReviewsModule(): void
    {
        if (class_exists(self::REVIEWS_EXTENSION)) {
            $this->markTestSkipped('reviews module is installed; the guard is satisfied by its presence.');
        }

        $this->assertFalse(
            (new ReviewsAdapterProvider())->appliesTo(SeoContext::create(null, null, 'http://seo.test/'))
        );
    }

    public function testEnrichesProductAggregateRating(): void
    {
        if (!$this->stubApplied) {
            $this->markTestSkipped('reviews module is installed; real rating data is exercised by the reviews suite.');
        }

        $product = Product::create(['Title' => 'Boots', 'BasePrice' => 10]);
        $product->write();

        $context = SeoContext::create($product, null, (string) $product->AbsoluteLink());
        $nodes = (new ReviewsAdapterProvider())->getNodes($context);

        $fragment = null;
        foreach ($nodes as $node) {
            if (isset($node['aggregateRating']) && str_ends_with((string) ($node['@id'] ?? ''), '#product')) {
                $fragment = $node;
                break;
            }
        }

        $this->assertNotNull($fragment);
        // A fragment, not a node: no @type, so the graph service merges it into the real Product node by @id.
        $this->assertArrayNotHasKey('@type', $fragment);
        $this->assertSame('4.5', $fragment['aggregateRating']['ratingValue']);
        $this->assertSame(8, $fragment['aggregateRating']['reviewCount']);
    }
}
