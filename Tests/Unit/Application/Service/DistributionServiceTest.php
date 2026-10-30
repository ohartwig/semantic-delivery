<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Tests\Unit\Application\Service;

use Koh\SemanticDelivery\Application\Service\DistributionService;
use Koh\SemanticDelivery\Domain\Contract\DistributorInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;
use Koh\SemanticDelivery\Domain\Model\DistributionJob;
use Koh\SemanticDelivery\Domain\Model\DistributionResult;
use Koh\SemanticDelivery\Domain\Repository\ChannelContentRepositoryInterface;
use Koh\SemanticDelivery\Domain\Repository\DistributionJobRepositoryInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class DistributionServiceTest extends TestCase
{
    private DistributionJobRepositoryInterface&MockObject $jobs;
    private ChannelContentRepositoryInterface&MockObject $contents;

    protected function setUp(): void
    {
        $this->jobs = $this->createMock(DistributionJobRepositoryInterface::class);
        $this->contents = $this->createMock(ChannelContentRepositoryInterface::class);
    }

    private function distributor(string $id, ?DistributionResult $result = null, bool $connection = true): DistributorInterface
    {
        $d = $this->createStub(DistributorInterface::class);
        $d->method('getIdentifier')->willReturn($id);
        $d->method('distribute')->willReturn($result ?? DistributionResult::success('ext', 'https://e.x/1'));
        $d->method('testConnection')->willReturn($connection);

        return $d;
    }

    #[Test]
    public function distributeFailsForUnknownDistributor(): void
    {
        $service = new DistributionService([], $this->jobs, $this->contents);

        $result = $service->distribute(new ChannelContent('c', 'x'), 'ghost', 'p');

        self::assertFalse($result->success);
        self::assertSame('Unknown distributor: ghost', $result->error);
    }

    #[Test]
    public function distributePassesPlatformAndOptionsToTheDistributor(): void
    {
        $content = new ChannelContent('c', 'x');
        $d = $this->createMock(DistributorInterface::class);
        $d->method('getIdentifier')->willReturn('hook');
        $d->expects(self::once())->method('distribute')->with($content, 'generic', ['o' => 1])
            ->willReturn(DistributionResult::success('9'));

        $result = (new DistributionService([$d], $this->jobs, $this->contents))
            ->distribute($content, 'hook', 'generic', ['o' => 1]);

        self::assertSame('9', $result->externalId);
    }

    #[Test]
    public function processJobMarksSuccessAndPersists(): void
    {
        $job = new DistributionJob(1, 'hook', 'generic');
        $this->jobs->expects(self::once())->method('update')->with($job);
        $service = new DistributionService([$this->distributor('hook')], $this->jobs, $this->contents);

        $result = $service->processJob($job, new ChannelContent('c', 'x'));

        self::assertTrue($result->success);
        self::assertSame('completed', $job->status);
        self::assertSame('ext', $job->externalId);
        self::assertSame('https://e.x/1', $job->externalUrl);
    }

    #[Test]
    public function processJobMarksFailureAndPersists(): void
    {
        $job = new DistributionJob(1, 'hook', 'generic');
        $this->jobs->expects(self::once())->method('update')->with($job);
        $service = new DistributionService(
            [$this->distributor('hook', DistributionResult::failure('HTTP 500'))],
            $this->jobs,
            $this->contents,
        );

        $result = $service->processJob($job, new ChannelContent('c', 'x'));

        self::assertFalse($result->success);
        self::assertSame('failed', $job->status);
        self::assertSame('HTTP 500', $job->errorMessage);
    }

    #[Test]
    public function processPendingJobsCountsOnlyJobsWithContent(): void
    {
        $ok = new DistributionJob(1, 'hook', 'generic');
        $orphan = new DistributionJob(2, 'hook', 'generic');
        $this->jobs->method('findPending')->willReturn([$ok, $orphan]);
        $this->contents->method('findByUid')->willReturnCallback(
            static fn(int $uid): ?ChannelContent => 1 === $uid ? new ChannelContent('c', 'x') : null,
        );
        $this->jobs->expects(self::exactly(2))->method('update');
        $service = new DistributionService([$this->distributor('hook')], $this->jobs, $this->contents);

        self::assertSame(1, $service->processPendingJobs());
        self::assertSame('completed', $ok->status);
        self::assertSame('failed', $orphan->status);
        self::assertSame('ChannelContent not found for UID: 2', $orphan->errorMessage);
    }

    #[Test]
    public function listsDistributorsAndTestsConnections(): void
    {
        $service = new DistributionService(
            [$this->distributor('up'), $this->distributor('down', null, false)],
            $this->jobs,
            $this->contents,
        );

        self::assertSame(['up', 'down'], $service->getAvailableDistributors());
        self::assertTrue($service->testConnection('up'));
        self::assertFalse($service->testConnection('down'));
        self::assertFalse($service->testConnection('ghost'));
    }
}
