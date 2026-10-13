<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Distribution;

use Koh\SemanticDelivery\Domain\Contract\DistributorInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;
use Koh\SemanticDelivery\Domain\Model\DistributionResult;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class TwitterXDistributor implements DistributorInterface
{
    private const API_BASE = 'https://api.x.com/2';

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly LoggerInterface $logger,
    ) {}

    public function getIdentifier(): string
    {
        return 'twitter';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function distribute(ChannelContent $content, string $platform, array $options = []): DistributionResult
    {
        $bearerToken = (string) ($options['bearerToken'] ?? '');
        if ('' === $bearerToken) {
            return DistributionResult::failure('X/Twitter bearerToken is required');
        }

        try {
            $payload = ['text' => $content->content];

            $response = $this->requestFactory->request(
                self::API_BASE . '/tweets',
                'POST',
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer ' . $bearerToken,
                    ],
                    'body' => json_encode($payload),
                ],
            );

            $data = json_decode((string) $response->getBody(), true);
            if (!is_array($data)) {
                return DistributionResult::failure('Invalid X API response');
            }

            if (isset($data['errors'])) {
                $errorMsg = (string) ($data['errors'][0]['message'] ?? 'Unknown X API error');

                return DistributionResult::failure($errorMsg);
            }

            $tweetId = (string) ($data['data']['id'] ?? '');
            $username = (string) ($options['username'] ?? '');
            $tweetUrl = '' !== $tweetId && '' !== $username
                ? 'https://x.com/' . $username . '/status/' . $tweetId
                : '';

            return DistributionResult::success($tweetId, $tweetUrl);
        } catch (\Throwable $e) {
            $this->logger->error('X/Twitter distribution failed', ['exception' => $e]);

            return DistributionResult::failure('X API temporarily unavailable');
        }
    }

    public function getAvailablePlatforms(): array
    {
        return ['twitter'];
    }

    public function testConnection(): bool
    {
        return true;
    }
}
