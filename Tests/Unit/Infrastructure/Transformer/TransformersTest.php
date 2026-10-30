<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Tests\Unit\Infrastructure\Transformer;

use Koh\SemanticDelivery\Infrastructure\Transformer\BlueskyTransformer;
use Koh\SemanticDelivery\Infrastructure\Transformer\DevToTransformer;
use Koh\SemanticDelivery\Infrastructure\Transformer\FacebookTransformer;
use Koh\SemanticDelivery\Infrastructure\Transformer\GenericTransformer;
use Koh\SemanticDelivery\Infrastructure\Transformer\InstagramTransformer;
use Koh\SemanticDelivery\Infrastructure\Transformer\LinkedInTransformer;
use Koh\SemanticDelivery\Infrastructure\Transformer\NewsletterTransformer;
use Koh\SemanticDelivery\Infrastructure\Transformer\TwitterXTransformer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TransformersTest extends TestCase
{
    /**
     * @return iterable<string, array{object, string, int}>
     */
    public static function identities(): iterable
    {
        yield 'bluesky' => [new BlueskyTransformer(), 'bluesky', 300];
        yield 'devto' => [new DevToTransformer(), 'devto', 0];
        yield 'facebook' => [new FacebookTransformer(), 'facebook', 63206];
        yield 'generic' => [new GenericTransformer(), 'generic', 0];
        yield 'instagram' => [new InstagramTransformer(), 'instagram', 2200];
        yield 'linkedin' => [new LinkedInTransformer(), 'linkedin', 3000];
        yield 'newsletter' => [new NewsletterTransformer(), 'newsletter', 500];
        yield 'twitter' => [new TwitterXTransformer(), 'twitter', 280];
    }

    #[Test]
    #[DataProvider('identities')]
    public function reportsPlatformIdentifierAndLimit(object $transformer, string $id, int $max): void
    {
        self::assertSame($id, $transformer->getPlatformIdentifier());
        self::assertSame($max, $transformer->getMaxLength());
    }

    #[Test]
    #[DataProvider('identities')]
    public function outputIsReadyAndTaggedWithThePlatform(object $transformer, string $id, int $max): void
    {
        $out = $transformer->transform('Hello world. Second one.', ['title' => 'T', 'url' => 'https://e.x/a']);

        self::assertSame('ready', $out->status);
        self::assertSame($max, $transformer->getMaxLength());
        self::assertSame($id, $out->metadata['platform']);
        self::assertSame('https://e.x/a', $out->metadata['url']);
    }

    #[Test]
    public function blueskyUsesTitleAndAppendsUrl(): void
    {
        $out = (new BlueskyTransformer())->transform('ignored', ['title' => 'Title', 'url' => 'https://e.x']);

        self::assertSame("Title\nhttps://e.x", $out->content);
        self::assertSame(['de'], $out->metadata['languages']);
        self::assertSame('text', $out->outputFormat);
    }

    #[Test]
    public function blueskyFallsBackToFirstSentenceWithoutMarkup(): void
    {
        $out = (new BlueskyTransformer())->transform('<b>First.</b> Second.', ['language' => 'en']);

        self::assertSame('First.', $out->content);
        self::assertSame(['en'], $out->metadata['languages']);
    }

    #[Test]
    public function blueskyTruncatesToGraphemeLimitIncludingUrl(): void
    {
        $url = 'https://e.x/' . str_repeat('u', 18);
        $out = (new BlueskyTransformer())->transform('x', ['title' => str_repeat('ä', 400), 'url' => $url]);

        self::assertSame(300, mb_strlen($out->content));
        self::assertStringEndsWith("…\n" . $url, $out->content);

        $noUrl = (new BlueskyTransformer())->transform('x', ['title' => str_repeat('ä', 400)]);
        self::assertSame(300, mb_strlen($noUrl->content));
        self::assertStringEndsWith('…', $noUrl->content);
    }

    #[Test]
    public function devToConvertsHtmlToMarkdown(): void
    {
        $html = '<h1>Head</h1><p>Some <strong>bold</strong> and <em>it</em> with <a href="https://e.x">link</a>.</p>'
            . '<ul><li>one</li><li>two</li></ul><pre><code>$a = 1;</code></pre><p>Use <code>x</code></p>'
            . '<h2>Two</h2><h3>Three</h3><h4>Four</h4><h5>Five</h5><h6>Six</h6><hr><blockquote>Quote</blockquote>'
            . 'a<br>b &amp; c';

        $out = (new DevToTransformer())->transform($html);

        self::assertSame('markdown', $out->outputFormat);
        self::assertSame('blogging', $out->channelIdentifier);
        foreach (['# Head', '**bold**', '*it*', '[link](https://e.x)', '- one', '- two', "```\n\$a = 1;\n```", '`x`', '## Two', '### Three', '#### Four', '##### Five', '###### Six', '---', '> Quote', "a\nb & c"] as $needle) {
            self::assertStringContainsString($needle, $out->content);
        }
        self::assertStringNotContainsString('<', $out->content);
        self::assertDoesNotMatchRegularExpression("/\n{3,}/", $out->content);
    }

    #[Test]
    public function devToConvertsImagesInBothAttributeOrders(): void
    {
        $out = (new DevToTransformer())->transform('<img src="/a.png" alt="A"><img alt="B" src="/b.png">');

        self::assertStringContainsString('![A](/a.png)', $out->content);
        self::assertStringContainsString('![B](/b.png)', $out->content);
    }

    #[Test]
    public function devToAddsLocalisedCanonicalNote(): void
    {
        $de = (new DevToTransformer())->transform('<p>x</p>', ['url' => 'https://e.x']);
        $en = (new DevToTransformer())->transform('<p>x</p>', ['url' => 'https://e.x', 'language' => 'en']);
        $none = (new DevToTransformer())->transform('<p>x</p>');

        self::assertStringContainsString('*Ursprünglich veröffentlicht auf [https://e.x](https://e.x)*', $de->content);
        self::assertStringContainsString('*Originally published at [https://e.x](https://e.x)*', $en->content);
        self::assertSame('x', $none->content);
    }

    #[Test]
    public function facebookBuildsHookBodyAndLink(): void
    {
        $out = (new FacebookTransformer())->transform('One. Two. Three.', ['url' => 'https://e.x']);

        self::assertSame("One.\n\nTwo. Three.\n\n➡ Mehr erfahren: https://e.x", $out->content);
    }

    #[Test]
    public function facebookPrefersTitleAndDescriptionAndEnglishLabel(): void
    {
        $out = (new FacebookTransformer())->transform('ignored', [
            'title' => 'Title',
            'description' => 'Desc one. Desc two.',
            'url' => 'https://e.x',
            'language' => 'en',
        ]);

        self::assertSame("Title\n\nDesc one. Desc two.\n\n➡ Read more: https://e.x", $out->content);
    }

    #[Test]
    public function facebookSingleSentenceHasNoBody(): void
    {
        $out = (new FacebookTransformer())->transform('<p>Only one.</p>');

        self::assertSame('Only one.', $out->content);
    }

    #[Test]
    public function facebookCapsBodyAndTotalLength(): void
    {
        $long = str_repeat('word ', 3000) . 'end.';
        $out = (new FacebookTransformer())->transform('Lead. ' . $long . ' Tail.');

        self::assertLessThanOrEqual(63206, mb_strlen($out->content));

        $body = (new FacebookTransformer())->transform('Lead. ' . str_repeat('a', 2500) . '.');
        self::assertSame(2001, mb_strlen(explode("\n\n", $body->content)[1]));
    }

    #[Test]
    public function facebookTruncatesOverlongPosts(): void
    {
        $out = (new FacebookTransformer())->transform('x', ['title' => str_repeat('t', 70000)]);

        self::assertSame(63206, mb_strlen($out->content));
        self::assertStringEndsWith('…', $out->content);
    }

    #[Test]
    public function genericStripsTags(): void
    {
        $out = (new GenericTransformer())->transform('<p>Hi <b>there</b></p>', ['k' => 'v']);

        self::assertSame('Hi there', $out->content);
        self::assertSame('v', $out->metadata['k']);
    }

    #[Test]
    public function instagramAppendsHashtagsFromKeywords(): void
    {
        $out = (new InstagramTransformer())->transform('Body.', [
            'title' => 'Title.',
            'keywords' => 'Type Script, php-ruby,  go ',
            'image' => 'i.png',
        ]);

        self::assertSame("Title. Body.\n\n#TypeScript #phpruby #go", $out->content);
        self::assertSame('i.png', $out->metadata['suggestedImage']);
    }

    #[Test]
    public function instagramPrefersDescriptionAndLimitsToFourSentences(): void
    {
        $out = (new InstagramTransformer())->transform('content', [
            'description' => 'A. B. C. D. E. F.',
        ]);

        self::assertSame('A. B. C. D.', $out->content);
        self::assertNull($out->metadata['suggestedImage']);
    }

    #[Test]
    public function instagramTruncatesCaptionButKeepsHashtags(): void
    {
        $out = (new InstagramTransformer())->transform(str_repeat('x', 3000), ['keywords' => 'alpha,beta']);

        self::assertSame(2200, mb_strlen($out->content));
        self::assertStringEndsWith("...\n\n#alpha #beta", $out->content);
    }

    #[Test]
    public function instagramHandlesEmptyInput(): void
    {
        self::assertSame('', (new InstagramTransformer())->transform('')->content);
    }

    #[Test]
    public function linkedInComposesHookBodyAndCta(): void
    {
        $out = (new LinkedInTransformer())->transform('Alpha. Beta.', ['title' => 'Hook', 'url' => 'https://e.x', 'language' => 'en']);

        self::assertSame("Hook\n\nAlpha. Beta.\n\n➡ Read more: https://e.x", $out->content);
    }

    #[Test]
    public function linkedInDerivesHookFromFirstSentenceWithoutTitle(): void
    {
        $out = (new LinkedInTransformer())->transform('Alpha! Beta.');

        self::assertSame("Alpha.\n\nAlpha! Beta.", $out->content);
    }

    #[Test]
    public function linkedInUsesGermanCtaByDefault(): void
    {
        $out = (new LinkedInTransformer())->transform('Body.', ['title' => 'T', 'url' => 'https://e.x']);

        self::assertStringEndsWith('➡ Mehr erfahren: https://e.x', $out->content);
    }

    #[Test]
    public function linkedInLimitsBodyToFiveSentences(): void
    {
        $out = (new LinkedInTransformer())->transform('A. B. C. D. E. F. G.', ['title' => 'T']);

        self::assertSame("T\n\nA. B. C. D. E.", $out->content);
    }

    #[Test]
    public function linkedInTruncatesLongBodyAndPost(): void
    {
        $out = (new LinkedInTransformer())->transform(str_repeat('x', 2500), ['title' => 'T']);
        self::assertSame("T\n\n" . str_repeat('x', 2000) . '...', $out->content);

        $post = (new LinkedInTransformer())->transform('x', ['title' => str_repeat('t', 3500)]);
        self::assertSame(3000, mb_strlen($post->content));
        self::assertStringEndsWith('...', $post->content);
    }

    #[Test]
    public function newsletterBuildsTitleTeaserAndCta(): void
    {
        $out = (new NewsletterTransformer())->transform('<p>One. Two.</p>', ['title' => 'T', 'url' => 'https://e.x']);

        self::assertSame("T\n\nOne. Two.\n\nJetzt lesen: https://e.x", $out->content);

        $en = (new NewsletterTransformer())->transform('One.', ['url' => 'https://e.x', 'language' => 'en']);
        self::assertSame("One.\n\nRead now: https://e.x", $en->content);
    }

    #[Test]
    public function newsletterTeaserStopsBeforeExceedingThreeHundredCharacters(): void
    {
        $s1 = str_repeat('a', 150) . '.';
        $s2 = str_repeat('b', 140) . '.';
        $s3 = str_repeat('c', 50) . '.';

        $out = (new NewsletterTransformer())->transform("$s1 $s2 $s3");

        self::assertSame("$s1 $s2", $out->content);
    }

    #[Test]
    public function newsletterWithEmptyContentIsEmpty(): void
    {
        self::assertSame('', (new NewsletterTransformer())->transform('')->content);
    }

    #[Test]
    public function twitterUsesFirstSentenceAndReservesUrlSpace(): void
    {
        $out = (new TwitterXTransformer())->transform('First. Second.', ['url' => 'https://e.x']);

        self::assertSame("First.\nhttps://e.x", $out->content);
    }

    #[Test]
    public function twitterPrefersTitleAndTruncates(): void
    {
        $out = (new TwitterXTransformer())->transform('x', ['title' => str_repeat('t', 400), 'url' => 'https://e.x']);

        $lines = explode("\n", $out->content);
        self::assertSame(280 - 24, mb_strlen($lines[0]));
        self::assertStringEndsWith('…', $lines[0]);
        self::assertSame('https://e.x', $lines[1]);

        $noUrl = (new TwitterXTransformer())->transform(str_repeat('t', 400));
        self::assertSame(280, mb_strlen($noUrl->content));
    }
}
