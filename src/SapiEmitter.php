<?php

declare(strict_types=1);

namespace PHPdot\Server\Sapi;

use Closure;
use PHPdot\Container\Attribute\Binds;
use PHPdot\Container\Attribute\Singleton;
use PHPdot\Server\Sapi\Contract\EmitterInterface;
use PHPdot\Server\Sapi\Exception\EmitterException;
use Psr\Http\Message\ResponseInterface;

/**
 * SapiEmitter.
 *
 * Emits a PSR-7 response to the classic PHP SAPI (PHP-FPM, Apache mod_php,
 * php -S). It guards against output that has already started, writes the
 * status line and headers (with multi-value support so repeated Set-Cookie
 * survives), and streams the body in fixed-size chunks. The body is omitted
 * for 204, 304, and HEAD requests.
 *
 * Status-line, header, and headers-sent emission are routed through injectable
 * sinks, so every branch is testable without touching global state or calling
 * the native header() function.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
#[Singleton]
#[Binds(EmitterInterface::class)]
final class SapiEmitter implements EmitterInterface
{
    /** @var Closure(string, bool): void Emits a single header line */
    private readonly Closure $headerSink;

    /** @var Closure(string, int): void Emits the status line and code */
    private readonly Closure $statusSink;

    /** @var Closure(): array{0: bool, 1: string, 2: int} Probes whether output has started */
    private readonly Closure $headersSentProbe;

    /**
     * Create a new SapiEmitter.
     *
     * @param int $bufferSize Body chunk size in bytes
     * @param (callable(string, bool): void)|null $headerSink Header emitter (defaults to native header())
     * @param (callable(string, int): void)|null $statusSink Status-line emitter (defaults to native header())
     * @param (callable(): array{0: bool, 1: string, 2: int})|null $headersSentProbe headers_sent() probe
     * @param string|null $requestMethod Request method override (defaults to $_SERVER at emit time)
     */
    public function __construct(
        private readonly int $bufferSize = 8192,
        callable|null $headerSink = null,
        callable|null $statusSink = null,
        callable|null $headersSentProbe = null,
        private readonly string|null $requestMethod = null,
    ) {
        $this->headerSink = $headerSink !== null
            ? Closure::fromCallable($headerSink)
            : static function (string $header, bool $replace): void {
                header($header, $replace);
            };

        $this->statusSink = $statusSink !== null
            ? Closure::fromCallable($statusSink)
            : static function (string $statusLine, int $code): void {
                header($statusLine, true, $code);
            };

        $this->headersSentProbe = $headersSentProbe !== null
            ? Closure::fromCallable($headersSentProbe)
            : static function (): array {
                $file = '';
                $line = 0;
                $sent = headers_sent($file, $line);

                return [$sent, $file, $line];
            };
    }

    /**
     * Emit a PSR-7 response to the SAPI.
     *
     * The headers-sent guard runs first and fails fast, so a misconfiguration
     * never produces a half-written response.
     *
     * @param ResponseInterface $response The response to send
     * @throws EmitterException If output has already been started
     */
    public function emit(ResponseInterface $response): void
    {
        [$sent, $file, $line] = ($this->headersSentProbe)();
        if ($sent) {
            throw EmitterException::headersAlreadySent($file, $line);
        }

        $this->emitStatusLine($response);
        $this->emitHeaders($response);

        if ($this->shouldOmitBody($response)) {
            return;
        }

        $this->emitBody($response);
    }

    /**
     * Emit the HTTP status line, e.g. "HTTP/1.1 201 Created".
     */
    private function emitStatusLine(ResponseInterface $response): void
    {
        $reason = $response->getReasonPhrase();
        $statusLine = sprintf(
            'HTTP/%s %d%s',
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            $reason === '' ? '' : ' ' . $reason,
        );

        ($this->statusSink)($statusLine, $response->getStatusCode());
    }

    /**
     * Emit all response headers.
     *
     * The first value of each header replaces any prior header of that name;
     * subsequent values are appended. Set-Cookie is never replaced, so every
     * cookie in the response is emitted.
     */
    private function emitHeaders(ResponseInterface $response): void
    {
        foreach ($response->getHeaders() as $name => $values) {
            $replace = strtolower($name) !== 'set-cookie';
            foreach ($values as $value) {
                ($this->headerSink)(sprintf('%s: %s', $name, $value), $replace);
                $replace = false;
            }
        }
    }

    /**
     * Stream the response body to the SAPI in fixed-size chunks.
     */
    private function emitBody(ResponseInterface $response): void
    {
        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (!$body->eof()) {
            $chunk = $body->read($this->bufferSize);
            if ($chunk === '') {
                break;
            }

            echo $chunk;
            flush();
        }
    }

    /**
     * Whether the body must be suppressed: 204, 304, or a HEAD request.
     */
    private function shouldOmitBody(ResponseInterface $response): bool
    {
        $status = $response->getStatusCode();
        if ($status === 204 || $status === 304) {
            return true;
        }

        return strtoupper($this->resolveRequestMethod()) === 'HEAD';
    }

    /**
     * Resolve the request method from the injected override or $_SERVER.
     */
    private function resolveRequestMethod(): string
    {
        if ($this->requestMethod !== null) {
            return $this->requestMethod;
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        return is_string($method) ? $method : 'GET';
    }
}
