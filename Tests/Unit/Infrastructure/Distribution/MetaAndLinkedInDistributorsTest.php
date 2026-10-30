<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Tests\Unit\Infrastructure\Distribution;

use Koh\SemanticDelivery\Domain\Model\ChannelContent;
use Koh\SemanticDelivery\Infrastructure\Distribution\FacebookDistributor;
use Koh\SemanticDelivery\Infrastructure\Distribution\InstagramDistributor;
use Koh\SemanticDelivery\Infrastructure\Distribution\LinkedInDistributor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MetaAndLinkedInDistributorsTest extends TestCase
{
    use RequestFactoryDouble;

    // --- Facebook ---

    #[Test]
    public function facebookRequiresTokenAndPageId(): void
    {
        $d = new FacebookDistributor($this->factoryReplaying([]), new NullLogger());

        self::assertFalse($d->distribute(new ChannelContent('c', 'x'), 'facebook', ['pageId' => '1'])->success);
        self::assertFalse($d->distribute(new ChannelContent('c', 'x'), 'facebook', ['pageAccessToken' => 't'])->success);
    }

    #[Test]
    public function facebookPostsTextWithLink(): void
    {
        $d = new FacebookDistributor($this->factoryReplaying([$this->reply(200, ['id' => '1_2'])]), new NullLogger());

        $result = $d->distribute(
            new ChannelContent('c', 'Msg', ['url' => 'https://e.x/a']),
            'facebook',
            ['pageAccessToken' => 'T', 'pageId' => '42'],
        );

        self::assertSame('1_2', $result->externalId);
        self::assertSame('https://www.facebook.com/1_2', $result->externalUrl);
        self::assertSame('https://graph.facebook.com/v21.0/42/feed', $this->requests[0]['uri']);
        self::assertSame(['message' => 'Msg', 'access_token' => 'T', 'link' => 'https://e.x/a'], $this->requests[0]['options']['form_params']);
    }

    #[Test]
    public function facebookPostsPhotoWhenImageIsPresentAndFallsBackToPostId(): void
    {
        $d = new FacebookDistributor($this->factoryReplaying([$this->reply(200, ['post_id' => 'p9'])]), new NullLogger());

        $result = $d->distribute(
            new ChannelContent('c', 'Msg', ['image' => 'https://e.x/i.png']),
            'facebook',
            ['pageAccessToken' => 'T', 'pageId' => '42'],
        );

        self::assertSame('p9', $result->externalId);
        self::assertSame('https://graph.facebook.com/v21.0/42/photos', $this->requests[0]['uri']);
        self::assertSame('https://e.x/i.png', $this->requests[0]['options']['form_params']['url']);
        self::assertSame('Msg', $this->requests[0]['options']['form_params']['caption']);
    }

    #[Test]
    public function facebookMapsFailures(): void
    {
        $content = new ChannelContent('c', 'x');
        $opts = ['pageAccessToken' => 'T', 'pageId' => '42'];

        $api = new FacebookDistributor($this->factoryReplaying([$this->reply(400, ['error' => ['message' => 'Expired']])]), new NullLogger());
        self::assertSame('Expired', $api->distribute($content, 'facebook', $opts)->error);

        $unknown = new FacebookDistributor($this->factoryReplaying([$this->reply(400, ['error' => []])]), new NullLogger());
        self::assertSame('Unknown Facebook error', $unknown->distribute($content, 'facebook', $opts)->error);

        $invalid = new FacebookDistributor($this->factoryReplaying([$this->reply(200, 'x')]), new NullLogger());
        self::assertSame('Invalid Facebook API response', $invalid->distribute($content, 'facebook', $opts)->error);

        $empty = new FacebookDistributor($this->factoryReplaying([$this->reply(200, [])]), new NullLogger());
        self::assertSame('', $empty->distribute($content, 'facebook', $opts)->externalUrl);

        $down = new FacebookDistributor($this->factoryReplaying([new \RuntimeException('x')]), $this->failingLogger());
        self::assertSame('Facebook API temporarily unavailable', $down->distribute($content, 'facebook', $opts)->error);
    }

    // --- Instagram ---

    #[Test]
    public function instagramRequiresCredentialsAndImage(): void
    {
        $d = new InstagramDistributor($this->factoryReplaying([]), new NullLogger());
        $content = new ChannelContent('c', 'x');

        self::assertSame(
            'Instagram pageAccessToken and instagramBusinessAccountId are required',
            $d->distribute($content, 'instagram', ['pageAccessToken' => 't'])->error,
        );
        self::assertSame(
            'Instagram requires an image URL for feed posts',
            $d->distribute($content, 'instagram', ['pageAccessToken' => 't', 'instagramBusinessAccountId' => 'i'])->error,
        );
    }

    #[Test]
    public function instagramCreatesContainerThenPublishes(): void
    {
        $d = new InstagramDistributor(
            $this->factoryReplaying([$this->reply(200, ['id' => 'c1']), $this->reply(200, ['id' => 'm1'])]),
            new NullLogger(),
        );

        $result = $d->distribute(
            new ChannelContent('c', 'Caption', ['suggestedImage' => 'https://e.x/i.png']),
            'instagram',
            ['pageAccessToken' => 't', 'instagramBusinessAccountId' => 'ig'],
        );

        self::assertSame('m1', $result->externalId);
        self::assertSame('https://www.instagram.com/p/m1/', $result->externalUrl);
        self::assertSame('https://graph.facebook.com/v21.0/ig/media', $this->requests[0]['uri']);
        self::assertSame('Caption', $this->requests[0]['options']['form_params']['caption']);
        self::assertSame('https://graph.facebook.com/v21.0/ig/media_publish', $this->requests[1]['uri']);
        self::assertSame('c1', $this->requests[1]['options']['form_params']['creation_id']);
    }

    #[Test]
    public function instagramFallsBackToImageMetadata(): void
    {
        $d = new InstagramDistributor($this->factoryReplaying([$this->reply(200, ['id' => 'c1']), $this->reply(200, ['id' => 'm1'])]), new NullLogger());

        $d->distribute(new ChannelContent('c', 'x', ['image' => 'https://e.x/b.png']), 'instagram', ['pageAccessToken' => 't', 'instagramBusinessAccountId' => 'ig']);

        self::assertSame('https://e.x/b.png', $this->requests[0]['options']['form_params']['image_url']);
    }

    #[Test]
    public function instagramMapsFailures(): void
    {
        $content = new ChannelContent('c', 'x', ['image' => 'https://e.x/i.png']);
        $opts = ['pageAccessToken' => 't', 'instagramBusinessAccountId' => 'ig'];
        $container = 'Failed to create Instagram media container';

        $err = new InstagramDistributor($this->factoryReplaying([$this->reply(400, ['error' => ['message' => 'x']])]), new NullLogger());
        self::assertSame($container, $err->distribute($content, 'instagram', $opts)->error);

        $bad = new InstagramDistributor($this->factoryReplaying([$this->reply(200, 'x')]), new NullLogger());
        self::assertSame($container, $bad->distribute($content, 'instagram', $opts)->error);

        $publishErr = new InstagramDistributor($this->factoryReplaying([$this->reply(200, ['id' => 'c']), $this->reply(400, ['error' => ['message' => 'Rate']])]), new NullLogger());
        self::assertSame('Rate', $publishErr->distribute($content, 'instagram', $opts)->error);

        $publishUnknown = new InstagramDistributor($this->factoryReplaying([$this->reply(200, ['id' => 'c']), $this->reply(400, ['error' => []])]), new NullLogger());
        self::assertSame('Instagram publish error', $publishUnknown->distribute($content, 'instagram', $opts)->error);

        $publishBad = new InstagramDistributor($this->factoryReplaying([$this->reply(200, ['id' => 'c']), $this->reply(200, 'x')]), new NullLogger());
        self::assertSame('Invalid Instagram publish response', $publishBad->distribute($content, 'instagram', $opts)->error);

        $down = new InstagramDistributor($this->factoryReplaying([new \RuntimeException('x')]), $this->failingLogger());
        self::assertSame('Instagram API temporarily unavailable', $down->distribute($content, 'instagram', $opts)->error);
    }

    // --- LinkedIn ---

    #[Test]
    public function linkedInRequiresTokenAndAuthor(): void
    {
        $d = new LinkedInDistributor($this->factoryReplaying([]), new NullLogger());
        $content = new ChannelContent('c', 'x');

        self::assertSame('LinkedIn accessToken is required', $d->distribute($content, 'linkedin')->error);
        self::assertSame('LinkedIn organizationId or personId is required', $d->distribute($content, 'linkedin', ['accessToken' => 't'])->error);
    }

    #[Test]
    public function linkedInPostsArticleShareAsOrganisation(): void
    {
        $d = new LinkedInDistributor($this->factoryReplaying([$this->reply(201, '', ['X-RestLi-Id' => 'urn:li:share:1'])]), new NullLogger());

        $result = $d->distribute(
            new ChannelContent('c', 'Post', ['url' => 'https://e.x/a']),
            'linkedin',
            ['accessToken' => 't', 'organizationId' => '9', 'personId' => '8'],
        );

        self::assertTrue($result->success);
        self::assertSame('urn:li:share:1', $result->externalId);
        self::assertSame('https://www.linkedin.com/feed/update/urn:li:share:1', $result->externalUrl);
        $payload = $this->sentJson();
        self::assertSame('urn:li:organization:9', $payload['author']);
        $share = $payload['specificContent']['com.linkedin.ugc.ShareContent'];
        self::assertSame('ARTICLE', $share['shareMediaCategory']);
        self::assertSame('https://e.x/a', $share['media'][0]['originalUrl']);
        self::assertSame('2.0.0', $this->requests[0]['options']['headers']['X-Restli-Protocol-Version']);
    }

    #[Test]
    public function linkedInPostsPlainShareAsPerson(): void
    {
        $d = new LinkedInDistributor($this->factoryReplaying([$this->reply(201, '')]), new NullLogger());

        $result = $d->distribute(new ChannelContent('c', 'Post'), 'linkedin', ['accessToken' => 't', 'personId' => '8']);

        $payload = $this->sentJson();
        self::assertSame('urn:li:person:8', $payload['author']);
        $share = $payload['specificContent']['com.linkedin.ugc.ShareContent'];
        self::assertSame('NONE', $share['shareMediaCategory']);
        self::assertArrayNotHasKey('media', $share);
        self::assertSame('', $result->externalUrl);
    }

    #[Test]
    public function linkedInMapsFailures(): void
    {
        $content = new ChannelContent('c', 'x');
        $opts = ['accessToken' => 't', 'personId' => '8'];

        $api = new LinkedInDistributor($this->factoryReplaying([$this->reply(403, ['message' => 'Denied'])]), new NullLogger());
        self::assertSame('Denied', $api->distribute($content, 'linkedin', $opts)->error);

        $json = new LinkedInDistributor($this->factoryReplaying([$this->reply(429, [])]), new NullLogger());
        self::assertSame('HTTP 429', $json->distribute($content, 'linkedin', $opts)->error);

        $plain = new LinkedInDistributor($this->factoryReplaying([$this->reply(500, 'x')]), new NullLogger());
        self::assertSame('HTTP 500', $plain->distribute($content, 'linkedin', $opts)->error);

        $down = new LinkedInDistributor($this->factoryReplaying([new \RuntimeException('x')]), $this->failingLogger());
        self::assertSame('LinkedIn API temporarily unavailable', $down->distribute($content, 'linkedin', $opts)->error);
    }

    #[Test]
    public function identifiersPlatformsAndConnectionCheck(): void
    {
        $factory = $this->factoryReplaying([]);
        $all = [
            [new FacebookDistributor($factory, new NullLogger()), 'facebook'],
            [new InstagramDistributor($factory, new NullLogger()), 'instagram'],
            [new LinkedInDistributor($factory, new NullLogger()), 'linkedin'],
        ];
        foreach ($all as [$d, $id]) {
            self::assertSame($id, $d->getIdentifier());
            self::assertSame([$id], $d->getAvailablePlatforms());
            self::assertTrue($d->testConnection());
        }
    }
}
