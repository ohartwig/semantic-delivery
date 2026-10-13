<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Application\Configuration;

final class ChannelConfiguration
{
    /** @var array<string, bool> */
    private array $enabledChannels = [];

    /** @var array<string, array<string, mixed>> */
    private array $channelOptions = [];

    public function enableChannel(string $identifier): self
    {
        $this->enabledChannels[$identifier] = true;

        return $this;
    }

    public function disableChannel(string $identifier): self
    {
        $this->enabledChannels[$identifier] = false;

        return $this;
    }

    public function isChannelEnabled(string $identifier): bool
    {
        return $this->enabledChannels[$identifier] ?? false;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function setChannelOptions(string $identifier, array $options): self
    {
        $this->channelOptions[$identifier] = $options;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getChannelOptions(string $identifier): array
    {
        return $this->channelOptions[$identifier] ?? [];
    }

    /** @return list<string> */
    public function getEnabledChannels(): array
    {
        return array_keys(array_filter($this->enabledChannels));
    }
}
