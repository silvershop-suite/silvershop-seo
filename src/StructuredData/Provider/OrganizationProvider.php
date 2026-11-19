<?php

declare(strict_types=1);

namespace SilverShop\Seo\StructuredData\Provider;

use SilverShop\Seo\StructuredData\SeoContext;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\ORM\DataObject;

/**
 * The site-level Organization + WebSite nodes, present on every page so other nodes (Product offers' seller,
 * article publisher, …) can reference them by {@code @id}.
 *
 * Data comes from {@code silvershop-suite/store-profile} when installed (name, logo, address, contact, VAT); otherwise
 * it falls back to the bare SiteConfig title + base URL. When {@code silvershop-suite/search} is installed a WebSite
 * {@code SearchAction} (the sitelinks search box) is added, pointing at the search page.
 */
class OrganizationProvider extends AbstractStructuredDataProvider
{
    use Configurable;

    /**
     * schema.org type for the shop entity. {@code Organization} by default; a shop with a physical storefront +
     * opening hours can set {@code LocalBusiness} or {@code Store}.
     */
    private static string $organization_type = 'Organization';

    public function getName(): string
    {
        return 'organization';
    }

    public function appliesTo(SeoContext $context): bool
    {
        return true;
    }

    public function getNodes(SeoContext $context): array
    {
        $config = $context->getSiteConfig();
        $base = rtrim($context->getBaseUrl(), '/') . '/';
        $name = (string) $this->callMethod($config, 'StoreProfileName', (string) $config->Title);
        if ($name === '') {
            $name = (string) $config->Title;
        }

        $organization = $this->prune([
            '@type' => (string) self::config()->get('organization_type') ?: 'Organization',
            '@id' => $context->siteId('organization'),
            'name' => $name,
            'url' => $base,
            'logo' => $this->logoUrl($config),
            'email' => (string) $config->getField('ContactEmail'),
            'telephone' => (string) $config->getField('ContactPhone'),
            'vatID' => (string) $config->getField('VatNumber'),
            'address' => $this->address($config),
            'sameAs' => $this->sameAs($config),
        ]);

        $website = $this->prune([
            '@type' => 'WebSite',
            '@id' => $context->siteId('website'),
            'url' => $base,
            'name' => $name,
            'publisher' => ['@id' => $context->siteId('organization')],
            'potentialAction' => $this->searchAction(),
        ]);

        return [$organization, $website];
    }

    private function logoUrl(DataObject $config): string
    {
        $logo = $this->callMethod($config, 'Logo');
        if ($logo instanceof Image && $logo->exists()) {
            return (string) $logo->getAbsoluteURL();
        }

        $default = $this->callMethod($config, 'SeoDefaultShareImage');
        if ($default instanceof Image && $default->exists()) {
            return (string) $default->getAbsoluteURL();
        }

        return '';
    }

    /**
     * @return array<string, string>
     */
    private function address(DataObject $config): array
    {
        if (!$this->callMethod($config, 'hasStoreAddress', false)) {
            return [];
        }

        $data = $this->callMethod($config, 'StoreAddressData', []);
        if (!is_array($data)) {
            return [];
        }

        return array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => (string) ($data['Address'] ?? ''),
            'addressLocality' => (string) ($data['City'] ?? ''),
            'addressRegion' => (string) ($data['State'] ?? ''),
            'postalCode' => (string) ($data['PostalCode'] ?? ''),
            'addressCountry' => (string) $config->getField('StoreCountryCode'),
        ], static fn (string $v): bool => $v !== '');
    }

    /**
     * Social-profile URLs from the SeoSiteConfigExtension "Social profiles" field (one per line).
     *
     * @return array<int, string>
     */
    private function sameAs(DataObject $config): array
    {
        $raw = (string) $config->getField('OrganizationSameAs');
        if (trim($raw) === '') {
            return [];
        }

        $urls = preg_split('/\r\n|\r|\n/', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $urls), static fn (string $v): bool => $v !== ''));
    }

    /**
     * A WebSite SearchAction pointing at the search page, when silvershop-suite/search is installed.
     *
     * @return array<string, mixed>
     */
    private function searchAction(): array
    {
        $searchClass = 'SilverShop\\Search\\SearchPage';
        if (!class_exists($searchClass)) {
            return [];
        }

        $page = DataObject::get($searchClass)->first();
        if ($page === null) {
            return [];
        }

        $link = (string) $this->callMethod($page, 'AbsoluteLink');
        if ($link === '') {
            return [];
        }

        return [
            '@type' => 'SearchAction',
            'target' => [
                '@type' => 'EntryPoint',
                'urlTemplate' => $link . '?q={search_term_string}',
            ],
            'query-input' => 'required name=search_term_string',
        ];
    }
}
