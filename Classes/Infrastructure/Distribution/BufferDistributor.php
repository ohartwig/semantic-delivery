<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Distribution;

use Koh\SemanticDelivery\Domain\Contract\DistributorInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;
use Koh\SemanticDelivery\Domain\Model\DistributionResult;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class BufferDistributor implements DistributorInterface
{
    private const API_BASE = 'https://api.bufferapp.com/1';

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly LoggerInterface $logger,
    ) {}

    public function getIdentifier(): string
    {
        return 'buffer';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function distribute(ChannelContent $content, string $platform, array $options = []): DistributionResult
    {
        $accessToken = $options['accessToken'] ?? '';
        if ('' === $accessToken) {
            return DistributionResult::failure('No Buffer access token configured');
        }

        $profileId = $options['profileId'] ?? '';
        if ('' === $profileId) {
            return DistributionResult::failure('No Buffer profile ID configured');
        }

        $payload = [
            'text' => $content->content,
            'profile_ids' => [$profileId],
        ];

        if (isset($content->metadata['image'])) {
            $payload['media'] = ['photo' => $content->metadata['image']];
        }

        try {
            $response = $this->requestFactory->request(
                self::API_BASE . '/updates/create.json',
                'POST',
                [
                    'headers' => [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                        'Authorization' => 'Bearer ' . $accessToken,
                    ],
                    'body' => http_build_query($payload),
                ],
            );

            $responseBody = json_decode((string) $response->getBody(), true);
            if (!is_array($responseBody)) {
                return DistributionResult::failure('Invalid response from service');
            }

            if (($responseBody['success'] ?? false) === true) {
                return DistributionResult::success(
                    $responseBody['updates'][0]['id'] ?? '',
                    '',
                );
            }

            return DistributionResult::failure($responseBody['message'] ?? 'Unknown Buffer error');
        } catch (\Throwable $e) {
            $this->logger->error('Distribution failed', ['distributor' => $this->getIdentifier(), 'exception' => $e]);

            return DistributionResult::failure('Distribution service temporarily unavailable');
        }
    }

    public function getAvailablePlatforms(): array
    {
        return ['linkedin', 'twitter', 'instagram', 'facebook', 'pinterest'];
    }

    public function testConnection(): bool
    {
        return true;
    }
}
