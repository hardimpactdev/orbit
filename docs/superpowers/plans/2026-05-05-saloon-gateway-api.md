# Saloon Gateway API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Orbit's hand-rolled gateway HTTP abstraction (`GatewayClient` + `GatewayRequest` interface + `GatewayRequestSender` + `GatewayResponse` + `GatewayResponseParser`) with [Saloon v3](https://docs.saloon.dev/), using a single `GatewayConnector`, idiomatic Saloon `Request` subclasses per endpoint, and typed response DTOs.

**Architecture:**
- One `GatewayConnector` (Saloon Connector) holds base URL, CA verify path, correlation header plugin, default headers (Accept JSON, X-Orbit-Client), and timeouts. Resolved per-call from `LocalGatewaySettings::current()`.
- Abstract base `App\Http\Gateway\GatewayRequest` (extends `Saloon\Http\Request`) centralizes envelope handling: overrides `hasRequestFailed()` to detect the `error` envelope on any status, `getRequestException()` to throw a typed `GatewayApiException` carrying code/message/meta, and exposes a `protected unwrapData(Response $response): array` helper for concrete DTO factories.
- Each concrete request lives under `App\Http\Gateway\Requests\<Family>\` and implements `createDtoFromResponse(Response $response): mixed` returning a typed DTO from `App\Http\Gateway\Responses\<Family>\`.
- Callers go from `GatewayRequestSender::make()->send($request)->data()['nodes']` (array soup) → `app(GatewayConnector::class)->send($request)->dto()->nodes` (typed property access). Errors raise `GatewayApiException` instead of being conveyed via `$response->isSuccess() === false`.
- Strangler migration: old code stays alongside new during the port; deletion happens in a final task once every caller is migrated and old tests are gone.

**Tech Stack:**
- Saloon v3 (`saloonphp/saloon` `^3.0`)
- PHP 8.5, Laravel 13, Pest 4, Larastan 3
- Out of scope: `FetchGatewayRootCa` (bootstrap/pre-trust call — keeps using `Http` directly), `/api/me` (no clean-repo typed caller yet)

---

## File Structure

**New (Saloon transport):**
- `app/Http/Gateway/GatewayConnector.php` — Saloon Connector
- `app/Http/Gateway/GatewayRequest.php` — abstract base extending `Saloon\Http\Request`
- `app/Http/Gateway/GatewayApiException.php` — thrown on envelope error
- `app/Http/Gateway/Plugins/HasCorrelationHeader.php` — Saloon plugin trait
- `app/Http/Gateway/Requests/Nodes/ListNodesRequest.php`
- `app/Http/Gateway/Requests/Nodes/ShowNodeRequest.php`
- `app/Http/Gateway/Requests/Nodes/GrantNodeRequest.php`
- `app/Http/Gateway/Requests/Nodes/RevokeNodeRequest.php`
- `app/Http/Gateway/Requests/Nodes/RemoveNodeRequest.php`
- `app/Http/Gateway/Requests/Nodes/UpdateNodeRequest.php`
- `app/Http/Gateway/Requests/Nodes/DefaultNodeShowRequest.php`
- `app/Http/Gateway/Requests/Nodes/DefaultNodeSetRequest.php`
- `app/Http/Gateway/Requests/Nodes/DefaultNodeClearRequest.php`
- `app/Http/Gateway/Responses/Nodes/NodeListResponse.php`
- `app/Http/Gateway/Responses/Nodes/NodeShowResponse.php`
- `app/Http/Gateway/Responses/Nodes/NodeGrantResponse.php`
- `app/Http/Gateway/Responses/Nodes/NodeRevokeResponse.php`
- `app/Http/Gateway/Responses/Nodes/NodeRemoveResponse.php`
- `app/Http/Gateway/Responses/Nodes/NodeUpdateResponse.php`
- `app/Http/Gateway/Responses/Nodes/NodeDefaultResponse.php`

**Modified callers:**
- `app/Console/Commands/NodeListCommand.php`
- `app/Console/Commands/NodeShowCommand.php`
- `app/Console/Commands/NodeGrantCommand.php`
- `app/Console/Commands/NodeRevokeCommand.php`
- `app/Console/Commands/NodeRemoveCommand.php`
- `app/Console/Commands/NodeUpdateCommand.php`
- `app/Console/Commands/NodeDefaultCommand.php`
- `app/Providers/AppServiceProvider.php` (replace sender binding with connector binding)

**Deleted (final task, after every caller is migrated):**
- `app/Services/Gateway/GatewayClient.php`
- `app/Services/Gateway/GatewayRequest.php`
- `app/Services/Gateway/GatewayRequestSender.php`
- `app/Services/Gateway/GatewayResponse.php`
- `app/Services/Gateway/GatewayResponseParser.php`
- `app/Services/Gateway/Requests/*.php` (all 7 hand-rolled request DTOs)
- `tests/Feature/Services/Gateway/GatewayClientTest.php`
- `tests/Unit/Services/Gateway/GatewayRequestSenderTest.php`
- `tests/Unit/Services/Gateway/GatewayResponseParserTest.php`
- `tests/Unit/Services/Gateway/Requests/{ListNodes,ShowNode,GrantNode}RequestTest.php`

**Untouched (intentionally):**
- `app/Services/Gateway/FetchGatewayRootCa.php` and `RootCaFetchResult.php` — bootstrap path (no CA yet)
- `app/Services/Gateway/GatewayApiRuntimeInstaller.php` — server-side install, not a client
- `tests/Feature/Services/Gateway/FetchGatewayRootCaTest.php`, `GatewayApiRuntimeInstallerTest.php`

**Docs updated (final task):**
- `docs/PORTING.md` — reverse `GATEWAY-API-0` decision, document new rationale
- `docs/abstractions/cross-cutting.md` — replace transport pattern guidance

---

## Worktree Note

This is a sizeable refactor that touches 7 commands + introduces a new package. Run it in a worktree:

```bash
git worktree add ../orbit-saloon -b feat/saloon-gateway-api
cd ../orbit-saloon
```

If the engineer is already in a clean worktree, skip this.

---

### Task 1: Add Saloon dependency

**Files:**
- Modify: `composer.json` (require section)
- Modify: `composer.lock` (auto-generated)

- [ ] **Step 1: Install Saloon**

Run from repo root:

```bash
composer require saloonphp/saloon:^3.0
```

Expected output: `Generating optimized autoload files` and a new entry in `composer.lock`.

If `^3.0` fails to resolve against PHP 8.5 / Laravel 13, retry with `composer require saloonphp/saloon` (let composer pick the highest compatible) and note the resolved version in the commit message.

- [ ] **Step 2: Verify install**

```bash
php -r 'require "vendor/autoload.php"; var_dump(class_exists(Saloon\Http\Connector::class));'
```

Expected: `bool(true)`.

- [ ] **Step 3: Run quality check to confirm nothing broke**

```bash
php artisan test --compact
```

Expected: all tests still pass (no Saloon code in app yet).

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock
git commit -m "chore: add saloonphp/saloon dependency"
```

---

### Task 2: HasCorrelationHeader Saloon plugin

**Files:**
- Create: `app/Http/Gateway/Plugins/HasCorrelationHeader.php`
- Test: `tests/Unit/Http/Gateway/Plugins/HasCorrelationHeaderTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Http/Gateway/Plugins/HasCorrelationHeaderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Plugins;

use App\Http\Gateway\Plugins\HasCorrelationHeader;
use App\Services\ActivityLogCorrelation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Http\PendingRequest;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('adds X-Orbit-Request-Id header when correlation id is set', function (): void {
    $expected = '11111111-1111-1111-1111-111111111111';
    app(ActivityLogCorrelation::class)->start($expected);

    $trait = new class
    {
        use HasCorrelationHeader;
    };

    $pending = Mockery::mock(PendingRequest::class);
    $headers = Mockery::mock();
    $headers->shouldReceive('add')->with('X-Orbit-Request-Id', $expected)->once();
    $headers->shouldReceive('add')->with('X-Orbit-Client', 'cli')->once();
    $pending->shouldReceive('headers')->andReturn($headers);

    $trait->bootHasCorrelationHeader($pending);
});

it('omits X-Orbit-Request-Id header when no correlation id is active', function (): void {
    $trait = new class
    {
        use HasCorrelationHeader;
    };

    $pending = Mockery::mock(PendingRequest::class);
    $headers = Mockery::mock();
    $headers->shouldReceive('add')->with('X-Orbit-Client', 'cli')->once();
    $headers->shouldNotReceive('add')->with('X-Orbit-Request-Id', Mockery::any());
    $pending->shouldReceive('headers')->andReturn($headers);

    $trait->bootHasCorrelationHeader($pending);
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=HasCorrelationHeader
```

Expected: FAIL with "Class App\Http\Gateway\Plugins\HasCorrelationHeader not found".

- [ ] **Step 3: Create the plugin**

Create `app/Http/Gateway/Plugins/HasCorrelationHeader.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Gateway\Plugins;

use App\Services\ActivityLogCorrelation;
use Saloon\Http\PendingRequest;

trait HasCorrelationHeader
{
    public function bootHasCorrelationHeader(PendingRequest $pendingRequest): void
    {
        $uuid = app(ActivityLogCorrelation::class)->current();

        if (is_string($uuid) && $uuid !== '') {
            $pendingRequest->headers()->add('X-Orbit-Request-Id', $uuid);
        }

        $pendingRequest->headers()->add(
            'X-Orbit-Client',
            app()->runningInConsole() ? 'cli' : 'api',
        );
    }
}
```

Note: `ActivityLogCorrelation::current()` returns `?string`. Confirm signature in `app/Services/ActivityLogCorrelation.php`.

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test --compact --filter=HasCorrelationHeader
```

Expected: PASS, 2 tests.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Gateway/Plugins/HasCorrelationHeader.php tests/Unit/Http/Gateway/Plugins/HasCorrelationHeaderTest.php
git commit -m "feat(gateway): add HasCorrelationHeader Saloon plugin"
```

---

### Task 3: GatewayConnector

**Files:**
- Create: `app/Http/Gateway/GatewayConnector.php`
- Test: `tests/Unit/Http/Gateway/GatewayConnectorTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Http/Gateway/GatewayConnectorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway;

use App\Http\Gateway\GatewayConnector;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $settings = LocalGatewaySettings::current();
    $settings->gateway_url = 'https://10.6.0.2';
    $settings->ca_pem_path = '/path/to/ca.pem';
    $settings->save();
});

it('resolves base url from local gateway settings', function (): void {
    $connector = new GatewayConnector;

    expect($connector->resolveBaseUrl())->toBe('https://10.6.0.2');
});

it('configures verify, allow_redirects, and timeouts', function (): void {
    $connector = new GatewayConnector;
    $config = $connector->config()->all();

    expect($config)
        ->toHaveKey('verify', '/path/to/ca.pem')
        ->toHaveKey('allow_redirects', false)
        ->toHaveKey('timeout', 900)
        ->toHaveKey('connect_timeout', 10);
});

it('sends Accept: application/json by default', function (): void {
    $connector = new GatewayConnector;
    $headers = $connector->headers()->all();

    expect($headers)->toHaveKey('Accept', 'application/json');
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=GatewayConnectorTest
```

Expected: FAIL with "Class App\Http\Gateway\GatewayConnector not found".

- [ ] **Step 3: Create the connector**

Create `app/Http/Gateway/GatewayConnector.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Gateway;

use App\Http\Gateway\Plugins\HasCorrelationHeader;
use App\Models\LocalGatewaySettings;
use Saloon\Http\Connector;

final class GatewayConnector extends Connector
{
    use HasCorrelationHeader;

    public function resolveBaseUrl(): string
    {
        return LocalGatewaySettings::current()->gateway_url ?? '';
    }

    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        $settings = LocalGatewaySettings::current();

        return [
            'verify' => $settings->ca_pem_path,
            'allow_redirects' => false,
            'timeout' => 900,
            'connect_timeout' => 10,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test --compact --filter=GatewayConnectorTest
```

Expected: PASS, 3 tests.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Gateway/GatewayConnector.php tests/Unit/Http/Gateway/GatewayConnectorTest.php
git commit -m "feat(gateway): add Saloon GatewayConnector"
```

---

### Task 4: GatewayApiException + abstract GatewayRequest base

**Files:**
- Create: `app/Http/Gateway/GatewayApiException.php`
- Create: `app/Http/Gateway/GatewayRequest.php`
- Test: `tests/Unit/Http/Gateway/GatewayRequestTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Http/Gateway/GatewayRequestTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\GatewayRequest;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Response;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $settings = LocalGatewaySettings::current();
    $settings->gateway_url = 'https://10.6.0.2';
    $settings->ca_pem_path = '/path/to/ca.pem';
    $settings->save();
});

function makeProbeRequest(): GatewayRequest
{
    return new class extends GatewayRequest
    {
        protected Method $method = Method::GET;

        public function resolveEndpoint(): string
        {
            return '/api/probe';
        }

        public function createDtoFromResponse(Response $response): array
        {
            return $this->unwrapData($response);
        }
    };
}

it('unwraps success.data envelope into an array', function (): void {
    $mock = new MockClient([
        '*' => MockResponse::make([
            'success' => ['data' => ['ok' => true]],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(makeProbeRequest())->dto();

    expect($dto)->toBe(['ok' => true]);
});

it('treats top-level "doctor" payload as data without rewrapping', function (): void {
    $mock = new MockClient([
        '*' => MockResponse::make([
            'doctor' => ['issues' => 0],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(makeProbeRequest())->dto();

    expect($dto)->toBe(['doctor' => ['issues' => 0]]);
});

it('throws GatewayApiException on error envelope at HTTP 200', function (): void {
    $mock = new MockClient([
        '*' => MockResponse::make([
            'error' => [
                'code' => 'status_mismatch',
                'message' => 'HTTP 200 but envelope says error',
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    expect(fn () => $connector->send(makeProbeRequest())->dto())
        ->toThrow(GatewayApiException::class, 'HTTP 200 but envelope says error');
});

it('throws GatewayApiException with code/meta on error envelope at HTTP 4xx', function (): void {
    $mock = new MockClient([
        '*' => MockResponse::make([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'The given data was invalid.',
                'meta' => ['field' => 'name'],
            ],
        ], 422),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    try {
        $connector->send(makeProbeRequest())->dto();
        $this->fail('Expected GatewayApiException');
    } catch (GatewayApiException $e) {
        expect($e->getMessage())->toBe('The given data was invalid.');
        expect($e->errorCode())->toBe('validation_failed');
        expect($e->errorMeta())->toBe(['field' => 'name']);
    }
});

it('throws GatewayApiException on HTTP 5xx with non-JSON body', function (): void {
    $mock = new MockClient([
        '*' => MockResponse::make('<html>Service Unavailable</html>', 503),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    expect(fn () => $connector->send(makeProbeRequest())->dto())
        ->toThrow(GatewayApiException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=GatewayRequestTest
```

Expected: FAIL with "Class App\Http\Gateway\GatewayRequest not found".

- [ ] **Step 3: Create the exception**

Create `app/Http/Gateway/GatewayApiException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Gateway;

use RuntimeException;
use Throwable;

final class GatewayApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $errorMeta
     */
    public function __construct(
        string $message,
        private readonly ?string $errorCode = null,
        private readonly array $errorMeta = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function errorMeta(): array
    {
        return $this->errorMeta;
    }
}
```

- [ ] **Step 4: Create the abstract request base**

Create `app/Http/Gateway/GatewayRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Gateway;

