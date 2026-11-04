<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * AUD-026-F01. The site set used to define a settings tree for platform
 * credentials (Bluesky, LinkedIn, X, Instagram, Facebook, Dev.to) that no
 * code ever passed to a distributor: processJob() calls distribute() without
 * options. An installation filled it from its secret store and so held
 * long-lived tokens for its public channels in the site configuration for a
 * feature that could not use them. The tree, the two settings nothing read
 * and the page fields nothing read are gone, and these tests keep them gone.
 */
final class SiteSetHoldsNoCredentialsTest extends TestCase
{
    private const SET_DIRECTORY = __DIR__ . '/../../../Configuration/Sets/SemanticDelivery';

    /**
     * The subtrees below `semanticDelivery` that held credentials or were
     * read by nothing.
     *
     * @return array<string, array{string}>
     */
    public static function removedSubtrees(): array
    {
        return [
            'social credentials' => ['social'],
            'blog credentials' => ['blogging'],
            'unread distribution settings' => ['distribution'],
        ];
    }

    #[Test]
    #[DataProvider('removedSubtrees')]
    public function theSettingsDefinitionsDeclareNothingBelowTheRemovedSubtree(string $subtree): void
    {
        $definitions = Yaml::parseFile(self::SET_DIRECTORY . '/settings.definitions.yaml');
        $prefix = 'semanticDelivery.' . $subtree;

        foreach (\array_keys($definitions['settings'] ?? []) as $key) {
            self::assertFalse(
                $key === $prefix || \str_starts_with((string) $key, $prefix . '.'),
                \sprintf('setting "%s" must not come back', $key),
            );
        }
        self::assertArrayNotHasKey($prefix, $definitions['categories'] ?? []);
    }

    #[Test]
    #[DataProvider('removedSubtrees')]
    public function theSetDefaultsCarryNothingBelowTheRemovedSubtree(string $subtree): void
    {
        $settings = Yaml::parseFile(self::SET_DIRECTORY . '/settings.yaml');

        self::assertArrayNotHasKey($subtree, $settings['semanticDelivery'] ?? []);
    }

    /**
     * The per-page platform selector was the only reader of the credential
     * settings, and its two fields were read by no code.
     */
    #[Test]
    public function thePageFieldsWithoutAReaderAreGone(): void
    {
        $root = __DIR__ . '/../../..';

        foreach (['ext_tables.sql', 'Configuration/TCA/Overrides/pages.php'] as $file) {
            $contents = (string) \file_get_contents($root . '/' . $file);
            foreach (['tx_semanticdelivery_distribution_enabled', 'tx_semanticdelivery_distribution_platforms'] as $field) {
                self::assertStringNotContainsString($field, $contents, $file);
            }
        }
    }
}
