<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Tests\Unit\Presentation\Middleware;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Koh\SemanticDelivery\Presentation\Middleware\ChannelDetectionMiddleware;
use Koh\SemanticDelivery\Presentation\Middleware\MarkdownNegotiationMiddleware;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Frontend\Page\PageInformation;

#[AllowMockObjectsWithoutExpectations]
final class MiddlewaresTest extends TestCase
{
    private ?ServerRequestInterface $handled = null;

    private function site(bool $enabled = true): Site
    {
        return new Site('blog', 1, [
            'base' => 'https://blog.example/',
            'websiteTitle' => 'The Blog',
            'settings' => ['semanticDelivery' => ['enabled' => $enabled]],
        ]);
    }

    private function handler(ResponseInterface $response): RequestHandlerInterface
    {
        $this->handled = null;

        return new class ($response, $this) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response, private readonly MiddlewaresTest $test) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->test->record($request);

                return $this->response;
            }
        };
    }

    public function record(ServerRequestInterface $request): void
    {
        $this->handled = $request;
    }

    // --- ChannelDetectionMiddleware ---

    /**
     * @return iterable<string, array{array<string, string>, array<string, string>, string|null}>
     */
    public static function channelRequests(): iterable
    {
        yield 'header wins' => [['X-Semantic-Channel' => 'voice', 'Accept' => 'application/json'], ['_channel' => 'website'], 'voice'];
        yield 'invalid header falls through to query' => [['X-Semantic-Channel' => 'bogus'], ['_channel' => 'social-media'], 'social-media'];
        yield 'query parameter' => [[], ['_channel' => 'ai-agent'], 'ai-agent'];
        yield 'invalid query falls through to accept' => [['Accept' => 'application/ld+json'], ['_channel' => 'nope'], 'ai-agent'];
        yield 'array query is ignored' => [[], ['_channel' => ['voice']], 'website'];
        yield 'accept json' => [['Accept' => 'text/html, application/json'], [], 'ai-agent'];
        yield 'accept ssml' => [['Accept' => 'application/ssml+xml'], [], 'voice'];
        yield 'default' => [['Accept' => 'text/html'], [], 'website'];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $query
     */
    #[Test]
    #[DataProvider('channelRequests')]
    public function detectsChannel(array $headers, array $query, ?string $expected): void
    {
        $request = (new ServerRequest('GET', 'https://blog.example/'))
            ->withQueryParams($query)
            ->withAttribute('site', $this->site());
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        (new ChannelDetectionMiddleware())->process($request, $this->handler(new Response()));

        self::assertSame($expected, $this->handled?->getAttribute('semantic-delivery.channel'));
    }

    #[Test]
    public function channelDetectionIsSkippedWithoutSiteOrWhenDisabled(): void
    {
        $middleware = new ChannelDetectionMiddleware();
        $base = (new ServerRequest('GET', 'https://blog.example/'))->withHeader('X-Semantic-Channel', 'voice');

        $middleware->process($base, $this->handler(new Response()));
        self::assertNull($this->handled?->getAttribute('semantic-delivery.channel'));

        $middleware->process($base->withAttribute('site', $this->site(false)), $this->handler(new Response()));
        self::assertNull($this->handled?->getAttribute('semantic-delivery.channel'));
    }

    // --- MarkdownNegotiationMiddleware ---

    private function markdown(?Context $context = null): MarkdownNegotiationMiddleware
    {
        return new MarkdownNegotiationMiddleware($this->createStub(ConnectionPool::class), $context ?? $this->createStub(Context::class));
    }

    private function markdownRequest(bool $enabled = true): ServerRequestInterface
    {
        $page = new PageInformation();
        $page->setPageRecord(['title' => 'Hello', 'description' => 'A page', 'slug' => '/hello']);

        return (new ServerRequest('GET', 'https://blog.example/hello'))
            ->withHeader('Accept', 'text/markdown')
            ->withAttribute('site', $this->site($enabled))
            ->withAttribute('frontend.page.information', $page);
    }

    #[Test]
    public function regularRequestsPassThroughWithVaryAccept(): void
    {
        $request = (new ServerRequest('GET', 'https://blog.example/'))->withHeader('Accept', 'text/html');

        $response = $this->markdown()->process($request, $this->handler(new Response(200, ['Content-Type' => 'text/html'], '<p>x</p>')));

        self::assertSame('<p>x</p>', (string) $response->getBody());
        self::assertSame(['Accept'], $response->getHeader('Vary'));
    }

    #[Test]
    public function markdownRequestsPassThroughWithoutSiteOrWhenDisabled(): void
    {
        $html = new Response(200, ['Content-Type' => 'text/html'], '<p>x</p>');
        $noSite = (new ServerRequest('GET', 'https://blog.example/'))->withHeader('Accept', 'text/markdown');

        self::assertSame('<p>x</p>', (string) $this->markdown()->process($noSite, $this->handler($html))->getBody());
        self::assertSame('<p>x</p>', (string) $this->markdown()->process($this->markdownRequest(false), $this->handler($html))->getBody());
    }

    #[Test]
    public function nonHtmlResponsesAreReturnedWithVaryAccept(): void
    {
        $json = new Response(200, ['Content-Type' => 'application/json'], '{}');

        $response = $this->markdown()->process($this->markdownRequest(), $this->handler($json));

        self::assertSame('{}', (string) $response->getBody());
        self::assertSame(['Accept'], $response->getHeader('Vary'));
    }

    #[Test]
    public function errorStatusResponsesAreNotConverted(): void
    {
        $notFound = new Response(404, ['Content-Type' => 'text/html'], 'nope');

        $response = $this->markdown()->process($this->markdownRequest(), $this->handler($notFound));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('nope', (string) $response->getBody());
    }

    #[Test]
    public function existingVaryAcceptOrWildcardIsNotDuplicated(): void
    {
        foreach (['accept, Cookie', '*'] as $vary) {
            $upstream = new Response(200, ['Content-Type' => 'application/json', 'Vary' => $vary], '{}');

            $response = $this->markdown()->process($this->markdownRequest(), $this->handler($upstream));

            self::assertSame([$vary], $response->getHeader('Vary'));
        }
    }

    #[Test]
    public function htmlWithoutPageInformationIsReturnedUnchanged(): void
    {
        $request = $this->markdownRequest()->withoutAttribute('frontend.page.information');
        $html = new Response(200, ['Content-Type' => 'text/html'], '<p>x</p>');

        $response = $this->markdown()->process($request, $this->handler($html));

        self::assertSame('<p>x</p>', (string) $response->getBody());
    }

    #[Test]
    public function htmlPageIsRenderedAsMarkdownDocument(): void
    {
        $html = new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], '<p>x</p>');

        $response = $this->markdown()->process($this->markdownRequest(), $this->handler($html));

        $body = (string) $response->getBody();
        self::assertSame("# Hello\n\n> A page\n\n**Website:** The Blog\n**URL:** https://blog.example/hello\n", $body);
        self::assertSame('text/markdown; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame((string) (int) ceil(strlen($body) / 4), $response->getHeaderLine('X-Markdown-Tokens'));
        self::assertSame('Accept, Cookie', $response->getHeaderLine('Vary'));
        self::assertSame('public, max-age=3600', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function xhtmlResponsesAreAlsoConverted(): void
    {
        $response = $this->markdown()->process(
            $this->markdownRequest(),
            $this->handler(new Response(200, ['Content-Type' => 'application/xhtml+xml'], '<p>x</p>')),
        );

        self::assertSame('text/markdown; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function responsesForLoggedInUsersAreNeverCachedPublicly(): void
    {
        $context = $this->createStub(Context::class);
        $context->method('getPropertyFromAspect')->willReturnMap([
            ['frontend.user', 'id', null, 12],
            ['frontend.user', 'groupIds', null, [0, -1]],
        ]);

        $response = $this->markdown($context)->process(
            $this->markdownRequest(),
            $this->handler(new Response(200, ['Content-Type' => 'text/html'], 'x')),
        );

        self::assertSame('private, no-store, max-age=0', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function usersInRealGroupsAreTreatedAsAuthenticated(): void
    {
        $context = $this->createStub(Context::class);
        $context->method('getPropertyFromAspect')->willReturnMap([
            ['frontend.user', 'id', null, 0],
            ['frontend.user', 'groupIds', null, [0, 3]],
        ]);

        $response = $this->markdown($context)->process(
            $this->markdownRequest(),
            $this->handler(new Response(200, ['Content-Type' => 'text/html'], 'x')),
        );

        self::assertSame('private, no-store, max-age=0', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function missingFrontendUserAspectCountsAsAnonymous(): void
    {
        $context = $this->createStub(Context::class);
        $context->method('getPropertyFromAspect')->willThrowException(new \RuntimeException('no aspect'));

        $response = $this->markdown($context)->process(
            $this->markdownRequest(),
            $this->handler(new Response(200, ['Content-Type' => 'text/html'], 'x')),
        );

        self::assertSame('public, max-age=3600', $response->getHeaderLine('Cache-Control'));
    }
}
