<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Persistence;

use Doctrine\DBAL\ParameterType;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;
use Koh\SemanticDelivery\Domain\Repository\ChannelContentRepositoryInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class ChannelContentRepository implements ChannelContentRepositoryInterface
{
    private const TABLE = 'tx_semanticdelivery_channel_content';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function findByPageAndChannel(int $pageUid, string $channelIdentifier): ?ChannelContent
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('page_uid', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('channel_identifier', $queryBuilder->createNamedParameter($channelIdentifier)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return $this->mapRowToModel($row);
    }

    public function findByPage(int $pageUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('page_uid', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->mapRowToModel(...), $rows);
    }

    public function findByStatus(string $status): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter($status)))
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->mapRowToModel(...), $rows);
    }

    public function save(ChannelContent $channelContent): int
    {
        $existing = $this->findByPageAndChannel($channelContent->pageUid, $channelContent->channelIdentifier);
        $data = [
            'channel_identifier' => $channelContent->channelIdentifier,
            'page_uid' => $channelContent->pageUid,
            'content' => $channelContent->content,
            'metadata' => json_encode($channelContent->metadata),
            'status' => $channelContent->status,
            'output_format' => $channelContent->outputFormat,
        ];

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);

        if (null !== $existing) {
            $connection->update(
                self::TABLE,
                $data,
                [
                    'page_uid' => $channelContent->pageUid,
                    'channel_identifier' => $channelContent->channelIdentifier,
                ],
            );

            return $existing->uid;
        }

        $connection->insert(self::TABLE, $data);

        return (int) $connection->lastInsertId();
    }

    public function findByUid(int $uid): ?ChannelContent
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

    public function delete(int $uid): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->delete(self::TABLE, ['uid' => $uid]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToModel(array $row): ChannelContent
    {
        return new ChannelContent(
            channelIdentifier: (string) $row['channel_identifier'],
            content: (string) $row['content'],
            metadata: (static function (string $json): array {
                $decoded = json_decode($json, true);

                return is_array($decoded) ? $decoded : [];
            })((string) $row['metadata']),
            status: (string) $row['status'],
            outputFormat: (string) $row['output_format'],
            pageUid: (int) $row['page_uid'],
            uid: (int) ($row['uid'] ?? 0),
        );
    }
}
