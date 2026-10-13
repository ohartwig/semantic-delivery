<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Presentation\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

final class ChannelDetectionMiddleware implements MiddlewareInterface
{
    private const CHANNEL_HEADER = 'X-Semantic-Channel';
    private const CHANNEL_QUERY_PARAM = '_channel';

    private const VALID_CHANNELS = ['website', 'ai-agent', 'voice', 'social-media'];

    private const ACCEPT_CHANNEL_MAP = [
        'application/ld+json' => 'ai-agent',
        'application/json' => 'ai-agent',
        'application/ssml+xml' => 'voice',
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return $handler->handle($request);
        }

        $settings = $site->getSettings();
        if (!(bool) $settings->get('semanticDelivery.enabled')) {
            return $handler->handle($request);
        }

        $channel = $this->detectChannel($request);
        $request = $request->withAttribute('semantic-delivery.channel', $channel);

        return $handler->handle($request);
    }

    private function detectChannel(ServerRequestInterface $request): string
    {
        $headerChannel = $request->getHeaderLine(self::CHANNEL_HEADER);
        if ('' !== $headerChannel && in_array($headerChannel, self::VALID_CHANNELS, true)) {
            return $headerChannel;
        }

        $queryParams = $request->getQueryParams();
        if (isset($queryParams[self::CHANNEL_QUERY_PARAM]) && is_string($queryParams[self::CHANNEL_QUERY_PARAM]) && in_array($queryParams[self::CHANNEL_QUERY_PARAM], self::VALID_CHANNELS, true)) {
            return $queryParams[self::CHANNEL_QUERY_PARAM];
        }

        $accept = $request->getHeaderLine('Accept');
        foreach (self::ACCEPT_CHANNEL_MAP as $mimeType => $channel) {
            if (str_contains($accept, $mimeType)) {
                return $channel;
            }
        }

        return 'website';
    }
}
