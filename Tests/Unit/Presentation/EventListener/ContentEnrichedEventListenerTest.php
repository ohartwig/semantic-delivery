<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Tests\Unit\Presentation\EventListener;

use Koh\SemanticDelivery\Application\Service\ContentTransformerService;
use Koh\SemanticDelivery\Domain\Contract\ChannelInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;
use Koh\SemanticDelivery\Domain\Repository\ChannelContentRepositoryInterface;
use Koh\SemanticDelivery\Presentation\EventListener\ContentEnrichedEventListener;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;
use Koh\StructuredContent\Presentation\Event\ContentEnrichedEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContentEnrichedEventListenerTest extends TestCase
{
    #[Test]
    public function persistsEveryChannelOutputWithDecodedSchemaData(): void
    {
        $seen = [];
        $channels = [];
        foreach (['website', 'voice'] as $id) {
            $channel = $this->createStub(ChannelInterface::class);
            $channel->method('getIdentifier')->willReturn($id);
            $channel->method('transform')->willReturnCallback(
                static function (array $page, array $elements, array $schema) use ($id, &$seen): ChannelContent {
                    $seen[$id] = [$page, $elements, $schema];

                    return new ChannelContent($id, 'out');
                },
            );
            $channels[] = $channel;
        }
        $saved = [];
        $repository = $this->createMock(ChannelContentRepositoryInterface::class);
        $repository->expects(self::exactly(2))->method('save')->willReturnCallback(
            static function (ChannelContent $content) use (&$saved): int {
                $saved[] = $content->channelIdentifier;

                return 1;
            },
        );
        $listener = new ContentEnrichedEventListener(new ContentTransformerService($channels), $repository);
        $event = new ContentEnrichedEvent(
            [new JsonLdDocument('Article', ['headline' => 'H'])],
            ['uid' => 4],
            4,
            [['header' => 'x']],
        );

        $listener($event);

        self::assertSame(['website', 'voice'], $saved);
        self::assertSame([['uid' => 4], [['header' => 'x']]], array_slice($seen['website'], 0, 2));
        self::assertSame('Article', $seen['website'][2][0]['@type']);
        self::assertSame('H', $seen['voice'][2][0]['headline']);
    }
}
