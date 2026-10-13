<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Distribution;

use Koh\SemanticDelivery\Domain\Contract\DistributorInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;
use Koh\SemanticDelivery\Domain\Model\DistributionResult;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class LateDevDistributor implements DistributorInterface
{
    private const API_BASE = 'https://api.late.dev/v1';

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly LoggerInterface $logger,
    ) {}

    public function getIdentifier(): string
    {
        return 'latedev';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function distribute(ChannelContent $content, string $platform, array $options = []): DistributionResult
    {
        $apiKey = $options['apiKey'] ?? '';
        if ('' === $apiKey) {
            return DistributionResult::failure('No Late.dev API key configured');
        }

        $payload = [
            'content' => $content->content,
            'platform' => $platform,
            'metadata' => $content->metadata,
        ];

        try {
            $response = $this->requestFactory->request(self::API_BASE . '/posts', 'POST', [
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

            if (($responseBody['success'] ?? false) === true) {
                return DistributionResult::success(
                    $responseBody['id'] ?? '',
                    $responseBody['url'] ?? '',
                );
            }

            return DistributionResult::failure($responseBody['error'] ?? 'Unknown Late.dev error');
        } catch (\Throwable $e) {
            $this->logger->error('Distribution failed', ['distributor' => $this->getIdentifier(), 'exception' => $e]);

            return DistributionResult::failure('Distribution service temporarily unavailable');
        }
    }

    public function getAvailablePlatforms(): array
    {
        return ['linkedin', 'twitter'];
    }

    public function testConnection(): bool
    {
        return true;
    }
}
