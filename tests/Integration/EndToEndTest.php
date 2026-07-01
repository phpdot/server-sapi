<?php

declare(strict_types=1);

namespace PHPdot\Server\Sapi\Tests\Integration;

use PHPdot\Http\Factory\ResponseFactory;
use PHPdot\Server\Sapi\RequestFactory;
use PHPdot\Server\Sapi\SapiEmitter;
use PHPdot\Server\Sapi\SapiServer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Wires the package's real components together with phpdot/http's concrete
 * PSR-7/PSR-17 implementation and asserts the fully emitted response.
 */
final class EndToEndTest extends TestCase
{
    /** @var list<array{string, int}> */
    private array $statusCalls = [];

    /** @var list<string> */
    private array $headerCalls = [];

    protected function setUp(): void
    {
        $this->statusCalls = [];
        $this->headerCalls = [];
    }

    #[Test]
    public function capturesHandlesAndEmitsAcrossTheFullStack(): void
    {
        $http = new ResponseFactory();
        $requests = new RequestFactory($http, $http, $http, $http);

        $request = $requests->fromArrays(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST' => 'api.test',
                'REQUEST_URI' => '/echo?greet=hi',
                'SERVER_PROTOCOL' => 'HTTP/1.1',
                'CONTENT_TYPE' => 'application/json',
            ],
            query: ['greet' => 'hi'],
            rawBody: '{"name":"omar"}',
        );

        $response = $this->echoHandler($http)->handle($request);
        $body = $this->emit($response, 'POST');

        $expected = json_encode(['greet' => 'hi', 'received' => '{"name":"omar"}']);
        self::assertSame([['HTTP/1.1 201 Created', 201]], $this->statusCalls);
        self::assertContains('Content-Type: application/json', $this->headerCalls);
        self::assertContains('X-Greet: hi', $this->headerCalls);
        self::assertContains('Set-Cookie: a=1', $this->headerCalls);
        self::assertContains('Set-Cookie: b=2', $this->headerCalls);
        self::assertSame($expected, $body);
    }

    #[Test]
    public function runtimeDrivesTheStackFromSuperglobals(): void
    {
        $http = new ResponseFactory();
        $requests = new RequestFactory($http, $http, $http, $http);
        $emitter = $this->capturingEmitter('GET');
        $runtime = new SapiServer($requests, $emitter);

        $originalServer = $_SERVER;
        $originalGet = $_GET;
        $originalPost = $_POST;
        $originalCookie = $_COOKIE;
        $originalFiles = $_FILES;

        try {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['HTTP_HOST'] = 'site.test';
            $_SERVER['REQUEST_URI'] = '/hello?name=world';
            $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
            $_GET = ['name' => 'world'];
            $_POST = [];
            $_COOKIE = [];
            $_FILES = [];

            ob_start();
            $runtime->serve($this->greetingHandler($http));
            $body = (string) ob_get_clean();
        } finally {
            $_SERVER = $originalServer;
            $_GET = $originalGet;
            $_POST = $originalPost;
            $_COOKIE = $originalCookie;
            $_FILES = $originalFiles;
        }

        self::assertSame([['HTTP/1.1 200 OK', 200]], $this->statusCalls);
        self::assertContains('Content-Type: text/plain', $this->headerCalls);
        self::assertSame('Hello, world', $body);
    }

    private function emit(ResponseInterface $response, string $requestMethod): string
    {
        ob_start();
        $this->capturingEmitter($requestMethod)->emit($response);

        return (string) ob_get_clean();
    }

    private function capturingEmitter(string $requestMethod): SapiEmitter
    {
        return new SapiEmitter(
            statusSink: function (string $statusLine, int $code): void {
                $this->statusCalls[] = [$statusLine, $code];
            },
            headerSink: function (string $header, bool $replace): void {
                $this->headerCalls[] = $header;
            },
            headersSentProbe: static fn(): array => [false, '', 0],
            requestMethod: $requestMethod,
        );
    }

    private function echoHandler(ResponseFactory $http): RequestHandlerInterface
    {
        return new class ($http) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseFactory $http) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $query = $request->getQueryParams();
                $greet = isset($query['greet']) && is_string($query['greet']) ? $query['greet'] : 'none';
                $payload = json_encode([
                    'greet' => $greet,
                    'received' => (string) $request->getBody(),
                ]);

                return $this->http->createResponse(201)
                    ->withHeader('Content-Type', 'application/json')
                    ->withHeader('X-Greet', $greet)
                    ->withAddedHeader('Set-Cookie', 'a=1')
                    ->withAddedHeader('Set-Cookie', 'b=2')
                    ->withBody($this->http->createStream($payload === false ? '' : $payload));
            }
        };
    }

    private function greetingHandler(ResponseFactory $http): RequestHandlerInterface
    {
        return new class ($http) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseFactory $http) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $query = $request->getQueryParams();
                $name = isset($query['name']) && is_string($query['name']) ? $query['name'] : 'stranger';

                return $this->http->createResponse(200)
                    ->withHeader('Content-Type', 'text/plain')
                    ->withBody($this->http->createStream('Hello, ' . $name));
            }
        };
    }
}
