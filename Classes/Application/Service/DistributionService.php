<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Application\Service;

use Koh\SemanticDelivery\Domain\Contract\DistributorInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;
use Koh\SemanticDelivery\Domain\Model\DistributionJob;
use Koh\SemanticDelivery\Domain\Model\DistributionResult;
use Koh\SemanticDelivery\Domain\Repository\ChannelContentRepositoryInterface;
use Koh\SemanticDelivery\Domain\Repository\DistributionJobRepositoryInterface;

final class DistributionService
{
    /** @var array<string, DistributorInterface> */
    private readonly array $distributorMap;

    /**
     * @param iterable<DistributorInterface> $distributors
     */
    public function __construct(
        iterable $distributors,
        private readonly DistributionJobRepositoryInterface $jobRepository,
        private readonly ChannelContentRepositoryInterface $channelContentRepository,
    ) {
        $map = [];
        foreach ($distributors as $distributor) {
            $map[$distributor->getIdentifier()] = $distributor;
        }
        $this->distributorMap = $map;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function distribute(ChannelContent $content, string $distributorIdentifier, string $platform, array $options = []): DistributionResult
    {
        $distributor = $this->distributorMap[$distributorIdentifier] ?? null;
        if (null === $distributor) {
            return DistributionResult::failure('Unknown distributor: ' . $distributorIdentifier);
        }

        return $distributor->distribute($content, $platform, $options);
    }

    public function processJob(DistributionJob $job, ChannelContent $content): DistributionResult
    {
        $result = $this->distribute($content, $job->distributor, $job->platform);

        if ($result->success) {
            $job->markSuccess($result->externalId, $result->externalUrl);
        } else {
            $job->markFailed($result->error);
        }

        $this->jobRepository->update($job);

        return $result;
    }

    public function processPendingJobs(): int
    {
        $pendingJobs = $this->jobRepository->findPending();
        $processedCount = 0;

        foreach ($pendingJobs as $job) {
            $channelContent = $this->channelContentRepository->findByUid($job->channelContentUid);

            if (null === $channelContent) {
                $job->markFailed('ChannelContent not found for UID: ' . $job->channelContentUid);
                $this->jobRepository->update($job);
                continue;
            }

            $this->processJob($job, $channelContent);
            ++$processedCount;
        }

        return $processedCount;
    }

    /** @return list<string> */
    public function getAvailableDistributors(): array
    {
        return array_keys($this->distributorMap);
    }

    public function testConnection(string $distributorIdentifier): bool
    {
        $distributor = $this->distributorMap[$distributorIdentifier] ?? null;
        if (null === $distributor) {
            return false;
        }

        return $distributor->testConnection();
    }
}
