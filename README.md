# Semantic Delivery — TYPO3 Extension

[![TYPO3 14](https://img.shields.io/badge/TYPO3-14.x-orange.svg)](https://get.typo3.org/)
[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-blue.svg)](https://php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

Schema.org enrichment and multi-channel content transformation for TYPO3. Delivers structured content across Website, AI Agent, Voice, and Social Media channels with automated distribution to external platforms.

## Features

- **Schema.org JSON-LD** — Automatic structured data enrichment for TYPO3 content
- **Multi-Channel Transformation** — Optimized output for Website, AI Agent, Voice, and Social Media
- **Direct Platform Distribution** — Post directly to LinkedIn, X/Twitter, Instagram, Facebook, Bluesky, Medium, and Dev.to via native APIs
- **Proxy Distribution** — Push via Ayrshare, Buffer, webhooks, or n8n
- **Per-Page Platform Selection** — Editors choose which platforms each page is distributed to; only platforms with configured credentials are shown
- **Blog Cross-Posting** — Publish full articles to Medium (HTML) and Dev.to (Markdown) with canonical URL attribution
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

- PHP 8.2+
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
| `tx_semanticdelivery_distribution_enabled` | Check | Enable distribution for this page |
| `tx_semanticdelivery_distribution_platforms` | CheckBox | Target platforms (dynamically populated, see below) |

The platform selector only shows platforms that have credentials configured in the current site's settings. This prevents editors from selecting targets that would fail.

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

### Setup

#### 1. Enable distribution in site settings

```yaml
semanticDelivery:
  channels:
    socialMedia:
      enabled: true
  distribution:
    enabled: true
    defaultDistributor: 'bluesky'
```

#### 2. Configure platform credentials

Credentials are configured per site in `config/sites/<name>/config.yaml`. Sensitive tokens use the `%secret()%` syntax (see [moselwal/secret-resolver](https://gitlab.moselwal.io/development/moselwal/secret-resolver)), public identifiers go directly into the YAML.

Only platforms with a non-empty primary credential appear in the backend page editor. Platforms without credentials are hidden from the editor so they cannot select targets that would fail.

#### 3. Run the database schema update

```bash
vendor/bin/typo3 database:updateschema
```

#### 4. Per-page platform selection

In the TYPO3 backend, each page has a **Semantic Delivery** tab:

1. Check **Enable Distribution**
2. Select target platforms (only configured platforms are shown)
3. Save and publish

The distribution system respects this selection — pages are only distributed to checked platforms.

---

### Platform Credential Guides

#### Bluesky

**Auth:** App Password (no OAuth required)
**Scope:** Full post access

1. Log in to [bsky.app](https://bsky.app)
2. Go to **Settings → Privacy and Security → App Passwords**
3. Click **Add App Password**, give it a name (e.g. "TYPO3 Distribution")
4. Copy the generated password

```yaml
# config/sites/my-site/config.yaml
semanticDelivery:
  social:
    bluesky:
      handle: 'yourhandle.bsky.social'             # or custom domain
      appPassword: '%secret(BLUESKY_APP_PASSWORD)%'
```

```env
# secrets/dev/secrets.env (via sops)
BLUESKY_APP_PASSWORD=xxxx-xxxx-xxxx-xxxx
```

Token does not expire. Revoke at any time in App Passwords settings.

---

#### LinkedIn

**Auth:** OAuth 2.0 Access Token
**Modes:** Personal posting (`w_member_social`) or Organization posting (`w_organization_social`)

**Step 1 — Create a LinkedIn App:**

1. Go to [linkedin.com/developers/apps](https://www.linkedin.com/developers/apps) → **Create app**
2. Fill in App name, associate your LinkedIn Company Page, upload a logo
3. Under **Products** tab → enable **Share on LinkedIn** (instant access)
4. Under **Auth** tab → note your **Client ID** and **Client Secret**
5. Add a **Redirect URL** (e.g. `https://yourdomain.com/linkedin-callback`)

**Step 2 — Get an Access Token:**

Open this URL in a browser (replace placeholders):

```
https://www.linkedin.com/oauth/v2/authorization?response_type=code&client_id=YOUR_CLIENT_ID&redirect_uri=YOUR_REDIRECT_URL&scope=openid%20profile%20w_member_social
```

After consent, the browser redirects to your redirect URL with `?code=AUTH_CODE`. Exchange it:

```bash
curl -X POST https://www.linkedin.com/oauth/v2/accessToken \
  -d "grant_type=authorization_code" \
  -d "code=AUTH_CODE" \
  -d "redirect_uri=YOUR_REDIRECT_URL" \
  -d "client_id=YOUR_CLIENT_ID" \
  -d "client_secret=YOUR_CLIENT_SECRET"
```

The response contains `access_token` (valid for 60 days) and `refresh_token`.

**Step 3 — Get your Person ID:**

```bash
curl -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  "https://api.linkedin.com/v2/userinfo"
```

The `sub` field in the response is your Person ID.

**Step 4 — Configure:**

```yaml
semanticDelivery:
  social:
    linkedin:
      accessToken: '%secret(LINKEDIN_ACCESS_TOKEN)%'
      personId: 'abc123def'          # from /v2/userinfo → sub
      organizationId: ''             # leave empty for personal posting
```

To post as a Company Page instead, set `organizationId` (requires `w_organization_social` scope via **Community Management API** product). When both are set, `organizationId` takes precedence.

> **Token expiry:** LinkedIn tokens expire after 60 days. Refresh manually or automate via `OAuthTokenService`.

---

#### X / Twitter

**Auth:** OAuth 2.0 Bearer Token
**Scope:** `tweet.read`, `tweet.write`, `users.read`

1. Go to [developer.x.com/en/portal](https://developer.x.com/en/portal/dashboard)
2. Create a **Project** and an **App** within it
3. Under **User authentication settings** → enable **OAuth 2.0**
4. Set App permissions to **Read and write**
5. Under **Keys and tokens** → generate **Bearer Token**

```yaml
semanticDelivery:
  social:
    twitter:
      bearerToken: '%secret(TWITTER_BEARER_TOKEN)%'
```

> **Note:** Free tier allows 1,500 tweets/month. The API requires a developer account with at least Basic access ($100/month) for tweet.write.

---

#### Facebook

**Auth:** Page Access Token (Long-Lived)
**Scope:** `pages_manage_posts`, `pages_read_engagement`

**Step 1 — Create a Facebook App:**

1. Go to [developers.facebook.com](https://developers.facebook.com/apps/) → **Create App**
2. Choose **Business** type
3. Add the **Facebook Login** product

**Step 2 — Get a Page Access Token:**

1. Open the [Graph API Explorer](https://developers.facebook.com/tools/explorer/)
2. Select your App from the dropdown
3. Click **Generate Access Token** → grant `pages_manage_posts` permission
4. Select the Page from the **User or Page** dropdown at top
5. The token shown is now a **Page Access Token**
6. Exchange for a **Long-Lived Token** (60 days):

```bash
curl "https://graph.facebook.com/v21.0/oauth/access_token?grant_type=fb_exchange_token&client_id=APP_ID&client_secret=APP_SECRET&fb_exchange_token=SHORT_LIVED_TOKEN"
```

**Step 3 — Get your Page ID:**

```bash
curl "https://graph.facebook.com/v21.0/me/accounts?access_token=YOUR_TOKEN"
```

The `id` field for your page is the Page ID.

```yaml
semanticDelivery:
  social:
    facebook:
      accessToken: '%secret(FACEBOOK_ACCESS_TOKEN)%'
      pageId: '123456789012345'
```

---

#### Instagram

**Auth:** Instagram Graph API via Facebook Page Token
**Scope:** `instagram_basic`, `instagram_content_publish`

**Prerequisites:** A Facebook Page linked to an Instagram Business/Creator account.

**Step 1 — Get the Instagram Business Account ID:**

```bash
curl "https://graph.facebook.com/v21.0/FACEBOOK_PAGE_ID?fields=instagram_business_account&access_token=PAGE_ACCESS_TOKEN"
```

The `instagram_business_account.id` is your Account ID.

**Step 2 — Configure:**

```yaml
semanticDelivery:
  social:
    instagram:
      accessToken: '%secret(INSTAGRAM_ACCESS_TOKEN)%'  # same as Facebook Page Token
      accountId: '17841400000000000'
```

> **Note:** Instagram posting via Graph API requires an image or video. Text-only posts are not supported. The `InstagramTransformer` handles this by including hashtags and truncating to 2,200 characters.

---

#### Dev.to

**Auth:** API Key (no OAuth)

1. Log in to [dev.to](https://dev.to)
2. Go to **Settings → Extensions** (or [dev.to/settings/extensions](https://dev.to/settings/extensions))
3. Under **DEV Community API Keys** → generate a new key
4. Copy the key

```yaml
semanticDelivery:
  blogging:
    devto:
      apiKey: '%secret(DEVTO_API_KEY)%'
```

Token does not expire. Posts are published as Markdown with canonical URL pointing back to the TYPO3 page.

---

### Complete Site Config Example

```yaml
# config/sites/my-site/config.yaml
semanticDelivery:
  social:
    bluesky:
      handle: 'example.org'
      appPassword: '%secret(BLUESKY_APP_PASSWORD)%'
    linkedin:
      accessToken: '%secret(LINKEDIN_ACCESS_TOKEN)%'
      personId: 'abc123'
      organizationId: ''
    twitter:
      bearerToken: '%secret(TWITTER_BEARER_TOKEN)%'
    instagram:
      accessToken: '%secret(INSTAGRAM_ACCESS_TOKEN)%'
      accountId: '17841400000000'
    facebook:
      accessToken: '%secret(FACEBOOK_ACCESS_TOKEN)%'
      pageId: '123456789'
  blogging:
    devto:
      apiKey: '%secret(DEVTO_API_KEY)%'
```

**Secret vs. public values:**

| Type | Where | Examples |
|------|-------|---------|
| Tokens, passwords, API keys | `%secret()%` in YAML → `/run/secrets/` or `_FILE` env | accessToken, appPassword, bearerToken, apiKey |
| Public identifiers | Directly in YAML | handle, personId, organizationId, accountId, pageId |

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

> **Cache-Key v3.0.4+:** Der Episode-Detector liest aus dem `content_blocks_collection_rssfeed`-Cache mit `'rssfeed_' . hash('sha256', $feedUrl)`. Muss mit dem Schreib-Key in `moselwal/content-blocks-collection`'s `PodcastFeedService::cacheIdentifierFor()` übereinstimmen (vorher md5 — Mismatch führte dazu, dass der Detector keine Episoden fand). Eigene `podcast_hash_*` / `podcast_guids_*`-Keys nutzen weiter md5, weil der Detector beide Seiten selbst pflegt.

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
3. Add a credential key mapping in `DistributionPlatformItemsProcFunc::CREDENTIAL_KEYS`
4. Add the corresponding site setting in `settings.definitions.yaml` and `settings.yaml`

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

The platform automatically appears in the page editor once credentials are configured for the site.

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
| `semanticDelivery.distribution.enabled` | bool | `true` | Distribution system |
| `semanticDelivery.distribution.defaultDistributor` | string | `'bluesky'` | Default distributor |
| `semanticDelivery.social.bluesky.handle` | string | `''` | Bluesky handle |
| `semanticDelivery.social.bluesky.appPassword` | string | `''` | Bluesky app password |
| `semanticDelivery.social.linkedin.accessToken` | string | `''` | LinkedIn OAuth token |
| `semanticDelivery.social.linkedin.organizationId` | string | `''` | LinkedIn org ID (w_organization_social) |
| `semanticDelivery.social.linkedin.personId` | string | `''` | LinkedIn person ID (w_member_social) |
| `semanticDelivery.social.twitter.bearerToken` | string | `''` | X/Twitter bearer token |
| `semanticDelivery.social.instagram.accessToken` | string | `''` | Instagram access token |
| `semanticDelivery.social.instagram.accountId` | string | `''` | Instagram account ID |
| `semanticDelivery.social.facebook.accessToken` | string | `''` | Facebook page token |
| `semanticDelivery.social.facebook.pageId` | string | `''` | Facebook page ID |
| `semanticDelivery.blogging.devto.apiKey` | string | `''` | Dev.to API key |

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