use Saloon\Http\Request as SaloonRequest;
use Saloon\Http\Response;
use Throwable;

abstract class GatewayRequest extends SaloonRequest
{
    /**
     * Mark the request as failed if Saloon's HTTP failure detection trips,
     * OR the JSON envelope contains an "error" key (which can occur on 200).
     */
    public function hasRequestFailed(Response $response): ?bool
    {
        if ($response->failed()) {
            return true;
        }

        $body = $this->decodeBodyOrNull($response);

        return is_array($body) && array_key_exists('error', $body);
    }

    /**
     * Translate any failed response into a typed GatewayApiException.
     */
    public function getRequestException(Response $response, ?Throwable $senderException): ?Throwable
    {
        $body = $this->decodeBodyOrNull($response);

        if (is_array($body) && isset($body['error']) && is_array($body['error'])) {
            $error = $body['error'];
            $message = is_string($error['message'] ?? null) && $error['message'] !== ''
                ? $error['message']
                : "Gateway request failed with HTTP status {$response->status()}";
            $code = is_string($error['code'] ?? null) ? $error['code'] : null;
            $meta = is_array($error['meta'] ?? null) ? $error['meta'] : [];

            return new GatewayApiException($message, $code, $meta, $senderException);
        }

        return new GatewayApiException(
            "Gateway request failed with HTTP status {$response->status()}",
            null,
            [],
            $senderException,
        );
    }

