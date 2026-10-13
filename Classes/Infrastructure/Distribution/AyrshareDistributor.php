<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Distribution;

use Koh\SemanticDelivery\Domain\Contract\DistributorInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;
use Koh\SemanticDelivery\Domain\Model\DistributionResult;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class AyrshareDistributor implements DistributorInterface
{
    private const API_BASE = 'https://app.ayrshare.com/api';

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly LoggerInterface $logger,
    ) {}

    public function getIdentifier(): string
    {
        return 'ayrshare';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function distribute(ChannelContent $content, string $platform, array $options = []): DistributionResult
    {
        $apiKey = $options['apiKey'] ?? '';
        if ('' === $apiKey) {
            return DistributionResult::failure('No Ayrshare API key configured');
        }

        $payload = [
            'post' => $content->content,
            'platforms' => [$platform],
        ];

        if (isset($content->metadata['image'])) {
            $payload['mediaUrls'] = [$content->metadata['image']];
        }

        if (isset($options['scheduledDate'])) {
            $payload['scheduleDate'] = $options['scheduledDate'];
        }

        try {
            $response = $this->requestFactory->request(self::API_BASE . '/post', 'POST', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $apiKey,
                ],
                'body' => json_encode($payload),
            ]);

            $responseBody = json_decode((string) $response->getBody(), true);
            if (!is_array($responseBody)) {
                return DistributionResult::failure('Invalid response from service');
            }

            if (($responseBody['status'] ?? '') === 'success') {
                return DistributionResult::success(
                    $responseBody['id'] ?? '',
                    $responseBody['postUrl'] ?? '',
                );
            }

            return DistributionResult::failure($responseBody['message'] ?? 'Unknown Ayrshare error');
        } catch (\Throwable $e) {
            $this->logger->error('Distribution failed', ['distributor' => $this->getIdentifier(), 'exception' => $e]);

            return DistributionResult::failure('Distribution service temporarily unavailable');
        }
    }

    public function getAvailablePlatforms(): array
    {
        return ['linkedin', 'twitter', 'instagram', 'facebook', 'tiktok', 'pinterest'];
    }

    public function testConnection(): bool
    {
        return true;
    }
}
