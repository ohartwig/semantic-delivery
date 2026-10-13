<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Presentation\Controller;

use Koh\SemanticDelivery\Application\Service\DistributionService;
use Koh\SemanticDelivery\Domain\Repository\ChannelContentRepositoryInterface;
use Koh\SemanticDelivery\Domain\Repository\DistributionJobRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;

final class DistributionController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly DistributionService $distributionService,
        private readonly ChannelContentRepositoryInterface $channelContentRepository,
        private readonly DistributionJobRepositoryInterface $jobRepository,
    ) {}

    public function previewAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        $pageUid = (int) ($request->getQueryParams()['pageUid'] ?? 0);
        $channelContents = $pageUid > 0
            ? $this->channelContentRepository->findByPage($pageUid)
            : [];

        $moduleTemplate->assignMultiple([
            'pageUid' => $pageUid,
            'channelContents' => $channelContents,
            'availableDistributors' => $this->distributionService->getAvailableDistributors(),
        ]);

        return $moduleTemplate->renderResponse('Distribution/Preview');
    }

    public function queueAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        $pendingJobs = $this->jobRepository->findPending();

        $moduleTemplate->assignMultiple([
            'pendingJobs' => $pendingJobs,
        ]);

        return $moduleTemplate->renderResponse('Distribution/Queue');
    }

    public function logAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        $completedJobs = $this->jobRepository->findByStatus('completed');
        $failedJobs = $this->jobRepository->findByStatus('failed');

        $moduleTemplate->assignMultiple([
            'completedJobs' => $completedJobs,
            'failedJobs' => $failedJobs,
        ]);

        return $moduleTemplate->renderResponse('Distribution/Log');
    }
}
