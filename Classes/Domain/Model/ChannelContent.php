<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Domain\Model;

final readonly class ChannelContent
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $channelIdentifier,
        public string $content,
        public array $metadata = [],
        public string $status = 'draft',
        public string $outputFormat = 'html',
        public int $pageUid = 0,
        public int $uid = 0,
    ) {}

    public function withStatus(string $status): self
    {
        return new self(
            channelIdentifier: $this->channelIdentifier,
            content: $this->content,
            metadata: $this->metadata,
            status: $status,
            outputFormat: $this->outputFormat,
            pageUid: $this->pageUid,
            uid: $this->uid,
        );
    }

    public function withContent(string $content): self
    {
        return new self(
            channelIdentifier: $this->channelIdentifier,
            content: $content,
            metadata: $this->metadata,
            status: $this->status,
            outputFormat: $this->outputFormat,
            pageUid: $this->pageUid,
            uid: $this->uid,
        );
    }
}
