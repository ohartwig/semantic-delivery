<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Channel;

use Koh\SemanticDelivery\Domain\Contract\ChannelInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;

final class AiAgentChannel implements ChannelInterface
{
    public function getIdentifier(): string
    {
        return 'ai-agent';
    }

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     * @param list<array<string, mixed>> $schemaData
     */
    public function transform(array $pageData, array $contentElements, array $schemaData): ChannelContent
    {
        $structuredContent = [
            'page' => [
                'uid' => (int) ($pageData['uid'] ?? 0),
                'title' => $pageData['title'] ?? '',
                'description' => $pageData['description'] ?? $pageData['abstract'] ?? '',
                'slug' => $pageData['slug'] ?? '',
            ],
            'content' => [],
            'schema' => $schemaData,
        ];

        foreach ($contentElements as $element) {
            $contentItem = [
                'type' => $element['CType'] ?? 'text',
                'header' => $element['header'] ?? '',
                'text' => strip_tags($element['bodytext'] ?? ''),
            ];

            if (isset($element['image']) && '' !== $element['image']) {
                $contentItem['images'] = $element['image'];
            }

            $structuredContent['content'][] = $contentItem;
        }

        $jsonResult = json_encode($structuredContent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $jsonContent = false !== $jsonResult ? $jsonResult : '{}';

        return new ChannelContent(
            channelIdentifier: $this->getIdentifier(),
            content: $jsonContent,
            metadata: $structuredContent['page'],
            status: 'ready',
            outputFormat: $this->getOutputFormat(),
            pageUid: (int) ($pageData['uid'] ?? 0),
        );
    }

    public function getMaxLength(): int
    {
        return 0; // no limit
    }

    public function getOutputFormat(): string
    {
        return 'json';
    }
}
