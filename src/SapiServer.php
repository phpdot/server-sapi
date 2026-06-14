<?php

declare(strict_types=1);

namespace PHPdot\Server\Sapi;

use Closure;
use PHPdot\Container\Attribute\Binds;
use PHPdot\Container\Attribute\Singleton;
use PHPdot\Contracts\Server\ServerInterface;
use PHPdot\Server\Sapi\Contract\EmitterInterface;
use PHPdot\Server\Sapi\Contract\RequestFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * SapiServer.
 *
 * The one-shot entry point for a classic-SAPI front controller: capture the
 * request from the environment, run it through a PSR-15 handler, and emit the
 * response. A last-resort catch turns any uncaught throwable into a minimal raw
 * 500 so a misconfiguration never white-screens — it does not render or log;
 * real error handling belongs in middleware or phpdot/error-handler. The catch
 * is a floor, not a feature, and depends on nothing that may have just failed.
 *
 * Implements the shared ServerInterface, so an application can type-hint
 * ServerInterface and swap between this and SwooleServer with no other change.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
#[Singleton]
#[Binds(ServerInterface::class)]
final class SapiServer implements ServerInterface
{
    /** @var Closure(int): void Emits the raw status code for the last-resort 500 */
    private readonly Closure $statusSink;

    /** @var Closure(string): void Emits the raw body for the last-resort 500 */
    private readonly Closure $outputSink;

    /** @var Closure(): bool Probes whether output has already started */
    private readonly Closure $headersSentProbe;

    /**
     * Create a new SapiServer.
     *
     * @param RequestFactoryInterface $requests Captures the server request
     * @param EmitterInterface $emitter Writes the response to the SAPI
     * @param (callable(int): void)|null $statusSink Floor status emitter (defaults to http_response_code())
     * @param (callable(string): void)|null $outputSink Floor body emitter (defaults to echo)
     * @param (callable(): bool)|null $headersSentProbe headers_sent() probe for the floor
     */
    public function __construct(
        private readonly RequestFactoryInterface $requests,
        private readonly EmitterInterface $emitter,
        callable|null $statusSink = null,
        callable|null $outputSink = null,
        callable|null $headersSentProbe = null,
    ) {
        $this->statusSink = $statusSink !== null
            ? Closure::fromCallable($statusSink)
            : static function (int $code): void {
                http_response_code($code);
            };

        $this->outputSink = $outputSink !== null
            ? Closure::fromCallable($outputSink)
            : static function (string $output): void {
                echo $output;
            };

        $this->headersSentProbe = $headersSentProbe !== null
            ? Closure::fromCallable($headersSentProbe)
            : static function (): bool {
                return headers_sent();
            };
    }

    /**
     * Serve one request with the given PSR-15 handler.
     *
     * Under the classic SAPI this handles exactly one request and returns — the
     * web server (PHP-FPM, Apache, php -S) provides the request loop.
     *
     * @param RequestHandlerInterface $handler The PSR-15 handler for the application
     */
    public function serve(RequestHandlerInterface $handler): void
    {
        try {
            $request = $this->requests->fromGlobals();
            $response = $handler->handle($request);
            $this->emitter->emit($response);
        } catch (Throwable) {
            $this->emitLastResort();
        }
    }

    /**
     * Emit a minimal raw 500 — the floor when the normal path fails.
     */
    private function emitLastResort(): void
    {
        if (!($this->headersSentProbe)()) {
            ($this->statusSink)(500);
        }

        ($this->outputSink)('Internal Server Error');
    }
}
