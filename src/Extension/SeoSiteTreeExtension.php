<?php

declare(strict_types=1);

namespace SilverShop\Seo\Extension;

use SilverShop\Extension\ShopConfigExtension;
use SilverShop\Page\Product;
use SilverShop\Seo\StructuredData\JsonLdGraphService;
use SilverShop\Seo\StructuredData\SeoContext;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\Image;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldGroup;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * The per-page SEO layer, applied to every {@link \SilverStripe\CMS\Model\SiteTree} (shop, blog, events, content).
 *
 * Adds a dedicated "SEO" CMS tab (meta title + the moved core meta description, canonical override, robots
 * index/follow toggles, OpenGraph + Twitter overrides) and injects the corresponding tags — plus the assembled
 * JSON-LD {@code @graph} — through the CMS 6 {@code updateMetaComponents} hook, so the theme only needs the standard
 * {@code $MetaTags} in its {@code <head>}. Everything is guarded so blank fields stay inert and sensible auto-defaults
 * apply (canonical = self, {@code index,follow} for public pages, {@code noindex,follow} for cart/checkout/account/
 * search). Also feeds the sitemap: {@code noindex} pages are excluded so sitemap and robots agree.
 *
 * @extends Extension<\SilverStripe\CMS\Model\SiteTree>
 * @property string $MetaTitle
 * @property string $CanonicalURLOverride
 * @property bool $RobotsIndex
 * @property bool $RobotsFollow
 * @property string $OGTitle
 * @property string $OGDescription
 * @property string $TwitterCard
 * @property int $OGImageID
 * @method Image OGImage()
 */
class SeoSiteTreeExtension extends Extension
{
    private static array $db = [
        'MetaTitle' => 'Varchar(255)',
        'CanonicalURLOverride' => 'Varchar(255)',
        'RobotsIndex' => 'Boolean(1)',
        'RobotsFollow' => 'Boolean(1)',
        'OGTitle' => 'Varchar(255)',
        'OGDescription' => 'Text',
        'TwitterCard' => "Enum('summary,summary_large_image','summary_large_image')",
    ];

    private static array $has_one = [
        'OGImage' => Image::class,
    ];

    private static array $owns = [
        'OGImage',
    ];

    /**
     * Page classes forced to noindex,follow regardless of their field value (cart/checkout/account/search). Guarded
     * by class_exists, so entries for modules that aren't installed are simply ignored.
     *
     * @var array<int, string>
     */
    private static array $noindex_page_classes = [
        'SilverShop\\Page\\CartPage',
        'SilverShop\\Page\\CheckoutPage',
        'SilverShop\\Page\\AccountPage',
        'SilverShop\\Search\\SearchPage',
        'SilverShop\\Comparison\\Pagetypes\\ProductComparisonPage',
    ];

    /**
     * Default OpenGraph type for non-product pages.
     */
    private static string $og_type_default = 'website';

