<?php

declare(strict_types=1);

namespace SilverShop\Seo\StructuredData\Provider;

use SilverShop\Seo\StructuredData\SeoContext;
use SilverShop\Seo\StructuredData\StructuredDataProvider;
use SilverStripe\Assets\Image;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;

/**
 * Shared plumbing for the bundled providers. Chiefly a guarded caller for methods that a sibling module's extension
 * adds (getAllImages, hasAvailableStock, AverageRating, Brand, StoreProfileName, …): those methods are not known to
 * static analysis because their modules are only soft suggests, so they are invoked defensively here.
 */
abstract class AbstractStructuredDataProvider implements StructuredDataProvider
{
    use Injectable;

    /**
     * Call $method on $object when it exists (including extension-provided methods), else return $default.
     *
     * @param array<int, mixed> $args
     */
    protected function callMethod(?object $object, string $method, mixed $default = null, array $args = []): mixed
    {
        if ($object !== null && $object->hasMethod($method)) {
            return call_user_func_array([$object, $method], $args);
        }

        return $default;
    }

    /**
     * Drop empty values ('' , null, []) from a node array, so blank schema.org properties are simply omitted.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function prune(array $data): array
    {
        return array_filter($data, static fn (mixed $value): bool => $value !== '' && $value !== null && $value !== []);
    }

    /**
     * Absolute URLs of a record's images, preferring the full gallery (product-gallery's getAllImages) and falling
     * back to the single primary Image(). Non-existent images are skipped.
     *
     * @return array<int, string>
     */
    protected function imageUrls(DataObject $record, int $limit = 0): array
    {
        $images = [];

        $all = $this->callMethod($record, 'getAllImages');
        if (is_iterable($all)) {
            foreach ($all as $image) {
                $images[] = $image;
            }
        } else {
            $primary = $this->callMethod($record, 'Image');
            if ($primary !== null) {
                $images[] = $primary;
            }
        }

        $urls = [];
        foreach ($images as $image) {
            if ($image instanceof Image && $image->exists()) {
                $url = (string) $image->getAbsoluteURL();
                if ($url !== '' && !in_array($url, $urls, true)) {
                    $urls[] = $url;
                }
            }
            if ($limit > 0 && count($urls) >= $limit) {
                break;
            }
        }

        return $urls;
    }

    /**
     * A BreadcrumbList node for a page, walking its SiteTree parent chain (root first) and ending on the page
     * itself. Returns {@code []} for a record that isn't in the page tree.
     *
     * @return array<string, mixed>
     */
    protected function buildBreadcrumbs(DataObject $page, SeoContext $context): array
    {
        if (!$page->hasMethod('AbsoluteLink')) {
            return [];
        }

        $chain = [];
        $node = $page;
        $guard = 0;
        while ($node instanceof DataObject && $node->exists() && $guard++ < 50) {
            array_unshift($chain, $node);
            $parentId = (int) $node->getField('ParentID');
            if ($parentId <= 0) {
                break;
            }
            $node = SiteTree::get()->byID($parentId);
        }

        if ($chain === []) {
            return [];
        }

        $items = [];
        $position = 1;

        // The on-page breadcrumb starts at Home; mirror that in JSON-LD by prepending a Home node, unless the chain
        // already begins at the site root.
        $base = rtrim((string) Director::absoluteBaseURL(), '/');
        $firstLink = rtrim((string) $this->callMethod($chain[0], 'AbsoluteLink'), '/');
        if ($firstLink !== $base) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => _t(self::class . '.BreadcrumbHome', 'Home'),
                'item' => $base . '/',
            ];
        }

        foreach ($chain as $crumb) {
            $items[] = $this->prune([
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => (string) ($crumb->getField('MenuTitle') ?: $crumb->getField('Title')),
                'item' => (string) $this->callMethod($crumb, 'AbsoluteLink'),
            ]);
        }

        return [
            '@type' => 'BreadcrumbList',
            '@id' => $context->nodeId('breadcrumbs'),
            'itemListElement' => $items,
        ];
    }

    /**
     * Format a date/datetime value as ISO-8601 for schema.org, or '' when empty/unparseable.
     */
    protected function isoDate(mixed $value): string
    {
        $value = (string) $value;
        if (trim($value) === '') {
            return '';
        }

        $timestamp = strtotime($value);

        return $timestamp !== false ? date('c', $timestamp) : '';
    }
}
