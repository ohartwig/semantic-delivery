<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Presentation\EventListener;

use Koh\SemanticDelivery\Application\Service\ContentTransformerService;
use Koh\SemanticDelivery\Domain\Repository\ChannelContentRepositoryInterface;
use Koh\StructuredContent\Presentation\Event\ContentEnrichedEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;

#[AsEventListener(identifier: 'semantic-delivery/content-enriched')]
final class ContentEnrichedEventListener
{
    public function __construct(
        private readonly ContentTransformerService $contentTransformerService,
        private readonly ChannelContentRepositoryInterface $channelContentRepository,
    ) {}

    public function __invoke(ContentEnrichedEvent $event): void
    {
        $schemaData = [];
        foreach ($event->getDocuments() as $document) {
            $decoded = json_decode($document->toJsonLd(), true);
            if (is_array($decoded)) {
                $schemaData[] = $decoded;
            }
        }

        $channelContents = $this->contentTransformerService->transformForAllChannels(
            $event->getPageData(),
            $event->getContentElements(),
            $schemaData,
        );

        foreach ($channelContents as $channelContent) {
            $this->channelContentRepository->save($channelContent);
        }
    }
}
