<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Channel;

use Koh\SemanticDelivery\Application\Service\SocialMediaTransformerService;
use Koh\SemanticDelivery\Domain\Contract\ChannelInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;

final class SocialMediaChannel implements ChannelInterface
{
    public function __construct(
        private readonly SocialMediaTransformerService $transformerService,
    ) {}

    public function getIdentifier(): string
    {
        return 'social-media';
    }

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     * @param list<array<string, mixed>> $schemaData
     */
    public function transform(array $pageData, array $contentElements, array $schemaData): ChannelContent
    {
        $textParts = [];

        $title = $pageData['title'] ?? '';
        if ('' !== $title) {
            $textParts[] = $title;
        }

        $description = $pageData['description'] ?? $pageData['abstract'] ?? '';
        if ('' !== $description) {
            $textParts[] = $description;
        }

        foreach ($contentElements as $element) {
            $bodytext = strip_tags($element['bodytext'] ?? '');
            if ('' !== $bodytext) {
                $textParts[] = $bodytext;
            }
        }

        $plainContent = implode("\n\n", $textParts);

        // Build full URL from site base + slug
        $siteBase = rtrim((string) ($pageData['_siteBase'] ?? ''), '/');
        $slug = (string) ($pageData['slug'] ?? '');
        $fullUrl = '' !== $siteBase && '' !== $slug ? $siteBase . $slug : $slug;

        $metadata = [
            'title' => $title,
            'description' => $description,
            'url' => $fullUrl,
            'keywords' => (string) ($pageData['keywords'] ?? ''),
            'language' => (string) ($pageData['_language'] ?? 'de'),
            'image' => (string) ($pageData['og:image'] ?? $pageData['_ogImage'] ?? ''),
            'schemas' => $schemaData,
            'platforms' => $this->transformerService->getAvailablePlatforms(),
        ];

        return new ChannelContent(
            channelIdentifier: $this->getIdentifier(),
            content: $plainContent,
            metadata: $metadata,
            status: 'ready',
            outputFormat: $this->getOutputFormat(),
            pageUid: (int) ($pageData['uid'] ?? 0),
        );
    }

    public function getMaxLength(): int
    {
        return 0; // varies by platform
    }

    public function getOutputFormat(): string
    {
        return 'text';
    }
}
