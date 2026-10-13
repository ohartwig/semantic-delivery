<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Command;

use Koh\SemanticDelivery\Application\Service\SocialMediaTransformerService;
use Koh\SemanticDelivery\Domain\Model\DistributionJob;
use Koh\SemanticDelivery\Domain\Repository\ChannelContentRepositoryInterface;
use Koh\SemanticDelivery\Domain\Repository\DistributionJobRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;

final class PodcastEpisodeDetectorCommand extends Command
{
    private const PODCAST_CTYPE = 'moselwal_videopodcastfeed';
    private const FEED_URL_FIELD = 'moselwal_videopodcastfeed_feed_url';
    private const FEED_CACHE_NAME = 'content_blocks_collection_rssfeed';
    private const HASH_CACHE_NAME = 'semantic_delivery';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly CacheManager $cacheManager,
        private readonly SocialMediaTransformerService $transformerService,
        private readonly ChannelContentRepositoryInterface $channelContentRepository,
        private readonly DistributionJobRepositoryInterface $jobRepository,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('auto-publish', null, InputOption::VALUE_NONE, 'Automatically publish instead of queuing for review');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Detect new episodes without creating jobs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $autoPublish = (bool) $input->getOption('auto-publish');
        $dryRun = (bool) $input->getOption('dry-run');

        $podcastElements = $this->findPodcastElements();
        if ([] === $podcastElements) {
            $io->info('No podcast feed content elements found.');

            return Command::SUCCESS;
        }

        $newEpisodesTotal = 0;

        foreach ($podcastElements as $element) {
            $feedUrl = (string) $element[self::FEED_URL_FIELD];
            $pageUid = (int) $element['pid'];

            if ('' === $feedUrl) {
                continue;
            }

            $newEpisodes = $this->detectNewEpisodes($feedUrl);
            if ([] === $newEpisodes) {
                $io->text(sprintf('Page %d: No new episodes.', $pageUid));
                continue;
            }

            $io->text(sprintf('Page %d: %d new episode(s) detected.', $pageUid, count($newEpisodes)));

            if ($dryRun) {
                foreach ($newEpisodes as $episode) {
                    $io->text('  - ' . ($episode['title'] ?? 'untitled'));
                }
                $newEpisodesTotal += count($newEpisodes);
                continue;
            }

            foreach ($newEpisodes as $episode) {
                $this->createDistributionJobs($episode, $pageUid, $autoPublish, $io);
                ++$newEpisodesTotal;
            }

            $this->updateEpisodeHash($feedUrl);
        }

        if ($newEpisodesTotal > 0) {
            $io->success(sprintf('Detected %d new episode(s) across %d feed(s).', $newEpisodesTotal, count($podcastElements)));
        } else {
            $io->info('No new episodes detected.');
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findPodcastElements(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new HiddenRestriction());

        return $queryBuilder
            ->select('pid', self::FEED_URL_FIELD)
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq(
                    'CType',
                    $queryBuilder->createNamedParameter(self::PODCAST_CTYPE),
                ),
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function detectNewEpisodes(string $feedUrl): array
    {
        try {
            $cache = $this->cacheManager->getCache(self::FEED_CACHE_NAME);
            // sha256 muss mit PodcastFeedService::cacheIdentifierFor() matchen
            // (content-blocks-collection migrierte md5 → sha256 im SSRF-Fix).
            $cacheIdentifier = 'rssfeed_' . hash('sha256', $feedUrl);

            $feedData = $cache->get($cacheIdentifier);
            if (!is_array($feedData) || !isset($feedData['episodes'])) {
                return [];
            }

            /** @var list<array<string, mixed>> $episodes */
            $episodes = $feedData['episodes'];

            $previousHash = $this->getStoredHash($feedUrl);
            $currentHash = $this->computeEpisodeHash($episodes);

            if ($previousHash === $currentHash) {
                return [];
            }

            if ('' === $previousHash) {
                // First run — don't flood all platforms with every episode
                return array_slice($episodes, 0, 1);
            }

            $previousGuids = $this->getStoredGuids($feedUrl);
            $newEpisodes = [];

            foreach ($episodes as $episode) {
                $guid = (string) ($episode['guid'] ?? $episode['link'] ?? '');
                if ('' !== $guid && !in_array($guid, $previousGuids, true)) {
                    $newEpisodes[] = $episode;
                }
            }

            return $newEpisodes;
        } catch (\Throwable $e) {
            $this->logger->warning('Episode detection failed', ['feedUrl' => $feedUrl, 'exception' => $e]);

            return [];
        }
    }

    /**
     * @param array<string, mixed> $episode
     */
    private function createDistributionJobs(array $episode, int $pageUid, bool $autoPublish, SymfonyStyle $io): void
    {
        $title = (string) ($episode['title'] ?? '');
        $description = (string) ($episode['description'] ?? '');
        $url = (string) ($episode['link'] ?? '');
        $image = (string) ($episode['localImage'] ?? $episode['imageUrl'] ?? '');

        $metadata = [
            'title' => $title,
            'description' => $description,
            'url' => $url,
            'image' => $image,
        ];

        $platformContents = $this->transformerService->transformForAllPlatforms($description, $metadata);

        foreach ($platformContents as $platformId => $channelContent) {
            $contentUid = $this->channelContentRepository->save($channelContent);

            $job = new DistributionJob(
                channelContentUid: $contentUid,
                distributor: $platformId,
                platform: $platformId,
                status: $autoPublish ? 'pending' : 'pending',
                scheduledAt: time(),
            );

            $this->jobRepository->save($job);
            $io->text(sprintf('  [%s] Job created for: %s', $platformId, $title));
        }
    }

    /**
     * @param list<array<string, mixed>> $episodes
     */
    private function computeEpisodeHash(array $episodes): string
    {
        $guids = array_map(
            static fn(array $ep): string => (string) ($ep['guid'] ?? $ep['link'] ?? ''),
            $episodes,
        );

        return md5(implode('|', $guids));
    }

    private function getStoredHash(string $feedUrl): string
    {
        try {
            $cache = $this->cacheManager->getCache(self::HASH_CACHE_NAME);
            $hash = $cache->get('podcast_hash_' . md5($feedUrl));

            return is_string($hash) ? $hash : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return list<string>
     */
    private function getStoredGuids(string $feedUrl): array
    {
        try {
            $cache = $this->cacheManager->getCache(self::HASH_CACHE_NAME);
            $guids = $cache->get('podcast_guids_' . md5($feedUrl));

            if (!is_array($guids)) {
                return [];
            }

            return array_values(array_filter($guids, 'is_string'));
        } catch (\Throwable) {
            return [];
        }
    }

    private function updateEpisodeHash(string $feedUrl): void
    {
        try {
            $feedCache = $this->cacheManager->getCache(self::FEED_CACHE_NAME);
            $feedData = $feedCache->get('rssfeed_' . hash('sha256', $feedUrl));

            if (!is_array($feedData) || !isset($feedData['episodes'])) {
                return;
            }

            /** @var list<array<string, mixed>> $episodes */
            $episodes = $feedData['episodes'];
            $hash = $this->computeEpisodeHash($episodes);

            $guids = array_map(
                static fn(array $ep): string => (string) ($ep['guid'] ?? $ep['link'] ?? ''),
                $episodes,
            );

            $cache = $this->cacheManager->getCache(self::HASH_CACHE_NAME);
            $cache->set('podcast_hash_' . md5($feedUrl), $hash, [], 86400 * 30);
            $cache->set('podcast_guids_' . md5($feedUrl), $guids, [], 86400 * 30);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to update episode hash', ['exception' => $e]);
        }
    }
}
