<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Domain\Repository;

use Koh\SemanticDelivery\Domain\Model\DistributionJob;

interface DistributionJobRepositoryInterface
{
    public function findByUid(int $uid): ?DistributionJob;

    /** @return list<DistributionJob> */
    public function findByStatus(string $status): array;

    /** @return list<DistributionJob> */
    public function findPending(): array;

    /** @return list<DistributionJob> */
    public function findByChannelContent(int $channelContentUid): array;

    public function save(DistributionJob $job): int;

    public function update(DistributionJob $job): void;
}
