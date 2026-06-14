<?php

declare(strict_types=1);

namespace PHPdot\Server\Sapi\Tests\Unit;

use PHPdot\Http\ResponseFactory;
use PHPdot\Server\Sapi\Contract\EmitterInterface;
use PHPdot\Server\Sapi\Contract\RequestFactoryInterface;
use PHPdot\Server\Sapi\Exception\EmitterException;
use PHPdot\Server\Sapi\SapiServer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

final class SapiServerTest extends TestCase
{
    private ResponseFactory $http;

    /** @var list<int> */
    private array $statusCalls = [];

    /** @var list<string> */
    private array $outputCalls = [];

    protected function setUp(): void
    {
        $this->http = new ResponseFactory();
        $this->statusCalls = [];
        $this->outputCalls = [];
    }

    #[Test]
    public function emitsHandlerResponseThroughEmitter(): void
    {
        $response = $this->http->createResponse(200)->withBody($this->http->createStream('hello'));
        $emitter = $this->spyEmitter();
        $runtime = new SapiServer($this->requestsReturning(), $emitter);

        $runtime->serve($this->handlerReturning($response));

        self::assertSame(1, $emitter->calls);
        self::assertSame($response, $emitter->emitted);
    }

    #[Test]
    public function emitsRawFiveHundredWhenHandlerThrows(): void
    {
        $emitter = $this->spyEmitter();
        $runtime = $this->runtimeWithFloorSinks($this->requestsReturning(), $emitter);

        $runtime->serve($this->handlerThrowing(new RuntimeException('boom')));

        self::assertSame([500], $this->statusCalls);
        self::assertSame(['Internal Server Error'], $this->outputCalls);
        self::assertSame(0, $emitter->calls);
    }

    #[Test]
    public function emitsRawFiveHundredWhenCaptureThrows(): void
    {
        $emitter = $this->spyEmitter();
        $requests = new class implements RequestFactoryInterface {
            public function fromGlobals(): ServerRequestInterface
            {
                throw new RuntimeException('cannot capture');
            }
        };
        $runtime = $this->runtimeWithFloorSinks($requests, $emitter);

        $runtime->serve($this->handlerReturning($this->http->createResponse(200)));

        self::assertSame([500], $this->statusCalls);
        self::assertSame(['Internal Server Error'], $this->outputCalls);
        self::assertSame(0, $emitter->calls);
    }

    #[Test]
    public function emitsRawFiveHundredWhenEmitterThrows(): void
    {
        $emitter = new class implements EmitterInterface {
            public function emit(ResponseInterface $response): void
            {
                throw new EmitterException('output already started');
            }
        };
        $runtime = $this->runtimeWithFloorSinks($this->requestsReturning(), $emitter);

        $runtime->serve($this->handlerReturning($this->http->createResponse(200)));

        self::assertSame([500], $this->statusCalls);
        self::assertSame(['Internal Server Error'], $this->outputCalls);
    }

    #[Test]
    public function skipsStatusInFloorWhenHeadersAlreadySent(): void
    {
        $emitter = new class implements EmitterInterface {
            public function emit(ResponseInterface $response): void
            {
                throw new EmitterException('output already started');
            }
        };

        $runtime = new SapiServer(
            $this->requestsReturning(),
            $emitter,
            statusSink: function (int $code): void {
                $this->statusCalls[] = $code;
            },
            outputSink: function (string $output): void {
                $this->outputCalls[] = $output;
            },
            headersSentProbe: static fn(): bool => true,
        );

        $runtime->serve($this->handlerReturning($this->http->createResponse(200)));

        self::assertSame([], $this->statusCalls);
        self::assertSame(['Internal Server Error'], $this->outputCalls);
    }

    private function runtimeWithFloorSinks(RequestFactoryInterface $requests, EmitterInterface $emitter): SapiServer
    {
        return new SapiServer(
            $requests,
            $emitter,
            statusSink: function (int $code): void {
                $this->statusCalls[] = $code;
            },
            outputSink: function (string $output): void {
                $this->outputCalls[] = $output;
            },
        );
    }

    private function requestsReturning(): RequestFactoryInterface
    {
        $request = $this->http->createServerRequest('GET', '/');

        return new class ($request) implements RequestFactoryInterface {
            public function __construct(private readonly ServerRequestInterface $request) {}

            public function fromGlobals(): ServerRequestInterface
            {
                return $this->request;
            }
        };
    }

    private function handlerReturning(ResponseInterface $response): RequestHandlerInterface
    {
        return new class ($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    private function handlerThrowing(Throwable $error): RequestHandlerInterface
    {
        return new class ($error) implements RequestHandlerInterface {
            public function __construct(private readonly Throwable $error) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->error;
            }
        };
    }

    private function spyEmitter(): EmitterInterface
    {
        return new class implements EmitterInterface {
            public int $calls = 0;
            public ResponseInterface|null $emitted = null;

            public function emit(ResponseInterface $response): void
            {
                $this->calls++;
                $this->emitted = $response;
            }
        };
    }
}
