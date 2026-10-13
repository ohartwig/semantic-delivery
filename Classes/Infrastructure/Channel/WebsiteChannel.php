<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Channel;

use Koh\SemanticDelivery\Domain\Contract\ChannelInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;

final class WebsiteChannel implements ChannelInterface
{
    public function getIdentifier(): string
    {
        return 'website';
    }

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     * @param list<array<string, mixed>> $schemaData
     */
    public function transform(array $pageData, array $contentElements, array $schemaData): ChannelContent
    {
        $htmlParts = [];

        foreach ($contentElements as $element) {
            $header = $element['header'] ?? '';
            $bodytext = $element['bodytext'] ?? '';

            if ('' !== $header) {
                $htmlParts[] = '<h2>' . htmlspecialchars($header, ENT_QUOTES, 'UTF-8') . '</h2>';
            }

            if ('' !== $bodytext) {
                $htmlParts[] = $bodytext;
            }
        }

        // SECURITY (audit M6): schemaData is built from editor-controlled
        // fields (page title, description, content-element copy). Without
        // JSON_HEX_TAG, a literal '</script>' inside a string value would
        // close the inline script and let everything after it parse as
        // HTML — Stored XSS. Match the same flag stack we use on the
        // structured-content side (JsonLdDocument), plus an explicit
        // '</' → '<\/' guard so a future flag-drop still cannot break out.
        // JSON_THROW_ON_ERROR turns silent encode failures (e.g. invalid
        // UTF-8 in editor input) into exceptions instead of empty scripts.
        $jsonLdScripts = '';
        foreach ($schemaData as $schema) {
            $json = json_encode(
                $schema,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
                | JSON_THROW_ON_ERROR,
            );
            $jsonLdScripts .= '<script type="application/ld+json">'
                . str_replace('</', '<\/', $json)
                . '</script>';
        }

        return new ChannelContent(
            channelIdentifier: $this->getIdentifier(),
            content: implode("\n", $htmlParts) . $jsonLdScripts,
            metadata: [
                'title' => $pageData['title'] ?? '',
                'description' => $pageData['description'] ?? '',
                'schemas' => $schemaData,
            ],
            status: 'ready',
            outputFormat: $this->getOutputFormat(),
            pageUid: (int) ($pageData['uid'] ?? 0),
        );
    }

    public function getMaxLength(): int
    {
        return 0; // no limit
    }

    public function getOutputFormat(): string
    {
        return 'html';
    }
}
