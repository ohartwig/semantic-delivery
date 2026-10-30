<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Tests\Unit\Infrastructure\Distribution;

use Koh\SemanticDelivery\Domain\Model\ChannelContent;
use Koh\SemanticDelivery\Infrastructure\Distribution\BlueskyDistributor;
use Koh\SemanticDelivery\Infrastructure\Distribution\DevToDistributor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BlueskyAndDevToDistributorsTest extends TestCase
{
    use RequestFactoryDouble;

    private const SESSION = ['did' => 'did:plc:abc', 'accessJwt' => 'jwt'];

    // --- Bluesky ---

    #[Test]
    public function blueskyRequiresCredentials(): void
    {
        $d = new BlueskyDistributor($this->factoryReplaying([]), new NullLogger());

        self::assertSame(
            'Bluesky handle and appPassword are required',
            $d->distribute(new ChannelContent('c', 'x'), 'bluesky', ['handle' => 'h'])->error,
        );
    }

    #[Test]
    public function blueskyCreatesSessionThenRecordWithLinkFacets(): void
    {
        $d = new BlueskyDistributor(
            $this->factoryReplaying([
                $this->reply(200, self::SESSION),
                $this->reply(200, ['uri' => 'at://did:plc:abc/app.bsky.feed.post/3kxyz']),
            ]),
            new NullLogger(),
        );
        $content = new ChannelContent('c', "Grüße: https://e.x/a\nund mehr", ['languages' => ['en']]);

        $result = $d->distribute($content, 'bluesky', ['handle' => 'ole.bsky.social', 'appPassword' => 'pw']);

        self::assertTrue($result->success);
        self::assertSame('at://did:plc:abc/app.bsky.feed.post/3kxyz', $result->externalId);
        self::assertSame('https://bsky.app/profile/ole.bsky.social/post/3kxyz', $result->externalUrl);
        self::assertSame('https://bsky.social/xrpc/com.atproto.server.createSession', $this->requests[0]['uri']);
        self::assertSame(['identifier' => 'ole.bsky.social', 'password' => 'pw'], $this->sentJson(0));
        self::assertSame('Bearer jwt', $this->requests[1]['options']['headers']['Authorization']);
        $body = $this->sentJson(1);
        self::assertSame('did:plc:abc', $body['repo']);
        self::assertSame('app.bsky.feed.post', $body['record']['$type']);
        self::assertSame(['en'], $body['record']['langs']);
        // "Grüße: " is 9 bytes (ü and ß take two bytes each).
        self::assertSame(['byteStart' => 9, 'byteEnd' => 9 + strlen('https://e.x/a')], $body['record']['facets'][0]['index']);
        self::assertSame('https://e.x/a', $body['record']['facets'][0]['features'][0]['uri']);
    }

    #[Test]
    public function blueskyOmitsFacetsWithoutLinksAndUsesCustomService(): void
    {
        $d = new BlueskyDistributor(
            $this->factoryReplaying([$this->reply(200, self::SESSION), $this->reply(200, ['uri' => 'at://x/y/k'])]),
            new NullLogger(),
        );

        $d->distribute(new ChannelContent('c', 'plain'), 'bluesky', ['handle' => 'h', 'appPassword' => 'p', 'service' => 'https://pds.example']);

        self::assertStringStartsWith('https://pds.example/', $this->requests[0]['uri']);
        $record = $this->sentJson(1)['record'];
        self::assertArrayNotHasKey('facets', $record);
        self::assertSame(['de'], $record['langs']);
    }

    #[Test]
    public function blueskyMapsAuthAndRecordFailures(): void
    {
        $opts = ['handle' => 'h', 'appPassword' => 'p'];
        $content = new ChannelContent('c', 'x');

        $auth = new BlueskyDistributor($this->factoryReplaying([$this->reply(401, ['error' => 'AuthRequired'])]), new NullLogger());
        self::assertSame('Bluesky authentication failed', $auth->distribute($content, 'bluesky', $opts)->error);

        $rec = new BlueskyDistributor($this->factoryReplaying([$this->reply(200, self::SESSION), $this->reply(200, 'oops')]), new NullLogger());
        self::assertSame('Failed to create Bluesky post', $rec->distribute($content, 'bluesky', $opts)->error);

        $down = new BlueskyDistributor($this->factoryReplaying([new \RuntimeException('x')]), $this->failingLogger());
        self::assertSame('Bluesky service temporarily unavailable', $down->distribute($content, 'bluesky', $opts)->error);
    }

    // --- Dev.to ---

    #[Test]
    public function devToRequiresApiKeyAndTitle(): void
    {
        $d = new DevToDistributor($this->factoryReplaying([]), new NullLogger());

        self::assertSame('Dev.to apiKey is required', $d->distribute(new ChannelContent('b', 'md'), 'devto')->error);
        self::assertSame(
            'Article title is required for Dev.to',
            $d->distribute(new ChannelContent('b', 'md'), 'devto', ['apiKey' => 'k'])->error,
        );
    }

    #[Test]
    public function devToPublishesArticleWithSanitisedTags(): void
    {
        $d = new DevToDistributor($this->factoryReplaying([$this->reply(201, ['id' => 77, 'url' => 'https://dev.to/a'])]), new NullLogger());
        $content = new ChannelContent('blogging', '# md', [
            'title' => 'T',
            'description' => str_repeat('d', 200),
            'url' => 'https://e.x/a',
            'tags' => ['Type-Script', 'PHP 8', 'a', 'b', 'c'],
        ]);

        $result = $d->distribute($content, 'devto', ['apiKey' => 'k', 'published' => false]);

        self::assertTrue($result->success);
        self::assertSame('77', $result->externalId);
        self::assertSame('https://dev.to/a', $result->externalUrl);
        self::assertSame('k', $this->requests[0]['options']['headers']['api-key']);
        $article = $this->sentJson()['article'];
        self::assertSame('T', $article['title']);
        self::assertSame('# md', $article['body_markdown']);
        self::assertFalse($article['published']);
        self::assertSame(140, mb_strlen($article['description']));
        self::assertSame('https://e.x/a', $article['canonical_url']);
        self::assertSame(['typescript', 'php8', 'a', 'b'], $article['tags']);
    }

    #[Test]
    public function devToPublishesByDefaultAndOmitsOptionalFields(): void
    {
        $d = new DevToDistributor($this->factoryReplaying([$this->reply(201, ['id' => 1, 'url' => 'u'])]), new NullLogger());

        $d->distribute(new ChannelContent('b', 'md', ['title' => 'T']), 'devto', ['apiKey' => 'k']);

        $article = $this->sentJson()['article'];
        self::assertTrue($article['published']);
        self::assertArrayNotHasKey('description', $article);
        self::assertArrayNotHasKey('canonical_url', $article);
        self::assertArrayNotHasKey('tags', $article);
    }

    #[Test]
    public function devToMapsFailures(): void
    {
        $content = new ChannelContent('b', 'md', ['title' => 'T']);
        $opts = ['apiKey' => 'k'];

        $api = new DevToDistributor($this->factoryReplaying([$this->reply(422, ['error' => 'Bad tags'])]), new NullLogger());
        self::assertSame('Bad tags', $api->distribute($content, 'devto', $opts)->error);

        $json = new DevToDistributor($this->factoryReplaying([$this->reply(422, [])]), new NullLogger());
        self::assertSame('HTTP 422', $json->distribute($content, 'devto', $opts)->error);

        $plain = new DevToDistributor($this->factoryReplaying([$this->reply(500, 'html')]), new NullLogger());
        self::assertSame('HTTP 500', $plain->distribute($content, 'devto', $opts)->error);

        $down = new DevToDistributor($this->factoryReplaying([new \RuntimeException('x')]), $this->failingLogger());
        self::assertSame('Dev.to API temporarily unavailable', $down->distribute($content, 'devto', $opts)->error);
    }

    #[Test]
    public function identifiersPlatformsAndConnectionCheck(): void
    {
        $factory = $this->factoryReplaying([]);
        foreach ([[new BlueskyDistributor($factory, new NullLogger()), 'bluesky'], [new DevToDistributor($factory, new NullLogger()), 'devto']] as [$d, $id]) {
            self::assertSame($id, $d->getIdentifier());
            self::assertSame([$id], $d->getAvailablePlatforms());
            self::assertTrue($d->testConnection());
        }
    }
}
