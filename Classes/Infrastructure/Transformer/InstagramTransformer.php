<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Transformer;

use Koh\SemanticDelivery\Domain\Contract\TransformerInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;

final class InstagramTransformer implements TransformerInterface
{
    private const MAX_LENGTH = 2200;

    public function getPlatformIdentifier(): string
    {
        return 'instagram';
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function transform(string $content, array $metadata = []): ChannelContent
    {
        $caption = $this->buildCaption($content, $metadata);
        $hashtags = $this->generateHashtags($metadata);

        $fullCaption = $caption;
        if ('' !== $hashtags) {
            $fullCaption .= "\n\n" . $hashtags;
        }

        if (mb_strlen($fullCaption) > self::MAX_LENGTH) {
            $availableForCaption = self::MAX_LENGTH - mb_strlen("\n\n" . $hashtags);
            $fullCaption = mb_substr($caption, 0, $availableForCaption - 3) . "...\n\n" . $hashtags;
        }

        return new ChannelContent(
            channelIdentifier: 'social-media',
            content: $fullCaption,
            metadata: array_merge($metadata, [
                'platform' => 'instagram',
                'suggestedImage' => $metadata['image'] ?? null,
            ]),
            status: 'ready',
            outputFormat: 'text',
        );
    }

    public function getMaxLength(): int
    {
        return self::MAX_LENGTH;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function buildCaption(string $content, array $metadata): string
    {
        $title = $metadata['title'] ?? '';
        $description = $metadata['description'] ?? '';

        $parts = array_filter([$title, '' !== $description ? $description : $content], static fn(string $v): bool => '' !== $v);
        $caption = implode("\n\n", $parts);

        $sentences = preg_split('/(?<=[.!?])\s+/', $caption, -1, PREG_SPLIT_NO_EMPTY);
        if (false === $sentences || [] === $sentences) {
            return $caption;
        }

        return implode(' ', array_slice($sentences, 0, 4));
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function generateHashtags(array $metadata): string
    {
        $keywords = $metadata['keywords'] ?? '';
        if ('' === $keywords) {
            return '';
        }

        $tags = array_map(
            static fn(string $keyword): string => '#' . str_replace([' ', '-'], '', trim($keyword)),
            explode(',', $keywords),
        );

        return implode(' ', array_slice($tags, 0, 30));
    }
}
