<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Distribution;

use Koh\SemanticDelivery\Domain\Contract\DistributorInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;
use Koh\SemanticDelivery\Domain\Model\DistributionResult;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * LinkedIn distributor supporting both personal (w_member_social)
 * and organization (w_organization_social) posting modes.
 *
 * Mode is determined by options:
 *   - personId set   → posts as urn:li:person:{id}
 *   - organizationId set → posts as urn:li:organization:{id}
 *   - both set       → organizationId takes precedence
 */
final class LinkedInDistributor implements DistributorInterface
{
    private const API_BASE = 'https://api.linkedin.com/rest';

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly LoggerInterface $logger,
    ) {}

    public function getIdentifier(): string
    {
        return 'linkedin';
    }

    /** @param array<string, mixed> $options */
    public function distribute(ChannelContent $content, string $platform, array $options = []): DistributionResult
    {
        $accessToken = (string) ($options['accessToken'] ?? '');
        $organizationId = (string) ($options['organizationId'] ?? '');
        $personId = (string) ($options['personId'] ?? '');

        if ('' === $accessToken) {
            return DistributionResult::failure('LinkedIn accessToken is required');
        }

        $author = $this->resolveAuthor($organizationId, $personId);
        if ('' === $author) {
            return DistributionResult::failure('LinkedIn organizationId or personId is required');
        }

        try {
            $url = (string) ($content->metadata['url'] ?? '');

            $payload = [
                'author' => $author,
                'lifecycleState' => 'PUBLISHED',
                'visibility' => ['com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'],
                'specificContent' => [
                    'com.linkedin.ugc.ShareContent' => $this->buildShareContent($content->content, $url),
                ],
            ];

            $response = $this->requestFactory->request(
                self::API_BASE . '/ugcPosts',
                'POST',
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer ' . $accessToken,
                        'X-Restli-Protocol-Version' => '2.0.0',
                        'LinkedIn-Version' => '202405',
                    ],
                    'body' => json_encode($payload, JSON_THROW_ON_ERROR),
                ],
            );

            $statusCode = $response->getStatusCode();
            if (201 === $statusCode) {
                $postId = $response->getHeaderLine('X-RestLi-Id');
                $postUrl = '' !== $postId ? 'https://www.linkedin.com/feed/update/' . $postId : '';

                return DistributionResult::success($postId, $postUrl);
            }

            $data = json_decode((string) $response->getBody(), true);
            $errorMsg = is_array($data) ? (string) ($data['message'] ?? 'HTTP ' . $statusCode) : 'HTTP ' . $statusCode;

            return DistributionResult::failure($errorMsg);
        } catch (\Throwable $e) {
            $this->logger->error('LinkedIn distribution failed', ['exception' => $e]);

            return DistributionResult::failure('LinkedIn API temporarily unavailable');
        }
    }

    /** @return list<string> */
    public function getAvailablePlatforms(): array
    {
        return ['linkedin'];
    }

    public function testConnection(): bool
    {
        return true;
    }

    private function resolveAuthor(string $organizationId, string $personId): string
    {
        if ('' !== $organizationId) {
            return 'urn:li:organization:' . $organizationId;
        }

        if ('' !== $personId) {
            return 'urn:li:person:' . $personId;
        }

        return '';
    }

    /** @return array<string, mixed> */
    private function buildShareContent(string $text, string $url): array
    {
        $shareContent = [
            'shareCommentary' => ['text' => $text],
            'shareMediaCategory' => 'NONE',
        ];

        if ('' !== $url) {
            $shareContent['shareMediaCategory'] = 'ARTICLE';
            $shareContent['media'] = [
                [
                    'status' => 'READY',
                    'originalUrl' => $url,
                ],
            ];
        }

        return $shareContent;
    }
}
