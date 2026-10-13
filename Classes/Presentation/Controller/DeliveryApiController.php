<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Presentation\Controller;

use Koh\SemanticDelivery\Application\Service\ContentTransformerService;
use Koh\StructuredContent\Application\Service\SchemaOrgService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;

final class DeliveryApiController
{
    public function __construct(
        private readonly SchemaOrgService $schemaOrgService,
        private readonly ContentTransformerService $contentTransformerService,
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function pageAction(ServerRequestInterface $request): ResponseInterface
    {
        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return new JsonResponse(['error' => 'No site context'], 400);
        }

        $settings = $site->getSettings();
        if (!(bool) $settings->get('semanticDelivery.enabled')) {
            return new JsonResponse(['error' => 'Semantic Delivery is disabled'], 403);
        }

        $routeArguments = $request->getAttribute('routing');
        if (!$routeArguments instanceof PageArguments) {
            return new JsonResponse(['error' => 'Invalid routing'], 400);
        }
        $pageUid = $routeArguments->getPageId();

        if (0 === $pageUid) {
            return new JsonResponse(['error' => 'Invalid page UID'], 400);
        }

        $pageData = $this->getPageData($pageUid);
        if (null === $pageData) {
            return new JsonResponse(['error' => 'Page not found'], 404);
        }

        $contentElements = $this->getContentElements($pageUid);

        $documents = $this->schemaOrgService->generateAll($pageData, $contentElements);
        $schemaData = [];
        foreach ($documents as $document) {
            $decoded = json_decode($document->toJsonLd(), true);
            if (is_array($decoded)) {
                $schemaData[] = $decoded;
            }
        }

        $channel = $request->getAttribute('semantic-delivery.channel', 'ai-agent');
        $channelContent = $this->contentTransformerService->transformForChannel(
            $channel,
            $pageData,
            $contentElements,
            $schemaData,
        );

        if (null === $channelContent) {
            return new JsonResponse(['error' => 'Channel not available'], 404);
        }

        $decodedContent = json_decode($channelContent->content, true);

        return new JsonResponse([
            'page' => [
                'uid' => $pageUid,
                'title' => $pageData['title'] ?? '',
                'slug' => $pageData['slug'] ?? '',
            ],
            'channel' => $channel,
            'content' => is_array($decodedContent) ? $decodedContent : $channelContent->content,
            'schema' => $schemaData,
            'metadata' => $channelContent->metadata,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getPageData(int $pageUid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $row = $queryBuilder
            ->select('*')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, \Doctrine\DBAL\ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        return false !== $row ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getContentElements(int $pageUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');

        return array_values($queryBuilder
            ->select('*')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, \Doctrine\DBAL\ParameterType::INTEGER)))
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative());
    }
}
