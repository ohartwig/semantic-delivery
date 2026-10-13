<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Presentation\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Frontend\Page\PageInformation;

/**
 * Supports Accept: text/markdown content negotiation.
 * Returns page content as Markdown instead of HTML when requested.
 */
final class MarkdownNegotiationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly Context $context,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $accept = $request->getHeaderLine('Accept');
        $wantsMarkdown = str_contains($accept, 'text/markdown');

        if (!$wantsMarkdown) {
            return $handler->handle($request)->withAddedHeader('Vary', 'Accept');
        }

        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return $handler->handle($request);
        }

        $settings = $site->getSettings();
        if (!(bool) $settings->get('semanticDelivery.enabled')) {
            return $handler->handle($request);
        }

        // Let the regular pipeline run to resolve routing and page info
        $response = $handler->handle($request);

        // Only a regular HTML page has a Markdown rendition. Everything else
        // that runs through the page pipeline (XML sitemap, feeds, JSON page
        // types) carries the page record of the page it is attached to —
        // for the sitemap that is the root page — so rendering Markdown from
        // it would answer /sitemap.xml with the homepage. Redirects and error
        // pages keep their status and body as well. The decision is taken on
        // the response, because that is the only place that knows for sure
        // what the pipeline produced.
        if (!$this->isHtmlPageResponse($response)) {
            return $this->withVaryAccept($response);
        }

        $pageInformation = $request->getAttribute('frontend.page.information');
        if (!$pageInformation instanceof PageInformation) {
            return $response;
        }

        $pageData = $pageInformation->getPageRecord();
        $contentElements = $this->getContentElements($request);

        $markdown = $this->renderMarkdown($pageData, $contentElements, $site);
        $tokenCount = (int) ceil(strlen($markdown) / 4);

        // Cache-Control depends on whether any FrontendGroupRestriction-
        // sensitive content was filtered: a logged-in FE user's response
        // must NOT be cached publicly because the next anonymous request
        // would receive their fe_group-only content from the proxy cache.
        // Use the same gate as TYPO3 Core: a non-empty frontend.user aspect
        // means we are in a private response.
        $isPrivate = $this->isAuthenticatedFrontendUser();
        $cacheControl = $isPrivate
            ? 'private, no-store, max-age=0'
            : 'public, max-age=3600';

        return new HtmlResponse($markdown, 200, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'X-Markdown-Tokens' => (string) $tokenCount,
            'Vary' => 'Accept, Cookie',
            'Cache-Control' => $cacheControl,
        ]);
    }

    private function isHtmlPageResponse(ResponseInterface $response): bool
    {
        if (200 !== $response->getStatusCode()) {
            return false;
        }

        $mediaType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));

        return 'text/html' === $mediaType || 'application/xhtml+xml' === $mediaType;
    }

    /**
     * The same URL is answered with and without this middleware's rendition
     * depending on Accept, so caches must key on it even when the answer
     * turned out not to be Markdown — otherwise the header set of a URL
     * changes with the request, which is exactly what Vary exists to avoid.
     */
    private function withVaryAccept(ResponseInterface $response): ResponseInterface
    {
        foreach ($response->getHeader('Vary') as $line) {
            foreach (explode(',', $line) as $token) {
                $token = trim($token);
                if ('*' === $token || 0 === strcasecmp($token, 'Accept')) {
                    return $response;
                }
            }
        }

        return $response->withAddedHeader('Vary', 'Accept');
    }

    private function isAuthenticatedFrontendUser(): bool
    {
        try {
            $userId = $this->context->getPropertyFromAspect('frontend.user', 'id');
            $groupIds = $this->context->getPropertyFromAspect('frontend.user', 'groupIds');
            if (is_int($userId) && $userId > 0) {
                return true;
            }
            if (is_array($groupIds)) {
                foreach ($groupIds as $groupId) {
                    // -1 and -2 are the anonymous / login groups; any
                    // positive group id means a logged-in user.
                    if (is_int($groupId) && $groupId > 0) {
                        return true;
                    }
                }
            }
        } catch (\Throwable) {
            // No frontend.user aspect available — treat as anonymous.
        }

        return false;
    }

    /**
     * @param array<string, mixed>             $pageData
     * @param array<int, array<string, mixed>> $contentElements
     */
    private function renderMarkdown(array $pageData, array $contentElements, Site $site): string
    {
        $title = (string) ($pageData['title'] ?? '');
        $description = (string) ($pageData['description'] ?? '');
        $websiteTitle = (string) ($site->getConfiguration()['websiteTitle'] ?? $site->getIdentifier());

        $lines = [];
        $lines[] = '# ' . $title;
        $lines[] = '';

        if ('' !== $description) {
            $lines[] = '> ' . $description;
            $lines[] = '';
        }

        $lines[] = '**Website:** ' . $websiteTitle;
        $lines[] = '**URL:** ' . rtrim((string) $site->getBase(), '/') . ($pageData['slug'] ?? '/');
        $lines[] = '';

        foreach ($contentElements as $element) {
            $header = trim((string) ($element['header'] ?? ''));
            $bodytext = trim((string) ($element['bodytext'] ?? ''));

            if ('' === $header && '' === $bodytext) {
                continue;
            }

            if ('' !== $header) {
                $lines[] = '## ' . $this->htmlToPlainText($header);
                $lines[] = '';
            }

            if ('' !== $bodytext) {
                $plainText = $this->htmlToPlainText($bodytext);

                if ('' !== $plainText) {
                    $lines[] = $plainText;
                    $lines[] = '';
                }
            }
        }

        return implode("\n", $lines);
    }

    private function htmlToPlainText(string $html): string
    {
        // Convert block elements to newlines before stripping
        $text = (string) preg_replace('/<br\s*\/?>/i', "\n", $html);
        $text = (string) preg_replace('/<\/(?:p|div|li|h[1-6])>/i', "\n", $text);
        $text = (string) preg_replace('/<(?:p|div|li|h[1-6])[^>]*>/i', '', $text);

        // Convert links to markdown format
        $text = (string) preg_replace('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>([^<]+)<\/a>/i', '[$2]($1)', $text);

        // Strip remaining tags
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Clean up whitespace
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getContentElements(ServerRequestInterface $request): array
    {
        $pageArguments = $request->getAttribute('routing');
        if (!$pageArguments instanceof PageArguments) {
            return [];
        }

        $pageUid = $pageArguments->getPageId();
        if (0 === $pageUid) {
            return [];
        }

        // FrontendRestrictionContainer applies the full public-facing
        // restriction stack: deleted, hidden, starttime/endtime, fe_group
        // (FrontendGroupRestriction), workspace and sys_language. Without
        // these, the Markdown dump would surface access-protected, scheduled
        // and workspace-draft content as a cacheable public response — the
        // exact failure mode the original code hit and that the sibling
        // MarkdownContentNegotiationMiddleware in structured-content already
        // gets right.
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->setRestrictions(
            new FrontendRestrictionContainer($this->context)
        );

        $result = $queryBuilder
            ->select('*')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, \Doctrine\DBAL\ParameterType::INTEGER))
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery();

        return $result->fetchAllAssociative();
    }
}
