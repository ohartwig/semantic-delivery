<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Distribution;

use Koh\SemanticDelivery\Domain\Contract\DistributorInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;
use Koh\SemanticDelivery\Domain\Model\DistributionResult;
use Koh\SemanticDelivery\Infrastructure\Validation\WebhookUrlValidator;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class N8nDistributor implements DistributorInterface
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly LoggerInterface $logger,
    ) {}

    public function getIdentifier(): string
    {
        return 'n8n';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function distribute(ChannelContent $content, string $platform, array $options = []): DistributionResult
    {
        $webhookUrl = $options['webhookUrl'] ?? '';
        if ('' === $webhookUrl) {
            return DistributionResult::failure('No n8n webhook URL configured');
        }

        if (!WebhookUrlValidator::isValid($webhookUrl, $options)) {
            return DistributionResult::failure('Invalid or blocked webhook URL');
        }

        $payload = [
            'source' => 'semantic-delivery',
            'channel' => $content->channelIdentifier,
            'platform' => $platform,
            'content' => $content->content,
            'metadata' => $content->metadata,
            'pageUid' => $content->pageUid,
        ];

        try {
            $response = $this->requestFactory->request($webhookUrl, 'POST', [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode($payload),
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                $responseBody = json_decode((string) $response->getBody(), true);
                if (!is_array($responseBody)) {
                    return DistributionResult::failure('Invalid response from service');
                }

                return DistributionResult::success(
                    $responseBody['executionId'] ?? '',
                    $responseBody['url'] ?? '',
                );
            }

            return DistributionResult::failure('n8n returned HTTP ' . $statusCode);
        } catch (\Throwable $e) {
            $this->logger->error('Distribution failed', ['distributor' => $this->getIdentifier(), 'exception' => $e]);

            return DistributionResult::failure('Distribution service temporarily unavailable');
        }
    }

    public function getAvailablePlatforms(): array
    {
        return ['generic'];
    }

    public function testConnection(): bool
    {
        return true;
    }
}
