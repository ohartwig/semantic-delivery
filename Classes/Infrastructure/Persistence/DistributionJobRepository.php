<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Persistence;

use Doctrine\DBAL\ParameterType;
use Koh\SemanticDelivery\Domain\Model\DistributionJob;
use Koh\SemanticDelivery\Domain\Repository\DistributionJobRepositoryInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class DistributionJobRepository implements DistributionJobRepositoryInterface
{
    private const TABLE = 'tx_semanticdelivery_distribution_job';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function findByUid(int $uid): ?DistributionJob
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return $this->mapRowToModel($row);
    }

    public function findByStatus(string $status): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter($status)))
            ->orderBy('scheduled_at', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->mapRowToModel(...), $rows);
    }

    public function findPending(): array
    {
        return $this->findByStatus('pending');
    }

    public function findByChannelContent(int $channelContentUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('channel_content_uid', $queryBuilder->createNamedParameter($channelContentUid, ParameterType::INTEGER)))
            ->orderBy('crdate', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->mapRowToModel(...), $rows);
    }

    public function save(DistributionJob $job): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'channel_content_uid' => $job->channelContentUid,
            'distributor' => $job->distributor,
            'platform' => $job->platform,
            'status' => $job->status,
            'external_id' => $job->externalId,
            'external_url' => $job->externalUrl,
            'error_message' => $job->errorMessage,
            'scheduled_at' => $job->scheduledAt,
            'executed_at' => $job->executedAt,
        ]);

        return (int) $connection->lastInsertId();
    }

    public function update(DistributionJob $job): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(self::TABLE, [
            'status' => $job->status,
            'external_id' => $job->externalId,
            'external_url' => $job->externalUrl,
            'error_message' => $job->errorMessage,
            'executed_at' => $job->executedAt,
        ], ['uid' => $job->uid]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToModel(array $row): DistributionJob
    {
        return new DistributionJob(
            channelContentUid: (int) $row['channel_content_uid'],
            distributor: (string) $row['distributor'],
            platform: (string) $row['platform'],
            status: (string) $row['status'],
            externalId: (string) $row['external_id'],
            externalUrl: (string) $row['external_url'],
            errorMessage: (string) $row['error_message'],
            scheduledAt: (int) $row['scheduled_at'],
            executedAt: (int) $row['executed_at'],
            uid: (int) $row['uid'],
        );
    }
}
