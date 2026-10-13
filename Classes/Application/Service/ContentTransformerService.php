<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Application\Service;

use Koh\SemanticDelivery\Domain\Contract\ChannelInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;

final class ContentTransformerService
{
    /** @var array<string, ChannelInterface> */
    private readonly array $channelMap;

    /**
     * @param iterable<ChannelInterface> $channels
     */
    public function __construct(iterable $channels)
    {
        $map = [];
        foreach ($channels as $channel) {
            $map[$channel->getIdentifier()] = $channel;
        }
        $this->channelMap = $map;
    }

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     * @param list<array<string, mixed>> $schemaData
     */
    public function transformForChannel(string $channelIdentifier, array $pageData, array $contentElements, array $schemaData): ?ChannelContent
    {
        $channel = $this->channelMap[$channelIdentifier] ?? null;
        if (null === $channel) {
            return null;
        }

        return $channel->transform($pageData, $contentElements, $schemaData);
    }

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     * @param list<array<string, mixed>> $schemaData
     *
     * @return array<string, ChannelContent>
     */
    public function transformForAllChannels(array $pageData, array $contentElements, array $schemaData): array
    {
        $results = [];

        foreach ($this->channelMap as $identifier => $channel) {
            $results[$identifier] = $channel->transform($pageData, $contentElements, $schemaData);
        }

        return $results;
    }

    /** @return list<string> */
    public function getAvailableChannels(): array
    {
        return array_keys($this->channelMap);
    }
}
