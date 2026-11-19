<?php

declare(strict_types=1);

namespace SilverShop\Seo\StructuredData\Provider;

use SilverShop\Page\Product;
use SilverShop\Seo\StructuredData\SeoContext;
use SilverStripe\Control\Director;
use SilverStripe\ORM\DataObject;

/**
 * Guarded, seo-side adapter for {@code silvershop-suite/brands}. Reads the product's linked Brand (without editing the
 * brands module) and merges a schema.org {@code brand} into the Product node by {@code @id}. Dormant unless the
 * brands module is installed and the product has a brand.
 */
class BrandAdapterProvider extends AbstractStructuredDataProvider
{
    private const BRAND_EXTENSION = 'SilverShop\\Brands\\ProductBrandExtension';

    public function getName(): string
    {
        return 'brand';
    }

    public function appliesTo(SeoContext $context): bool
    {
        return class_exists(self::BRAND_EXTENSION) && $context->getRecord() instanceof Product;
    }

    public function getNodes(SeoContext $context): array
    {
        $product = $context->getRecord();
        if (!$product instanceof Product) {
            return [];
        }

        $brand = $this->callMethod($product, 'Brand');
        if (!$brand instanceof DataObject || !$brand->exists()) {
            return [];
        }

        $brandNode = array_filter([
            '@type' => 'Brand',
            'name' => (string) $brand->getField('Title'),
            'url' => $this->brandUrl($brand),
        ], static fn (string $v): bool => $v !== '');

        if (!isset($brandNode['name'])) {
            return [];
        }

        return [[
            '@id' => $context->nodeId('product'),
            'brand' => $brandNode,
        ]];
    }

    private function brandUrl(DataObject $brand): string
    {
        $link = $this->callMethod($brand, 'Link');
        if (!is_string($link) || $link === '') {
            return '';
        }

        return (string) Director::absoluteURL($link);
    }
}
