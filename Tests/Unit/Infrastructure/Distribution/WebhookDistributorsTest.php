<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Tests\Unit\Infrastructure\Distribution;

use Koh\SemanticDelivery\Domain\Model\ChannelContent;
use Koh\SemanticDelivery\Infrastructure\Distribution\N8nDistributor;
use Koh\SemanticDelivery\Infrastructure\Distribution\WebhookDistributor;
use Koh\SemanticDelivery\Infrastructure\Validation\WebhookUrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WebhookDistributorsTest extends TestCase
{
    use RequestFactoryDouble;

    private function content(): ChannelContent
    {
        return new ChannelContent('social-media', 'Hello', ['k' => 'v'], pageUid: 5);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, bool}>
     */
    public static function urls(): iterable
    {
        yield 'public ipv4' => ['https://8.8.8.8/hook', [], true];
        yield 'public ipv6' => ['https://[2001:4860:4860::8888]/hook', [], true];
        yield 'http rejected' => ['http://8.8.8.8/hook', [], false];
        yield 'http allowed explicitly' => ['http://8.8.8.8/hook', ['allowInsecure' => true], true];
        yield 'ftp rejected' => ['ftp://8.8.8.8/hook', ['allowInsecure' => true], false];
        yield 'no scheme' => ['8.8.8.8/hook', [], false];
        yield 'no host' => ['https:///hook', [], false];
        yield 'localhost' => ['https://localhost/hook', [], false];
        yield 'loopback v4' => ['https://127.0.0.1/hook', [], false];
        yield 'any v4' => ['https://0.0.0.0/hook', [], false];
        yield 'loopback v6' => ['https://[::1]/hook', [], false];
        yield 'private 10/8' => ['https://10.1.2.3/hook', [], false];
        yield 'private 192.168' => ['https://192.168.0.10/hook', [], false];
        yield 'private 172.16' => ['https://172.16.5.5/hook', [], false];
        yield 'link-local metadata' => ['https://169.254.169.254/latest', [], false];
        yield 'mapped private v6' => ['https://[::ffff:10.0.0.1]/hook', [], false];
        yield 'unique local v6' => ['https://[fd00::1]/hook', [], false];
        yield 'uppercase scheme and host' => ['HTTPS://LOCALHOST/hook', [], false];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Test]
    #[DataProvider('urls')]
    public function validatorClassifiesUrls(string $url, array $options, bool $expected): void
    {
        self::assertSame($expected, WebhookUrlValidator::isValid($url, $options));
    }

    #[Test]
    public function webhookRejectsMissingAndBlockedUrls(): void
    {
        $d = new WebhookDistributor($this->factoryReplaying([]), new NullLogger());

        self::assertSame('No webhook URL configured', $d->distribute($this->content(), 'generic')->error);
        self::assertSame('Invalid or blocked webhook URL', $d->distribute($this->content(), 'generic', ['url' => 'https://10.0.0.1/x'])->error);
        self::assertSame([], $this->requests);
    }

    #[Test]
    public function webhookPostsPayloadAndMapsSuccess(): void
    {
        $d = new WebhookDistributor($this->factoryReplaying([$this->reply(200, ['id' => 'w1', 'url' => 'https://w'])]), new NullLogger());

        $result = $d->distribute($this->content(), 'generic', ['url' => 'https://8.8.8.8/hook']);

        self::assertTrue($result->success);
        self::assertSame('w1', $result->externalId);
        self::assertSame('https://w', $result->externalUrl);
        $payload = $this->sentJson();
        self::assertSame('social-media', $payload['channel']);
        self::assertSame('generic', $payload['platform']);
        self::assertSame('Hello', $payload['content']);
        self::assertSame(['k' => 'v'], $payload['metadata']);
        self::assertIsInt($payload['timestamp']);
        self::assertSame('application/json', $this->requests[0]['options']['headers']['Content-Type']);
        self::assertArrayNotHasKey('X-Webhook-Signature', $this->requests[0]['options']['headers']);
    }

    #[Test]
    public function webhookDropsSensitiveCustomHeadersCaseInsensitively(): void
    {
        $d = new WebhookDistributor($this->factoryReplaying([$this->reply(200, [])]), new NullLogger());

        $d->distribute($this->content(), 'generic', [
            'url' => 'https://8.8.8.8/hook',
            'headers' => ['Authorization' => 'x', 'COOKIE' => 'y', 'Host' => 'evil', 'X-Custom' => 'ok'],
        ]);

        $headers = $this->requests[0]['options']['headers'];
        self::assertSame('ok', $headers['X-Custom']);
        self::assertArrayNotHasKey('Authorization', $headers);
        self::assertArrayNotHasKey('COOKIE', $headers);
        self::assertArrayNotHasKey('Host', $headers);
    }

    #[Test]
    public function webhookSignsPayloadWhenSecretIsGiven(): void
    {
        $d = new WebhookDistributor($this->factoryReplaying([$this->reply(200, [])]), new NullLogger());

        $d->distribute($this->content(), 'generic', ['url' => 'https://8.8.8.8/hook', 'secret' => 's3cret']);

        $signature = $this->requests[0]['options']['headers']['X-Webhook-Signature'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $signature);
        // The timestamp may tick between signing and sending, so accept either neighbouring second.
        $body = (string) $this->requests[0]['options']['body'];
        $candidates = [$body];
        $payload = $this->sentJson();
        foreach ([-1, 1] as $delta) {
            $payload['timestamp'] += $delta;
            $candidates[] = json_encode($payload);
            $payload['timestamp'] -= $delta;
        }
        $valid = array_filter($candidates, static fn(string $c): bool => hash_equals(hash_hmac('sha256', $c, 's3cret'), $signature));
        self::assertNotSame([], $valid);
    }

    #[Test]
    public function webhookMapsFailures(): void
    {
        $opts = ['url' => 'https://8.8.8.8/hook'];

        $http = new WebhookDistributor($this->factoryReplaying([$this->reply(502, '')]), new NullLogger());
        self::assertSame('Webhook returned HTTP 502', $http->distribute($this->content(), 'g', $opts)->error);

        $invalid = new WebhookDistributor($this->factoryReplaying([$this->reply(200, 'plain')]), new NullLogger());
        self::assertSame('Invalid response from service', $invalid->distribute($this->content(), 'g', $opts)->error);

        $down = new WebhookDistributor($this->factoryReplaying([new \RuntimeException('x')]), $this->failingLogger());
        self::assertSame('Distribution service temporarily unavailable', $down->distribute($this->content(), 'g', $opts)->error);
    }

    #[Test]
    public function n8nValidatesUrl(): void
    {
        $d = new N8nDistributor($this->factoryReplaying([]), new NullLogger());

        self::assertSame('No n8n webhook URL configured', $d->distribute($this->content(), 'generic')->error);
        self::assertSame(
            'Invalid or blocked webhook URL',
            $d->distribute($this->content(), 'generic', ['webhookUrl' => 'https://127.0.0.1/x'])->error,
        );
    }

    #[Test]
    public function n8nPostsPayloadAndMapsSuccess(): void
    {
        $d = new N8nDistributor($this->factoryReplaying([$this->reply(200, ['executionId' => 'e1', 'url' => 'https://n'])]), new NullLogger());

        $result = $d->distribute($this->content(), 'generic', ['webhookUrl' => 'https://8.8.8.8/hook']);

        self::assertTrue($result->success);
        self::assertSame('e1', $result->externalId);
        self::assertSame('https://n', $result->externalUrl);
        $payload = $this->sentJson();
        self::assertSame('semantic-delivery', $payload['source']);
        self::assertSame(5, $payload['pageUid']);
    }

    #[Test]
    public function n8nMapsFailures(): void
    {
        $opts = ['webhookUrl' => 'https://8.8.8.8/hook'];

        $http = new N8nDistributor($this->factoryReplaying([$this->reply(500, '')]), new NullLogger());
        self::assertSame('n8n returned HTTP 500', $http->distribute($this->content(), 'g', $opts)->error);

        $invalid = new N8nDistributor($this->factoryReplaying([$this->reply(200, 'plain')]), new NullLogger());
        self::assertSame('Invalid response from service', $invalid->distribute($this->content(), 'g', $opts)->error);

        $down = new N8nDistributor($this->factoryReplaying([new \RuntimeException('x')]), $this->failingLogger());
        self::assertSame('Distribution service temporarily unavailable', $down->distribute($this->content(), 'g', $opts)->error);
    }

    #[Test]
    public function identifiersPlatformsAndConnectionCheck(): void
    {
        $factory = $this->factoryReplaying([]);
        foreach ([[new WebhookDistributor($factory, new NullLogger()), 'webhook'], [new N8nDistributor($factory, new NullLogger()), 'n8n']] as [$d, $id]) {
            self::assertSame($id, $d->getIdentifier());
            self::assertSame(['generic'], $d->getAvailablePlatforms());
            self::assertTrue($d->testConnection());
        }
    }
}
