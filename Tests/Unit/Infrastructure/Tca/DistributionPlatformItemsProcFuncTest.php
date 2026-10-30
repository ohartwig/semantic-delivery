<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Tests\Unit\Infrastructure\Tca;

use Koh\SemanticDelivery\Domain\Contract\DistributorInterface;
use Koh\SemanticDelivery\Infrastructure\Tca\DistributionPlatformItemsProcFunc;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

final class DistributionPlatformItemsProcFuncTest extends TestCase
{
    /**
     * @param list<string> $platforms
     */
    private function distributor(array $platforms): DistributorInterface
    {
        $d = $this->createStub(DistributorInterface::class);
        $d->method('getAvailablePlatforms')->willReturn($platforms);

        return $d;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function finderFor(array $settings): SiteFinder
    {
        $finder = $this->createStub(SiteFinder::class);
        $finder->method('getSiteByPageId')->willReturn(new Site('test', 1, ['base' => 'https://e.x/', 'settings' => $settings]));

        return $finder;
    }

    #[Test]
    public function offersOnlyPlatformsWithCredentialsSortedByLabelAndDeduplicated(): void
    {
        $procFunc = new DistributionPlatformItemsProcFunc(
            [$this->distributor(['twitter', 'linkedin']), $this->distributor(['linkedin', 'bluesky', 'facebook'])],
            $this->finderFor(['semanticDelivery' => ['social' => [
                'twitter' => ['bearerToken' => 'x'],
                'linkedin' => ['accessToken' => 'y'],
                'bluesky' => ['appPassword' => ''],
            ]]]),
        );
        $config = ['row' => ['uid' => 5]];

        $procFunc->getItems($config);

        self::assertSame(
            [['label' => 'Linkedin', 'value' => 'linkedin'], ['label' => 'Twitter', 'value' => 'twitter']],
            $config['items'],
        );
    }

    #[Test]
    public function usesPidWhenRecordHasNoUid(): void
    {
        $finder = $this->createMock(SiteFinder::class);
        $finder->expects(self::once())->method('getSiteByPageId')->with(12)
            ->willReturn(new Site('t', 1, ['base' => 'https://e.x/', 'settings' => ['semanticDelivery' => ['blogging' => ['devto' => ['apiKey' => 'k']]]]]));
        $procFunc = new DistributionPlatformItemsProcFunc([$this->distributor(['devto'])], $finder);
        $config = ['row' => ['pid' => 12]];

        $procFunc->getItems($config);

        self::assertSame([['label' => 'Devto', 'value' => 'devto']], $config['items']);
    }

    #[Test]
    public function offersNothingWithoutAPageContext(): void
    {
        $procFunc = new DistributionPlatformItemsProcFunc([$this->distributor(['twitter'])], $this->finderFor([]));
        $config = [];

        $procFunc->getItems($config);

        self::assertSame([], $config['items']);
    }

    #[Test]
    public function offersNothingWhenTheSiteCannotBeResolved(): void
    {
        $finder = $this->createStub(SiteFinder::class);
        $finder->method('getSiteByPageId')->willThrowException(new \RuntimeException('no site'));
        $procFunc = new DistributionPlatformItemsProcFunc([$this->distributor(['twitter'])], $finder);
        $config = ['row' => ['uid' => 5]];

        $procFunc->getItems($config);

        self::assertSame([], $config['items']);
    }

    #[Test]
    public function ignoresNonStringCredentialValues(): void
    {
        $procFunc = new DistributionPlatformItemsProcFunc(
            [$this->distributor(['twitter'])],
            $this->finderFor(['semanticDelivery' => ['social' => ['twitter' => ['bearerToken' => 123]]]]),
        );
        $config = ['row' => ['uid' => 5]];

        $procFunc->getItems($config);

        self::assertSame([], $config['items']);
    }
}
