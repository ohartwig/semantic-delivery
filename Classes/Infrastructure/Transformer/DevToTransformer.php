<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Transformer;

use Koh\SemanticDelivery\Domain\Contract\TransformerInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;

final class DevToTransformer implements TransformerInterface
{
    public function getPlatformIdentifier(): string
    {
        return 'devto';
    }

    /** @param array<string, mixed> $metadata */
    public function transform(string $content, array $metadata = []): ChannelContent
    {
        $url = (string) ($metadata['url'] ?? '');

        $markdown = $this->htmlToMarkdown($content);

        if ('' !== $url) {
            $language = (string) ($metadata['language'] ?? 'de');
            $label = 'en' === $language ? 'Originally published at' : 'Ursprünglich veröffentlicht auf';
            $markdown .= "\n\n---\n*" . $label . ' [' . $url . '](' . $url . ")*\n";
        }

        return new ChannelContent(
            channelIdentifier: 'blogging',
            content: $markdown,
            metadata: array_merge($metadata, ['platform' => 'devto']),
            status: 'ready',
            outputFormat: 'markdown',
        );
    }

    public function getMaxLength(): int
    {
        return 0;
    }

    private function htmlToMarkdown(string $html): string
    {
        $markdown = $html;

        // Headers
        $markdown = (string) preg_replace('/<h1[^>]*>(.*?)<\/h1>/is', "# $1\n\n", $markdown);
        $markdown = (string) preg_replace('/<h2[^>]*>(.*?)<\/h2>/is', "## $1\n\n", $markdown);
        $markdown = (string) preg_replace('/<h3[^>]*>(.*?)<\/h3>/is', "### $1\n\n", $markdown);
        $markdown = (string) preg_replace('/<h4[^>]*>(.*?)<\/h4>/is', "#### $1\n\n", $markdown);
        $markdown = (string) preg_replace('/<h5[^>]*>(.*?)<\/h5>/is', "##### $1\n\n", $markdown);
        $markdown = (string) preg_replace('/<h6[^>]*>(.*?)<\/h6>/is', "###### $1\n\n", $markdown);

        // Bold and italic
        $markdown = (string) preg_replace('/<(strong|b)[^>]*>(.*?)<\/\1>/is', '**$2**', $markdown);
        $markdown = (string) preg_replace('/<(em|i)[^>]*>(.*?)<\/\1>/is', '*$2*', $markdown);

        // Links
        $markdown = (string) preg_replace('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', '[$2]($1)', $markdown);

        // Images
        $markdown = (string) preg_replace('/<img[^>]+src=["\']([^"\']+)["\'][^>]*alt=["\']([^"\']*)["\'][^>]*\/?>/is', '![$2]($1)', $markdown);
        $markdown = (string) preg_replace('/<img[^>]+alt=["\']([^"\']*)["\'][^>]*src=["\']([^"\']+)["\'][^>]*\/?>/is', '![$1]($2)', $markdown);

        // Code blocks
        $markdown = (string) preg_replace('/<pre[^>]*><code[^>]*>(.*?)<\/code><\/pre>/is', "```\n$1\n```\n\n", $markdown);
        $markdown = (string) preg_replace('/<code[^>]*>(.*?)<\/code>/is', '`$1`', $markdown);

        // Lists
        $markdown = (string) preg_replace('/<li[^>]*>(.*?)<\/li>/is', "- $1\n", $markdown);
        $markdown = (string) preg_replace('/<\/?[uo]l[^>]*>/is', "\n", $markdown);

        // Paragraphs and line breaks
        $markdown = (string) preg_replace('/<p[^>]*>(.*?)<\/p>/is', "$1\n\n", $markdown);
        $markdown = (string) preg_replace('/<br\s*\/?>/is', "\n", $markdown);

        // Horizontal rules
        $markdown = (string) preg_replace('/<hr\s*\/?>/is', "\n---\n\n", $markdown);

        // Blockquotes
        $markdown = (string) preg_replace('/<blockquote[^>]*>(.*?)<\/blockquote>/is', "> $1\n\n", $markdown);

        // Strip remaining tags
        $markdown = strip_tags($markdown);

        // Decode HTML entities
        $markdown = html_entity_decode($markdown, ENT_QUOTES, 'UTF-8');

        // Clean up excessive whitespace
        $markdown = (string) preg_replace("/\n{3,}/", "\n\n", $markdown);

        return trim($markdown);
    }
}
