<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Tests\Unit\Presentation\Event;

use Koh\SemanticDelivery\Domain\Model\ChannelContent;
use Koh\SemanticDelivery\Domain\Model\DistributionResult;
use Koh\SemanticDelivery\Presentation\Event\AfterDistributionEvent;
use Koh\SemanticDelivery\Presentation\Event\BeforeChannelTransformEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    #[Test]
    public function beforeTransformEventExposesAndMutatesData(): void
    {
        $event = new BeforeChannelTransformEvent(['a' => 1], [['b' => 2]], ['@type' => 'X'], 'website');

        self::assertSame(['a' => 1], $event->getPageData());
        self::assertSame([['b' => 2]], $event->getContentElements());
        self::assertSame(['@type' => 'X'], $event->getSchemaData());
        self::assertSame('website', $event->getChannelIdentifier());

        $event->setPageData(['a' => 2]);
        $event->setContentElements([]);
        $event->setSchemaData([]);

        self::assertSame(['a' => 2], $event->getPageData());
        self::assertSame([], $event->getContentElements());
        self::assertSame([], $event->getSchemaData());
    }

    #[Test]
    public function afterDistributionEventCarriesItsContext(): void
    {
        $content = new ChannelContent('website', 'c');
        $result = DistributionResult::success('1');

        $event = new AfterDistributionEvent($content, $result, 'webhook', 'generic');

        self::assertSame($content, $event->channelContent);
        self::assertSame($result, $event->result);
        self::assertSame('webhook', $event->distributor);
        self::assertSame('generic', $event->platform);
    }
}
