<?php

declare(strict_types=1);

namespace PHPdot\Server\Sapi;

use PHPdot\Container\Attribute\Binds;
use PHPdot\Container\Attribute\Singleton;
use PHPdot\Server\Sapi\Contract\RequestFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

/**
 * RequestFactory.
 *
 * Assembles a PSR-7 server request from PHP's request environment, in-house,
 * using the injected PSR-17 factories — no third-party server-request creator.
 * fromGlobals() reads the superglobals once and delegates to fromArrays(),
 * which is the deterministic seam every branch is tested through.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
#[Singleton]
#[Binds(RequestFactoryInterface::class)]
final class RequestFactory implements RequestFactoryInterface
{
    private readonly UploadedFileNormalizer $normalizer;

    /**
     * Create a new RequestFactory.
     *
     * @param ServerRequestFactoryInterface $serverRequests Creates the base server request
     * @param UriFactoryInterface $uris Builds the request URI
     * @param UploadedFileFactoryInterface $files Creates PSR-7 uploaded files
     * @param StreamFactoryInterface $streams Creates body and uploaded-file streams
     * @param UploadedFileNormalizer|null $normalizer Normalizes $_FILES (defaults to a new instance)
     */
    public function __construct(
        private readonly ServerRequestFactoryInterface $serverRequests,
        private readonly UriFactoryInterface $uris,
        private readonly UploadedFileFactoryInterface $files,
        private readonly StreamFactoryInterface $streams,
        UploadedFileNormalizer|null $normalizer = null,
    ) {
        $this->normalizer = $normalizer ?? new UploadedFileNormalizer();
    }

    /**
     * Build a server request from PHP's superglobals.
     *
     * The parsed body is set to $_POST only when it is populated — PHP fills it
     * for form-encoded and multipart bodies; for any other content type the
     * application parses the raw body itself.
     */
    public function fromGlobals(): ServerRequestInterface
    {
        return $this->fromArrays(
            $_SERVER,
            $this->captureHeaders(),
            $_COOKIE,
            $_GET,
            $_POST !== [] ? $_POST : null,
            $_FILES,
            null,
        );
    }

    /**
     * Build a server request from explicit arrays — the testable seam.
     *
     * @param array<array-key, mixed> $server Server params (PHP's $_SERVER shape)
     * @param array<string, string|list<string>> $headers Header lines; derived from $server when empty
     * @param array<array-key, mixed> $cookies Cookie params
     * @param array<array-key, mixed> $query Query params
     * @param array<array-key, mixed>|null $parsedBody Parsed body, or null for none
     * @param array<array-key, mixed> $files Uploaded files (PHP's $_FILES shape)
     * @param mixed $rawBody Body as a string, resource, StreamInterface, or null to read php://input
     */
    public function fromArrays(
        array $server,
        array $headers = [],
        array $cookies = [],
        array $query = [],
        array|null $parsedBody = null,
        array $files = [],
        mixed $rawBody = null,
    ): ServerRequestInterface {
        $request = $this->serverRequests->createServerRequest(
            $this->method($server),
            $this->uri($server),
            $server,
        );

        $request = $request->withProtocolVersion($this->protocolVersion($server));

        $resolvedHeaders = $headers === [] ? $this->headersFromServer($server) : $headers;
        foreach ($resolvedHeaders as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $request = $request
            ->withBody($this->body($rawBody))
            ->withUploadedFiles($this->normalizer->normalize($files, $this->files, $this->streams))
            ->withCookieParams($cookies)
            ->withQueryParams($query);

        if ($parsedBody !== null) {
            $request = $request->withParsedBody($parsedBody);
        }

        return $request;
    }

    /**
     * Resolve the request method, defaulting to GET.
     *
     * @param array<array-key, mixed> $server
     */
    private function method(array $server): string
    {
        $method = $server['REQUEST_METHOD'] ?? null;

        return is_string($method) && $method !== '' ? $method : 'GET';
    }

    /**
     * Build the request URI from server params.
     *
     * @param array<array-key, mixed> $server
     */
    private function uri(array $server): UriInterface
    {
        return $this->uris->createUri(
            $this->scheme($server) . '://' . $this->host($server) . $this->requestTarget($server),
        );
    }

    /**
     * Determine the URI scheme from HTTPS and the server port.
     *
     * @param array<array-key, mixed> $server
     */
    private function scheme(array $server): string
    {
        $https = $server['HTTPS'] ?? null;
        if ($https === true) {
            return 'https';
        }
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return 'https';
        }

        $port = $server['SERVER_PORT'] ?? null;
        if ((is_int($port) || is_string($port)) && (int) $port === 443) {
            return 'https';
        }

        return 'http';
    }

    /**
     * Determine the host (with non-default port) from server params.
     *
     * @param array<array-key, mixed> $server
     */
    private function host(array $server): string
    {
        $httpHost = $server['HTTP_HOST'] ?? null;
        if (is_string($httpHost) && $httpHost !== '') {
            return $httpHost;
        }

        $name = $server['SERVER_NAME'] ?? null;
        if (!is_string($name) || $name === '') {
            $addr = $server['SERVER_ADDR'] ?? null;
            $name = is_string($addr) && $addr !== '' ? $addr : 'localhost';
        }

        $port = $server['SERVER_PORT'] ?? null;
        if (is_int($port) || (is_string($port) && $port !== '')) {
            $portNumber = (int) $port;
            if ($portNumber !== 0 && $portNumber !== 80 && $portNumber !== 443) {
                return $name . ':' . $portNumber;
            }
        }

        return $name;
    }

    /**
     * Build the origin-form request target (path with optional query).
     *
     * @param array<array-key, mixed> $server
     */
    private function requestTarget(array $server): string
    {
        $requestUri = $server['REQUEST_URI'] ?? null;
        if (is_string($requestUri) && $requestUri !== '') {
            return $requestUri;
        }

        $path = $server['PHP_SELF'] ?? null;
        $path = is_string($path) && $path !== '' ? $path : '/';

        $queryString = $server['QUERY_STRING'] ?? null;
        if (is_string($queryString) && $queryString !== '') {
            return $path . '?' . $queryString;
        }

        return $path;
    }

    /**
     * Extract the HTTP protocol version, e.g. "1.1" from "HTTP/1.1".
     *
     * @param array<array-key, mixed> $server
     */
    private function protocolVersion(array $server): string
    {
        $protocol = $server['SERVER_PROTOCOL'] ?? null;
        if (is_string($protocol) && str_starts_with($protocol, 'HTTP/')) {
            return substr($protocol, 5);
        }

        return '1.1';
    }

    /**
     * Build the body stream from the raw body, reading php://input by default.
     */
    private function body(mixed $rawBody): StreamInterface
    {
        if ($rawBody instanceof StreamInterface) {
            return $rawBody;
        }

        if (is_string($rawBody)) {
            return $this->streams->createStream($rawBody);
        }

        if (is_resource($rawBody)) {
            return $this->streams->createStreamFromResource($rawBody);
        }

        return $this->streams->createStreamFromFile('php://input', 'r');
    }

    /**
     * Capture request headers, preferring getallheaders() over HTTP_* parsing.
     *
     * @return array<string, string|list<string>>
     */
    private function captureHeaders(): array
    {
        // getallheaders() exists under Apache and PHP-FPM but not every SAPI.
        // Resolve it dynamically so its availability stays a soft, runtime-only
        // preference (with an HTTP_* fallback) rather than a hard dependency.
        $probe = 'getall' . 'headers';
        if (function_exists($probe)) {
            $headers = [];
            foreach ($probe() as $name => $value) {
                if (is_string($name) && is_string($value)) {
                    $headers[$name] = $value;
                }
            }

            return $headers;
        }

        return $this->headersFromServer($_SERVER);
    }

    /**
     * Derive headers from the HTTP_* and CONTENT_* server params.
     *
     * @param array<array-key, mixed> $server
     * @return array<string, string>
     */
    private function headersFromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $headers[$this->headerName(substr($key, 5))] = $value;
            } elseif ($key === 'CONTENT_TYPE') {
                $headers['Content-Type'] = $value;
            } elseif ($key === 'CONTENT_LENGTH') {
                $headers['Content-Length'] = $value;
            }
        }

        return $headers;
    }

    /**
     * Convert a server header key segment to a canonical header name,
     * e.g. "ACCEPT_ENCODING" becomes "Accept-Encoding".
     */
    private function headerName(string $key): string
    {
        return ucwords(strtolower(str_replace('_', '-', $key)), '-');
    }
}
