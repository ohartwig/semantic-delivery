<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Domain\Model;

final readonly class ContentRelationship
{
    public function __construct(
        public int $sourceUid,
        public int $targetUid,
        public string $relationType,
    ) {}
}
