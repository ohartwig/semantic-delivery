<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Presentation\Event;

use Koh\SemanticDelivery\Domain\Model\ChannelContent;
use Koh\SemanticDelivery\Domain\Model\DistributionResult;

final readonly class AfterDistributionEvent
{
    public function __construct(
        public ChannelContent $channelContent,
        public DistributionResult $result,
        public string $distributor,
        public string $platform,
    ) {}
}
