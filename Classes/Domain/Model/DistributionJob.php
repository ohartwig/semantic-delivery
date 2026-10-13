<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Domain\Model;

/**
 * Intentionally mutable Entity (not a Value Object).
 *
 * State transitions (status, externalId, errorMessage, executedAt) are applied
 * in place via markSuccess() / markFailed() because the job represents a
 * lifecycle-tracked record persisted to the database.
 */
final class DistributionJob
{
    public function __construct(
        public readonly int $channelContentUid,
        public readonly string $distributor,
        public readonly string $platform,
        public string $status = 'pending',
        public string $externalId = '',
        public string $externalUrl = '',
        public string $errorMessage = '',
        public int $scheduledAt = 0,
        public int $executedAt = 0,
        public int $uid = 0,
    ) {}

    public function markSuccess(string $externalId, string $externalUrl): void
    {
        $this->status = 'completed';
        $this->externalId = $externalId;
        $this->externalUrl = $externalUrl;
        $this->executedAt = time();
    }

    public function markFailed(string $errorMessage): void
    {
        $this->status = 'failed';
        $this->errorMessage = $errorMessage;
        $this->executedAt = time();
    }
}
