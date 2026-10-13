<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Domain\Model;

final readonly class DistributionResult
{
    public function __construct(
        public bool $success,
        public string $externalId = '',
        public string $externalUrl = '',
        public string $error = '',
    ) {}

    public static function success(string $externalId = '', string $externalUrl = ''): self
    {
        return new self(true, $externalId, $externalUrl);
    }

    public static function failure(string $error): self
    {
        return new self(false, error: $error);
    }
}
