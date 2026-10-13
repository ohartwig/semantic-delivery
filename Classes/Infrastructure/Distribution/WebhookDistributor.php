<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Distribution;

use Koh\SemanticDelivery\Domain\Contract\DistributorInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;
use Koh\SemanticDelivery\Domain\Model\DistributionResult;
use Koh\SemanticDelivery\Infrastructure\Validation\WebhookUrlValidator;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class WebhookDistributor implements DistributorInterface
{
    /** @var list<string> Headers that user-supplied options cannot override */
    private const array BLOCKED_HEADERS = [
        'host',
        'authorization',
        'cookie',
    ];

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly LoggerInterface $logger,
    ) {}

    public function getIdentifier(): string
    {
        return 'webhook';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function distribute(ChannelContent $content, string $platform, array $options = []): DistributionResult
    {
        $url = $options['url'] ?? '';
        if ('' === $url) {
            return DistributionResult::failure('No webhook URL configured');
        }

        if (!WebhookUrlValidator::isValid($url, $options)) {
            return DistributionResult::failure('Invalid or blocked webhook URL');
        }

        $payload = [
            'channel' => $content->channelIdentifier,
            'platform' => $platform,
            'content' => $content->content,
            'metadata' => $content->metadata,
            'timestamp' => time(),
        ];

        $userHeaders = is_array($options['headers'] ?? null) ? $options['headers'] : [];
        $filteredHeaders = array_filter(
            $userHeaders,
            static fn(string $name): bool => !in_array(strtolower($name), self::BLOCKED_HEADERS, true),
            ARRAY_FILTER_USE_KEY,
        );

        $headers = array_merge(
            ['Content-Type' => 'application/json'],
            $filteredHeaders,
        );

        if (isset($options['secret'])) {
            $encoded = json_encode($payload);
            $headers['X-Webhook-Signature'] = hash_hmac('sha256', false !== $encoded ? $encoded : '', $options['secret']);
        }

        try {
            $response = $this->requestFactory->request($url, 'POST', [
                'headers' => $headers,
                'body' => json_encode($payload),
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                $responseBody = json_decode((string) $response->getBody(), true);
                if (!is_array($responseBody)) {
                    return DistributionResult::failure('Invalid response from service');
                }

                return DistributionResult::success(
                    $responseBody['id'] ?? '',
                    $responseBody['url'] ?? '',
                );
            }

            return DistributionResult::failure('Webhook returned HTTP ' . $statusCode);
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
