<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Tests\Unit\Presentation\Controller;

use GuzzleHttp\Psr7\ServerRequest;
use Koh\SemanticDelivery\Application\Service\ContentTransformerService;
use Koh\SemanticDelivery\Domain\Contract\ChannelInterface;
use Koh\SemanticDelivery\Domain\Model\ChannelContent;
use Koh\SemanticDelivery\Presentation\Controller\DeliveryApiController;
use Koh\SemanticDelivery\Tests\Unit\Support\DatabaseDouble;
use Koh\StructuredContent\Application\Service\SchemaOrgService;
use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;

final class DeliveryApiControllerTest extends TestCase
{
    use DatabaseDouble;

    private function site(bool $enabled = true): Site
    {
        return new Site('blog', 1, ['base' => 'https://blog.example/', 'settings' => ['semanticDelivery' => ['enabled' => $enabled]]]);
    }

    private function request(bool $enabled = true, int $pageId = 4): ServerRequestInterface
    {
        return (new ServerRequest('GET', 'https://blog.example/api'))
            ->withAttribute('site', $this->site($enabled))
            ->withAttribute('routing', new PageArguments($pageId, '0', []));
    }

    private function controller(ConnectionPool $pool, ?ChannelInterface $channel = null, ?SchemaGeneratorInterface $generator = null): DeliveryApiController
    {
        return new DeliveryApiController(
            new SchemaOrgService(null === $generator ? [] : [$generator]),
            new ContentTransformerService(null === $channel ? [] : [$channel]),
            $pool,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function rejectsRequestsWithoutSiteContext(): void
    {
        $response = $this->controller($this->pool($this->queryBuilder()))->pageAction(new ServerRequest('GET', 'https://blog.example/api'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('No site context', $this->json($response)['error']);
    }

    #[Test]
    public function refusesWhenTheFeatureIsDisabledForTheSite(): void
    {
        $response = $this->controller($this->pool($this->queryBuilder()))->pageAction($this->request(false));

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function rejectsMissingRoutingAndPageZero(): void
    {
        $controller = $this->controller($this->pool($this->queryBuilder()));

        $noRouting = $controller->pageAction($this->request()->withoutAttribute('routing'));
        $pageZero = $controller->pageAction($this->request(true, 0));

        self::assertSame([400, 'Invalid routing'], [$noRouting->getStatusCode(), $this->json($noRouting)['error']]);
        self::assertSame([400, 'Invalid page UID'], [$pageZero->getStatusCode(), $this->json($pageZero)['error']]);
    }

    #[Test]
    public function answers404ForUnknownPage(): void
    {
        $response = $this->controller($this->pool($this->queryBuilder(null)))->pageAction($this->request());

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Page not found', $this->json($response)['error']);
    }

    #[Test]
    public function answers404WhenTheRequestedChannelDoesNotExist(): void
    {
        $qb = $this->queryBuilder(['uid' => 4, 'title' => 'T', 'slug' => '/t'], []);

        $response = $this->controller($this->pool($qb))->pageAction($this->request()->withAttribute('semantic-delivery.channel', 'voice'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Channel not available', $this->json($response)['error']);
    }

    #[Test]
    public function returnsDecodedChannelContentWithSchemaAndMetadata(): void
    {
        $qb = $this->queryBuilder(['uid' => 4, 'title' => 'T', 'slug' => '/t'], [['header' => 'H']]);
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getIdentifier')->willReturn('ai-agent');
        $channel->expects(self::once())->method('transform')->with(
            ['uid' => 4, 'title' => 'T', 'slug' => '/t'],
            [['header' => 'H']],
            self::callback(static fn(array $schema): bool => 'Article' === $schema[0]['@type']),
        )->willReturn(new ChannelContent('ai-agent', '{"a":1}', ['m' => 'x']));
        $generator = $this->createStub(SchemaGeneratorInterface::class);
        $generator->method('canHandle')->willReturn(true);
        $generator->method('generate')->willReturn(new JsonLdDocument('Article', ['headline' => 'T']));

        $response = $this->controller($this->pool($qb), $channel, $generator)->pageAction($this->request());

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        self::assertSame(['uid' => 4, 'title' => 'T', 'slug' => '/t'], $data['page']);
        self::assertSame('ai-agent', $data['channel']);
        self::assertSame(['a' => 1], $data['content']);
        self::assertSame('T', $data['schema'][0]['headline']);
        self::assertSame(['m' => 'x'], $data['metadata']);
    }

    #[Test]
    public function keepsNonJsonChannelContentAsString(): void
    {
        $qb = $this->queryBuilder(['uid' => 4], []);
        $channel = $this->createStub(ChannelInterface::class);
        $channel->method('getIdentifier')->willReturn('voice');
        $channel->method('transform')->willReturn(new ChannelContent('voice', '<speak/>'));

        $response = $this->controller($this->pool($qb), $channel)->pageAction(
            $this->request()->withAttribute('semantic-delivery.channel', 'voice'),
        );

        $data = $this->json($response);
        self::assertSame('<speak/>', $data['content']);
        self::assertSame('', $data['page']['title']);
    }
}
