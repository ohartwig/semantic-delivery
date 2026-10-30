<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Tests\Unit\Domain\Model;

use Koh\SemanticDelivery\Domain\Model\ChannelContent;
use Koh\SemanticDelivery\Domain\Model\DistributionJob;
use Koh\SemanticDelivery\Domain\Model\DistributionResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModelTest extends TestCase
{
    #[Test]
    public function channelContentHasDraftHtmlDefaults(): void
    {
        $content = new ChannelContent('website', '<p>x</p>');

        self::assertSame('draft', $content->status);
        self::assertSame('html', $content->outputFormat);
        self::assertSame([], $content->metadata);
        self::assertSame(0, $content->pageUid);
        self::assertSame(0, $content->uid);
    }

    #[Test]
    public function withStatusReturnsModifiedCopyAndKeepsOriginal(): void
    {
        $original = new ChannelContent('voice', 'a', ['k' => 'v'], 'draft', 'ssml', 7, 9);

        $copy = $original->withStatus('ready');

        self::assertNotSame($original, $copy);
        self::assertSame('draft', $original->status);
        self::assertSame('ready', $copy->status);
        self::assertSame('voice', $copy->channelIdentifier);
        self::assertSame('a', $copy->content);
        self::assertSame(['k' => 'v'], $copy->metadata);
        self::assertSame('ssml', $copy->outputFormat);
        self::assertSame(7, $copy->pageUid);
        self::assertSame(9, $copy->uid);
    }

    #[Test]
    public function withContentReturnsModifiedCopyAndKeepsOriginal(): void
    {
        $original = new ChannelContent('voice', 'a', ['k' => 'v'], 'ready', 'ssml', 7, 9);

        $copy = $original->withContent('b');

        self::assertSame('a', $original->content);
        self::assertSame('b', $copy->content);
        self::assertSame('ready', $copy->status);
        self::assertSame(['k' => 'v'], $copy->metadata);
        self::assertSame('ssml', $copy->outputFormat);
        self::assertSame(7, $copy->pageUid);
        self::assertSame(9, $copy->uid);
    }

    #[Test]
    public function distributionResultFactories(): void
    {
        $ok = DistributionResult::success('id-1', 'https://example.com/1');
        self::assertTrue($ok->success);
        self::assertSame('id-1', $ok->externalId);
        self::assertSame('https://example.com/1', $ok->externalUrl);
        self::assertSame('', $ok->error);

        $bad = DistributionResult::failure('boom');
        self::assertFalse($bad->success);
        self::assertSame('boom', $bad->error);
        self::assertSame('', $bad->externalId);
        self::assertSame('', $bad->externalUrl);

        self::assertTrue(DistributionResult::success()->success);
    }

    #[Test]
    public function newJobIsPending(): void
    {
        $job = new DistributionJob(5, 'webhook', 'generic');

        self::assertSame('pending', $job->status);
        self::assertSame(0, $job->executedAt);
        self::assertSame(5, $job->channelContentUid);
    }

    #[Test]
    public function markSuccessRecordsExternalReferenceAndTimestamp(): void
    {
        $job = new DistributionJob(5, 'webhook', 'generic');
        $before = time();

        $job->markSuccess('ext', 'https://example.com/p');

        self::assertSame('completed', $job->status);
        self::assertSame('ext', $job->externalId);
        self::assertSame('https://example.com/p', $job->externalUrl);
        self::assertGreaterThanOrEqual($before, $job->executedAt);
    }

    #[Test]
    public function markFailedRecordsErrorAndTimestamp(): void
    {
        $job = new DistributionJob(5, 'webhook', 'generic');
        $before = time();

        $job->markFailed('nope');

        self::assertSame('failed', $job->status);
        self::assertSame('nope', $job->errorMessage);
        self::assertGreaterThanOrEqual($before, $job->executedAt);
    }
}
