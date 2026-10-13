<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Transformer;

use Koh\SemanticDelivery\Domain\Contract\TransformerInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;

final class LinkedInTransformer implements TransformerInterface
{
    private const MAX_LENGTH = 3000;

    public function getPlatformIdentifier(): string
    {
        return 'linkedin';
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function transform(string $content, array $metadata = []): ChannelContent
    {
        $title = $metadata['title'] ?? '';
        $url = $metadata['url'] ?? '';

        $language = (string) ($metadata['language'] ?? 'de');

        $hook = $this->generateHook($title, $content);
        $body = $this->formatBody($content);
        $cta = $this->generateCta($url, $language);

        $post = implode("\n\n", array_filter([$hook, $body, $cta], static fn(string $v): bool => '' !== $v));

        if (mb_strlen($post) > self::MAX_LENGTH) {
            $post = mb_substr($post, 0, self::MAX_LENGTH - 3) . '...';
        }

        return new ChannelContent(
            channelIdentifier: 'social-media',
            content: $post,
            metadata: array_merge($metadata, ['platform' => 'linkedin']),
            status: 'ready',
            outputFormat: 'text',
        );
    }

    public function getMaxLength(): int
    {
        return self::MAX_LENGTH;
    }

    private function generateHook(string $title, string $content): string
    {
        if ('' !== $title) {
            return $title;
        }

        $firstSentence = strtok($content, '.!?');

        return false !== $firstSentence ? $firstSentence . '.' : '';
    }

    private function formatBody(string $content): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', $content, -1, PREG_SPLIT_NO_EMPTY);
        if (false === $sentences || [] === $sentences) {
            return $content;
        }

        $body = implode(' ', array_slice($sentences, 0, 5));

        if (mb_strlen($body) > 2000) {
            $body = mb_substr($body, 0, 2000) . '...';
        }

        return $body;
    }

    private function generateCta(string $url, string $language): string
    {
        if ('' === $url) {
            return '';
        }

        $label = 'en' === $language ? '➡ Read more: ' : '➡ Mehr erfahren: ';

        return $label . $url;
    }
}
