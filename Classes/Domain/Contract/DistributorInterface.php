<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Domain\Contract;

use Koh\SemanticDelivery\Domain\Model\ChannelContent;
use Koh\SemanticDelivery\Domain\Model\DistributionResult;

interface DistributorInterface
{
    public function getIdentifier(): string;

    /**
     * @param array<string, mixed> $options
     */
    public function distribute(ChannelContent $content, string $platform, array $options = []): DistributionResult;

    /** @return list<string> */
    public function getAvailablePlatforms(): array;

    public function testConnection(): bool;
}
