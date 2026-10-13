<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Tca;

use Koh\SemanticDelivery\Domain\Contract\DistributorInterface;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Dynamically populates the distribution_platforms select field
 * from registered DistributorInterface implementations that have
 * credentials configured in the current site's settings.
 */
final class DistributionPlatformItemsProcFunc
{
    /**
     * Maps platform identifier → Site Setting key that must be non-empty
     * for the platform to be considered "configured". Only the primary
     * auth credential is checked (not public IDs like organizationId).
     *
     * @var array<string, string>
     */
    private const CREDENTIAL_KEYS = [
        'bluesky' => 'semanticDelivery.social.bluesky.appPassword',
        'linkedin' => 'semanticDelivery.social.linkedin.accessToken',
        'twitter' => 'semanticDelivery.social.twitter.bearerToken',
        'instagram' => 'semanticDelivery.social.instagram.accessToken',
        'facebook' => 'semanticDelivery.social.facebook.accessToken',
        'devto' => 'semanticDelivery.blogging.devto.apiKey',
        'ayrshare' => 'semanticDelivery.social.ayrshare.apiKey',
        'buffer' => 'semanticDelivery.social.buffer.accessToken',
        'n8n' => 'semanticDelivery.social.n8n.webhookUrl',
        'latedev' => 'semanticDelivery.social.latedev.apiKey',
        'webhook' => 'semanticDelivery.social.webhook.url',
    ];

    /** @param iterable<DistributorInterface> $distributors */
    public function __construct(
        private readonly iterable $distributors,
        private readonly SiteFinder $siteFinder,
    ) {}

    /** @param array<string, mixed> $fieldConfig */
    public function getItems(array &$fieldConfig): void
    {
        $configuredCredentials = $this->resolveConfiguredPlatforms($fieldConfig);

        $items = [];
        $seen = [];

        foreach ($this->distributors as $distributor) {
            foreach ($distributor->getAvailablePlatforms() as $platform) {
                if (isset($seen[$platform])) {
                    continue;
                }
                $seen[$platform] = true;

                if (!isset($configuredCredentials[$platform])) {
                    continue;
                }

                $items[] = [
                    'label' => ucfirst($platform),
                    'value' => $platform,
                ];
            }
        }

        usort($items, static fn(array $a, array $b): int => $a['label'] <=> $b['label']);

        $fieldConfig['items'] = $items;
    }

    /**
     * Resolve which platforms have non-empty credentials in the current site.
     *
     * @param array<string, mixed> $fieldConfig
     *
     * @return array<string, true>
     */
    private function resolveConfiguredPlatforms(array $fieldConfig): array
    {
        $row = $fieldConfig['row'] ?? [];
        $pid = (int) ($row['uid'] ?? $row['pid'] ?? 0);

        if (0 === $pid) {
            return [];
        }

        try {
            $site = $this->siteFinder->getSiteByPageId($pid);
        } catch (\Throwable) {
            return [];
        }

        $settings = $site->getSettings();
        $configured = [];

        foreach (self::CREDENTIAL_KEYS as $platform => $settingKey) {
            $value = $this->resolveNestedSetting($settings, $settingKey);
            if ('' !== $value) {
                $configured[$platform] = true;
            }
        }

        return $configured;
    }

    /**
     * Resolve a dot-separated setting key from TYPO3 SiteSettings.
     */
    private function resolveNestedSetting(\TYPO3\CMS\Core\Site\Entity\SiteSettings $settings, string $key): string
    {
        try {
            $value = $settings->get($key);

            return is_string($value) ? $value : '';
        } catch (\Throwable) {
            return '';
        }
    }
}
