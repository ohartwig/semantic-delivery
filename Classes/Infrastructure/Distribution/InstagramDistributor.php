<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Distribution;

use Koh\SemanticDelivery\Domain\Contract\DistributorInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;
use Koh\SemanticDelivery\Domain\Model\DistributionResult;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class InstagramDistributor implements DistributorInterface
{
    private const GRAPH_API_VERSION = 'v21.0';
    private const GRAPH_API_BASE = 'https://graph.facebook.com';

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly LoggerInterface $logger,
    ) {}

    public function getIdentifier(): string
    {
        return 'instagram';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function distribute(ChannelContent $content, string $platform, array $options = []): DistributionResult
    {
        $accessToken = (string) ($options['pageAccessToken'] ?? '');
        $igUserId = (string) ($options['instagramBusinessAccountId'] ?? '');

        if ('' === $accessToken || '' === $igUserId) {
            return DistributionResult::failure('Instagram pageAccessToken and instagramBusinessAccountId are required');
        }

        $imageUrl = (string) ($content->metadata['suggestedImage'] ?? $content->metadata['image'] ?? '');
        if ('' === $imageUrl) {
            return DistributionResult::failure('Instagram requires an image URL for feed posts');
        }

        try {
            $containerId = $this->createMediaContainer($igUserId, $accessToken, $imageUrl, $content->content);
            if ('' === $containerId) {
                return DistributionResult::failure('Failed to create Instagram media container');
            }

            return $this->publishContainer($igUserId, $accessToken, $containerId);
        } catch (\Throwable $e) {
            $this->logger->error('Instagram distribution failed', ['exception' => $e]);

            return DistributionResult::failure('Instagram API temporarily unavailable');
        }
    }

    public function getAvailablePlatforms(): array
    {
        return ['instagram'];
    }

    public function testConnection(): bool
    {
        return true;
    }

    private function createMediaContainer(string $igUserId, string $token, string $imageUrl, string $caption): string
    {
        $response = $this->requestFactory->request(
            self::GRAPH_API_BASE . '/' . self::GRAPH_API_VERSION . '/' . $igUserId . '/media',
            'POST',
            [
                'form_params' => [
                    'image_url' => $imageUrl,
                    'caption' => $caption,
                    'access_token' => $token,
                ],
            ],
        );

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) {
            return '';
        }

        if (isset($data['error'])) {
            $this->logger->warning('Instagram container creation failed', ['error' => $data['error']]);

            return '';
        }

        return (string) ($data['id'] ?? '');
    }

    private function publishContainer(string $igUserId, string $token, string $containerId): DistributionResult
    {
        $response = $this->requestFactory->request(
            self::GRAPH_API_BASE . '/' . self::GRAPH_API_VERSION . '/' . $igUserId . '/media_publish',
            'POST',
            [
                'form_params' => [
                    'creation_id' => $containerId,
                    'access_token' => $token,
                ],
            ],
        );

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) {
            return DistributionResult::failure('Invalid Instagram publish response');
        }

        if (isset($data['error'])) {
            return DistributionResult::failure((string) ($data['error']['message'] ?? 'Instagram publish error'));
        }

        $mediaId = (string) ($data['id'] ?? '');
        $postUrl = '' !== $mediaId ? 'https://www.instagram.com/p/' . $mediaId . '/' : '';

        return DistributionResult::success($mediaId, $postUrl);
    }
}
