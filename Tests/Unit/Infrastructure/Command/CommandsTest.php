<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Tests\Unit\Infrastructure\Command;

use Koh\SemanticDelivery\Application\Service\DistributionService;
use Koh\SemanticDelivery\Application\Service\SocialMediaTransformerService;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;
use Koh\SemanticDelivery\Domain\Model\DistributionJob;
use Koh\SemanticDelivery\Domain\Repository\ChannelContentRepositoryInterface;
use Koh\SemanticDelivery\Domain\Repository\DistributionJobRepositoryInterface;
use Koh\SemanticDelivery\Infrastructure\Command\PodcastEpisodeDetectorCommand;
use Koh\SemanticDelivery\Infrastructure\Command\ProcessPendingJobsCommand;
use Koh\SemanticDelivery\Infrastructure\Transformer\GenericTransformer;
use Koh\SemanticDelivery\Infrastructure\Transformer\TwitterXTransformer;
use Koh\SemanticDelivery\Tests\Unit\Support\DatabaseDouble;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

#[AllowMockObjectsWithoutExpectations]
final class CommandsTest extends TestCase
{
    use DatabaseDouble;

    private const FEED_URL = 'https://feed.example/rss';
    private const FEED_ROW = ['pid' => 7, 'moselwal_videopodcastfeed_feed_url' => self::FEED_URL];

    private ChannelContentRepositoryInterface&MockObject $contents;
    private DistributionJobRepositoryInterface&MockObject $jobs;
    private LoggerInterface&MockObject $logger;
    private FrontendInterface&MockObject $hashCache;

