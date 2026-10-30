<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Tests\Unit\Infrastructure\Persistence;

use Koh\SemanticDelivery\Domain\Model\ChannelContent;
use Koh\SemanticDelivery\Domain\Model\DistributionJob;
use Koh\SemanticDelivery\Infrastructure\Persistence\ChannelContentRepository;
use Koh\SemanticDelivery\Infrastructure\Persistence\DistributionJobRepository;
use Koh\SemanticDelivery\Tests\Unit\Support\DatabaseDouble;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;

final class RepositoriesTest extends TestCase
{
    use DatabaseDouble;

    private const CONTENT_ROW = [
        'uid' => '11',
        'channel_identifier' => 'website',
        'page_uid' => '4',
        'content' => '<p>x</p>',
        'metadata' => '{"title":"T"}',
        'status' => 'ready',
        'output_format' => 'html',
    ];

    private const JOB_ROW = [
        'uid' => '3',
        'channel_content_uid' => '11',
        'distributor' => 'webhook',
        'platform' => 'generic',
        'status' => 'failed',
        'external_id' => 'e',
        'external_url' => 'u',
        'error_message' => 'boom',
        'scheduled_at' => '100',
        'executed_at' => '200',
    ];

    // --- ChannelContentRepository ---

    #[Test]
    public function channelContentIsMappedFromRow(): void
    {
        $repo = new ChannelContentRepository($this->pool($this->queryBuilder(self::CONTENT_ROW)));

        $content = $repo->findByUid(11);

        self::assertInstanceOf(ChannelContent::class, $content);
        self::assertSame(11, $content->uid);
        self::assertSame(4, $content->pageUid);
        self::assertSame(['title' => 'T'], $content->metadata);
        self::assertSame('html', $content->outputFormat);
        self::assertSame('ready', $content->status);
    }

    #[Test]
    public function channelContentFindersReturnNullWhenNothingMatches(): void
    {
        $repo = new ChannelContentRepository($this->pool($this->queryBuilder(null)));

        self::assertNull($repo->findByUid(1));
        self::assertNull($repo->findByPageAndChannel(1, 'website'));
    }

    #[Test]
    public function channelContentListsAreMapped(): void
    {
        $broken = ['uid' => '12', 'metadata' => 'not json'] + self::CONTENT_ROW;
        $repo = new ChannelContentRepository($this->pool($this->queryBuilder(null, [self::CONTENT_ROW, $broken])));

        $byPage = $repo->findByPage(4);
        $byStatus = $repo->findByStatus('ready');

        self::assertCount(2, $byPage);
        self::assertSame([], $byPage[1]->metadata, 'Undecodable metadata falls back to an empty array');
        self::assertCount(2, $byStatus);
    }

    #[Test]
    public function savingNewContentInsertsAndReturnsInsertId(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('insert')->with(
            'tx_semanticdelivery_channel_content',
            self::callback(static fn(array $data): bool => 'website' === $data['channel_identifier']
                && 4 === $data['page_uid']
                && '{"a":1}' === $data['metadata']
                && 'ready' === $data['status']),
        );
        $connection->expects(self::never())->method('update');
        $connection->method('lastInsertId')->willReturn('77');
        $repo = new ChannelContentRepository($this->pool($this->queryBuilder(null), $connection));

        $uid = $repo->save(new ChannelContent('website', '<p>x</p>', ['a' => 1], 'ready', 'html', 4));

        self::assertSame(77, $uid);
    }

    #[Test]
    public function savingExistingContentUpdatesInPlaceAndKeepsUid(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('insert');
        $connection->expects(self::once())->method('update')->with(
            'tx_semanticdelivery_channel_content',
            self::anything(),
            ['page_uid' => 4, 'channel_identifier' => 'website'],
        );
        $repo = new ChannelContentRepository($this->pool($this->queryBuilder(self::CONTENT_ROW), $connection));

        self::assertSame(11, $repo->save(new ChannelContent('website', 'new', [], 'ready', 'html', 4)));
    }

    #[Test]
    public function deletingRemovesByUid(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('delete')->with('tx_semanticdelivery_channel_content', ['uid' => 9]);

        (new ChannelContentRepository($this->pool($this->queryBuilder(), $connection)))->delete(9);
    }

    // --- DistributionJobRepository ---

    #[Test]
    public function jobIsMappedFromRow(): void
    {
        $repo = new DistributionJobRepository($this->pool($this->queryBuilder(self::JOB_ROW)));

        $job = $repo->findByUid(3);

        self::assertInstanceOf(DistributionJob::class, $job);
        self::assertSame(3, $job->uid);
        self::assertSame(11, $job->channelContentUid);
        self::assertSame('failed', $job->status);
        self::assertSame('boom', $job->errorMessage);
        self::assertSame(100, $job->scheduledAt);
        self::assertSame(200, $job->executedAt);
    }

    #[Test]
    public function missingJobIsNull(): void
    {
        self::assertNull((new DistributionJobRepository($this->pool($this->queryBuilder(null))))->findByUid(1));
    }

    #[Test]
    public function jobListsAreMapped(): void
    {
        $repo = new DistributionJobRepository($this->pool($this->queryBuilder(null, [self::JOB_ROW, self::JOB_ROW])));

        self::assertCount(2, $repo->findByStatus('failed'));
        self::assertCount(2, $repo->findPending());
        self::assertCount(2, $repo->findByChannelContent(11));
    }

    #[Test]
    public function pendingJobsAreFilteredByPendingStatusOldestFirst(): void
    {
        $qb = $this->queryBuilder(null, []);
        $repo = new DistributionJobRepository($this->pool($qb));

        $repo->findPending();

        self::assertSame([['scheduled_at', 'ASC']], $this->callsTo('orderBy'));
    }

    #[Test]
    public function savingJobInsertsAllFieldsAndReturnsInsertId(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('insert')->with('tx_semanticdelivery_distribution_job', [
            'channel_content_uid' => 11,
            'distributor' => 'webhook',
            'platform' => 'generic',
            'status' => 'pending',
            'external_id' => '',
            'external_url' => '',
            'error_message' => '',
            'scheduled_at' => 5,
            'executed_at' => 0,
        ]);
        $connection->method('lastInsertId')->willReturn('8');
        $repo = new DistributionJobRepository($this->pool($this->queryBuilder(), $connection));

        self::assertSame(8, $repo->save(new DistributionJob(11, 'webhook', 'generic', scheduledAt: 5)));
    }

    #[Test]
    public function updatingJobWritesResultFieldsByUid(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('update')->with(
            'tx_semanticdelivery_distribution_job',
            [
                'status' => 'completed',
                'external_id' => 'x',
                'external_url' => 'y',
                'error_message' => '',
                'executed_at' => 99,
            ],
            ['uid' => 3],
        );
        $repo = new DistributionJobRepository($this->pool($this->queryBuilder(), $connection));

        $repo->update(new DistributionJob(11, 'webhook', 'generic', 'completed', 'x', 'y', '', 0, 99, 3));
    }
}
