<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Transformer;

use Koh\SemanticDelivery\Domain\Contract\TransformerInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;

final class NewsletterTransformer implements TransformerInterface
{
    public function getPlatformIdentifier(): string
    {
        return 'newsletter';
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function transform(string $content, array $metadata = []): ChannelContent
    {
        $title = $metadata['title'] ?? '';
        $url = $metadata['url'] ?? '';

        $language = (string) ($metadata['language'] ?? 'de');
        $teaser = $this->buildTeaser($content);
        $ctaLabel = 'en' === $language ? 'Read now: ' : 'Jetzt lesen: ';
        $cta = '' !== $url ? $ctaLabel . $url : '';

        $parts = array_filter([$title, $teaser, $cta], static fn(string $v): bool => '' !== $v);
        $newsletterContent = implode("\n\n", $parts);

        return new ChannelContent(
            channelIdentifier: 'social-media',
            content: $newsletterContent,
            metadata: array_merge($metadata, ['platform' => 'newsletter']),
            status: 'ready',
            outputFormat: 'text',
        );
    }

    public function getMaxLength(): int
    {
        return 500;
    }

    private function buildTeaser(string $content): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', strip_tags($content), -1, PREG_SPLIT_NO_EMPTY);
        if (false === $sentences || [] === $sentences) {
            return mb_substr($content, 0, 200);
        }

        $teaser = '';
        foreach ($sentences as $sentence) {
            if (mb_strlen($teaser . ' ' . $sentence) > 300) {
                break;
            }
            $teaser .= ('' !== $teaser ? ' ' : '') . $sentence;
        }

        return $teaser;
    }
}
