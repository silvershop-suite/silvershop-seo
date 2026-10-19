# silvershop-suite/seo

Centralised SEO for [SilverShop](https://github.com/silvershop/silvershop-core) (SilverStripe CMS 6). It makes SEO a
first-class, installable layer instead of markup baked into the theme or scattered across feature modules. It owns:

1. **Per-page meta** — canonical, meta robots (index/follow), OpenGraph and Twitter cards, with sensible auto-defaults
   and a social-image fallback chain.
2. **One JSON-LD `@graph`** — a single, coherent structured-data document assembled from pluggable providers
   (Product/Offer, Organization/WebSite, BreadcrumbList, category ItemList), enriched from other modules
   (reviews, brands, …) without editing them.
3. **Sitemap + robots.txt** — an XML sitemap (built on `wilr/silverstripe-googlesitemaps`) and a generated
   `robots.txt`.

It builds on the CMS 6 `updateMetaComponents` hook, so a theme only needs the standard `$MetaTags` in its `<head>` —
nothing else to wire up.

## Requirements

- PHP 8.3+
- `silverstripe/framework` ^6, `silverstripe/cms` ^6
- `silvershop/core` ^6
- `wilr/silverstripe-googlesitemaps` ^4 (the sitemap spine)

## Installation

```bash
composer require silvershop-suite/seo
```

Then run a dev/build (`/dev/build?flush=1`). The extensions are applied automatically to `SiteTree` and `SiteConfig`;
a new **SEO** tab appears on every page and in Settings.

## How it works

### Meta (the SEO tab)

Every page gains an **SEO** tab with:

- **Meta title** — overrides `<title>`; blank falls back to the page title.
- **Meta description** — the core field, moved here for one place to edit search snippets.
- **Canonical URL override** — blank = the page's own absolute URL (self-referential canonical).
- **Search engine indexing** — `index` / `follow` toggles. Cart, checkout, account and search pages are always
  `noindex` regardless (see `noindex_page_classes`), and `noindex` pages are excluded from the sitemap so the sitemap
  and robots agree.
- **Social sharing** — OpenGraph + Twitter title/description/image overrides and the Twitter card type.

Site-wide defaults live in **Settings → SEO**: a default share image, the Twitter site handle, the organization's
social-profile URLs (`sameAs`), and an optional hand-written `robots.txt`.

The **social-image fallback chain** is: page OG image → the product's primary / gallery image → SiteConfig default
share image → store-profile logo.

All of this is emitted through `SiteTree::updateMetaComponents()` as structured tag descriptors, so output is clean,
de-duplicated and theme-independent.

### Structured data — the `StructuredDataProvider` contract

The JSON-LD `@graph` is assembled by `JsonLdGraphService` from the providers registered in
`StructuredDataRegistry`. Each provider implements:

```php
interface StructuredDataProvider
{
    public function getName(): string;
    public function appliesTo(SeoContext $context): bool;   // right page/record type, module present, data non-empty
    public function getNodes(SeoContext $context): array;    // schema.org nodes, or @id-keyed fragments to merge
}
```

Nodes that share an `@id` are **merged**, so an enrichment fragment (e.g. an `aggregateRating`) lands inside the node
another provider created rather than producing a second, competing node. `SeoContext` mints the shared `@id`s
(`…/#product`, `…/#organization`, `…/#website`, `…/#breadcrumbs`) so every provider agrees.

Register your own provider in config:

```yaml
SilverShop\Seo\StructuredData\StructuredDataRegistry:
  providers:
    my-thing: '%$My\Module\MyProvider'
```

### Bundled providers

| Provider | Nodes / fragments | Active when |
|---|---|---|
| `OrganizationProvider` | `Organization` + `PostalAddress` + `WebSite` (+ `SearchAction`) | always (store-profile data when present, else SiteConfig title) |
| `ProductProvider` | `Product` + `Offer` (price, currency, availability, SKU, condition, seller) + `BreadcrumbList` | on `SilverShop\Page\Product` |
| `ListingProvider` | `ItemList` + `BreadcrumbList` | on `SilverShop\Page\ProductCategory` |
| `ReviewsAdapterProvider` | `aggregateRating` + `review[]` → Product node; shop `aggregateRating` → Organization node | `silvershop-suite/reviews` installed |
| `BrandAdapterProvider` | `brand` → Product node | `silvershop-suite/brands` installed, product has a brand |
| `ArticleProvider` | `BlogPosting` | `silverstripe/blog` installed |
| `EventProvider` | `Event` | an events page class is listed in `event_classes` |

The reviews, brands, blog and events integrations are **seo-side adapters**: they read the other module's data behind
a `class_exists` guard and contribute to the graph **without editing that module**. They are dormant (and emit
nothing) when the module is absent. Offer availability (`InStock` / `OutOfStock`) is read from `silvershop/stock`
when present.

#### Known caveat — reviews double-emit

`silvershop-suite/reviews` still prints its own standalone `<script type="application/ld+json">` on product and shop pages.
With both modules installed, a page therefore emits the rating **twice**: once inside this module's `@graph`, once in
the reviews module's own block. Google tolerates duplicate `aggregateRating`, but the clean fix is a **coordinated
reviews release** in which reviews implements this contract and self-suppresses its standalone markup when
`silvershop-suite/seo` is present. That is a documented follow-up; this module does not (and must not) edit reviews.

### Sitemap

The XML sitemap is served at `/sitemap.xml` by `wilr/silverstripe-googlesitemaps`, which already includes every
published page — Products, ProductCategories, brand pages and content pages are all `SiteTree`, so they are covered
out of the box (respecting `ShowInSearch`), and each page's primary image is included. This module additionally
excludes `noindex` pages so the sitemap matches the robots directives.

### robots.txt

`/robots.txt` is served by `RobotsController`. By default it references the sitemap and disallows the cart, checkout,
account and search pages (resolved from their live URLs). A merchant can supply a full custom body in
**Settings → SEO → Custom robots.txt**.

```yaml
SilverShop\Seo\Control\RobotsController:
  include_sitemap: true
  sitemap_path: 'sitemap.xml'
  disallow:
    - '/dev'
```

## Configuration reference

```yaml
SilverShop\Seo\Extension\SeoSiteTreeExtension:
  og_type_default: 'website'
  noindex_page_classes:
    - 'SilverShop\Page\CartPage'
    - 'SilverShop\Page\CheckoutPage'
    - 'SilverShop\Page\AccountPage'
    - 'SilverShop\Search\SearchPage'

SilverShop\Seo\StructuredData\Provider\OrganizationProvider:
  organization_type: 'Organization'   # or LocalBusiness / Store for a physical storefront

SilverShop\Seo\StructuredData\Provider\ProductProvider:
  item_condition: 'https://schema.org/NewCondition'
  price_valid_period: '+1 year'       # strtotime() expression for Offer priceValidUntil; blank = omit
  image_limit: 6

SilverShop\Seo\StructuredData\Provider\EventProvider:
  event_classes:
    - 'My\Events\EventPage'
```

## Multi-locale (hreflang)

`tractorcow/silverstripe-fluent` is not required. hreflang alternates and locale-correct canonicals are intended as a
guarded, forward-looking addition that activates when Fluent is installed.

## Theme note

Per the storefront direction, the theme renders **no** JSON-LD and defers to this module: it only needs the standard
`$MetaTags` in `<head>`. Core's inline microdata can stay as a no-JS fallback; the JSON-LD `@graph` is the single
source of truth where they overlap.

## License

BSD-3-Clause.

## Credits

Built and maintained by **Remy Vaartjes** (<remy@xd.nl>) — part of the **SilverShop-Suite**.
