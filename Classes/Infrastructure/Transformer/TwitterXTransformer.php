<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Transformer;

use Koh\SemanticDelivery\Domain\Contract\TransformerInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;

final class TwitterXTransformer implements TransformerInterface
{
    private const MAX_LENGTH = 280;
    private const URL_LENGTH = 23;

    public function getPlatformIdentifier(): string
    {
        return 'twitter';
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function transform(string $content, array $metadata = []): ChannelContent
    {
        $url = $metadata['url'] ?? '';
        $availableLength = self::MAX_LENGTH;

        if ('' !== $url) {
            $availableLength -= self::URL_LENGTH + 1;
        }

        $title = $metadata['title'] ?? '';
        $text = '' !== $title ? $title : $content;

        $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (false !== $sentences && [] !== $sentences) {
            $text = $sentences[0];
        }

        if (mb_strlen($text) > $availableLength) {
            $text = mb_substr($text, 0, $availableLength - 1) . '…';
        }

        $tweet = '' !== $url ? $text . "\n" . $url : $text;

        return new ChannelContent(
            channelIdentifier: 'social-media',
            content: $tweet,
            metadata: array_merge($metadata, ['platform' => 'twitter']),
            status: 'ready',
            outputFormat: 'text',
        );
    }

    public function getMaxLength(): int
    {
        return self::MAX_LENGTH;
    }
}