    /**
     * Strip the gateway success envelope and return the raw data array.
     *
     * @return array<string, mixed>
     */
    protected function unwrapData(Response $response): array
    {
        $body = $this->decodeBodyOrNull($response);

        if (! is_array($body)) {
            throw new GatewayApiException('Gateway response is not valid JSON.');
        }

        if (array_key_exists('doctor', $body)) {
            return $body;
        }

        if (array_key_exists('success', $body)) {
            $success = $body['success'];

            if (is_array($success)) {
                $data = $success['data'] ?? [];

                return is_array($data) ? $data : [];
            }

            if ($success === true) {
                $data = $body['data'] ?? [];

                return is_array($data) ? $data : [];
            }
        }

        return $body;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeBodyOrNull(Response $response): ?array
    {
        $raw = $response->body();

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return $decoded;
        } catch (\JsonException) {
            return null;
        }
    }
}
```

- [ ] **Step 5: Run tests**

```bash
php artisan test --compact --filter=GatewayRequestTest
```

Expected: PASS, 5 tests.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Gateway/GatewayApiException.php app/Http/Gateway/GatewayRequest.php tests/Unit/Http/Gateway/GatewayRequestTest.php
git commit -m "feat(gateway): add Saloon GatewayRequest base + GatewayApiException"
```

---

### Task 5: Migrate ListNodesRequest

**Files:**
- Create: `app/Http/Gateway/Requests/Nodes/ListNodesRequest.php`
- Create: `app/Http/Gateway/Responses/Nodes/NodeListResponse.php`
- Modify: `app/Console/Commands/NodeListCommand.php`
- Test: `tests/Unit/Http/Gateway/Requests/Nodes/ListNodesRequestTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Http/Gateway/Requests/Nodes/ListNodesRequestTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Nodes\ListNodesRequest;
use App\Http\Gateway\Responses\Nodes\NodeListResponse;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $settings = LocalGatewaySettings::current();
    $settings->gateway_url = 'https://10.6.0.2';
    $settings->ca_pem_path = '/path/to/ca.pem';
    $settings->save();
});

it('resolves to GET /api/nodes', function (): void {
    $request = new ListNodesRequest;

    expect($request->resolveEndpoint())->toBe('/api/nodes');
    expect($request->getMethod())->toBe(Method::GET);
});

it('serializes role/environment/doctor as query parameters when provided', function (): void {
    $request = new ListNodesRequest(role: 'app', environment: 'production', doctor: true);

    expect($request->query()->all())->toBe([
        'role' => 'app',
        'environment' => 'production',
        'doctor' => true,
    ]);
});

it('omits null filters from the query', function (): void {
    $request = new ListNodesRequest(role: 'gateway');

    expect($request->query()->all())->toBe(['role' => 'gateway']);
});

it('returns a NodeListResponse DTO with nodes and meta', function (): void {
    $mock = new MockClient([
        ListNodesRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'nodes' => [
                        ['name' => 'gw-1', 'role' => 'gateway'],
                        ['name' => 'app-1', 'role' => 'app'],
                    ],
                ],
                'meta' => ['doctor' => ['issues' => 0]],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new ListNodesRequest)->dto();

    expect($dto)->toBeInstanceOf(NodeListResponse::class);
    expect($dto->nodes)->toBe([
        ['name' => 'gw-1', 'role' => 'gateway'],
        ['name' => 'app-1', 'role' => 'app'],
    ]);
    expect($dto->meta)->toBe(['doctor' => ['issues' => 0]]);
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=ListNodesRequestTest
```

Expected: FAIL on missing classes.

- [ ] **Step 3: Create the response DTO**

Create `app/Http/Gateway/Responses/Nodes/NodeListResponse.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Nodes;

final readonly class NodeListResponse
{
    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $nodes,
        public array $meta,
    ) {}
}
```

- [ ] **Step 4: Create the Saloon request**

Create `app/Http/Gateway/Requests/Nodes/ListNodesRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Nodes\NodeListResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListNodesRequest extends GatewayRequest
{
    protected Method $method = Method::GET;

    public function __construct(
        public readonly ?string $role = null,
        public readonly ?string $environment = null,
        public readonly bool $doctor = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/nodes';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter([
            'role' => $this->role,
            'environment' => $this->environment,
            'doctor' => $this->doctor ? true : null,
        ], static fn (mixed $v): bool => $v !== null);
    }

    public function createDtoFromResponse(Response $response): NodeListResponse
    {
        $data = $this->unwrapData($response);

        $envelopeMeta = $this->envelopeMeta($response);

        $nodes = $data['nodes'] ?? [];

        return new NodeListResponse(
            nodes: is_array($nodes) ? array_values($nodes) : [],
            meta: $envelopeMeta,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function envelopeMeta(Response $response): array
    {
        $body = $response->json();

        if (is_array($body) && isset($body['success']['meta']) && is_array($body['success']['meta'])) {
            return $body['success']['meta'];
        }

        return [];
    }
}
```

- [ ] **Step 5: Run request test**

```bash
php artisan test --compact --filter=ListNodesRequestTest
```

Expected: PASS, 4 tests.

- [ ] **Step 6: Refactor `NodeListCommand` to use the new request**

Modify `app/Console/Commands/NodeListCommand.php`:

Replace the `use` block lines 7-11 (replace `GatewayRequestSender`, `GatewayResponse`, and old `ListNodesRequest` imports) with:

```php
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Nodes\ListNodesRequest;
use App\Http\Gateway\Responses\Nodes\NodeListResponse;
use App\Models\Node;
use App\Services\Nodes\NodesDoctorSummary;
```

Replace the `fetchNodes` method body (the gateway branch, currently lines 107-123) with:

```php
private function fetchNodes(?string $role, ?string $environment, bool $doctor, NodesDoctorSummary $doctorSummary): array|GatewayApiException
{
    if ($this->isGatewayCaller()) {
        return $this->fetchLocalNodes(
            role: $role,
            environment: $environment,
            doctor: $doctor,
            doctorSummary: $doctorSummary,
        );
    }

    try {
        $dto = app(GatewayConnector::class)
            ->send(new ListNodesRequest(role: $role, environment: $environment, doctor: $doctor))
            ->dto();
    } catch (GatewayApiException $e) {
        return $e;
    }

    /** @var NodeListResponse $dto */
    return [
        'nodes' => $dto->nodes,
        'meta' => $dto->meta,
    ];
}
```

Then update the `handle()` method's failure branch (currently `if ($result instanceof GatewayResponse)` on line 67) to:

```php
if ($result instanceof GatewayApiException) {
    return $this->failGatewayException($result);
}
```

Replace `failGatewayResponse()` (lines 344-352) with:

```php
private function failGatewayException(GatewayApiException $exception): int
{
    return $this->failGatewayError(
        code: $exception->errorCode() ?? 'gateway_unavailable',
        message: $exception->getMessage() !== ''
            ? $exception->getMessage()
            : 'Gateway connection is required to list nodes.',
        meta: $exception->errorMeta(),
    );
}
```

- [ ] **Step 7: Update existing `node:list` feature tests if any reference `GatewayRequestSender`**

Check:

```bash
php artisan test --compact --filter=NodeList
```

If a feature test mocked `GatewayRequestSender`, switch it to a Saloon `MockClient` bound on the `GatewayConnector` singleton:

```php
use App\Http\Gateway\GatewayConnector;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

$mock = MockClient::global([
    '*' => MockResponse::make(['success' => ['data' => ['nodes' => []]]], 200),
]);
```

(Adjust per existing test pattern. If no `node:list` feature test exists yet, skip.)

- [ ] **Step 8: Run all node:list tests**