    protected function setUp(): void
    {
        $this->provideTcaSchemaFactory();
        $this->contents = $this->createMock(ChannelContentRepositoryInterface::class);
        $this->jobs = $this->createMock(DistributionJobRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->releaseTcaSchemaFactory();
    }

    // --- ProcessPendingJobsCommand ---

    #[Test]
    public function processCommandReportsWhenNothingIsPending(): void
    {
        $service = new DistributionService([], $this->jobs, $this->contents);
        $this->jobs->method('findPending')->willReturn([]);
        $tester = new CommandTester(new ProcessPendingJobsCommand($service));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('No pending distribution jobs.', $tester->getDisplay());
    }

    #[Test]
    public function processCommandReportsProcessedCount(): void
    {
        $service = new DistributionService([], $this->jobs, $this->contents);
        $this->jobs->method('findPending')->willReturn([new DistributionJob(1, 'ghost', 'p'), new DistributionJob(2, 'ghost', 'p')]);
        $this->contents->method('findByUid')->willReturn(new ChannelContent('c', 'x'));
        $tester = new CommandTester(new ProcessPendingJobsCommand($service));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Processed 2 distribution job(s).', $tester->getDisplay());
    }

    // --- PodcastEpisodeDetectorCommand ---

    /**
     * @param list<array<string, mixed>> $feedRows rows returned for the podcast content elements
     * @param array<string, mixed>|null  $feedData cached feed, null when the feed cache is empty
     */
    private function command(array $feedRows, ?array $feedData, ?string $storedHash = null, ?array $storedGuids = null, ?\Throwable $cacheFailure = null): CommandTester
    {
        $feedCache = $this->createStub(FrontendInterface::class);
        $feedCache->method('get')->willReturn($feedData ?? false);
        $this->hashCache = $this->createMock(FrontendInterface::class);
        $this->hashCache->method('get')->willReturnCallback(
            static fn(string $id): mixed => str_starts_with($id, 'podcast_hash_') ? ($storedHash ?? false) : ($storedGuids ?? false),
        );
        $manager = $this->createStub(CacheManager::class);
        $manager->method('getCache')->willReturnCallback(
            function (string $name) use ($feedCache, $cacheFailure) {
                if ('content_blocks_collection_rssfeed' === $name) {
                    if (null !== $cacheFailure) {
                        throw $cacheFailure;
                    }

                    return $feedCache;
                }

                return $this->hashCache;
            },
        );
        $this->contents->method('save')->willReturnOnConsecutiveCalls(101, 102, 103);

        return new CommandTester(new PodcastEpisodeDetectorCommand(
            $this->pool($this->queryBuilder(null, $feedRows)),
            $manager,
            new SocialMediaTransformerService([new GenericTransformer(), new TwitterXTransformer()]),
            $this->contents,
            $this->jobs,
            $this->logger,
        ));
    }

    /**
     * @return array{episodes: list<array<string, string>>}
     */
    private static function feed(string ...$guids): array
    {
        return ['episodes' => array_map(
            static fn(string $guid): array => ['guid' => $guid, 'title' => 'Episode ' . $guid, 'description' => 'About ' . $guid . '.', 'link' => 'https://e.x/' . $guid],
            $guids,
        )];
    }

    #[Test]
    public function podcastCommandStopsWithoutPodcastElements(): void
    {
        $tester = $this->command([], null);

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('No podcast feed content elements found.', $tester->getDisplay());
    }

    #[Test]
    public function podcastCommandSkipsElementsWithoutFeedUrl(): void
    {
        $tester = $this->command([['pid' => 7, 'moselwal_videopodcastfeed_feed_url' => '']], self::feed('a'));

        $tester->execute([]);

        self::assertStringContainsString('No new episodes detected.', $tester->getDisplay());
    }

    #[Test]
    public function podcastCommandReportsEmptyFeedCache(): void
    {
        $tester = $this->command([self::FEED_ROW], null);

        $tester->execute([]);

        self::assertStringContainsString('Page 7: No new episodes.', $tester->getDisplay());
    }

    #[Test]
    public function podcastCommandReportsUnchangedFeed(): void
    {
        $feed = self::feed('a', 'b');
        $tester = $this->command([self::FEED_ROW], $feed, md5('a|b'));
        $this->jobs->expects(self::never())->method('save');

        $tester->execute([]);

        self::assertStringContainsString('Page 7: No new episodes.', $tester->getDisplay());
    }

    #[Test]
    public function podcastCommandAnnouncesOnlyTheLatestEpisodeOnFirstRun(): void
    {
        $tester = $this->command([self::FEED_ROW], self::feed('a', 'b', 'c'));
        $this->contents->expects(self::exactly(2))->method('save')->with(self::isInstanceOf(ChannelContent::class));
        $this->jobs->expects(self::exactly(2))->method('save')->with(self::callback(
            static fn(DistributionJob $job): bool => 'pending' === $job->status && $job->scheduledAt > 0 && $job->channelContentUid >= 101,
        ));
        $this->hashCache->expects(self::exactly(2))->method('set');

        self::assertSame(0, $tester->execute([]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('Page 7: 1 new episode(s) detected.', $display);
        self::assertStringContainsString('[generic] Job created for: Episode a', $display);
        self::assertStringContainsString('[twitter] Job created for: Episode a', $display);
        self::assertStringContainsString('Detected 1 new episode(s) across 1 feed(s).', $display);
    }

    #[Test]
    public function podcastCommandDetectsEpisodesAbsentFromStoredGuids(): void
    {
        $tester = $this->command([self::FEED_ROW], self::feed('c', 'b', 'a'), md5('b|a'), ['b', 'a']);
        $this->jobs->expects(self::exactly(2))->method('save');

        $tester->execute([]);

        self::assertStringContainsString('Page 7: 1 new episode(s) detected.', $tester->getDisplay());
    }

    #[Test]
    public function podcastCommandDryRunListsEpisodesWithoutCreatingJobs(): void
    {
        $tester = $this->command([self::FEED_ROW], self::feed('a'));
        $this->contents->expects(self::never())->method('save');
        $this->jobs->expects(self::never())->method('save');
        $this->hashCache->expects(self::never())->method('set');

        $tester->execute(['--dry-run' => true]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('- Episode a', $display);
        self::assertStringContainsString('Detected 1 new episode(s)', $display);
    }

    #[Test]
    public function podcastCommandDegradesGracefullyWhenTheCacheFails(): void
    {
        $tester = $this->command([self::FEED_ROW], null, cacheFailure: new \RuntimeException('cache down'));
        $this->logger->expects(self::atLeastOnce())->method('warning');

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('No new episodes detected.', $tester->getDisplay());
    }
}
