<?php

namespace SilverShop\Seo\Tests;

use SilverShop\Extension\ShopConfigExtension;
use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverShop\Seo\StructuredData\Provider\ProductProvider;
use SilverShop\Seo\StructuredData\SeoContext;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * The intrinsic Product provider: node shape, the nested Offer, and the BreadcrumbList, fixtured as a product under a
 * two-level category tree.
 */
class ProductProviderTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static bool $use_draft_site = true;

    protected ProductCategory $clothing;
    protected ProductCategory $jackets;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(ProductCategory::class, 'must_have_price', false);
        Config::modify()->set(Product::class, 'global_allow_purchase', true);
        Config::modify()->set(ShopConfigExtension::class, 'base_currency', 'EUR');

        $this->clothing = ProductCategory::create(['Title' => 'Clothing']);
        $this->clothing->write();

        $this->jackets = ProductCategory::create(['Title' => 'Jackets', 'ParentID' => $this->clothing->ID]);
        $this->jackets->write();

        $this->product = Product::create([
            'Title' => 'Rain Jacket',
            'BasePrice' => 89.95,
            'InternalItemID' => 'RJ-001',
            'MetaDescription' => 'A waterproof rain jacket.',
            'ParentID' => $this->jackets->ID,
        ]);
        $this->product->write();
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

    public function testProductNodeShape(): void
    {
        $context = SeoContext::create($this->product, null, (string) $this->product->AbsoluteLink());
        $nodes = (new ProductProvider())->getNodes($context);

        $product = $this->nodeOfType($nodes, 'Product');
        $this->assertNotNull($product);
        $this->assertSame('Rain Jacket', $product['name']);
        $this->assertSame('RJ-001', $product['sku']);
        $this->assertSame('A waterproof rain jacket.', $product['description']);
        $this->assertStringEndsWith('#product', $product['@id']);
    }

    public function testOfferShape(): void
    {
        $context = SeoContext::create($this->product, null, (string) $this->product->AbsoluteLink());
        $product = $this->nodeOfType((new ProductProvider())->getNodes($context), 'Product');

        $this->assertNotNull($product);
        $offer = $product['offers'];
        $this->assertSame('Offer', $offer['@type']);
        $this->assertSame('89.95', $offer['price']);
        $this->assertSame('EUR', $offer['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $offer['availability']);
        $this->assertSame('https://schema.org/NewCondition', $offer['itemCondition']);
        $this->assertArrayHasKey('seller', $offer);
        $this->assertStringEndsWith('#organization', $offer['seller']['@id']);
    }

    public function testBreadcrumbList(): void
    {
        $context = SeoContext::create($this->product, null, (string) $this->product->AbsoluteLink());
        $breadcrumbs = $this->nodeOfType((new ProductProvider())->getNodes($context), 'BreadcrumbList');

        $this->assertNotNull($breadcrumbs);
        $items = $breadcrumbs['itemListElement'];

        // Home > Clothing > Jackets > Rain Jacket (Home is prepended to mirror the on-page breadcrumb).
        $this->assertCount(4, $items);
        $this->assertSame(1, $items[0]['position']);
        $this->assertSame('Home', $items[0]['name']);
        $this->assertSame('Clothing', $items[1]['name']);
        $this->assertSame('Jackets', $items[2]['name']);
        $this->assertSame('Rain Jacket', $items[3]['name']);
        $this->assertSame(4, $items[3]['position']);
    }

    public function testAppliesOnlyToProducts(): void
    {
        $provider = new ProductProvider();

        $this->assertTrue($provider->appliesTo(SeoContext::create($this->product)));
        $this->assertFalse($provider->appliesTo(SeoContext::create($this->jackets)));
    }
}