```bash
php artisan test --compact --filter=NodeList
```

Expected: PASS.

- [ ] **Step 9: Format, run quality check, commit**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact
```

Expected: full suite green.

```bash
git add app/Http/Gateway/Requests/Nodes/ListNodesRequest.php \
        app/Http/Gateway/Responses/Nodes/NodeListResponse.php \
        app/Console/Commands/NodeListCommand.php \
        tests/Unit/Http/Gateway/Requests/Nodes/ListNodesRequestTest.php
git commit -m "refactor(gateway): migrate node:list to Saloon ListNodesRequest"
```

---

### Task 6: Migrate ShowNodeRequest

**Files:**
- Create: `app/Http/Gateway/Requests/Nodes/ShowNodeRequest.php`
- Create: `app/Http/Gateway/Responses/Nodes/NodeShowResponse.php`
- Modify: `app/Console/Commands/NodeShowCommand.php`
- Test: `tests/Unit/Http/Gateway/Requests/Nodes/ShowNodeRequestTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Http/Gateway/Requests/Nodes/ShowNodeRequestTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Nodes\ShowNodeRequest;
use App\Http\Gateway\Responses\Nodes\NodeShowResponse;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $settings = LocalGatewaySettings::current();
    $settings->gateway_url = 'https://10.6.0.2';
    $settings->ca_pem_path = '/path/to/ca.pem';
    $settings->save();
});

it('resolves to GET /api/nodes/{name}', function (): void {
    $request = new ShowNodeRequest('gw-1');

    expect($request->resolveEndpoint())->toBe('/api/nodes/gw-1');
    expect($request->getMethod())->toBe(Method::GET);
});

