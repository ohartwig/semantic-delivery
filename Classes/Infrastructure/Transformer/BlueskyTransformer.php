<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Transformer;

use Koh\SemanticDelivery\Domain\Contract\TransformerInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;

final class BlueskyTransformer implements TransformerInterface
{
    private const MAX_GRAPHEMES = 300;

    public function getPlatformIdentifier(): string
    {
        return 'bluesky';
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function transform(string $content, array $metadata = []): ChannelContent
    {
        $url = (string) ($metadata['url'] ?? '');
        $title = (string) ($metadata['title'] ?? '');

        $text = '' !== $title ? $title : $this->firstSentence($content);

        if ('' !== $url) {
            $urlLength = mb_strlen($url) + 1;
            $availableLength = self::MAX_GRAPHEMES - $urlLength;

            if (mb_strlen($text) > $availableLength) {
                $text = mb_substr($text, 0, $availableLength - 1) . '…';
            }

            $post = $text . "\n" . $url;
        } else {
            if (mb_strlen($text) > self::MAX_GRAPHEMES) {
                $text = mb_substr($text, 0, self::MAX_GRAPHEMES - 1) . '…';
            }
            $post = $text;
        }

        return new ChannelContent(
            channelIdentifier: 'social-media',
            content: $post,
            metadata: array_merge($metadata, [
                'platform' => 'bluesky',
                'languages' => [$metadata['language'] ?? 'de'],
            ]),
            status: 'ready',
            outputFormat: 'text',
        );
    }

    public function getMaxLength(): int
    {
        return self::MAX_GRAPHEMES;
    }

    private function firstSentence(string $text): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', strip_tags($text), -1, PREG_SPLIT_NO_EMPTY);

        return (false !== $sentences && [] !== $sentences) ? $sentences[0] : $text;
    }
}
