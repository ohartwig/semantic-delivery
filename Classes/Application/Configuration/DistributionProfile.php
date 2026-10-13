<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Application\Configuration;

final class DistributionProfile
{
    /** @var list<array{channel: string, distributor: string, platform: string, options: array<string, mixed>}> */
    private array $rules = [];

    /**
     * @param array<string, mixed> $options
     */
    public function addRule(string $channelIdentifier, string $distributorIdentifier, string $platform, array $options = []): self
    {
        $this->rules[] = [
            'channel' => $channelIdentifier,
            'distributor' => $distributorIdentifier,
            'platform' => $platform,
            'options' => $options,
        ];

        return $this;
    }

    /**
     * @return list<array{channel: string, distributor: string, platform: string, options: array<string, mixed>}>
     */
    public function getRulesForChannel(string $channelIdentifier): array
    {
        return array_values(array_filter(
            $this->rules,
            static fn(array $rule): bool => $rule['channel'] === $channelIdentifier,
        ));
    }

    /**
     * @return list<array{channel: string, distributor: string, platform: string, options: array<string, mixed>}>
     */
    public function getAllRules(): array
    {
        return $this->rules;
    }
}
