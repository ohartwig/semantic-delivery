<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Presentation\Event;

final class BeforeChannelTransformEvent
{
    /**
     * @param array<string, mixed>             $pageData
     * @param array<int, array<string, mixed>> $contentElements
     * @param array<string, mixed>             $schemaData
     */
    public function __construct(
        private array $pageData,
        private array $contentElements,
        private array $schemaData,
        private readonly string $channelIdentifier,
    ) {}

    /** @return array<string, mixed> */
    public function getPageData(): array
    {
        return $this->pageData;
    }

    /** @param array<string, mixed> $pageData */
    public function setPageData(array $pageData): void
    {
        $this->pageData = $pageData;
    }

    /** @return array<int, array<string, mixed>> */
    public function getContentElements(): array
    {
        return $this->contentElements;
    }

    /** @param array<int, array<string, mixed>> $contentElements */
    public function setContentElements(array $contentElements): void
    {
        $this->contentElements = $contentElements;
    }

    /** @return array<string, mixed> */
    public function getSchemaData(): array
    {
        return $this->schemaData;
    }

    /** @param array<string, mixed> $schemaData */
    public function setSchemaData(array $schemaData): void
    {
        $this->schemaData = $schemaData;
    }

    public function getChannelIdentifier(): string
    {
        return $this->channelIdentifier;
    }
}
