<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Transformer;

use Koh\SemanticDelivery\Domain\Contract\TransformerInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;

final class FacebookTransformer implements TransformerInterface
{
    private const MAX_LENGTH = 63206;

    public function getPlatformIdentifier(): string
    {
        return 'facebook';
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function transform(string $content, array $metadata = []): ChannelContent
    {
        $url = (string) ($metadata['url'] ?? '');
        $title = (string) ($metadata['title'] ?? '');
        $description = (string) ($metadata['description'] ?? '');

        $source = '' !== $description ? $description : strip_tags($content);
        $sentences = preg_split('/(?<=[.!?])\s+/', $source, -1, PREG_SPLIT_NO_EMPTY);

        $hook = '' !== $title ? $title : ((false !== $sentences && [] !== $sentences) ? $sentences[0] : '');

        $body = '';
        if (false !== $sentences && count($sentences) > 1) {
            $bodyParts = array_slice($sentences, '' !== $title ? 0 : 1, 5);
            $body = implode(' ', $bodyParts);
            if (mb_strlen($body) > 2000) {
                $body = mb_substr($body, 0, 2000) . '…';
            }
        }

        $language = (string) ($metadata['language'] ?? 'de');
        $parts = array_filter([$hook, $body], static fn(string $v): bool => '' !== $v);
        if ('' !== $url) {
            $label = 'en' === $language ? '➡ Read more: ' : '➡ Mehr erfahren: ';
            $parts[] = $label . $url;
        }

        $post = implode("\n\n", $parts);
        if (mb_strlen($post) > self::MAX_LENGTH) {
            $post = mb_substr($post, 0, self::MAX_LENGTH - 1) . '…';
        }

        return new ChannelContent(
            channelIdentifier: 'social-media',
            content: $post,
            metadata: array_merge($metadata, ['platform' => 'facebook']),
            status: 'ready',
            outputFormat: 'text',
        );
    }

    public function getMaxLength(): int
    {
        return self::MAX_LENGTH;
    }
}
