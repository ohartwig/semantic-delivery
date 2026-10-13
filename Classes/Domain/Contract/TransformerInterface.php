<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Domain\Contract;

use Koh\SemanticDelivery\Domain\Model\ChannelContent;

interface TransformerInterface
{
    public function getPlatformIdentifier(): string;

    /**
     * @param array<string, mixed> $metadata
     */
    public function transform(string $content, array $metadata = []): ChannelContent;

    public function getMaxLength(): int;
}
