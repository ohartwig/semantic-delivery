<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Tests\Unit\Infrastructure\Distribution;

use Koh\SemanticDelivery\Domain\Model\ChannelContent;
use Koh\SemanticDelivery\Infrastructure\Distribution\AyrshareDistributor;
use Koh\SemanticDelivery\Infrastructure\Distribution\BufferDistributor;
use Koh\SemanticDelivery\Infrastructure\Distribution\LateDevDistributor;
use Koh\SemanticDelivery\Infrastructure\Distribution\TwitterXDistributor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BearerApiDistributorsTest extends TestCase
{
    use RequestFactoryDouble;

    private function content(array $metadata = []): ChannelContent
    {
        return new ChannelContent('social-media', 'Hello', $metadata);
    }

    // --- Ayrshare ---

    #[Test]
    public function ayrshareRequiresApiKey(): void
    {
        $d = new AyrshareDistributor($this->factoryReplaying([]), new NullLogger());

        self::assertFalse($d->distribute($this->content(), 'linkedin')->success);
        self::assertSame([], $this->requests);
    }

    #[Test]
    public function ayrsharePostsPayloadAndMapsSuccess(): void
    {
        $d = new AyrshareDistributor(
            $this->factoryReplaying([$this->reply(200, ['status' => 'success', 'id' => 'a1', 'postUrl' => 'https://p'])]),
            new NullLogger(),
        );

        $result = $d->distribute($this->content(['image' => 'i.png']), 'linkedin', ['apiKey' => 'K', 'scheduledDate' => '2030-01-01']);

        self::assertTrue($result->success);
        self::assertSame('a1', $result->externalId);
        self::assertSame('https://p', $result->externalUrl);
        self::assertSame('https://app.ayrshare.com/api/post', $this->requests[0]['uri']);
        self::assertSame('Bearer K', $this->requests[0]['options']['headers']['Authorization']);
        self::assertSame(
            ['post' => 'Hello', 'platforms' => ['linkedin'], 'mediaUrls' => ['i.png'], 'scheduleDate' => '2030-01-01'],
            $this->sentJson(),
        );
    }

    #[Test]
    public function ayrshareReportsApiMessageInvalidBodyAndTransportErrors(): void
    {
        $api = new AyrshareDistributor($this->factoryReplaying([$this->reply(200, ['status' => 'error', 'message' => 'Quota'])]), new NullLogger());
        self::assertSame('Quota', $api->distribute($this->content(), 'x', ['apiKey' => 'K'])->error);

        $unknown = new AyrshareDistributor($this->factoryReplaying([$this->reply(200, [])]), new NullLogger());
        self::assertSame('Unknown Ayrshare error', $unknown->distribute($this->content(), 'x', ['apiKey' => 'K'])->error);

        $invalid = new AyrshareDistributor($this->factoryReplaying([$this->reply(200, 'not json')]), new NullLogger());
        self::assertSame('Invalid response from service', $invalid->distribute($this->content(), 'x', ['apiKey' => 'K'])->error);

        $down = new AyrshareDistributor($this->factoryReplaying([new \RuntimeException('boom')]), $this->failingLogger());
        $result = $down->distribute($this->content(), 'x', ['apiKey' => 'K']);
        self::assertSame('Distribution service temporarily unavailable', $result->error);
        self::assertStringNotContainsString('boom', $result->error);
    }

    // --- Buffer ---

    #[Test]
    public function bufferRequiresTokenAndProfile(): void
    {
        $d = new BufferDistributor($this->factoryReplaying([]), new NullLogger());

        self::assertSame('No Buffer access token configured', $d->distribute($this->content(), 'x')->error);
        self::assertSame('No Buffer profile ID configured', $d->distribute($this->content(), 'x', ['accessToken' => 't'])->error);
    }

    #[Test]
    public function bufferSendsFormEncodedUpdate(): void
    {
        $d = new BufferDistributor(
            $this->factoryReplaying([$this->reply(200, ['success' => true, 'updates' => [['id' => 'u1']]])]),
            new NullLogger(),
        );

        $result = $d->distribute($this->content(['image' => 'i.png']), 'x', ['accessToken' => 't', 'profileId' => 'p']);

        self::assertTrue($result->success);
        self::assertSame('u1', $result->externalId);
        parse_str((string) $this->requests[0]['options']['body'], $form);
        self::assertSame('Hello', $form['text']);
        self::assertSame(['p'], $form['profile_ids']);
        self::assertSame('i.png', $form['media']['photo']);
        self::assertSame('Bearer t', $this->requests[0]['options']['headers']['Authorization']);
    }

    #[Test]
    public function bufferMapsFailures(): void
    {
        $opts = ['accessToken' => 't', 'profileId' => 'p'];

        $api = new BufferDistributor($this->factoryReplaying([$this->reply(200, ['success' => false, 'message' => 'Limit'])]), new NullLogger());
        self::assertSame('Limit', $api->distribute($this->content(), 'x', $opts)->error);

        $unknown = new BufferDistributor($this->factoryReplaying([$this->reply(200, [])]), new NullLogger());
        self::assertSame('Unknown Buffer error', $unknown->distribute($this->content(), 'x', $opts)->error);

        $invalid = new BufferDistributor($this->factoryReplaying([$this->reply(200, '')]), new NullLogger());
        self::assertSame('Invalid response from service', $invalid->distribute($this->content(), 'x', $opts)->error);

        $down = new BufferDistributor($this->factoryReplaying([new \RuntimeException('x')]), $this->failingLogger());
        self::assertSame('Distribution service temporarily unavailable', $down->distribute($this->content(), 'x', $opts)->error);
    }

    // --- Late.dev ---

    #[Test]
    public function lateDevRequiresApiKey(): void
    {
        $d = new LateDevDistributor($this->factoryReplaying([]), new NullLogger());

        self::assertSame('No Late.dev API key configured', $d->distribute($this->content(), 'x')->error);
    }

    #[Test]
    public function lateDevPostsContentAndMetadata(): void
    {
        $d = new LateDevDistributor(
            $this->factoryReplaying([$this->reply(200, ['success' => true, 'id' => 'l1', 'url' => 'https://l'])]),
            new NullLogger(),
        );

        $result = $d->distribute($this->content(['k' => 'v']), 'twitter', ['apiKey' => 'K']);

        self::assertTrue($result->success);
        self::assertSame('l1', $result->externalId);
        self::assertSame('https://l', $result->externalUrl);
        self::assertSame(['content' => 'Hello', 'platform' => 'twitter', 'metadata' => ['k' => 'v']], $this->sentJson());
        self::assertSame('https://api.late.dev/v1/posts', $this->requests[0]['uri']);
    }

    #[Test]
    public function lateDevMapsFailures(): void
    {
        $opts = ['apiKey' => 'K'];

        $api = new LateDevDistributor($this->factoryReplaying([$this->reply(200, ['success' => false, 'error' => 'Nope'])]), new NullLogger());
        self::assertSame('Nope', $api->distribute($this->content(), 'x', $opts)->error);

        $unknown = new LateDevDistributor($this->factoryReplaying([$this->reply(200, [])]), new NullLogger());
        self::assertSame('Unknown Late.dev error', $unknown->distribute($this->content(), 'x', $opts)->error);

        $invalid = new LateDevDistributor($this->factoryReplaying([$this->reply(200, 'x')]), new NullLogger());
        self::assertSame('Invalid response from service', $invalid->distribute($this->content(), 'x', $opts)->error);

        $down = new LateDevDistributor($this->factoryReplaying([new \RuntimeException('x')]), $this->failingLogger());
        self::assertFalse($down->distribute($this->content(), 'x', $opts)->success);
    }

    // --- X / Twitter ---

    #[Test]
    public function twitterRequiresBearerToken(): void
    {
        $d = new TwitterXDistributor($this->factoryReplaying([]), new NullLogger());

        self::assertSame('X/Twitter bearerToken is required', $d->distribute($this->content(), 'twitter')->error);
    }

    #[Test]
    public function twitterBuildsTweetUrlFromUsername(): void
    {
        $d = new TwitterXDistributor($this->factoryReplaying([$this->reply(201, ['data' => ['id' => '99']])]), new NullLogger());

        $result = $d->distribute($this->content(), 'twitter', ['bearerToken' => 'B', 'username' => 'ole']);

        self::assertSame('99', $result->externalId);
        self::assertSame('https://x.com/ole/status/99', $result->externalUrl);
        self::assertSame(['text' => 'Hello'], $this->sentJson());
        self::assertSame('https://api.x.com/2/tweets', $this->requests[0]['uri']);
    }

    #[Test]
    public function twitterOmitsUrlWithoutUsername(): void
    {
        $d = new TwitterXDistributor($this->factoryReplaying([$this->reply(201, ['data' => ['id' => '99']])]), new NullLogger());

        self::assertSame('', $d->distribute($this->content(), 'twitter', ['bearerToken' => 'B'])->externalUrl);
    }

    #[Test]
    public function twitterMapsFailures(): void
    {
        $opts = ['bearerToken' => 'B'];

        $err = new TwitterXDistributor($this->factoryReplaying([$this->reply(403, ['errors' => [['message' => 'Forbidden']]])]), new NullLogger());
        self::assertSame('Forbidden', $err->distribute($this->content(), 'x', $opts)->error);

        $unknown = new TwitterXDistributor($this->factoryReplaying([$this->reply(403, ['errors' => [[]]])]), new NullLogger());
        self::assertSame('Unknown X API error', $unknown->distribute($this->content(), 'x', $opts)->error);

        $invalid = new TwitterXDistributor($this->factoryReplaying([$this->reply(200, 'x')]), new NullLogger());
        self::assertSame('Invalid X API response', $invalid->distribute($this->content(), 'x', $opts)->error);

        $down = new TwitterXDistributor($this->factoryReplaying([new \RuntimeException('x')]), $this->failingLogger());
        self::assertSame('X API temporarily unavailable', $down->distribute($this->content(), 'x', $opts)->error);
    }

    #[Test]
    public function identifiersPlatformsAndConnectionCheck(): void
    {
        $factory = $this->factoryReplaying([]);
        $logger = new NullLogger();
        $expected = [
            [new AyrshareDistributor($factory, $logger), 'ayrshare', 'tiktok'],
            [new BufferDistributor($factory, $logger), 'buffer', 'pinterest'],
            [new LateDevDistributor($factory, $logger), 'latedev', 'linkedin'],
            [new TwitterXDistributor($factory, $logger), 'twitter', 'twitter'],
        ];

        foreach ($expected as [$distributor, $id, $platform]) {
            self::assertSame($id, $distributor->getIdentifier());
            self::assertContains($platform, $distributor->getAvailablePlatforms());
            self::assertTrue($distributor->testConnection());
        }
    }
}
