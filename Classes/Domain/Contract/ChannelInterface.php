<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Domain\Contract;

use Koh\SemanticDelivery\Domain\Model\ChannelContent;

interface ChannelInterface
{
    public function getIdentifier(): string;

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     * @param list<array<string, mixed>> $schemaData
     */
    public function transform(array $pageData, array $contentElements, array $schemaData): ChannelContent;

    public function getMaxLength(): int;

    public function getOutputFormat(): string;
}
