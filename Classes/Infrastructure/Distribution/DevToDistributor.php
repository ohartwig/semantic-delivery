<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Distribution;

use Koh\SemanticDelivery\Domain\Contract\DistributorInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;
use Koh\SemanticDelivery\Domain\Model\DistributionResult;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class DevToDistributor implements DistributorInterface
{
    private const API_BASE = 'https://dev.to/api';

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly LoggerInterface $logger,
    ) {}

    public function getIdentifier(): string
    {
        return 'devto';
    }

    /** @param array<string, mixed> $options */
    public function distribute(ChannelContent $content, string $platform, array $options = []): DistributionResult
    {
        $apiKey = (string) ($options['apiKey'] ?? '');

        if ('' === $apiKey) {
            return DistributionResult::failure('Dev.to apiKey is required');
        }

        $title = (string) ($content->metadata['title'] ?? '');
        $description = (string) ($content->metadata['description'] ?? '');
        $canonicalUrl = (string) ($content->metadata['url'] ?? '');
        $tags = $content->metadata['tags'] ?? [];
        $published = (bool) ($options['published'] ?? true);

        if ('' === $title) {
            return DistributionResult::failure('Article title is required for Dev.to');
        }

        try {
            $article = [
                'title' => $title,
                'body_markdown' => $content->content,
                'published' => $published,
            ];

            if ('' !== $description) {
                $article['description'] = mb_substr($description, 0, 140);
            }

            if ('' !== $canonicalUrl) {
                $article['canonical_url'] = $canonicalUrl;
            }

            if (is_array($tags) && [] !== $tags) {
                $article['tags'] = array_slice(
                    array_map(
                        static fn(string $tag): string => strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $tag) ?? $tag),
                        $tags,
                    ),
                    0,
                    4,
                );
            }

            $response = $this->requestFactory->request(
                self::API_BASE . '/articles',
                'POST',
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'api-key' => $apiKey,
                        'Accept' => 'application/json',
                    ],
                    'body' => json_encode(['article' => $article], JSON_THROW_ON_ERROR),
                ],
            );

            $statusCode = $response->getStatusCode();
            $data = json_decode((string) $response->getBody(), true);

            if (201 === $statusCode && is_array($data)) {
                $postId = (string) ($data['id'] ?? '');
                $postUrl = (string) ($data['url'] ?? '');

                return DistributionResult::success($postId, $postUrl);
            }

            $errorMsg = is_array($data) ? (string) ($data['error'] ?? 'HTTP ' . $statusCode) : 'HTTP ' . $statusCode;

            return DistributionResult::failure($errorMsg);
        } catch (\Throwable $e) {
            $this->logger->error('Dev.to distribution failed', ['exception' => $e]);

            return DistributionResult::failure('Dev.to API temporarily unavailable');
        }
    }

    /** @return list<string> */
    public function getAvailablePlatforms(): array
    {
        return ['devto'];
    }

    public function testConnection(): bool
    {
        return true;
    }
}