it('returns a NodeShowResponse DTO with node array', function (): void {
    $mock = new MockClient([
        ShowNodeRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'node' => [
                        'name' => 'gw-1',
                        'role' => 'gateway',
                        'status' => 'active',
                        'wireguard_address' => '10.6.0.2',
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new ShowNodeRequest('gw-1'))->dto();

    expect($dto)->toBeInstanceOf(NodeShowResponse::class);
    expect($dto->node)->toMatchArray([
        'name' => 'gw-1',
        'role' => 'gateway',
        'status' => 'active',
        'wireguard_address' => '10.6.0.2',
    ]);
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=ShowNodeRequestTest
```

Expected: FAIL.

- [ ] **Step 3: Create the response DTO**

Create `app/Http/Gateway/Responses/Nodes/NodeShowResponse.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Nodes;

final readonly class NodeShowResponse
{
    /**
     * @param  array<string, mixed>  $node
     */
    public function __construct(
        public array $node,
    ) {}
}
```

- [ ] **Step 4: Create the Saloon request**

Create `app/Http/Gateway/Requests/Nodes/ShowNodeRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Nodes\NodeShowResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowNodeRequest extends GatewayRequest
{
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $name,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/nodes/{$this->name}";
    }

    public function createDtoFromResponse(Response $response): NodeShowResponse
    {
        $data = $this->unwrapData($response);

        $node = $data['node'] ?? [];

        return new NodeShowResponse(
            node: is_array($node) ? $node : [],
        );
    }
}
```

- [ ] **Step 5: Run request test**

```bash
php artisan test --compact --filter=ShowNodeRequestTest
```

Expected: PASS, 2 tests.

- [ ] **Step 6: Refactor `NodeShowCommand`**

Modify `app/Console/Commands/NodeShowCommand.php` lines 7-9 (the `use` block):

```php
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Nodes\ShowNodeRequest;
use App\Http\Gateway\Responses\Nodes\NodeShowResponse;
use App\Models\Node;
```

Replace the `if ($callerRole !== 'gateway')` block (lines 49-77) with:

```php
if ($callerRole !== 'gateway') {
    try {
        /** @var NodeShowResponse $dto */
        $dto = app(GatewayConnector::class)
            ->send(new ShowNodeRequest($name))
            ->dto();
    } catch (GatewayApiException $e) {
        return $this->failCommand(
            code: $e->errorCode() ?? 'gateway_unavailable',
            message: $e->getMessage() !== ''
                ? $e->getMessage()
                : 'Gateway connection is required to show node details.',
            meta: $e->errorMeta(),
        );
    }

    $payload = ['node' => $this->restructureGatewayData(['node' => $dto->node])];

    if ($this->wantsJson()) {
        return $this->jsonSuccess($payload);
    }

    $this->renderHuman($payload['node']);

    return self::SUCCESS;
}
```

- [ ] **Step 7: Run node:show tests**

```bash
php artisan test --compact --filter=NodeShow
```

Expected: PASS.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Gateway/Requests/Nodes/ShowNodeRequest.php \
        app/Http/Gateway/Responses/Nodes/NodeShowResponse.php \
        app/Console/Commands/NodeShowCommand.php \
        tests/Unit/Http/Gateway/Requests/Nodes/ShowNodeRequestTest.php
git commit -m "refactor(gateway): migrate node:show to Saloon ShowNodeRequest"
```

---

### Task 7: Migrate GrantNodeRequest

**Files:**
- Create: `app/Http/Gateway/Requests/Nodes/GrantNodeRequest.php`
- Create: `app/Http/Gateway/Responses/Nodes/NodeGrantResponse.php`
- Modify: `app/Console/Commands/NodeGrantCommand.php`
- Test: `tests/Unit/Http/Gateway/Requests/Nodes/GrantNodeRequestTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Nodes\GrantNodeRequest;
use App\Http\Gateway\Responses\Nodes\NodeGrantResponse;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $settings = LocalGatewaySettings::current();
    $settings->gateway_url = 'https://10.6.0.2';
    $settings->ca_pem_path = '/path/to/ca.pem';
    $settings->save();
});

it('resolves to POST /api/nodes/grant with consuming/serving body', function (): void {
    $request = new GrantNodeRequest('app-1', 'gw-1');

    expect($request->resolveEndpoint())->toBe('/api/nodes/grant');
    expect($request->getMethod())->toBe(Method::POST);
    expect($request->body()->all())->toBe([
        'consuming_node' => 'app-1',
        'serving_node' => 'gw-1',
    ]);
});

it('returns a NodeGrantResponse DTO with consuming/serving/already_granted', function (): void {
    $mock = new MockClient([
        GrantNodeRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'consuming_node' => 'app-1',
                    'serving_node' => 'gw-1',
                    'already_granted' => false,
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new GrantNodeRequest('app-1', 'gw-1'))->dto();

    expect($dto)->toBeInstanceOf(NodeGrantResponse::class);
    expect($dto->consumingNode)->toBe('app-1');
    expect($dto->servingNode)->toBe('gw-1');
    expect($dto->alreadyGranted)->toBeFalse();
});
```

- [ ] **Step 2: Verify failure**

```bash
php artisan test --compact --filter=GrantNodeRequestTest
```

Expected: FAIL.

- [ ] **Step 3: Create the response DTO**

```php
<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Nodes;

final readonly class NodeGrantResponse
{
    public function __construct(
        public string $consumingNode,
        public string $servingNode,
        public bool $alreadyGranted,
    ) {}
}
```

- [ ] **Step 4: Create the Saloon request**

```php
<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Nodes\NodeGrantResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class GrantNodeRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $consumingNode,
        public readonly string $servingNode,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/nodes/grant';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'consuming_node' => $this->consumingNode,
            'serving_node' => $this->servingNode,
        ];
    }

    public function createDtoFromResponse(Response $response): NodeGrantResponse
    {
        $data = $this->unwrapData($response);

        return new NodeGrantResponse(
            consumingNode: is_string($data['consuming_node'] ?? null) ? $data['consuming_node'] : $this->consumingNode,
            servingNode: is_string($data['serving_node'] ?? null) ? $data['serving_node'] : $this->servingNode,
            alreadyGranted: is_bool($data['already_granted'] ?? null) ? $data['already_granted'] : false,
        );
    }
}
```

- [ ] **Step 5: Run request test**

```bash
php artisan test --compact --filter=GrantNodeRequestTest
```

Expected: PASS, 2 tests.

- [ ] **Step 6: Refactor `NodeGrantCommand`**

Replace `use` block lines 7-12 of `NodeGrantCommand.php`:

```php
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Nodes\GrantNodeRequest;
use App\Http\Gateway\Responses\Nodes\NodeGrantResponse;
use App\Models\Node;
use App\Models\NodeAccess;
```

Replace the `forwardGrant()` method body (lines 54-80):

```php
private function forwardGrant(): int
{
    $consumerName = (string) $this->argument('consuming_node');
    $servingName = (string) $this->argument('serving_node');

    try {
        /** @var NodeGrantResponse $dto */
        $dto = app(GatewayConnector::class)
            ->send(new GrantNodeRequest($consumerName, $servingName))
            ->dto();
    } catch (GatewayApiException $e) {
        return $this->failCommand(
            code: $e->errorCode() ?? 'gateway_unavailable',
            message: $e->getMessage() !== ''
                ? $e->getMessage()
                : 'Gateway connection is required to grant node access.',
            meta: $e->errorMeta(),
        );
    }

    return $this->respondSuccess($dto->consumingNode, $dto->servingNode, $dto->alreadyGranted);
}
```

Delete the now-unused `respondForwardedSuccess()` method (lines 122-134).

- [ ] **Step 7: Run node:grant tests**

```bash
php artisan test --compact --filter=NodeGrant
```

Expected: PASS.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Gateway/Requests/Nodes/GrantNodeRequest.php \
        app/Http/Gateway/Responses/Nodes/NodeGrantResponse.php \
        app/Console/Commands/NodeGrantCommand.php \
        tests/Unit/Http/Gateway/Requests/Nodes/GrantNodeRequestTest.php
git commit -m "refactor(gateway): migrate node:grant to Saloon GrantNodeRequest"
```

---

### Task 8: Migrate RevokeNodeRequest

**Files:**
- Create: `app/Http/Gateway/Requests/Nodes/RevokeNodeRequest.php`
- Create: `app/Http/Gateway/Responses/Nodes/NodeRevokeResponse.php`
- Modify: `app/Console/Commands/NodeRevokeCommand.php`
- Test: `tests/Unit/Http/Gateway/Requests/Nodes/RevokeNodeRequestTest.php`

- [ ] **Step 1: Read `app/Console/Commands/NodeRevokeCommand.php` to confirm response shape it consumes** (the existing payload it reads from `$response->data()`).

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Nodes\RevokeNodeRequest;
use App\Http\Gateway\Responses\Nodes\NodeRevokeResponse;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $settings = LocalGatewaySettings::current();
    $settings->gateway_url = 'https://10.6.0.2';
    $settings->ca_pem_path = '/path/to/ca.pem';
    $settings->save();
});

it('resolves to POST /api/nodes/revoke with body', function (): void {
    $request = new RevokeNodeRequest('app-1', 'gw-1');

    expect($request->resolveEndpoint())->toBe('/api/nodes/revoke');
    expect($request->getMethod())->toBe(Method::POST);
    expect($request->body()->all())->toBe([
        'consuming_node' => 'app-1',
        'serving_node' => 'gw-1',
        'force' => true,
    ]);
});

it('returns a NodeRevokeResponse DTO', function (): void {
    $mock = new MockClient([
        RevokeNodeRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'consuming_node' => 'app-1',
                    'serving_node' => 'gw-1',
                    'already_revoked' => false,
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new RevokeNodeRequest('app-1', 'gw-1'))->dto();

    expect($dto)->toBeInstanceOf(NodeRevokeResponse::class);
    expect($dto->consumingNode)->toBe('app-1');
    expect($dto->servingNode)->toBe('gw-1');
    expect($dto->alreadyRevoked)->toBeFalse();
});
```

- [ ] **Step 3: Verify failure**

```bash
php artisan test --compact --filter=RevokeNodeRequestTest
```

Expected: FAIL.

- [ ] **Step 4: Create response DTO**

```php
<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Nodes;

final readonly class NodeRevokeResponse
{
    public function __construct(
        public string $consumingNode,
        public string $servingNode,
        public bool $alreadyRevoked,
    ) {}
}
```

- [ ] **Step 5: Create Saloon request**

```php
<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Nodes\NodeRevokeResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RevokeNodeRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $consumingNode,
        public readonly string $servingNode,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/nodes/revoke';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'consuming_node' => $this->consumingNode,
            'serving_node' => $this->servingNode,
            'force' => true,
        ];
    }

    public function createDtoFromResponse(Response $response): NodeRevokeResponse
    {
        $data = $this->unwrapData($response);

        return new NodeRevokeResponse(
            consumingNode: is_string($data['consuming_node'] ?? null) ? $data['consuming_node'] : $this->consumingNode,
            servingNode: is_string($data['serving_node'] ?? null) ? $data['serving_node'] : $this->servingNode,
            alreadyRevoked: is_bool($data['already_revoked'] ?? null) ? $data['already_revoked'] : false,
        );
    }
}
```

- [ ] **Step 6: Run request test**

```bash
php artisan test --compact --filter=RevokeNodeRequestTest
```

Expected: PASS, 2 tests.

- [ ] **Step 7: Refactor `NodeRevokeCommand`**

Apply the same pattern as Task 7 (Grant): replace `GatewayRequestSender::make()->send(new (old) RevokeNodeRequest(...))` with `app(GatewayConnector::class)->send(new (new) RevokeNodeRequest(...))->dto()`, catching `GatewayApiException`. Drop the `if (! $response->isSuccess())` branch and any forwarded-response remap helper.

If `NodeRevokeCommand` reads other DTO fields, extend `NodeRevokeResponse` accordingly.

- [ ] **Step 8: Run node:revoke tests**

```bash
php artisan test --compact --filter=NodeRevoke
```

Expected: PASS.

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Gateway/Requests/Nodes/RevokeNodeRequest.php \
        app/Http/Gateway/Responses/Nodes/NodeRevokeResponse.php \
        app/Console/Commands/NodeRevokeCommand.php \
        tests/Unit/Http/Gateway/Requests/Nodes/RevokeNodeRequestTest.php
git commit -m "refactor(gateway): migrate node:revoke to Saloon RevokeNodeRequest"
```

---

### Task 9: Migrate RemoveNodeRequest

**Files:**
- Create: `app/Http/Gateway/Requests/Nodes/RemoveNodeRequest.php`
- Create: `app/Http/Gateway/Responses/Nodes/NodeRemoveResponse.php`
- Modify: `app/Console/Commands/NodeRemoveCommand.php`
- Test: `tests/Unit/Http/Gateway/Requests/Nodes/RemoveNodeRequestTest.php`

- [ ] **Step 1: Read `app/Console/Commands/NodeRemoveCommand.php` to confirm DTO shape needed.**

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Nodes\RemoveNodeRequest;
use App\Http\Gateway\Responses\Nodes\NodeRemoveResponse;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $settings = LocalGatewaySettings::current();
    $settings->gateway_url = 'https://10.6.0.2';
    $settings->ca_pem_path = '/path/to/ca.pem';
    $settings->save();
});

it('resolves to DELETE /api/nodes/{name} with destructive consent body', function (): void {
    $request = new RemoveNodeRequest('app-1', destructiveConsentSource: 'flag');

    expect($request->resolveEndpoint())->toBe('/api/nodes/app-1');
    expect($request->getMethod())->toBe(Method::DELETE);
    expect($request->body()->all())->toBe([
        'destructive_consent' => true,
        'destructive_consent_source' => 'flag',
    ]);
});

it('defaults destructive_consent_source to "force"', function (): void {
    $request = new RemoveNodeRequest('app-1');

    expect($request->body()->all())->toBe([
        'destructive_consent' => true,
        'destructive_consent_source' => 'force',
    ]);
});

it('returns a NodeRemoveResponse DTO', function (): void {
    $mock = new MockClient([
        RemoveNodeRequest::class => MockResponse::make([
            'success' => [
                'data' => ['name' => 'app-1', 'removed' => true],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new RemoveNodeRequest('app-1'))->dto();

    expect($dto)->toBeInstanceOf(NodeRemoveResponse::class);
    expect($dto->name)->toBe('app-1');
    expect($dto->removed)->toBeTrue();
});
```

- [ ] **Step 3: Verify failure**

```bash
php artisan test --compact --filter=RemoveNodeRequestTest
```

Expected: FAIL.

- [ ] **Step 4: Create response DTO**

```php
<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Nodes;

final readonly class NodeRemoveResponse
{
    public function __construct(
        public string $name,
        public bool $removed,
    ) {}
}
```

- [ ] **Step 5: Create Saloon request**

```php
<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Nodes\NodeRemoveResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RemoveNodeRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::DELETE;

    public function __construct(
        public readonly string $name,
        public readonly string $destructiveConsentSource = 'force',
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/nodes/{$this->name}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'destructive_consent' => true,
            'destructive_consent_source' => $this->destructiveConsentSource,
        ];
    }

    public function createDtoFromResponse(Response $response): NodeRemoveResponse
    {
        $data = $this->unwrapData($response);

        return new NodeRemoveResponse(
            name: is_string($data['name'] ?? null) ? $data['name'] : $this->name,
            removed: is_bool($data['removed'] ?? null) ? $data['removed'] : true,
        );
    }
}
```

- [ ] **Step 6: Run request test**

```bash
php artisan test --compact --filter=RemoveNodeRequestTest
```

Expected: PASS, 3 tests.

- [ ] **Step 7: Refactor `NodeRemoveCommand`**

Apply the same Saloon-call pattern. If the existing command reads richer fields off the response, extend `NodeRemoveResponse`.

- [ ] **Step 8: Run node:remove tests**

```bash
php artisan test --compact --filter=NodeRemove
```

Expected: PASS.

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Gateway/Requests/Nodes/RemoveNodeRequest.php \
        app/Http/Gateway/Responses/Nodes/NodeRemoveResponse.php \
        app/Console/Commands/NodeRemoveCommand.php \
        tests/Unit/Http/Gateway/Requests/Nodes/RemoveNodeRequestTest.php
git commit -m "refactor(gateway): migrate node:remove to Saloon RemoveNodeRequest"
```

---

### Task 10: Migrate UpdateNodeRequest

**Files:**
- Create: `app/Http/Gateway/Requests/Nodes/UpdateNodeRequest.php`
- Create: `app/Http/Gateway/Responses/Nodes/NodeUpdateResponse.php`
- Modify: `app/Console/Commands/NodeUpdateCommand.php`
- Test: `tests/Unit/Http/Gateway/Requests/Nodes/UpdateNodeRequestTest.php`

- [ ] **Step 1: Read `app/Console/Commands/NodeUpdateCommand.php` to confirm fields and DTO shape.**

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Nodes\UpdateNodeRequest;
use App\Http\Gateway\Responses\Nodes\NodeUpdateResponse;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $settings = LocalGatewaySettings::current();
    $settings->gateway_url = 'https://10.6.0.2';
    $settings->ca_pem_path = '/path/to/ca.pem';
    $settings->save();
});

it('resolves to PUT /api/nodes/{name} with non-null fields only', function (): void {
    $request = new UpdateNodeRequest('app-1', ['environment' => 'production', 'platform' => null]);

    expect($request->resolveEndpoint())->toBe('/api/nodes/app-1');
    expect($request->getMethod())->toBe(Method::PUT);
    expect($request->body()->all())->toBe(['environment' => 'production']);
});

it('returns a NodeUpdateResponse DTO with updated node array', function (): void {
    $mock = new MockClient([
        UpdateNodeRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'node' => ['name' => 'app-1', 'environment' => 'production'],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new UpdateNodeRequest('app-1', ['environment' => 'production']))->dto();

    expect($dto)->toBeInstanceOf(NodeUpdateResponse::class);
    expect($dto->node)->toBe(['name' => 'app-1', 'environment' => 'production']);
});
```

- [ ] **Step 3: Verify failure**

```bash
php artisan test --compact --filter=UpdateNodeRequestTest
```

Expected: FAIL.

- [ ] **Step 4: Create response DTO**

```php
<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Nodes;

final readonly class NodeUpdateResponse
{
    /**
     * @param  array<string, mixed>  $node
     */
    public function __construct(
        public array $node,
    ) {}
}
```

- [ ] **Step 5: Create Saloon request**

```php
<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Nodes\NodeUpdateResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class UpdateNodeRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::PUT;

    /**
     * @param  array<string, string|null>  $fields
     */
    public function __construct(
        public readonly string $name,
        public readonly array $fields,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/nodes/{$this->name}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter(
            $this->fields,
            static fn (?string $value): bool => $value !== null,
        );
    }

    public function createDtoFromResponse(Response $response): NodeUpdateResponse
    {
        $data = $this->unwrapData($response);

        $node = $data['node'] ?? [];

        return new NodeUpdateResponse(
            node: is_array($node) ? $node : [],
        );
    }
}
```

- [ ] **Step 6: Run request test**

```bash
php artisan test --compact --filter=UpdateNodeRequestTest
```

Expected: PASS, 2 tests.

- [ ] **Step 7: Refactor `NodeUpdateCommand`**

Apply Saloon-call pattern.

- [ ] **Step 8: Run node:update tests**

```bash
php artisan test --compact --filter=NodeUpdate
```

Expected: PASS.

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Gateway/Requests/Nodes/UpdateNodeRequest.php \
        app/Http/Gateway/Responses/Nodes/NodeUpdateResponse.php \
        app/Console/Commands/NodeUpdateCommand.php \
        tests/Unit/Http/Gateway/Requests/Nodes/UpdateNodeRequestTest.php
git commit -m "refactor(gateway): migrate node:update to Saloon UpdateNodeRequest"
```

---

### Task 11: Migrate DefaultNodeRequest (split into 3 Saloon requests)

The old `DefaultNodeRequest` was a multi-method dispatcher (`show`/`set`/`clear` factory methods → GET/PUT/DELETE on the same path). With Saloon, each HTTP method is its own request class.

**Files:**
- Create: `app/Http/Gateway/Requests/Nodes/DefaultNodeShowRequest.php`
- Create: `app/Http/Gateway/Requests/Nodes/DefaultNodeSetRequest.php`
- Create: `app/Http/Gateway/Requests/Nodes/DefaultNodeClearRequest.php`
- Create: `app/Http/Gateway/Responses/Nodes/NodeDefaultResponse.php`
- Modify: `app/Console/Commands/NodeDefaultCommand.php`
- Test: `tests/Unit/Http/Gateway/Requests/Nodes/DefaultNodeRequestsTest.php`

- [ ] **Step 1: Read `NodeDefaultCommand.php` to confirm exactly which path (show/set/clear) is used and what the command reads off the response.**

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Nodes\DefaultNodeClearRequest;
use App\Http\Gateway\Requests\Nodes\DefaultNodeSetRequest;
use App\Http\Gateway\Requests\Nodes\DefaultNodeShowRequest;
use App\Http\Gateway\Responses\Nodes\NodeDefaultResponse;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $settings = LocalGatewaySettings::current();
    $settings->gateway_url = 'https://10.6.0.2';
    $settings->ca_pem_path = '/path/to/ca.pem';
    $settings->save();
});

it('show resolves to GET /api/nodes/default', function (): void {
    $request = new DefaultNodeShowRequest;

    expect($request->resolveEndpoint())->toBe('/api/nodes/default');
    expect($request->getMethod())->toBe(Method::GET);
});

it('set resolves to PUT /api/nodes/default with name body', function (): void {
    $request = new DefaultNodeSetRequest('app-1');

    expect($request->resolveEndpoint())->toBe('/api/nodes/default');
    expect($request->getMethod())->toBe(Method::PUT);
    expect($request->body()->all())->toBe(['name' => 'app-1']);
});

it('clear resolves to DELETE /api/nodes/default', function (): void {
    $request = new DefaultNodeClearRequest;

    expect($request->resolveEndpoint())->toBe('/api/nodes/default');
    expect($request->getMethod())->toBe(Method::DELETE);
});

it('returns a NodeDefaultResponse DTO from show', function (): void {
    $mock = new MockClient([
        DefaultNodeShowRequest::class => MockResponse::make([
            'success' => ['data' => ['default_node' => 'app-1']],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new DefaultNodeShowRequest)->dto();

    expect($dto)->toBeInstanceOf(NodeDefaultResponse::class);
    expect($dto->defaultNode)->toBe('app-1');
});

it('returns a NodeDefaultResponse with null when no default is set', function (): void {
    $mock = new MockClient([
        DefaultNodeShowRequest::class => MockResponse::make([
            'success' => ['data' => ['default_node' => null]],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new DefaultNodeShowRequest)->dto();

    expect($dto->defaultNode)->toBeNull();
});
```

- [ ] **Step 3: Verify failure**

```bash
php artisan test --compact --filter=DefaultNodeRequestsTest
```

Expected: FAIL.

- [ ] **Step 4: Create response DTO**

```php
<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Nodes;

final readonly class NodeDefaultResponse
{
    public function __construct(
        public ?string $defaultNode,
    ) {}
}
```

- [ ] **Step 5: Create the three Saloon requests**

`app/Http/Gateway/Requests/Nodes/DefaultNodeShowRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Nodes\NodeDefaultResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class DefaultNodeShowRequest extends GatewayRequest
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/nodes/default';
    }

    public function createDtoFromResponse(Response $response): NodeDefaultResponse
    {
        $data = $this->unwrapData($response);
        $name = $data['default_node'] ?? null;

        return new NodeDefaultResponse(
            defaultNode: is_string($name) && $name !== '' ? $name : null,
        );
    }
}
```

`app/Http/Gateway/Requests/Nodes/DefaultNodeSetRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Nodes\NodeDefaultResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class DefaultNodeSetRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::PUT;

    public function __construct(
        public readonly string $name,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/nodes/default';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return ['name' => $this->name];
    }

    public function createDtoFromResponse(Response $response): NodeDefaultResponse
    {
        $data = $this->unwrapData($response);
        $name = $data['default_node'] ?? $this->name;

        return new NodeDefaultResponse(
            defaultNode: is_string($name) && $name !== '' ? $name : null,
        );
    }
}
```

`app/Http/Gateway/Requests/Nodes/DefaultNodeClearRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Nodes\NodeDefaultResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class DefaultNodeClearRequest extends GatewayRequest
{
    protected Method $method = Method::DELETE;

    public function resolveEndpoint(): string
    {
        return '/api/nodes/default';
    }

    public function createDtoFromResponse(Response $response): NodeDefaultResponse
    {
        $this->unwrapData($response);

        return new NodeDefaultResponse(defaultNode: null);
    }
}
```

- [ ] **Step 6: Run tests**

```bash
php artisan test --compact --filter=DefaultNodeRequestsTest
```

Expected: PASS, 5 tests.

- [ ] **Step 7: Refactor `NodeDefaultCommand`**

Replace each `GatewayRequestSender::make()->send(DefaultNodeRequest::show())` style call with the matching new request class via `app(GatewayConnector::class)->send(...)->dto()`. Catch `GatewayApiException`.

- [ ] **Step 8: Run node:default tests**

```bash
php artisan test --compact --filter=NodeDefault
```

Expected: PASS.

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Gateway/Requests/Nodes/DefaultNodeShowRequest.php \
        app/Http/Gateway/Requests/Nodes/DefaultNodeSetRequest.php \
        app/Http/Gateway/Requests/Nodes/DefaultNodeClearRequest.php \
        app/Http/Gateway/Responses/Nodes/NodeDefaultResponse.php \
        app/Console/Commands/NodeDefaultCommand.php \
        tests/Unit/Http/Gateway/Requests/Nodes/DefaultNodeRequestsTest.php
git commit -m "refactor(gateway): migrate node:default to Saloon DefaultNode requests"
```

---

### Task 12: Bind GatewayConnector + remove old abstractions and tests

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Delete: `app/Services/Gateway/GatewayClient.php`
- Delete: `app/Services/Gateway/GatewayRequest.php`
- Delete: `app/Services/Gateway/GatewayRequestSender.php`
- Delete: `app/Services/Gateway/GatewayResponse.php`
- Delete: `app/Services/Gateway/GatewayResponseParser.php`
- Delete: `app/Services/Gateway/Requests/DefaultNodeRequest.php`
- Delete: `app/Services/Gateway/Requests/GrantNodeRequest.php`
- Delete: `app/Services/Gateway/Requests/ListNodesRequest.php`
- Delete: `app/Services/Gateway/Requests/RemoveNodeRequest.php`
- Delete: `app/Services/Gateway/Requests/RevokeNodeRequest.php`
- Delete: `app/Services/Gateway/Requests/ShowNodeRequest.php`
- Delete: `app/Services/Gateway/Requests/UpdateNodeRequest.php`
- Delete: `tests/Feature/Services/Gateway/GatewayClientTest.php`
- Delete: `tests/Unit/Services/Gateway/GatewayRequestSenderTest.php`
- Delete: `tests/Unit/Services/Gateway/GatewayResponseParserTest.php`
- Delete: `tests/Unit/Services/Gateway/Requests/GrantNodeRequestTest.php`
- Delete: `tests/Unit/Services/Gateway/Requests/ListNodesRequestTest.php`
- Delete: `tests/Unit/Services/Gateway/Requests/ShowNodeRequestTest.php`

- [ ] **Step 1: Verify no remaining references to the old abstractions**

```bash
grep -r "GatewayRequestSender\|App\\\\Services\\\\Gateway\\\\GatewayRequest\b\|GatewayClient::make\|GatewayResponseParser\|GatewayResponse" app tests --include='*.php' | grep -v "FetchGatewayRootCa\|GatewayApiRuntimeInstaller\|RootCaFetchResult"
```

Expected: empty output. If anything prints, remove or migrate that caller before continuing.

- [ ] **Step 2: Update `AppServiceProvider`**

Modify `app/Providers/AppServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Gateway\GatewayConnector;
use App\Services\ActivityLogCorrelation;
use App\Services\Dns\LocalResolver;
use App\Services\Trust\LinuxTrustStoreInstaller;
use App\Services\Trust\MacOsTrustStoreInstaller;
use App\Services\Trust\TrustStoreInstaller;
use App\Support\LocalPlatform;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->scoped(ActivityLogCorrelation::class);
        $this->app->singleton(GatewayConnector::class);
        $this->app->singleton(LocalResolver::class);

        $this->app->bind(TrustStoreInstaller::class, function ($app): TrustStoreInstaller {
            $platform = $app->make(LocalPlatform::class);

            return match ($platform->current()) {
                'macos' => new MacOsTrustStoreInstaller,
                'linux' => new LinuxTrustStoreInstaller,
                default => throw new RuntimeException('Unsupported platform for trust store operations.'),
            };
        });
    }

    public function boot(): void
    {
        //
    }
}
```

- [ ] **Step 3: Delete old code**

```bash
rm app/Services/Gateway/GatewayClient.php
rm app/Services/Gateway/GatewayRequest.php
rm app/Services/Gateway/GatewayRequestSender.php
rm app/Services/Gateway/GatewayResponse.php
rm app/Services/Gateway/GatewayResponseParser.php
rm app/Services/Gateway/Requests/DefaultNodeRequest.php
rm app/Services/Gateway/Requests/GrantNodeRequest.php
rm app/Services/Gateway/Requests/ListNodesRequest.php
rm app/Services/Gateway/Requests/RemoveNodeRequest.php
rm app/Services/Gateway/Requests/RevokeNodeRequest.php
rm app/Services/Gateway/Requests/ShowNodeRequest.php
rm app/Services/Gateway/Requests/UpdateNodeRequest.php
rmdir app/Services/Gateway/Requests
```

- [ ] **Step 4: Delete obsolete tests**

```bash
rm tests/Feature/Services/Gateway/GatewayClientTest.php
rm tests/Unit/Services/Gateway/GatewayRequestSenderTest.php
rm tests/Unit/Services/Gateway/GatewayResponseParserTest.php
rm tests/Unit/Services/Gateway/Requests/GrantNodeRequestTest.php
rm tests/Unit/Services/Gateway/Requests/ListNodesRequestTest.php
rm tests/Unit/Services/Gateway/Requests/ShowNodeRequestTest.php
rmdir tests/Unit/Services/Gateway/Requests 2>/dev/null || true
```

- [ ] **Step 5: Run full test suite**

```bash
php artisan test --compact
```

Expected: full suite green. If anything fails because of a missed reference, fix the caller (don't restore the deleted file).

- [ ] **Step 6: Run quality check**

```bash
composer quality-check
```

Expected: green (Pint, Larastan, Rector all clean).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "refactor(gateway): remove hand-rolled HTTP abstraction in favor of Saloon"
```

---

### Task 13: Reverse GATEWAY-API-0 docs decision + update cross-cutting guidance

**Files:**
- Modify: `docs/PORTING.md` (`GATEWAY-API-0` section, ~lines 845-926)
- Modify: `docs/abstractions/cross-cutting.md` (transport pattern section)

- [ ] **Step 1: Read both files** to find the exact sections.

```bash
grep -n "GATEWAY-API-0" docs/PORTING.md
grep -n "Gateway API\|GatewayClient\|GatewayRequestSender" docs/abstractions/cross-cutting.md
```

- [ ] **Step 2: Rewrite the `GATEWAY-API-0` section in `docs/PORTING.md`**

Replace the existing decision block (the one currently saying "Decided — thin `GatewayClient` wrapper over Laravel `Http` facade") with:

```markdown
### GATEWAY-API-0 Decision: Gateway API Client Transport

**Status:** Reversed — adopt `saloonphp/saloon` as the gateway API transport.

**Original decision (superseded):** thin `GatewayClient` wrapper over Laravel's `Http` facade with a hand-rolled `GatewayRequest` interface, `GatewayRequestSender`, and `GatewayResponseParser`.

**Reversal rationale:**

1. **The hand-rolled abstraction was reinventing Saloon poorly.** The clean repo's
   `GatewayRequest` interface (`method()/path()/query()/data()`) is a literal
   subset of Saloon's `Request` shape, with weaker mocking, no plugin pipeline,
   no typed response DTOs, and no community familiarity.

2. **Cost of the abstraction was already paid.** With 7 typed requests + a
   sender + an envelope parser + an interface in place, we were carrying
   abstraction cost equivalent to a Saloon footprint without any of its
   benefits. The cheapest moment to switch was while the surface was still
   small — exactly the moment we were in.

3. **Off-the-shelf > home-grown for proven patterns.** Saloon is the de facto
   standard for typed HTTP clients in Laravel. New contributors recognize it.
   Plugin ecosystems (logging, retries, OAuth) are first-class. The "we don't
   need a taxonomy yet" framing in the original decision cuts the other way:
   if a taxonomy is inevitable, build it on the well-trodden tool.

4. **Typed responses unlock real value.** Saloon's `createDtoFromResponse()`
   replaces `array` plumbing with typed DTOs at every caller — caller code
   reads `$dto->nodes` instead of `$response->data()['nodes'] ?? []`.

**Implementation footprint:**

- `App\Http\Gateway\GatewayConnector` — single connector, base URL + CA verify
  + correlation header plugin from `LocalGatewaySettings::current()`
- `App\Http\Gateway\GatewayRequest` — abstract base with envelope-aware
  `hasRequestFailed()` / `getRequestException()` / `unwrapData()` helpers
- `App\Http\Gateway\GatewayApiException` — thrown on envelope errors
- `App\Http\Gateway\Plugins\HasCorrelationHeader` — Saloon plugin trait for
  `X-Orbit-Request-Id` and `X-Orbit-Client` headers
- `App\Http\Gateway\Requests\<Family>\` — per-endpoint typed Saloon requests
- `App\Http\Gateway\Responses\<Family>\` — typed response DTOs

**Out of scope for the migration:**

- `FetchGatewayRootCa` stays on `Http`. It runs before the CA exists, has
  unique connector requirements (no verify, redirect handling), and gains
  nothing from a Saloon migration.
- `GatewayApiRuntimeInstaller` is a server-side install helper, not a client.

**Reusable implementation guidance:** see `docs/abstractions/cross-cutting.md`
for the Saloon-based gateway transport pattern.
```

- [ ] **Step 3: Mark the workstream as complete**

In the `### Remaining Workstream Items` block (lines ~898-926), update the
node API line to fully checked, and add a new entry below documenting the
Saloon migration:

```markdown
- [x] Migrate gateway transport from hand-rolled `GatewayClient` /
  `GatewayRequestSender` to Saloon (`saloonphp/saloon`). Single `GatewayConnector`
  with abstract `GatewayRequest` base handles envelope unwrapping and typed
  `GatewayApiException`. Per-endpoint Saloon `Request` subclasses return typed
  DTOs from `App\Http\Gateway\Responses\<Family>\`.
```

- [ ] **Step 4: Update `docs/abstractions/cross-cutting.md`**

Replace any reference to `GatewayClient` / `GatewayRequestSender` / `GatewayResponse` in the gateway transport section with the Saloon pattern. Concretely, the section should now describe:

- Use `app(GatewayConnector::class)->send($request)->dto()` from callers
- Each endpoint gets a `final class FooRequest extends App\Http\Gateway\GatewayRequest` that implements `createDtoFromResponse(Response $response): FooResponse`
- Errors surface as `GatewayApiException` with `errorCode()`, `errorMeta()`, and the parsed `message`
- Bodies use `Saloon\Traits\Body\HasJsonBody` via `defaultBody()`; query strings use `defaultQuery()`
- Tests use Saloon's `MockClient` (`$connector->withMockClient(...)` or `MockClient::global(...)`)

If the doc has a concrete code example, replace it with a small Saloon-based example mirroring `ListNodesRequest`.

- [ ] **Step 5: Run docs linter**

```bash
composer docs-lint
```

Expected: green. Fix any complaints before committing.

- [ ] **Step 6: Final full check**

```bash
composer quality-check
```

Expected: green.

- [ ] **Step 7: Commit docs**

```bash
git add docs/PORTING.md docs/abstractions/cross-cutting.md
git commit -m "docs(gateway): reverse GATEWAY-API-0 decision in favor of Saloon"
```

---

## Self-Review Checklist (already applied during plan authoring)

- **Spec coverage:** every existing `App\Services\Gateway\Requests\*` request has a migration task (Tasks 5, 6, 7, 8, 9, 10, 11), and the old multi-method `DefaultNodeRequest` is split into three Saloon classes (Task 11). Old abstractions and tests are deleted in Task 12. Docs reversal is Task 13.
- **Out-of-scope items called out explicitly:** `FetchGatewayRootCa` (bootstrap) and `GatewayApiRuntimeInstaller` (server-side) stay untouched.
- **Type consistency:** all DTO classes live under `App\Http\Gateway\Responses\Nodes\` with consistent `Node{Action}Response` naming. All requests live under `App\Http\Gateway\Requests\Nodes\` with consistent `{Action}NodeRequest` naming. The `GatewayConnector::class` binding is added in Task 12 (`AppServiceProvider`) and used by every caller in Tasks 5-11.
- **No "similar to Task N":** every task spells out its full Saloon Request, response DTO, test code, and commit command. The Revoke/Remove/Update/Default tasks include their own complete code blocks rather than referring back.

---

## Risks & Notes

- **Saloon version pin:** the plan uses `^3.0`. If composer can't resolve against PHP 8.5 / Laravel 13, fall back to `composer require saloonphp/saloon` (no constraint) and pin to whatever resolves. Mention the actual version in Task 1's commit message.
- **Saloon API surface assumed:** `withMockClient`, `MockResponse::make`, `defaultQuery`, `defaultBody`, `defaultHeaders`, `defaultConfig`, `createDtoFromResponse`, `hasRequestFailed`, `getRequestException`, `body()->all()`, `query()->all()`, `headers()->all()`, `config()->all()` are all stable Saloon v3 APIs. If any test snippet uses a method that doesn't exist on the resolved Saloon version, adjust to the documented equivalent (Saloon docs at https://docs.saloon.dev).
- **`MockClient::global` vs per-connector mock:** Tests in this plan attach mocks per connector instance for isolation. If existing feature tests use Laravel's `Http::fake()` against the gateway URL, they will silently pass for the old code paths but break once Saloon owns the requests — Saloon bypasses Laravel's HTTP fake. Migrate those feature tests to `MockClient::global([...])` in their `beforeEach`.
- **`LocalGatewaySettings::current()` is hit twice per request** (once for base URL, once for config) in the `GatewayConnector`. If profiling shows this matters, cache it inside the connector instance — but defer until measured.
- **No legacy compatibility shim.** Per project rules, we delete the old code in Task 12 rather than keeping a deprecated wrapper.

---
