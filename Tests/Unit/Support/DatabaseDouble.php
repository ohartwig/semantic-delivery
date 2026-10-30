<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Tests\Unit\Support;

use Doctrine\DBAL\Result;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Fluent query builder double: every builder method returns the builder itself,
 * the result yields the configured rows, and each call is recorded in
 * $queryCalls so that tests can assert on what was written.
 */
trait DatabaseDouble
{
    /** @var list<array{string, list<mixed>}> */
    private array $queryCalls = [];

    /**
     * The Deleted/Hidden restrictions resolve the TCA schema factory through
     * GeneralUtility when they are constructed. Register a stub for it; call
     * releaseTcaSchemaFactory() in tearDown().
     */
    private function provideTcaSchemaFactory(): void
    {
        GeneralUtility::setSingletonInstance(TcaSchemaFactory::class, new InertTcaSchemaFactory());
    }

    private function releaseTcaSchemaFactory(): void
    {
        GeneralUtility::resetSingletonInstances([]);
    }

    /**
     * @param array<string, mixed>|null  $row  returned by fetchAssociative() (false when null)
     * @param list<array<string, mixed>> $rows returned by fetchAllAssociative()
     */
    private function queryBuilder(?array $row = null, array $rows = []): QueryBuilder
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn($row ?? false);
        $result->method('fetchAllAssociative')->willReturn($rows);

        $expr = $this->createStub(ExpressionBuilder::class);
        $expr->method('eq')->willReturn('1 = 1');

        $restrictions = $this->createStub(QueryRestrictionContainerInterface::class);
        $restrictions->method('removeAll')->willReturnSelf();
        $restrictions->method('add')->willReturnSelf();

        $qb = $this->createStub(QueryBuilder::class);
        foreach (['select', 'from', 'where', 'orderBy', 'setMaxResults', 'insert', 'values', 'update', 'set', 'delete', 'setRestrictions'] as $method) {
            $qb->method($method)->willReturnCallback(function (mixed ...$args) use ($method, &$qb) {
                $this->queryCalls[] = [$method, $args];

                return $qb;
            });
        }
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturn(':param');
        $qb->method('getRestrictions')->willReturn($restrictions);
        $qb->method('executeQuery')->willReturn($result);
        $qb->method('executeStatement')->willReturn(1);

        return $qb;
    }

    /**
     * @return list<list<mixed>> the argument lists of all recorded calls to $method
     */
    private function callsTo(string $method): array
    {
        return array_values(array_map(
            static fn(array $call): array => $call[1],
            array_filter($this->queryCalls, static fn(array $call): bool => $call[0] === $method),
        ));
    }

    private function pool(QueryBuilder $queryBuilder, ?Connection $connection = null): ConnectionPool
    {
        $pool = $this->createStub(ConnectionPool::class);
        $pool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
        if (null !== $connection) {
            $pool->method('getConnectionForTable')->willReturn($connection);
        }

        return $pool;
    }
}
