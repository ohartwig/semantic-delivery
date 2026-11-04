# Semantic Delivery — TYPO3 Extension

[![TYPO3 14](https://img.shields.io/badge/TYPO3-14.x-orange.svg)](https://get.typo3.org/)
[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-blue.svg)](https://php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

Schema.org enrichment and multi-channel content transformation for TYPO3. Delivers structured content across Website, AI Agent, Voice, and Social Media channels with automated distribution to external platforms.

## Features

- **Schema.org JSON-LD** — Automatic structured data enrichment for TYPO3 content
- **Multi-Channel Transformation** — Optimized output for Website, AI Agent, Voice, and Social Media
- **Platform Distributors** — Distributor classes for LinkedIn, X/Twitter, Instagram, Facebook, Bluesky, Medium and Dev.to (native APIs) and for Ayrshare, Buffer, webhooks and n8n; they post only when the caller passes the credentials (see [Distribution](#distribution))
- **Podcast Episode Detection** — Automatic new-episode monitoring with multi-platform job creation
- **Channel Detection** — Middleware-based automatic detection of requesting channel
- **OAuth Token Management** — Encrypted per-platform token storage with refresh support
- **Schema Caching** — Performance-optimized schema generation with TYPO3 caching
- **DDD 4-Layer Architecture** — Strict layer separation enforced by deptrac

## Installation

```bash
composer require koh/semantic-delivery
```

### Requirements

- PHP 8.3+
- TYPO3 14.0

## Architecture

```
Classes/
├── Domain/          # Channel models, schema value objects, contracts
├── Application/     # Transformation services, distribution orchestration
├── Infrastructure/  # Distribution adapters, schema generators, TCA helpers
└── Presentation/    # Controllers, event listeners
```

### Database Tables

| Table | Purpose |
|-------|---------|
| `tx_semanticdelivery_channel_content` | Channel-specific content variants |
| `tx_semanticdelivery_distribution_job` | Distribution job queue |
| `tx_semanticdelivery_schema_cache` | Cached Schema.org data |
| `tx_semanticdelivery_oauth_token` | Encrypted platform OAuth tokens |

### Page Fields

| Field | Type | Description |
|-------|------|-------------|
| `tx_semanticdelivery_schema_type` | Select | Schema.org type override (auto-detect by default, grouped by Article / WebPage / Other family — see [Schema.org Generation](#schemaorg-generation)) |
| `tx_semanticdelivery_channels` | CheckBox | Delivery channels (Website, AI Agent, Voice, Social Media) |

## Schema.org Generation

Each frontend response is enriched with one or more `<script type="application/ld+json">` blocks. Generators are auto-discovered (`semantic_delivery.schema_generator` tag) and ordered by priority — every generator that returns `true` from `canHandle()` contributes one document.

### Generator Priority Order

| Priority | Generator | Output |
|---|---|---|
| 70 | `FaqSchemaGenerator` | `FAQPage` (CType `*faq*` or Q&A patterns) |
| 60 | `ProductSchemaGenerator` / `PackagesGridProductSchemaGenerator` / `ServiceSchemaGenerator` | `Product`, `Service`, `Offer` |
| 55 | `BreadcrumbListSchemaGenerator`, `OrganizationSchemaGenerator`, … | `BreadcrumbList`, `Organization` |
| **50** | **`ArticleSchemaGenerator`** | **Article family** — see resolver |
| **45** | **`WebPageSchemaGenerator`** | **WebPage family** — see resolver |
| 30 | `ContentBlockSchemaMapper` | Site-settings-driven CType → schema mapping |
| 10 | `AutoDetectSchemaGenerator` | Fuzzy fallback (only when resolver did not match) |

### Article / WebPage Family Routing

`Application/Service/SchemaTypeResolverService` is the **single source of truth** for picking a schema type from the Article- or WebPage-family. Both `ArticleSchemaGenerator` (priority 50) and `WebPageSchemaGenerator` (priority 45) delegate their `canHandle()` to the resolver, so a page emits **either** an Article-family **or** a WebPage-family document — never both.

Resolution order (highest priority first):

1. **Manual override** — `pages.tx_semanticdelivery_schema_type`. Article-/WebPage-family values are honoured directly. Other values (e.g. `Product`, `FAQPage`) cause the resolver to return `null` so the dedicated generator owns the page.

2. **Slug heuristic — language-stable index/section patterns only:**

   | Slug pattern | Schema Type |
   |---|---|
   | `/blog` (root) | `CollectionPage` |
   | `/blog/<sub>` | `BlogPosting` |
   | `/news`, `/press`, `/presse` (root) | `CollectionPage` |
   | `/news/<sub>`, `/press/<sub>`, `/presse/<sub>` | `NewsArticle` |
   | `/portfolio`, `/referenz*` (root) | `CollectionPage` |
   | `/portfolio/<sub>`, `/referenz*/<sub>` | `ItemPage` |

   Language-specific patterns (`/kontakt`, `/ueber-uns`, `/about`, `/contact`, `/team`, `/profil`) are **not** auto-detected — for these pages the editor sets the override in the backend (the dropdown shows a description per type).

3. **Hero content block hint** — first matching CType wins:

   | Hero CType | Family | Schema Type |
   |---|---|---|
   | `moselwal_bloghero` | article | `BlogPosting` |
   | `moselwal_pagehero` | article | `Article` |
   | `moselwal_herocard` | article | `Article` |
   | `moselwal_teaser` | article | `NewsArticle` |
   | `moselwal_hero` | webpage | `WebPage` |
   | `moselwal_olehero` | webpage | `AboutPage` |
   | `moselwal_nozzlehero` | webpage | `WebPage` |

4. **Keywords heuristic** (`pages.keywords` substrings):

   | Keyword contains | Schema Type |
   |---|---|
   | `tech`, `technical` | `TechArticle` |
   | `paper`, `study`, `research` | `ScholarlyArticle` |
   | `report` | `Report` |
   | `social` | `SocialMediaPosting` |
   | `blog` | `BlogPosting` |
   | `news`, `press` | `NewsArticle` |
   | `collection`, `index` | `CollectionPage` |

5. **Default** — `Article` for standard pages (`doktype` 1 or 2 with content), otherwise `WebPage`.

The resolver returns `null` (and the dedicated generator takes over) when the page contains FAQ / Service / Product / Podcast / PackagesGrid CTypes, or sits below `/leistungen`, `/impressum`, `/datenschutz`, `/karriere`, `/podcasts`.

### Backend Override Dropdown

The page property dropdown is grouped via `--div--` separators and every option carries a descriptive tooltip rendered by the TYPO3 backend:

```
Auto (detect via Slug / Hero-CB / Keywords)
── Article family ──
  Article, BlogPosting, NewsArticle, TechArticle,
  ScholarlyArticle, Report, SocialMediaPosting
── WebPage family ──
  WebPage, AboutPage, ContactPage, CollectionPage,
  ItemPage, ProfilePage, QAPage
── Other ──
  FAQPage, Product, HowTo, Organization, LocalBusiness
```

The descriptions guide editors on when to pick each type — important for language-specific pages (`/kontakt`, `/ueber-uns`, `/about` …) that the resolver does not auto-detect. Values outside the Article/WebPage families let the dedicated generator (FAQ, Product, Organization, …) own the page.

## Distribution

### Supported Platforms

#### Social Media (short-form)

| Platform | Distributor | Auth Method | Character Limit |
|----------|-------------|-------------|-----------------|
| Bluesky | `BlueskyDistributor` | App Password | 300 graphemes |
| LinkedIn | `LinkedInDistributor` | OAuth 2.0 Access Token | 3,000 chars |
| X/Twitter | `TwitterXDistributor` | Bearer Token (OAuth 2.0) | 280 chars |
| Instagram | `InstagramDistributor` | Page Access Token | 2,200 chars |
| Facebook | `FacebookDistributor` | Page Access Token | 63,000 chars |

#### Blog Platforms (long-form)

| Platform | Distributor | Auth Method | Content Format |
|----------|-------------|-------------|----------------|
| Dev.to | `DevToDistributor` | API Key | Markdown |

Blog distributors include canonical URL attribution back to the original TYPO3 page.

> **Note:** Medium has discontinued issuing new API integration tokens. The MediumDistributor has been removed.

#### Proxy Services

| Platform | Distributor | Auth Method |
|----------|-------------|-------------|
| Ayrshare | `AyrshareDistributor` | API Key |
| Buffer | `BufferDistributor` | Access Token |
| n8n | `N8nDistributor` | Webhook URL |
| Late.dev | `LateDevDistributor` | API Key |
| Generic Webhook | `WebhookDistributor` | Custom URL |

### Credentials are not read from site settings

The distributors take their credentials from the `$options` array of `DistributionService::distribute()` and refuse without them. Nothing in this package maps site settings into that array, and `semantic-delivery:process-jobs` passes none, so a queued job ends `failed` with a "… is required" message until an integration supplies the credentials itself.

Up to 3.2 the site set defined `social` and `blogging` settings subtrees under `semanticDelivery` for platform credentials, and a per-page platform selector read it. Nothing ever passed those values to a distributor, so an installation held long-lived tokens for its public channels in its site configuration for a feature that could not use them (AUD-026-F01). The settings tree, the two unread settings `semanticDelivery.distribution.enabled` / `.defaultDistributor` and the page fields `tx_semanticdelivery_distribution_enabled` / `_platforms` were removed. **Do not put platform credentials into site settings.** If an installation still has them in `config/sites/*/config.yaml`, delete them there, delete the secrets behind them, and revoke the tokens at the platforms.

### CLI Commands

#### Detect new podcast episodes

```bash
# Dry run
vendor/bin/typo3 semantic-delivery:detect-episodes --dry-run

# Create distribution jobs
vendor/bin/typo3 semantic-delivery:detect-episodes

# Auto-publish without review
vendor/bin/typo3 semantic-delivery:detect-episodes --auto-publish
```

> **Cache key v3.0.4+:** the episode detector reads from the `content_blocks_collection_rssfeed` cache with `'rssfeed_' . hash('sha256', $feedUrl)`. This must match the write key in `moselwal/content-blocks-collection`'s `PodcastFeedService::cacheIdentifierFor()` (previously md5 — the mismatch meant the detector found no episodes). Its own `podcast_hash_*` / `podcast_guids_*` keys still use md5, because the detector maintains both sides itself.

#### Process pending jobs

```bash
vendor/bin/typo3 semantic-delivery:process-jobs
```

#### Recommended cron schedule

```cron
*/15 * * * * cd /app && vendor/bin/typo3 semantic-delivery:detect-episodes
*/5  * * * * cd /app && vendor/bin/typo3 semantic-delivery:process-jobs
```

### Backend Module

Available under **Content → Semantic Delivery** (admin access):

- **Preview** — View transformed content per platform before publishing
- **Queue** — Manage pending distribution jobs
- **Log** — Review completed and failed distributions with external URLs

### Adding a New Distributor

New distributors are auto-discovered via dependency injection:

1. Create a class implementing `DistributorInterface` in `Classes/Infrastructure/Distribution/`
2. Optionally create a `TransformerInterface` in `Classes/Infrastructure/Transformer/`

```php
final class MastodonDistributor implements DistributorInterface
{
    public function getIdentifier(): string { return 'mastodon'; }
    public function distribute(ChannelContent $content, string $platform, array $options = []): DistributionResult
    {
        // Call Mastodon API
        return DistributionResult::success($statusId, $statusUrl);
    }
    public function getAvailablePlatforms(): array { return ['mastodon']; }
    public function testConnection(): bool { return true; }
}
```

### OAuth Token Service

For platforms requiring OAuth 2.0 with token refresh:

```php
$oauthService->storeToken(
    platform: 'linkedin',
    siteIdentifier: 'example',
    accessToken: $accessToken,
    refreshToken: $refreshToken,
    expiresAt: time() + 3600,
    scope: 'w_organization_social',
    accountId: '12345678',
    accountName: 'Example Org',
);

$token = $oauthService->getToken('linkedin', 'example');

if ($oauthService->isTokenExpired('linkedin', 'example')) {
    // Refresh token flow
}
```

Tokens are encrypted at rest using AES-256-CBC with the TYPO3 encryption key.

## Configuration Reference

### Site Settings

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `semanticDelivery.enabled` | bool | `true` | Master switch |
| `semanticDelivery.schema.enabled` | bool | `true` | JSON-LD generation |
| `semanticDelivery.schema.autoDetect` | bool | `true` | Auto-detect schema types |
| `semanticDelivery.channels.socialMedia.enabled` | bool | `true` | Social media channel |

## Development

```bash
composer install
composer test                    # Unit tests
composer phpstan                 # Static analysis (level 8)
composer deptrac                 # DDD layer enforcement
vendor/bin/php-cs-fixer fix      # Code style (PER-CS3x0)
```

## Dependencies

| Package | Type | Purpose |
|---------|------|---------|
| `koh/content-intelligence` | Optional | Content quality analysis |
| `moselwal/secret-resolver` | Suggested | `%secret()%` syntax for credentials |
| `koh/dev` | Dev | Shared QA tooling |

## License

MIT — see [LICENSE](LICENSE) for details.
