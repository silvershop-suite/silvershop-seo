<?php

namespace SilverShop\Seo\Tests\Stub;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * Stands in for the reviews module's product aggregate helpers so the ReviewsAdapterProvider enrichment can be tested
 * without depending on silvershop-suite/reviews. Methods only (no $db), so it can be applied at runtime.
 *
 * @extends Extension<\SilverShop\Page\Product>
 */
class StubReviewableExtension extends Extension implements TestOnly
{
    public function HasReviews(): bool
    {
        return true;
    }

    public function AverageRating(): float
    {
        return 4.5;
    }

    public function RatingCount(): int
    {
        return 8;
    }
}
