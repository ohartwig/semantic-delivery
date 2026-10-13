<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Command;

use Koh\SemanticDelivery\Application\Service\DistributionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ProcessPendingJobsCommand extends Command
{
    public function __construct(
        private readonly DistributionService $distributionService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $count = $this->distributionService->processPendingJobs();

        if (0 === $count) {
            $io->info('No pending distribution jobs.');
        } else {
            $io->success(sprintf('Processed %d distribution job(s).', $count));
        }

        return Command::SUCCESS;
    }
}