    protected function updateCMSFields(FieldList $fields): void
    {
        // On owners that auto-scaffold their $db fields into the form (e.g. SilverShop\Page\Product), these
        // extension fields are already present; remove the scaffolded copies so re-adding them to the SEO tab
        // below cannot duplicate a name (FieldList::getDataFields() throws on duplicates). SiteTree pages don't
        // scaffold ext $db fields, so this is a no-op there.
        $fields->removeByName([
            'MetaTitle',
            'CanonicalURLOverride',
            'RobotsIndex',
            'RobotsFollow',
            'OGTitle',
            'OGDescription',
            'TwitterCard',
            'OGImage',
        ]);

        $seoFields = [
            TextField::create('MetaTitle', _t(self::class . '.MetaTitle', 'Meta title'))
                ->setDescription(_t(self::class . '.MetaTitleDesc', 'Overrides the <title> tag. Blank = the page title.')),
        ];

        // Move the core MetaDescription field into the SEO tab, keeping the field object (so any extension
        // decoration on it survives) rather than recreating it.
        $metaDescription = $fields->dataFieldByName('MetaDescription');
        if ($metaDescription) {
            $fields->removeByName('MetaDescription');
            $metaDescription->setDescription(_t(
                self::class . '.MetaDescriptionDesc',
                'Shown in search-engine result snippets. Around 150–160 characters works best.'
            ));
            $seoFields[] = $metaDescription;
        }

        $seoFields = array_merge($seoFields, [
            TextField::create('CanonicalURLOverride', _t(self::class . '.Canonical', 'Canonical URL override'))
                ->setDescription(_t(
                    self::class . '.CanonicalDesc',
                    'Blank = this page\'s own address. Set only to point search engines at a different canonical URL.'
                )),
            FieldGroup::create(
                _t(self::class . '.Robots', 'Search engine indexing'),
                CheckboxField::create('RobotsIndex', _t(self::class . '.RobotsIndex', 'Allow indexing (index)')),
                CheckboxField::create('RobotsFollow', _t(self::class . '.RobotsFollow', 'Follow links (follow)'))
            )->setDescription(_t(
                self::class . '.RobotsDesc',
                'Cart, checkout, account and search pages are always noindex regardless of these settings.'
            )),
            HeaderField::create('SeoSocialHeader', _t(self::class . '.SocialHeader', 'Social sharing (OpenGraph & Twitter)')),
            TextField::create('OGTitle', _t(self::class . '.OGTitle', 'Social title'))
                ->setDescription(_t(self::class . '.OGTitleDesc', 'Blank = meta title / page title.')),
            TextareaField::create('OGDescription', _t(self::class . '.OGDescription', 'Social description'))
                ->setDescription(_t(self::class . '.OGDescriptionDesc', 'Blank = meta description.')),
            $ogImage = UploadField::create('OGImage', _t(self::class . '.OGImage', 'Social share image')),
            DropdownField::create('TwitterCard', _t(self::class . '.TwitterCard', 'Twitter card type'), [
                'summary' => _t(self::class . '.TwitterSummary', 'Summary'),
                'summary_large_image' => _t(self::class . '.TwitterLarge', 'Summary with large image'),
            ]),
        ]);

        $ogImage->setFolderName('seo/social');
        $ogImage->getValidator()->setAllowedExtensions(['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp']);

        $fields->addFieldsToTab('Root.SEO', $seoFields);
    }

    /**
     * Inject canonical / robots / OpenGraph / Twitter and the JSON-LD @graph as structured MetaComponents descriptors
     * (CMS 6). See {@link \SilverStripe\CMS\Model\SiteTree::MetaComponents()}.
     *
     * @param array<string, mixed> $tags
     */
    protected function updateMetaComponents(array &$tags): void
    {
        // MetaComponents is also consulted in the CMS; og/jsonld belong only to the rendered front-end <head>.
        if ($this->isAdminRequest()) {
            return;
        }

        $owner = $this->getOwner();

        $metaTitle = trim((string) $owner->getField('MetaTitle'));
        if ($metaTitle !== '') {
            $tags['title'] = ['tag' => 'title', 'content' => $metaTitle];
        }

        $canonical = $this->canonicalUrl();
        if ($canonical !== '') {
            $tags['ssseoCanonical'] = [
                'tag' => 'link',
                'attributes' => ['rel' => 'canonical', 'href' => $canonical],
            ];
        }

        $tags['ssseoRobots'] = [
            'attributes' => ['name' => 'robots', 'content' => $this->EffectiveRobots()],
        ];

        foreach ($this->socialTags() as $key => $descriptor) {
            $tags[$key] = $descriptor;
        }

        $json = JsonLdGraphService::singleton()->jsonFor($this->seoContext());
        if ($json !== '') {
            $tags['ssseoJsonLd'] = [
                'tag' => 'script',
                'attributes' => ['type' => 'application/ld+json'],
                'content' => $json,
            ];
        }
    }

