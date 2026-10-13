<?php

namespace SilverShop\Seo\Tests;

use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverShop\Seo\StructuredData\Provider\ListingProvider;
use SilverShop\Seo\StructuredData\SeoContext;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * The intrinsic listing provider: an ItemList of the category's products plus a BreadcrumbList.
 */
class ListingProviderTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static bool $use_draft_site = true;

    protected ProductCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(ProductCategory::class, 'must_have_price', false);
        Config::modify()->set(Product::class, 'global_allow_purchase', true);

        $this->category = ProductCategory::create(['Title' => 'Shoes']);
        $this->category->write();

        foreach (['Sneaker', 'Boot'] as $title) {
            $product = Product::create(['Title' => $title, 'BasePrice' => 50, 'ParentID' => $this->category->ID]);
            $product->write();
        }
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @return array<string, mixed>|null
     */
    private function nodeOfType(array $nodes, string $type): ?array
    {
        foreach ($nodes as $node) {
            if (($node['@type'] ?? null) === $type) {
                return $node;
            }
        }

        return null;
    }

    public function testAppliesOnlyToCategories(): void
    {
        $provider = new ListingProvider();

        $this->assertTrue($provider->appliesTo(SeoContext::create($this->category)));
        $this->assertFalse($provider->appliesTo(SeoContext::create(Product::create(['Title' => 'X']))));
    }

    public function testItemListAndBreadcrumb(): void
    {
        $context = SeoContext::create($this->category, null, (string) $this->category->AbsoluteLink());
        $nodes = (new ListingProvider())->getNodes($context);

        $itemList = $this->nodeOfType($nodes, 'ItemList');
        $this->assertNotNull($itemList);
        $this->assertSame('Shoes', $itemList['name']);
        $this->assertSame(2, $itemList['numberOfItems']);
        $this->assertCount(2, $itemList['itemListElement']);
        $this->assertSame(1, $itemList['itemListElement'][0]['position']);

        $breadcrumbs = $this->nodeOfType($nodes, 'BreadcrumbList');
        $this->assertNotNull($breadcrumbs);
        // Home is prepended (the on-page breadcrumb starts at Home), then the category chain.
        $this->assertSame('Home', $breadcrumbs['itemListElement'][0]['name']);
        $this->assertSame('Shoes', $breadcrumbs['itemListElement'][1]['name']);
    }
}
