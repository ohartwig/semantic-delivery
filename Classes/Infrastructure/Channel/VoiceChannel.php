<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Channel;

use Koh\SemanticDelivery\Domain\Contract\ChannelInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;

final class VoiceChannel implements ChannelInterface
{
    public function getIdentifier(): string
    {
        return 'voice';
    }

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     * @param list<array<string, mixed>> $schemaData
     */
    public function transform(array $pageData, array $contentElements, array $schemaData): ChannelContent
    {
        $ssmlParts = ['<speak>'];

        $title = $pageData['title'] ?? '';
        if ('' !== $title) {
            $ssmlParts[] = '<p><s>' . $this->escapeXml($title) . '.</s></p>';
        }

        $description = $pageData['description'] ?? $pageData['abstract'] ?? '';
        if ('' !== $description) {
            $ssmlParts[] = '<p><s>' . $this->escapeXml($description) . '</s></p>';
            $ssmlParts[] = '<break time="500ms"/>';
        }

        foreach ($contentElements as $element) {
            $header = $element['header'] ?? '';
            $bodytext = strip_tags($element['bodytext'] ?? '');

            if ('' !== $header) {
                $ssmlParts[] = '<p><emphasis level="moderate">' . $this->escapeXml($header) . '</emphasis></p>';
            }

            if ('' !== $bodytext) {
                $sentences = preg_split('/(?<=[.!?])\s+/', $bodytext, -1, PREG_SPLIT_NO_EMPTY);
                if (false !== $sentences) {
                    $ssmlParts[] = '<p>';
                    foreach ($sentences as $sentence) {
                        $ssmlParts[] = '<s>' . $this->escapeXml(trim($sentence)) . '</s>';
                    }
                    $ssmlParts[] = '</p>';
                }
            }

            $ssmlParts[] = '<break time="300ms"/>';
        }

        $ssmlParts[] = '</speak>';

        $ssml = implode("\n", $ssmlParts);

        return new ChannelContent(
            channelIdentifier: $this->getIdentifier(),
            content: $ssml,
            metadata: [
                'title' => $title,
                'contentCount' => count($contentElements),
            ],
            status: 'ready',
            outputFormat: $this->getOutputFormat(),
            pageUid: (int) ($pageData['uid'] ?? 0),
        );
    }

    public function getMaxLength(): int
    {
        return 5000;
    }

    public function getOutputFormat(): string
    {
        return 'ssml';
    }

    private function escapeXml(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
