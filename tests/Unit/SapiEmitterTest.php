<?php

declare(strict_types=1);

namespace PHPdot\Server\Sapi\Tests\Unit;

use PHPdot\Http\Factory\ResponseFactory;
use PHPdot\Server\Sapi\Exception\EmitterException;
use PHPdot\Server\Sapi\SapiEmitter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class SapiEmitterTest extends TestCase
{
    private ResponseFactory $http;

    /** @var list<array{string, bool}> */
    private array $headerCalls = [];

    /** @var list<array{string, int}> */
    private array $statusCalls = [];

    protected function setUp(): void
    {
        $this->http = new ResponseFactory();
        $this->headerCalls = [];
        $this->statusCalls = [];
    }

    #[Test]
    public function emitsStatusLineWithReasonPhrase(): void
    {
        $this->emit($this->emitter(), $this->http->createResponse(201));

        self::assertSame([['HTTP/1.1 201 Created', 201]], $this->statusCalls);
    }

    #[Test]
    public function emitsSingleHeaderWithReplace(): void
    {
        $response = $this->http->createResponse(200)->withHeader('X-Request-Id', 'abc');

        $this->emit($this->emitter(), $response);

        self::assertCount(1, $this->headerCalls);
        self::assertSame(['X-Request-Id: abc', true], $this->headerCalls[0]);
    }

    #[Test]
    public function emitsRepeatedSetCookieHeaders(): void
    {
        $response = $this->http->createResponse(200)
            ->withHeader('Set-Cookie', 'a=1; Path=/')
            ->withAddedHeader('Set-Cookie', 'b=2; Path=/');

        $this->emit($this->emitter(), $response);

        self::assertContains(['Set-Cookie: a=1; Path=/', false], $this->headerCalls);
        self::assertContains(['Set-Cookie: b=2; Path=/', false], $this->headerCalls);
        self::assertCount(2, $this->headerCalls);
    }

    #[Test]
    public function emitsBody(): void
    {
        $response = $this->http->createResponse(200)->withBody($this->http->createStream('Hello, world'));

        $out = $this->emit($this->emitter(), $response);

        self::assertSame('Hello, world', $out);
    }

    #[Test]
    public function omitsBodyOn204(): void
    {
        $response = $this->http->createResponse(204)->withBody($this->http->createStream('should not appear'));

        $out = $this->emit($this->emitter(), $response);

        self::assertSame('', $out);
        self::assertSame([['HTTP/1.1 204 No Content', 204]], $this->statusCalls);
    }

    #[Test]
    public function omitsBodyOn304(): void
    {
        $response = $this->http->createResponse(304)->withBody($this->http->createStream('not modified body'));

        $out = $this->emit($this->emitter(), $response);

        self::assertSame('', $out);
    }

    #[Test]
    public function omitsBodyForHeadRequestButStillEmitsHeaders(): void
    {
        $response = $this->http->createResponse(200)
            ->withHeader('Content-Length', '9')
            ->withBody($this->http->createStream('body here'));

        $out = $this->emit($this->emitter(requestMethod: 'HEAD'), $response);

        self::assertSame('', $out);
        self::assertSame([['HTTP/1.1 200 OK', 200]], $this->statusCalls);
        self::assertContains(['Content-Length: 9', true], $this->headerCalls);
    }

    #[Test]
    public function handlesEmptyBody(): void
    {
        $response = $this->http->createResponse(200)->withBody($this->http->createStream(''));

        $out = $this->emit($this->emitter(), $response);

        self::assertSame('', $out);
    }

    #[Test]
    public function streamsLargeBodyAcrossBufferBoundaries(): void
    {
        $payload = str_repeat('AB', 5000);
        $response = $this->http->createResponse(200)->withBody($this->http->createStream($payload));

        $out = $this->emit($this->emitter(bufferSize: 1024), $response);

        self::assertSame($payload, $out);
        self::assertSame(10000, strlen($out));
    }

    #[Test]
    public function throwsWhenOutputAlreadyStartedAndLeaksNothing(): void
    {
        $response = $this->http->createResponse(200)->withBody($this->http->createStream('leaked?'));
        $emitter = $this->emitter(headersSent: true, sentFile: '/app/index.php', sentLine: 7);

        $caught = null;
        ob_start();
        try {
            $emitter->emit($response);
        } catch (EmitterException $e) {
            $caught = $e;
        }
        $leaked = (string) ob_get_clean();

        self::assertInstanceOf(EmitterException::class, $caught);
        self::assertStringContainsString('/app/index.php', $caught->getMessage());
        self::assertStringContainsString('line 7', $caught->getMessage());
        self::assertSame('', $leaked);
        self::assertSame([], $this->headerCalls);
        self::assertSame([], $this->statusCalls);
    }

    #[Test]
    public function emitsStatusLineWithoutReasonPhrase(): void
    {
        // Status 299 has no standard reason phrase, so none is appended.
        $this->emit($this->emitter(), $this->http->createResponse(299));

        self::assertSame([['HTTP/1.1 299', 299]], $this->statusCalls);
    }

    #[Test]
    public function emitsStatusLineForNonDefaultProtocolVersion(): void
    {
        $response = $this->http->createResponse(200)->withProtocolVersion('2');

        $this->emit($this->emitter(), $response);

        self::assertSame([['HTTP/2 200 OK', 200]], $this->statusCalls);
    }

    #[Test]
    public function emitsMultipleValuesOfANonCookieHeader(): void
    {
        $response = $this->http->createResponse(200)
            ->withHeader('X-Multi', 'one')
            ->withAddedHeader('X-Multi', 'two');

        $this->emit($this->emitter(), $response);

        self::assertSame(
            [['X-Multi: one', true], ['X-Multi: two', false]],
            $this->headerCalls,
        );
    }

    #[Test]
    public function resolvesRequestMethodFromServerWhenNotInjected(): void
    {
        $original = $_SERVER['REQUEST_METHOD'] ?? null;

        try {
            $_SERVER['REQUEST_METHOD'] = 'HEAD';
            $emitter = new SapiEmitter(
                headerSink: function (string $header, bool $replace): void {
                    $this->headerCalls[] = [$header, $replace];
                },
                statusSink: function (string $statusLine, int $code): void {
                    $this->statusCalls[] = [$statusLine, $code];
                },
                headersSentProbe: static fn(): array => [false, '', 0],
            );

            ob_start();
            $emitter->emit($this->http->createResponse(200)->withBody($this->http->createStream('hidden')));
            $body = (string) ob_get_clean();
        } finally {
            if ($original === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $original;
            }
        }

        self::assertSame('', $body);
        self::assertSame([['HTTP/1.1 200 OK', 200]], $this->statusCalls);
    }

    private function emitter(
        string $requestMethod = 'GET',
        int $bufferSize = 8192,
        bool $headersSent = false,
        string $sentFile = '',
        int $sentLine = 0,
    ): SapiEmitter {
        return new SapiEmitter(
            bufferSize: $bufferSize,
            headerSink: function (string $header, bool $replace): void {
                $this->headerCalls[] = [$header, $replace];
            },
            statusSink: function (string $statusLine, int $code): void {
                $this->statusCalls[] = [$statusLine, $code];
            },
            headersSentProbe: static fn(): array => [$headersSent, $sentFile, $sentLine],
            requestMethod: $requestMethod,
        );
    }

    private function emit(SapiEmitter $emitter, ResponseInterface $response): string
    {
        ob_start();
        $emitter->emit($response);

        return (string) ob_get_clean();
    }
}
