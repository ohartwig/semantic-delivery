<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Application\Service;

use Koh\SemanticDelivery\Domain\Contract\TransformerInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;

final class SocialMediaTransformerService
{
    /** @var array<string, TransformerInterface> */
    private readonly array $transformerMap;

    /**
     * @param iterable<TransformerInterface> $transformers
     */
    public function __construct(iterable $transformers)
    {
        $map = [];
        foreach ($transformers as $transformer) {
            $map[$transformer->getPlatformIdentifier()] = $transformer;
        }
        $this->transformerMap = $map;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function transformForPlatform(string $platform, string $content, array $metadata = []): ?ChannelContent
    {
        $transformer = $this->transformerMap[$platform] ?? null;
        if (null === $transformer) {
            return null;
        }

        return $transformer->transform($content, $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, ChannelContent>
     */
    public function transformForAllPlatforms(string $content, array $metadata = []): array
    {
        $results = [];

        foreach ($this->transformerMap as $platform => $transformer) {
            $results[$platform] = $transformer->transform($content, $metadata);
        }

        return $results;
    }

    /** @return list<string> */
    public function getAvailablePlatforms(): array
    {
        return array_keys($this->transformerMap);
    }
}
