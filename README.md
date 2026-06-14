# phpdot/server-sapi

Classic-SAPI runtime adapter for **PSR-15** — the counterpart to
[`phpdot/server-swoole`](https://github.com/phpdot/server-swoole) for **PHP-FPM**,
**Apache `mod_php`**, and **`php -S`**. It captures the PHP request environment as a
PSR-7 `ServerRequestInterface`, runs it through your PSR-15
`RequestHandlerInterface`, and emits the PSR-7 `ResponseInterface` back to the SAPI
**correctly**. It's runtime glue — nothing more.

Request capture is built **in-house** from your own injected PSR-17 factories, so
the only dependencies are **interface-only packages** (PSR + `phpdot/contracts`) —
no third-party server-request creator to drag in. Bring any PSR-7/PSR-17
implementation (the framework uses [`phpdot/http`](https://github.com/phpdot/http));
the concrete objects are whatever your factories produce. PHP 8.3+.

## Install

```bash
composer require phpdot/server-sapi
```

| Requirement | Version | Notes |
|---|---|---|
| PHP | >= 8.3 | |
| psr/http-message | ^2.0 | PSR-7 interfaces |
| psr/http-factory | ^1.0 | PSR-17 factory interfaces |
| psr/http-server-handler | ^1.0 | PSR-15 `RequestHandlerInterface` |
| phpdot/contracts | ^1.8 | shared `ServerInterface` (interface-only) |
| A PSR-7/PSR-17 implementation | — | e.g. `phpdot/http`; supplies the concrete objects via your factories |

## Quick Start

A standalone front controller is a handful of lines:

```php
use PHPdot\Http\ResponseFactory;        // any PSR-7 / PSR-17 implementation
use PHPdot\Server\Sapi\RequestFactory;
use PHPdot\Server\Sapi\SapiEmitter;
use PHPdot\Server\Sapi\SapiServer;

$http = new ResponseFactory();          // phpdot/http satisfies all five PSR-17 factories

$server = new SapiServer(
    new RequestFactory($http, $http, $http, $http),
    new SapiEmitter(),
);

// $handler is any PSR-15 RequestHandlerInterface — your router / middleware pipeline.
$server->serve($handler);
```

Inside the phpdot framework you never wire this by hand — every class autowires,
so the front controller collapses to `$container->get(SapiServer::class)->serve($router)`.
See [DI Wiring](#di-wiring).

## Why This Package

- **Interface-only dependencies.** Beyond the PSR interface packages and
  `phpdot/contracts` (pure interfaces too), there is nothing. `fromGlobals()` is
  built in-house (~one focused class), so the package is genuinely usable standalone
  and never forces a specific PSR-7 implementation on you.
- **Correct emission.** A `headers_sent()` guard runs *first* and fails fast — you
  get an exception with the offending `file:line`, never a half-written body.
  Repeated `Set-Cookie` headers survive, the body streams in fixed-size chunks, and
  there is no body for `204`, `304`, or `HEAD`.
- **The fiddly corner is isolated.** PHP's transposed nested `$_FILES` shape
  (`name[a][b][]`) lives behind `UploadedFileNormalizer` with its own tests — the
  one historically error-prone part of request capture, contained.
- **Deterministically testable.** `fromArrays()` and injectable sinks mean every
  branch is exercised without touching a superglobal or calling native `header()`.
- **Swappable runtime.** `SapiServer` implements the shared `ServerInterface` (from
  `phpdot/contracts`) — the same interface `phpdot/server-swoole`'s `SwooleServer`
  implements — so the same app moves between SAPI and Swoole by changing one binding.
- **Framework-agnostic.** The `#[Singleton]`/`#[Binds]` attributes are inert at
  runtime; `phpdot/container` is a dev-only dependency.
- **Strict.** `declare(strict_types=1)` everywhere, `final` classes, PHPStan
  **level 10** with strict rules, zero ignored errors.

## Architecture

```
src/
  SapiServer.php               #[Singleton] #[Binds(ServerInterface)] — inject this; serve(handler) is the API
  RequestFactory.php           #[Singleton] #[Binds(RequestFactoryInterface)] — environment → PSR-7
  SapiEmitter.php              #[Singleton] #[Binds(EmitterInterface)] — PSR-7 → SAPI
  UploadedFileNormalizer.php   flattens PHP's nested $_FILES into PSR-7 uploaded files
  Contract/
    RequestFactoryInterface.php   capture swap point (fromGlobals)
    EmitterInterface.php          emission swap point (emit)
  Exception/
    ServerSapiException.php         base exception
    EmitterException.php           output already started
```

`SapiServer` implements `PHPdot\Contracts\Server\ServerInterface::serve()`, the
contract shared with `phpdot/server-swoole`.

Flow — one request, start to finish:

```
$_SERVER · $_GET · $_POST · $_COOKIE · $_FILES · php://input
      │
      ▼  RequestFactory::fromGlobals()
ServerRequestInterface ─► PSR-15 RequestHandlerInterface (your app)
                                  │
                                  ▼  ResponseInterface
                          SapiEmitter::emit()
                                  │
                                  ▼
                   status line · headers · body ─► client
```

`SapiServer` ties the three together and wraps them in a last-resort `catch` that
emits a minimal raw `500` so a misconfiguration never white-screens. It does not
render or log — real error handling belongs in your middleware or
`phpdot/error-handler`. The catch is a floor, not a feature.

## API Reference

### SapiServer · implements ServerInterface (inject this)

`#[Singleton]` `#[Binds(ServerInterface::class)]`

| Method | Returns | Notes |
|---|---|---|
| `__construct(RequestFactoryInterface $requests, EmitterInterface $emitter, ?callable $statusSink = null, ?callable $outputSink = null, ?callable $headersSentProbe = null)` | — | The sinks/probe override the floor's native `http_response_code()`/`echo`/`headers_sent()` — for tests; production leaves them null |
| `serve(RequestHandlerInterface $handler)` | `void` | `ServerInterface::serve` — `fromGlobals()` → `handle()` → `emit()`, with a last-resort raw `500` floor |

### RequestFactory · implements RequestFactoryInterface

`#[Singleton]` `#[Binds(RequestFactoryInterface::class)]`

| Method | Returns | Notes |
|---|---|---|
| `__construct(ServerRequestFactoryInterface, UriFactoryInterface, UploadedFileFactoryInterface, StreamFactoryInterface, ?UploadedFileNormalizer = null)` | — | All four PSR-17 factories; in the framework they resolve to one `phpdot/http` instance |
| `fromGlobals()` | `ServerRequestInterface` | Reads the superglobals once and delegates to `fromArrays()` |
| `fromArrays(array $server, array $headers = [], array $cookies = [], array $query = [], ?array $parsedBody = null, array $files = [], mixed $rawBody = null)` | `ServerRequestInterface` | The deterministic seam — every branch testable without a superglobal |

Capture covers: method, URI (scheme/host/port/path/query), protocol version,
headers (via `getallheaders()` with an `HTTP_*` fallback), cookies, query params,
parsed body, the `php://input` body stream, and uploaded files.

### SapiEmitter · implements EmitterInterface

`#[Singleton]` `#[Binds(EmitterInterface::class)]`

| Method | Returns | Notes |
|---|---|---|
| `__construct(int $bufferSize = 8192, ?callable $headerSink = null, ?callable $statusSink = null, ?callable $headersSentProbe = null, ?string $requestMethod = null)` | — | Sinks default to native `header()`/`headers_sent()`; override for testing |
| `emit(ResponseInterface $response)` | `void` | Throws `EmitterException` if output has already started |

Guarantees: fail-fast `headers_sent()` guard before any output · status line
`HTTP/{proto} {code} {reason}` · multi-value headers (repeated `Set-Cookie`) ·
chunked body streaming · no body for `204`/`304`/`HEAD`.

### UploadedFileNormalizer

| Method | Returns | Notes |
|---|---|---|
| `normalize(array $files, UploadedFileFactoryInterface $factory, StreamFactoryInterface $streams)` | `array` | `$_FILES` (single, flat `name[]`, or nested `name[a][b][]`) → a tree of PSR-7 `UploadedFileInterface` |

## Working Examples

### A controller-style PSR-15 handler

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class HelloHandler implements RequestHandlerInterface
{
    public function __construct(private readonly ResponseFactoryInterface $responses) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $name = $request->getQueryParams()['name'] ?? 'world';

        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'text/plain')
            ->withBody($this->responses->createStream("Hello, {$name}"));
    }
}
```

### Capturing without the superglobals (testing, CLI, custom SAPIs)

```php
$request = $requestFactory->fromArrays(
    server: ['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'api.test', 'REQUEST_URI' => '/users?page=2'],
    query: ['page' => '2'],
    parsedBody: ['email' => 'omar@phpdot.com'],
    rawBody: '{"email":"omar@phpdot.com"}',
);
```

## DI Wiring

Everything autowires — there is nothing to register. `RequestFactory`,
`SapiEmitter`, and `SapiServer` carry `#[Binds(...)]`, so `RequestFactoryInterface`,
`EmitterInterface`, and `ServerInterface` resolve to them; `SapiServer` depends only
on the first two. The four PSR-17 factory parameters on `RequestFactory` all resolve
to `phpdot/http`'s `ResponseFactory` (it binds all five PSR-17 interfaces), so the
graph wires itself.

Type-hint the shared `ServerInterface` and your front controller stays portable —
swapping `phpdot/server-sapi` for `phpdot/server-swoole` needs no change here:

```php
use PHPdot\Contracts\Server\ServerInterface;

final class FrontController
{
    public function __construct(private readonly ServerInterface $server) {}

    public function handle(RequestHandlerInterface $router): void
    {
        $this->server->serve($router);
    }
}
```

The attributes are inert without a container — standalone consumers just `new` the
classes as shown in [Quick Start](#quick-start).

## Development

```bash
composer test        # PHPUnit
composer analyse     # PHPStan level 10
composer cs-fix      # PHP-CS-Fixer
composer check       # all three (test + analyse + cs-check)
```

## License

MIT — see [LICENSE](LICENSE).