    /**
     * Default the robots toggles to on for a new page (fired by {@see DataObject::populateDefaults()}), so the CMS
     * checkboxes show checked and a freshly-created page is index,follow by default. Field-type defaults such as
     * {@code Boolean(1)} are not applied to in-memory new objects, so this hook does it explicitly. Pages hydrated
     * from the database keep their stored values (this hook does not fire for them).
     */
    protected function onAfterPopulateDefaults(): void
    {
        $owner = $this->getOwner();
        if ($owner->getField('RobotsIndex') === null) {
            $owner->setField('RobotsIndex', 1);
        }
        if ($owner->getField('RobotsFollow') === null) {
            $owner->setField('RobotsFollow', 1);
        }
    }

    /**
     * Sitemap hook (wilr/silverstripe-googlesitemaps): exclude pages that resolve to noindex so the sitemap and the
     * robots meta agree.
     */
    protected function alterCanIncludeInGoogleSitemap(mixed $can): bool
    {
        if (!$this->isPageIndexable()) {
            return false;
        }

        return (bool) $can;
    }

    /**
     * Whether this page should be indexed: its RobotsIndex field is on AND it isn't a forced-noindex page type.
     */
    public function isPageIndexable(): bool
    {
        if (!$this->getOwner()->getField('RobotsIndex')) {
            return false;
        }

        return !$this->isNoindexPageType();
    }

    /**
     * The effective robots directive, e.g. "index,follow" or "noindex,follow".
     */
    public function EffectiveRobots(): string
    {
        $index = $this->isPageIndexable() ? 'index' : 'noindex';
        $follow = $this->getOwner()->getField('RobotsFollow') ? 'follow' : 'nofollow';

        return $index . ',' . $follow;
    }

