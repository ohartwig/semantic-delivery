<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Tests\Unit\Application\Configuration;

use Koh\SemanticDelivery\Application\Configuration\ChannelConfiguration;
use Koh\SemanticDelivery\Application\Configuration\DistributionProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
    #[Test]
    public function unknownChannelIsDisabledWithoutOptions(): void
    {
        $config = new ChannelConfiguration();

        self::assertFalse($config->isChannelEnabled('x'));
        self::assertSame([], $config->getChannelOptions('x'));
        self::assertSame([], $config->getEnabledChannels());
    }

    #[Test]
    public function enableAndDisableAreFluentAndListedCorrectly(): void
    {
        $config = new ChannelConfiguration();

        $result = $config->enableChannel('a')->enableChannel('b')->disableChannel('b');

        self::assertSame($config, $result);
        self::assertTrue($config->isChannelEnabled('a'));
        self::assertFalse($config->isChannelEnabled('b'));
        self::assertSame(['a'], $config->getEnabledChannels());
    }

    #[Test]
    public function channelOptionsAreStoredPerChannel(): void
    {
        $config = (new ChannelConfiguration())
            ->setChannelOptions('a', ['x' => 1])
            ->setChannelOptions('b', ['y' => 2]);

        self::assertSame(['x' => 1], $config->getChannelOptions('a'));
        self::assertSame(['y' => 2], $config->getChannelOptions('b'));
    }

    #[Test]
    public function profileFiltersRulesByChannelAndReindexes(): void
    {
        $profile = (new DistributionProfile())
            ->addRule('social-media', 'webhook', 'generic')
            ->addRule('blogging', 'devto', 'devto', ['published' => true])
            ->addRule('social-media', 'buffer', 'twitter');

        $rules = $profile->getRulesForChannel('social-media');

        self::assertCount(2, $rules);
        self::assertSame([0, 1], array_keys($rules));
        self::assertSame('webhook', $rules[0]['distributor']);
        self::assertSame('buffer', $rules[1]['distributor']);
        self::assertSame([], $profile->getRulesForChannel('voice'));
        self::assertCount(3, $profile->getAllRules());
        self::assertSame(['published' => true], $profile->getAllRules()[1]['options']);
    }
}
