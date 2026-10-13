<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Domain\Repository;

use Koh\SemanticDelivery\Domain\Model\ChannelContent;

interface ChannelContentRepositoryInterface
{
    public function findByPageAndChannel(int $pageUid, string $channelIdentifier): ?ChannelContent;

    /** @return list<ChannelContent> */
    public function findByPage(int $pageUid): array;

    /** @return list<ChannelContent> */
    public function findByStatus(string $status): array;

    public function findByUid(int $uid): ?ChannelContent;

    public function save(ChannelContent $channelContent): int;

    public function delete(int $uid): void;
}