    private function isNoindexPageType(): bool
    {
        $owner = $this->getOwner();
        foreach ((array) $owner->config()->get('noindex_page_classes') as $class) {
            if (is_string($class) && $class !== '' && class_exists($class) && $owner instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function canonicalUrl(): string
    {
        $owner = $this->getOwner();
        $override = trim((string) $owner->getField('CanonicalURLOverride'));
        if ($override !== '') {
            return str_starts_with($override, 'http') ? $override : (string) Director::absoluteURL($override);
        }

        $base = $owner->hasMethod('AbsoluteLink') ? (string) $owner->AbsoluteLink() : '';

        // Action/slug sub-pages (e.g. a brand detail /brands/<slug> served by BrandsPage) share their parent page's
        // AbsoluteLink(), which would wrongly canonicalise every sub-page to the index. When the live request is a
        // deeper path under the page's own link, self-canonicalise to that request URL (query string stripped).
        $request = Controller::curr()?->getRequest();
        if ($request) {
            $current = (string) Director::absoluteURL('/' . ltrim((string) $request->getURL(false), '/'));
            if ($base === '') {
                return $current;
            }
            $basePath = rtrim((string) parse_url($base, PHP_URL_PATH), '/');
            $curPath = rtrim((string) parse_url($current, PHP_URL_PATH), '/');
            if ($curPath !== $basePath && str_starts_with($curPath . '/', $basePath . '/')) {
                return $current;
            }
        }

        return $base;
    }

    /**
     * OpenGraph + Twitter descriptors, keyed uniquely.
     *
     * @return array<string, array<string, mixed>>
     */
    private function socialTags(): array
    {
        $owner = $this->getOwner();

        $title = $this->firstNonEmpty([
            (string) $owner->getField('OGTitle'),
            (string) $owner->getField('MetaTitle'),
            (string) $owner->getField('Title'),
        ]);
        $description = $this->firstNonEmpty([
            (string) $owner->getField('OGDescription'),
            (string) $owner->getField('MetaDescription'),
        ]);
        $url = $this->canonicalUrl();
        $image = $this->resolveShareImageUrl();

        $tags = [];

        $og = [
            'og:title' => $title,
            'og:description' => $description,
            'og:type' => $this->ogType(),
            'og:url' => $url,
            'og:site_name' => $this->siteName(),
            'og:image' => $image,
        ];
        foreach ($og as $property => $content) {
            if ($content === '') {
                continue;
            }
            $tags['ssseoOg_' . str_replace(':', '_', $property)] = [
                'attributes' => ['property' => $property, 'content' => $content],
            ];
        }

        if ($owner instanceof Product) {
            $tags['ssseoOg_product_price_amount'] = [
                'attributes' => [
                    'property' => 'product:price:amount',
                    'content' => number_format((float) $owner->sellingPrice(), 2, '.', ''),
                ],
            ];
            $tags['ssseoOg_product_price_currency'] = [
                'attributes' => [
                    'property' => 'product:price:currency',
                    'content' => ShopConfigExtension::get_site_currency(),
                ],
            ];
        }

        $card = (string) $owner->getField('TwitterCard') ?: 'summary_large_image';
        $twitter = [
            'twitter:card' => $card,
            'twitter:title' => $title,
            'twitter:description' => $description,
            'twitter:image' => $image,
            'twitter:site' => $this->twitterSite(),
        ];
        foreach ($twitter as $name => $content) {
            if ($content === '') {
                continue;
            }
            $tags['ssseoTw_' . str_replace(':', '_', $name)] = [
                'attributes' => ['name' => $name, 'content' => $content],
            ];
        }

        return $tags;
    }

    private function ogType(): string
    {
        if ($this->getOwner() instanceof Product) {
            return 'product';
        }

        return (string) $this->getOwner()->config()->get('og_type_default') ?: 'website';
    }

    /**
     * Social image fallback chain: page OG image → product/gallery image → SiteConfig default → store-profile logo.
     */
    private function resolveShareImageUrl(): string
    {
        $owner = $this->getOwner();

        $ownImage = $this->imageUrl($owner, 'OGImage');
        if ($ownImage !== '') {
            return $ownImage;
        }

        foreach ($this->ownerImages($owner) as $url) {
            return $url;
        }

        $config = SiteConfig::current_site_config();
        $default = $this->imageUrl($config, 'SeoDefaultShareImage');
        if ($default !== '') {
            return $default;
        }

        return $this->imageUrl($config, 'Logo');
    }

    /**
     * Absolute URL of an Image returned by $object->$method(), or '' when absent/empty.
     */
    private function imageUrl(object $object, string $method): string
    {
        if (!$object->hasMethod($method)) {
            return '';
        }
        $image = call_user_func([$object, $method]);

        return ($image instanceof Image && $image->exists()) ? (string) $image->getAbsoluteURL() : '';
    }

    /**
     * Absolute URLs of the page's own images (gallery first, then primary), when it is image-bearing.
     *
     * @return array<int, string>
     */
    private function ownerImages(object $owner): array
    {
        $urls = [];
        $images = [];

        if ($owner->hasMethod('getAllImages')) {
            $all = call_user_func([$owner, 'getAllImages']);
            if (is_iterable($all)) {
                foreach ($all as $image) {
                    $images[] = $image;
                }
            }
        } elseif ($owner->hasMethod('Image')) {
            $images[] = call_user_func([$owner, 'Image']);
        }

        foreach ($images as $image) {
            if ($image instanceof Image && $image->exists()) {
                $urls[] = (string) $image->getAbsoluteURL();
            }
        }

        return $urls;
    }

    private function siteName(): string
    {
        $config = SiteConfig::current_site_config();
        if ($config->hasMethod('StoreProfileName')) {
            $name = (string) call_user_func([$config, 'StoreProfileName']);
            if ($name !== '') {
                return $name;
            }
        }

        return (string) $config->getField('Title');
    }

    private function twitterSite(): string
    {
        return (string) SiteConfig::current_site_config()->getField('TwitterSiteHandle');
    }

    /**
     * @param array<int, string> $candidates
     */
    private function firstNonEmpty(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function seoContext(): SeoContext
    {
        $owner = $this->getOwner();

        return SeoContext::create($owner, Controller::curr(), (string) $owner->AbsoluteLink());
    }

    private function isAdminRequest(): bool
    {
        $controller = Controller::curr();
        if ($controller === null) {
            return false;
        }
        $leftAndMain = 'SilverStripe\\Admin\\LeftAndMain';

        return class_exists($leftAndMain) && $controller instanceof $leftAndMain;
    }
}
