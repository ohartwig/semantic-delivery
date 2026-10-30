<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Tests\Unit\Infrastructure\Channel;

use Koh\SemanticDelivery\Application\Service\SocialMediaTransformerService;
use Koh\SemanticDelivery\Infrastructure\Channel\AiAgentChannel;
use Koh\SemanticDelivery\Infrastructure\Channel\SocialMediaChannel;
use Koh\SemanticDelivery\Infrastructure\Channel\VoiceChannel;
use Koh\SemanticDelivery\Infrastructure\Channel\WebsiteChannel;
use Koh\SemanticDelivery\Infrastructure\Transformer\GenericTransformer;
use Koh\SemanticDelivery\Infrastructure\Transformer\TwitterXTransformer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChannelsTest extends TestCase
{
    #[Test]
    public function aiAgentChannelProducesStructuredJson(): void
    {
        $channel = new AiAgentChannel();

        $out = $channel->transform(
            ['uid' => '12', 'title' => 'T', 'abstract' => 'Abs', 'slug' => '/t'],
            [
                ['CType' => 'text', 'header' => 'H', 'bodytext' => '<p>Body</p>', 'image' => 'a.jpg'],
                ['bodytext' => 'x', 'image' => ''],
            ],
            [['@type' => 'Article']],
        );

        $data = json_decode($out->content, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('ai-agent', $out->channelIdentifier);
        self::assertSame('json', $out->outputFormat);
        self::assertSame(12, $out->pageUid);
        self::assertSame('ready', $out->status);
        self::assertSame('Abs', $data['page']['description']);
        self::assertSame('/t', $data['page']['slug']);
        self::assertSame('Body', $data['content'][0]['text']);
        self::assertSame('a.jpg', $data['content'][0]['images']);
        self::assertSame('text', $data['content'][1]['type']);
        self::assertArrayNotHasKey('images', $data['content'][1]);
        self::assertSame([['@type' => 'Article']], $data['schema']);
        self::assertStringNotContainsString('\/', $out->content);
        self::assertSame(0, $channel->getMaxLength());
        self::assertSame('ai-agent', $channel->getIdentifier());
        self::assertSame('json', $channel->getOutputFormat());
    }

    #[Test]
    public function aiAgentChannelToleratesEmptyInput(): void
    {
        $out = (new AiAgentChannel())->transform([], [], []);

        $data = json_decode($out->content, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(0, $data['page']['uid']);
        self::assertSame([], $data['content']);
    }

    #[Test]
    public function socialMediaChannelBuildsPlainTextAndMetadata(): void
    {
        $channel = new SocialMediaChannel(new SocialMediaTransformerService([new GenericTransformer(), new TwitterXTransformer()]));

        $out = $channel->transform(
            [
                'uid' => 4,
                'title' => 'Title',
                'description' => 'Desc',
                'slug' => '/page',
                '_siteBase' => 'https://e.x/',
                '_language' => 'en',
                'keywords' => 'a,b',
                '_ogImage' => 'og.png',
            ],
            [['bodytext' => '<p>Body</p>'], ['bodytext' => ''], []],
            [['@type' => 'Article']],
        );

        self::assertSame("Title\n\nDesc\n\nBody", $out->content);
        self::assertSame('https://e.x/page', $out->metadata['url']);
        self::assertSame('en', $out->metadata['language']);
        self::assertSame('a,b', $out->metadata['keywords']);
        self::assertSame('og.png', $out->metadata['image']);
        self::assertSame(['generic', 'twitter'], $out->metadata['platforms']);
        self::assertSame([['@type' => 'Article']], $out->metadata['schemas']);
        self::assertSame(4, $out->pageUid);
        self::assertSame('social-media', $out->channelIdentifier);
        self::assertSame('text', $channel->getOutputFormat());
        self::assertSame('social-media', $channel->getIdentifier());
        self::assertSame(0, $channel->getMaxLength());
    }

    #[Test]
    public function socialMediaChannelFallsBackToSlugAndDefaults(): void
    {
        $channel = new SocialMediaChannel(new SocialMediaTransformerService([]));

        $out = $channel->transform(['slug' => '/only', 'abstract' => 'Abs', 'og:image' => 'x.png'], [], []);

        self::assertSame('Abs', $out->content);
        self::assertSame('/only', $out->metadata['url']);
        self::assertSame('de', $out->metadata['language']);
        self::assertSame('x.png', $out->metadata['image']);
        self::assertSame([], $out->metadata['platforms']);
    }

    #[Test]
    public function voiceChannelBuildsEscapedSsml(): void
    {
        $channel = new VoiceChannel();

        $out = $channel->transform(
            ['uid' => 3, 'title' => 'A & B', 'description' => 'Desc <x>'],
            [['header' => 'Head', 'bodytext' => '<b>One.</b> Two "q"!'], ['header' => '', 'bodytext' => '']],
            [],
        );

        self::assertStringStartsWith("<speak>\n", $out->content);
        self::assertStringEndsWith("\n</speak>", $out->content);
        self::assertStringContainsString('<p><s>A &amp; B.</s></p>', $out->content);
        self::assertStringContainsString('<p><s>Desc &lt;x&gt;</s></p>', $out->content);
        self::assertStringContainsString('<break time="500ms"/>', $out->content);
        self::assertStringContainsString('<emphasis level="moderate">Head</emphasis>', $out->content);
        self::assertStringContainsString("<s>One.</s>\n<s>Two &quot;q&quot;!</s>", $out->content);
        self::assertSame(2, substr_count($out->content, '<break time="300ms"/>'));
        self::assertSame(2, $out->metadata['contentCount']);
        self::assertSame('A & B', $out->metadata['title']);
        self::assertSame(3, $out->pageUid);
        self::assertSame('ssml', $channel->getOutputFormat());
        self::assertSame('voice', $channel->getIdentifier());
        self::assertSame(5000, $channel->getMaxLength());
    }

    #[Test]
    public function voiceChannelWithoutDataIsEmptySpeakDocument(): void
    {
        self::assertSame("<speak>\n</speak>", (new VoiceChannel())->transform([], [], [])->content);
    }

    #[Test]
    public function websiteChannelEscapesHeadersAndKeepsBodyHtml(): void
    {
        $channel = new WebsiteChannel();

        $out = $channel->transform(
            ['uid' => 8, 'title' => 'T', 'description' => 'D'],
            [['header' => '<i>H</i>', 'bodytext' => '<p>Body</p>'], ['header' => '', 'bodytext' => '']],
            [],
        );

        self::assertSame("<h2>&lt;i&gt;H&lt;/i&gt;</h2>\n<p>Body</p>", $out->content);
        self::assertSame('html', $out->outputFormat);
        self::assertSame('T', $out->metadata['title']);
        self::assertSame(8, $out->pageUid);
        self::assertSame('website', $channel->getIdentifier());
        self::assertSame(0, $channel->getMaxLength());
        self::assertSame('html', $channel->getOutputFormat());
    }

    #[Test]
    public function websiteChannelEmbedsJsonLdWithoutScriptBreakout(): void
    {
        $out = (new WebsiteChannel())->transform(
            [],
            [],
            [['@type' => 'Article', 'headline' => '</script><script>alert(1)</script>']],
        );

        self::assertStringStartsWith('<script type="application/ld+json">', $out->content);
        self::assertSame(1, substr_count($out->content, '</script>'));
        self::assertStringNotContainsString('<script>alert', $out->content);
    }
}
