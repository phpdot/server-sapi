<?php

declare(strict_types=1);

namespace PHPdot\Server\Sapi\Tests\Unit;

use PHPdot\Http\ResponseFactory;
use PHPdot\Server\Sapi\RequestFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequestFactoryTest extends TestCase
{
    private ResponseFactory $http;
    private RequestFactory $factory;

    protected function setUp(): void
    {
        $this->http = new ResponseFactory();
        $this->factory = new RequestFactory($this->http, $this->http, $this->http, $this->http);
    }

    #[Test]
    public function capturesMethod(): void
    {
        $request = $this->factory->fromArrays($this->baseServer(['REQUEST_METHOD' => 'DELETE']));

        self::assertSame('DELETE', $request->getMethod());
    }

    #[Test]
    public function defaultsMethodToGet(): void
    {
        $request = $this->factory->fromArrays(['HTTP_HOST' => 'example.com', 'REQUEST_URI' => '/']);

        self::assertSame('GET', $request->getMethod());
    }

    #[Test]
    public function buildsSecureUriWithHostPortPathAndQuery(): void
    {
        $request = $this->factory->fromArrays([
            'REQUEST_METHOD' => 'GET',
            'HTTPS' => 'on',
            'HTTP_HOST' => 'example.com:8443',
            'REQUEST_URI' => '/users/42?page=1',
        ]);

        $uri = $request->getUri();
        self::assertSame('https', $uri->getScheme());
        self::assertSame('example.com', $uri->getHost());
        self::assertSame(8443, $uri->getPort());
        self::assertSame('/users/42', $uri->getPath());
        self::assertSame('page=1', $uri->getQuery());
    }

    #[Test]
    public function buildsUriFromServerNameAndPort(): void
    {
        $request = $this->factory->fromArrays([
            'REQUEST_METHOD' => 'GET',
            'SERVER_NAME' => 'host.local',
            'SERVER_PORT' => '8080',
            'REQUEST_URI' => '/path',
        ]);

        $uri = $request->getUri();
        self::assertSame('http', $uri->getScheme());
        self::assertSame('host.local', $uri->getHost());
        self::assertSame(8080, $uri->getPort());
        self::assertSame('/path', $uri->getPath());
    }

    #[Test]
    public function capturesQueryParams(): void
    {
        $request = $this->factory->fromArrays(
            $this->baseServer(),
            query: ['page' => '2', 'q' => 'php'],
        );

        self::assertSame(['page' => '2', 'q' => 'php'], $request->getQueryParams());
    }

    #[Test]
    public function capturesParsedBody(): void
    {
        $request = $this->factory->fromArrays(
            $this->baseServer(['REQUEST_METHOD' => 'POST']),
            parsedBody: ['email' => 'a@b.com'],
        );

        self::assertSame(['email' => 'a@b.com'], $request->getParsedBody());
    }

    #[Test]
    public function leavesParsedBodyNullWhenNotProvided(): void
    {
        $request = $this->factory->fromArrays($this->baseServer());

        self::assertNull($request->getParsedBody());
    }

    #[Test]
    public function capturesCookies(): void
    {
        $request = $this->factory->fromArrays(
            $this->baseServer(),
            cookies: ['session_id' => 'abc123'],
        );

        self::assertSame(['session_id' => 'abc123'], $request->getCookieParams());
    }

    #[Test]
    public function exposesServerParams(): void
    {
        $server = $this->baseServer(['CUSTOM_KEY' => 'value']);

        $request = $this->factory->fromArrays($server);

        self::assertSame($server, $request->getServerParams());
    }

    #[Test]
    public function capturesProtocolVersion(): void
    {
        $request = $this->factory->fromArrays($this->baseServer(['SERVER_PROTOCOL' => 'HTTP/2']));

        self::assertSame('2', $request->getProtocolVersion());
    }

    #[Test]
    public function derivesHeadersFromServerWhenNoneProvided(): void
    {
        $request = $this->factory->fromArrays([
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'example.com',
            'REQUEST_URI' => '/',
            'HTTP_X_CUSTOM' => 'custom-value',
            'HTTP_ACCEPT_ENCODING' => 'gzip',
            'CONTENT_TYPE' => 'application/json',
        ]);

        self::assertSame('custom-value', $request->getHeaderLine('X-Custom'));
        self::assertSame('gzip', $request->getHeaderLine('Accept-Encoding'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('example.com', $request->getHeaderLine('Host'));
    }

    #[Test]
    public function usesProvidedHeadersInsteadOfServerDerivation(): void
    {
        $request = $this->factory->fromArrays(
            [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST' => 'example.com',
                'REQUEST_URI' => '/',
                'HTTP_X_IGNORED' => 'ignored',
            ],
            headers: ['X-Provided' => 'yes'],
        );

        self::assertSame('yes', $request->getHeaderLine('X-Provided'));
        self::assertSame('', $request->getHeaderLine('X-Ignored'));
    }

    #[Test]
    public function readsRawBodyString(): void
    {
        $request = $this->factory->fromArrays(
            $this->baseServer(['REQUEST_METHOD' => 'POST']),
            rawBody: '{"key":"value"}',
        );

        self::assertSame('{"key":"value"}', (string) $request->getBody());
    }

    #[Test]
    public function normalizesUploadedFiles(): void
    {
        $request = $this->factory->fromArrays(
            $this->baseServer(['REQUEST_METHOD' => 'POST']),
            files: [
                'avatar' => [
                    'name' => 'a.txt',
                    'type' => 'text/plain',
                    'tmp_name' => '',
                    'error' => UPLOAD_ERR_NO_FILE,
                    'size' => 0,
                ],
            ],
        );

        $files = $request->getUploadedFiles();
        self::assertArrayHasKey('avatar', $files);
        self::assertSame('a.txt', $files['avatar']->getClientFilename());
    }

    #[Test]
    public function detectsHttpsFromPort443(): void
    {
        $request = $this->factory->fromArrays([
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'secure.test',
            'SERVER_PORT' => '443',
            'REQUEST_URI' => '/',
        ]);

        self::assertSame('https', $request->getUri()->getScheme());
    }

    #[Test]
    public function treatsHttpsOffAsPlainHttp(): void
    {
        $request = $this->factory->fromArrays([
            'REQUEST_METHOD' => 'GET',
            'HTTPS' => 'off',
            'HTTP_HOST' => 'plain.test',
            'REQUEST_URI' => '/',
        ]);

        self::assertSame('http', $request->getUri()->getScheme());
    }

    #[Test]
    public function fallsBackToServerAddrForHost(): void
    {
        $request = $this->factory->fromArrays([
            'REQUEST_METHOD' => 'GET',
            'SERVER_ADDR' => '10.0.0.5',
            'REQUEST_URI' => '/',
        ]);

        self::assertSame('10.0.0.5', $request->getUri()->getHost());
    }

    #[Test]
    public function defaultsHostToLocalhost(): void
    {
        $request = $this->factory->fromArrays([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
        ]);

        self::assertSame('localhost', $request->getUri()->getHost());
    }

    #[Test]
    public function omitsDefaultPort80(): void
    {
        $request = $this->factory->fromArrays([
            'REQUEST_METHOD' => 'GET',
            'SERVER_NAME' => 'host.local',
            'SERVER_PORT' => '80',
            'REQUEST_URI' => '/',
        ]);

        $uri = $request->getUri();
        self::assertSame('host.local', $uri->getHost());
        self::assertNull($uri->getPort());
    }

    #[Test]
    public function buildsTargetFromPhpSelfAndQueryStringWithoutRequestUri(): void
    {
        $request = $this->factory->fromArrays([
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'example.com',
            'PHP_SELF' => '/index.php',
            'QUERY_STRING' => 'a=1&b=2',
        ]);

        $uri = $request->getUri();
        self::assertSame('/index.php', $uri->getPath());
        self::assertSame('a=1&b=2', $uri->getQuery());
    }

    #[Test]
    public function defaultsProtocolVersionWhenServerProtocolUnusable(): void
    {
        $request = $this->factory->fromArrays([
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'example.com',
            'REQUEST_URI' => '/',
            'SERVER_PROTOCOL' => 'SPDY/3',
        ]);

        self::assertSame('1.1', $request->getProtocolVersion());
    }

    #[Test]
    public function acceptsRawBodyAsStream(): void
    {
        $stream = $this->http->createStream('stream-body');

        $request = $this->factory->fromArrays(
            $this->baseServer(['REQUEST_METHOD' => 'POST']),
            rawBody: $stream,
        );

        self::assertSame($stream, $request->getBody());
        self::assertSame('stream-body', (string) $request->getBody());
    }

    #[Test]
    public function acceptsRawBodyAsResource(): void
    {
        $resource = fopen('php://temp', 'r+');
        self::assertIsResource($resource);
        fwrite($resource, 'resource-body');

        $request = $this->factory->fromArrays(
            $this->baseServer(['REQUEST_METHOD' => 'POST']),
            rawBody: $resource,
        );

        self::assertSame('resource-body', (string) $request->getBody());
    }

    #[Test]
    public function derivesContentLengthHeaderFromServer(): void
    {
        $request = $this->factory->fromArrays([
            'REQUEST_METHOD' => 'POST',
            'HTTP_HOST' => 'example.com',
            'REQUEST_URI' => '/',
            'CONTENT_LENGTH' => '42',
        ]);

        self::assertSame('42', $request->getHeaderLine('Content-Length'));
    }

    #[Test]
    public function fromGlobalsReadsSuperglobals(): void
    {
        $originalServer = $_SERVER;
        $originalGet = $_GET;
        $originalPost = $_POST;
        $originalCookie = $_COOKIE;
        $originalFiles = $_FILES;

        try {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['HTTP_HOST'] = 'globals.test';
            $_SERVER['REQUEST_URI'] = '/from-globals?x=1';
            $_GET = ['x' => '1'];
            $_POST = [];
            $_COOKIE = ['c' => 'v'];
            $_FILES = [];

            $request = $this->factory->fromGlobals();

            self::assertSame('GET', $request->getMethod());
            self::assertSame('globals.test', $request->getUri()->getHost());
            self::assertSame('/from-globals', $request->getUri()->getPath());
            self::assertSame(['x' => '1'], $request->getQueryParams());
            self::assertSame(['c' => 'v'], $request->getCookieParams());
        } finally {
            $_SERVER = $originalServer;
            $_GET = $originalGet;
            $_POST = $originalPost;
            $_COOKIE = $originalCookie;
            $_FILES = $originalFiles;
        }
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function baseServer(array $overrides = []): array
    {
        return array_merge(
            [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST' => 'example.com',
                'REQUEST_URI' => '/',
                'SERVER_PROTOCOL' => 'HTTP/1.1',
            ],
            $overrides,
        );
    }
}
