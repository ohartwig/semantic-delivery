<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Transformer;

use Koh\SemanticDelivery\Domain\Contract\TransformerInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;

final class GenericTransformer implements TransformerInterface
{
    public function getPlatformIdentifier(): string
    {
        return 'generic';
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function transform(string $content, array $metadata = []): ChannelContent
    {
        $plainContent = strip_tags($content);

        return new ChannelContent(
            channelIdentifier: 'social-media',
            content: $plainContent,
            metadata: array_merge($metadata, ['platform' => 'generic']),
            status: 'ready',
            outputFormat: 'text',
        );
    }

    public function getMaxLength(): int
    {
        return 0;
    }
}
