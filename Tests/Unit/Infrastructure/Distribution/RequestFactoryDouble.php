<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Tests\Unit\Infrastructure\Distribution;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Builds a RequestFactory double that replays queued responses (or throws
 * queued exceptions) and records every request it receives.
 */
trait RequestFactoryDouble
{
    /** @var list<array{uri: string, method: string, options: array<string, mixed>}> */
    private array $requests = [];

    /**
     * @param list<Response|\Throwable> $queue
     */
    private function factoryReplaying(array $queue): RequestFactory
    {
        $this->requests = [];
        $factory = $this->createStub(RequestFactory::class);
        $factory->method('request')->willReturnCallback(
            function (string $uri, string $method = 'GET', array $options = []) use (&$queue) {
                $this->requests[] = ['uri' => $uri, 'method' => $method, 'options' => $options];
                $next = array_shift($queue);
                if ($next instanceof \Throwable) {
                    throw $next;
                }

                return $next ?? new Response(200, [], '{}');
            },
        );

        return $factory;
    }

    /**
     * @param array<string, mixed>|string $body
     * @param array<string, string>       $headers
     */
    private function reply(int $status, array|string $body, array $headers = []): Response
    {
        return new Response($status, $headers, is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function sentJson(int $index = 0): array
    {
        return json_decode((string) $this->requests[$index]['options']['body'], true, flags: JSON_THROW_ON_ERROR);
    }

    private function failingLogger(): LoggerInterface&MockObject
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        return $logger;
    }
}
