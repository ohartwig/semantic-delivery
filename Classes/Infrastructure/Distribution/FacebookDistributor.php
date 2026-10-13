<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Distribution;

use Koh\SemanticDelivery\Domain\Contract\DistributorInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;
use Koh\SemanticDelivery\Domain\Model\DistributionResult;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class FacebookDistributor implements DistributorInterface
{
    private const GRAPH_API_VERSION = 'v21.0';
    private const GRAPH_API_BASE = 'https://graph.facebook.com';

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly LoggerInterface $logger,
    ) {}

    public function getIdentifier(): string
    {
        return 'facebook';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function distribute(ChannelContent $content, string $platform, array $options = []): DistributionResult
    {
        $pageAccessToken = (string) ($options['pageAccessToken'] ?? '');
        $pageId = (string) ($options['pageId'] ?? '');

        if ('' === $pageAccessToken || '' === $pageId) {
            return DistributionResult::failure('Facebook pageAccessToken and pageId are required');
        }

        try {
            $imageUrl = (string) ($content->metadata['image'] ?? '');

            if ('' !== $imageUrl) {
                return $this->postWithImage($pageId, $pageAccessToken, $content->content, $imageUrl);
            }

            return $this->postText($pageId, $pageAccessToken, $content->content, $content->metadata['url'] ?? '');
        } catch (\Throwable $e) {
            $this->logger->error('Facebook distribution failed', ['exception' => $e]);

            return DistributionResult::failure('Facebook API temporarily unavailable');
        }
    }

    public function getAvailablePlatforms(): array
    {
        return ['facebook'];
    }

    public function testConnection(): bool
    {
        return true;
    }

    private function postText(string $pageId, string $token, string $message, string $link): DistributionResult
    {
        $params = ['message' => $message, 'access_token' => $token];
        if ('' !== $link) {
            $params['link'] = $link;
        }

        $response = $this->requestFactory->request(
            self::GRAPH_API_BASE . '/' . self::GRAPH_API_VERSION . '/' . $pageId . '/feed',
            'POST',
            ['form_params' => $params],
        );

        return $this->parseResponse($response, $pageId);
    }

    private function postWithImage(string $pageId, string $token, string $message, string $imageUrl): DistributionResult
    {
        $response = $this->requestFactory->request(
            self::GRAPH_API_BASE . '/' . self::GRAPH_API_VERSION . '/' . $pageId . '/photos',
            'POST',
            [
                'form_params' => [
                    'url' => $imageUrl,
                    'caption' => $message,
                    'access_token' => $token,
                ],
            ],
        );

        return $this->parseResponse($response, $pageId);
    }

    /**
     * @param \Psr\Http\Message\ResponseInterface $response
     */
    private function parseResponse($response, string $pageId): DistributionResult
    {
        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) {
            return DistributionResult::failure('Invalid Facebook API response');
        }

        if (isset($data['error'])) {
            $errorMsg = (string) ($data['error']['message'] ?? 'Unknown Facebook error');

            return DistributionResult::failure($errorMsg);
        }

        $postId = (string) ($data['id'] ?? $data['post_id'] ?? '');
        $postUrl = '' !== $postId ? 'https://www.facebook.com/' . $postId : '';

        return DistributionResult::success($postId, $postUrl);
    }
}
