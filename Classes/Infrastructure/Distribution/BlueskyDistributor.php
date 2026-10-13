<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Distribution;

use Koh\SemanticDelivery\Domain\Contract\DistributorInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;
use Koh\SemanticDelivery\Domain\Model\DistributionResult;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class BlueskyDistributor implements DistributorInterface
{
    private const DEFAULT_SERVICE = 'https://bsky.social';

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly LoggerInterface $logger,
    ) {}

    public function getIdentifier(): string
    {
        return 'bluesky';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function distribute(ChannelContent $content, string $platform, array $options = []): DistributionResult
    {
        $handle = (string) ($options['handle'] ?? '');
        $appPassword = (string) ($options['appPassword'] ?? '');
        $service = (string) ($options['service'] ?? self::DEFAULT_SERVICE);

        if ('' === $handle || '' === $appPassword) {
            return DistributionResult::failure('Bluesky handle and appPassword are required');
        }

        try {
            $session = $this->createSession($service, $handle, $appPassword);
            if (null === $session) {
                return DistributionResult::failure('Bluesky authentication failed');
            }

            $record = $this->buildRecord($content, $session['did']);
            $response = $this->createRecord($service, $session['accessJwt'], $session['did'], $record);

            if (null === $response) {
                return DistributionResult::failure('Failed to create Bluesky post');
            }

            $uri = $response['uri'] ?? '';
            $postUrl = $this->buildPostUrl($handle, $uri);

            return DistributionResult::success($uri, $postUrl);
        } catch (\Throwable $e) {
            $this->logger->error('Bluesky distribution failed', ['exception' => $e]);

            return DistributionResult::failure('Bluesky service temporarily unavailable');
        }
    }

    public function getAvailablePlatforms(): array
    {
        return ['bluesky'];
    }

    public function testConnection(): bool
    {
        return true;
    }

    /**
     * @return array{did: string, accessJwt: string}|null
     */
    private function createSession(string $service, string $handle, string $appPassword): ?array
    {
        $response = $this->requestFactory->request(
            $service . '/xrpc/com.atproto.server.createSession',
            'POST',
            [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode(['identifier' => $handle, 'password' => $appPassword]),
            ],
        );

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data) || !isset($data['did'], $data['accessJwt'])) {
            return null;
        }

        return ['did' => (string) $data['did'], 'accessJwt' => (string) $data['accessJwt']];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRecord(ChannelContent $content, string $did): array
    {
        $text = $content->content;
        $facets = $this->extractFacets($text);

        $record = [
            '$type' => 'app.bsky.feed.post',
            'text' => $text,
            'createdAt' => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format('c'),
        ];

        if ([] !== $facets) {
            $record['facets'] = $facets;
        }

        $langs = $content->metadata['languages'] ?? ['de'];
        if (is_array($langs) && [] !== $langs) {
            $record['langs'] = $langs;
        }

        return $record;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractFacets(string $text): array
    {
        $facets = [];

        // Detect URLs
        if (false !== preg_match_all('#https?://[^\s)]+#', $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $url = $match[0];
                $byteStart = strlen(mb_strcut($text, 0, $match[1]));
                $byteEnd = $byteStart + strlen($url);

                $facets[] = [
                    'index' => ['byteStart' => $byteStart, 'byteEnd' => $byteEnd],
                    'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $url]],
                ];
            }
        }

        return $facets;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>|null
     */
    private function createRecord(string $service, string $accessJwt, string $did, array $record): ?array
    {
        $response = $this->requestFactory->request(
            $service . '/xrpc/com.atproto.repo.createRecord',
            'POST',
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $accessJwt,
                ],
                'body' => json_encode([
                    'repo' => $did,
                    'collection' => 'app.bsky.feed.post',
                    'record' => $record,
                ]),
            ],
        );

        $data = json_decode((string) $response->getBody(), true);

        return is_array($data) ? $data : null;
    }

    private function buildPostUrl(string $handle, string $uri): string
    {
        // URI format: at://did:plc:xxx/app.bsky.feed.post/rkey
        $parts = explode('/', $uri);
        $rkey = end($parts);

        return 'https://bsky.app/profile/' . $handle . '/post/' . $rkey;
    }
}
