<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Tests\Unit\Application\Service;

use Koh\SemanticDelivery\Application\Service\ContentTransformerService;
use Koh\SemanticDelivery\Application\Service\SocialMediaTransformerService;
use Koh\SemanticDelivery\Domain\Contract\ChannelInterface;
use Koh\SemanticDelivery\Domain\Contract\TransformerInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TransformerServicesTest extends TestCase
{
    #[Test]
    public function contentServiceRoutesToTheMatchingChannel(): void
    {
        $expected = new ChannelContent('a', 'out');
        $a = $this->createMock(ChannelInterface::class);
        $a->method('getIdentifier')->willReturn('a');
        $a->expects(self::once())->method('transform')->with(['p'], ['e'], ['s'])->willReturn($expected);
        $b = $this->createMock(ChannelInterface::class);
        $b->method('getIdentifier')->willReturn('b');
        $b->expects(self::never())->method('transform');

        $service = new ContentTransformerService([$a, $b]);

        self::assertSame($expected, $service->transformForChannel('a', ['p'], ['e'], ['s']));
        self::assertSame(['a', 'b'], $service->getAvailableChannels());
    }

    #[Test]
    public function contentServiceReturnsNullForUnknownChannel(): void
    {
        $service = new ContentTransformerService([]);

        self::assertNull($service->transformForChannel('nope', [], [], []));
        self::assertSame([], $service->transformForAllChannels([], [], []));
    }

    #[Test]
    public function contentServiceTransformsForAllChannelsKeyedByIdentifier(): void
    {
        $channels = [];
        foreach (['a', 'b'] as $id) {
            $channel = $this->createStub(ChannelInterface::class);
            $channel->method('getIdentifier')->willReturn($id);
            $channel->method('transform')->willReturn(new ChannelContent($id, 'out-' . $id));
            $channels[] = $channel;
        }

        $results = (new ContentTransformerService($channels))->transformForAllChannels([], [], []);

        self::assertSame(['a', 'b'], array_keys($results));
        self::assertSame('out-b', $results['b']->content);
    }

    #[Test]
    public function socialServiceRoutesAndListsPlatforms(): void
    {
        $expected = new ChannelContent('social-media', 'x');
        $t = $this->createMock(TransformerInterface::class);
        $t->method('getPlatformIdentifier')->willReturn('twitter');
        $t->expects(self::once())->method('transform')->with('body', ['m' => 1])->willReturn($expected);

        $service = new SocialMediaTransformerService([$t]);

        self::assertSame($expected, $service->transformForPlatform('twitter', 'body', ['m' => 1]));
        self::assertNull($service->transformForPlatform('myspace', 'body'));
        self::assertSame(['twitter'], $service->getAvailablePlatforms());
    }

    #[Test]
    public function socialServiceTransformsForAllPlatforms(): void
    {
        $transformers = [];
        foreach (['x', 'y'] as $id) {
            $t = $this->createStub(TransformerInterface::class);
            $t->method('getPlatformIdentifier')->willReturn($id);
            $t->method('transform')->willReturn(new ChannelContent('social-media', 'out-' . $id));
            $transformers[] = $t;
        }

        $results = (new SocialMediaTransformerService($transformers))->transformForAllPlatforms('c');

        self::assertSame(['x', 'y'], array_keys($results));
        self::assertSame('out-x', $results['x']->content);
    }
}
