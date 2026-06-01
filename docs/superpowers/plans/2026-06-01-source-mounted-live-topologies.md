# Source-Mounted Live Topologies Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Docker and Incus development topologies run from the current worktree source while node-local Orbit state lives outside the mounted repo and internal executor tokens are verified by the gateway.

**Architecture:** First align product docs, then move gateway mutable state under `~/.config/orbit`, make `apps/cli/orbit` the source entrypoint, replace node-local executor shared-secret verification with gateway API introspection, and finally switch Docker/Incus live topology overlays to source mounts. Keep the artifact lane separate so the native CLI binary and `orbit-runtime` install contract still get production-like verification.

**Tech Stack:** Laravel 13 gateway, Laravel Zero CLI, Pest 4, Docker prepared topologies, Incus retained topologies, WireGuard-authenticated gateway API, SQLite.

---

## Scope

This is a complex multi-phase change. Execute in the listed order. Do not start
Docker or Incus source mounts until the gateway state-root and CLI/internal
executor changes are green in focused tests.

## File Map

- `apps/docs/content/architecture.md`, `apps/docs/content/tech-stack.md`, `apps/docs/content/concepts.md`, `apps/docs/content/domains/1_node/node-concepts.md`, `apps/docs/content/execution-lanes.md`, `apps/docs/content/testing/README.md`: product authority updates.
- `apps/gateway/bootstrap/app.php`, `apps/gateway/bootstrap/orbit_paths.php`: gateway bootstrap path resolution before Laravel loads env/config.
- `apps/gateway/config/database.php`, `apps/gateway/config/orbit.php`: path/config contracts if bootstrap alone is not enough.
- `bin/install-orbit`: installer state-root creation, runtime mount, and removal of node-local executor shared-secret writes.
- `apps/gateway/app/Services/Ca/OrbitCaService.php`: verify CA paths move with Laravel storage path; change only if bootstrap storage-path move is insufficient.
- `apps/cli/orbit`, `apps/cli/config/orbit.php`, `apps/cli/app/Providers/OperationTokenGuardServiceProvider.php`, `apps/cli/app/Services/Executor/OperationTokenGuard.php`: direct source entrypoint and gateway-backed internal token guard.
- `apps/cli/app/Services/GatewayApiClient.php`: add a typed call or reuse `post()` for token verification.
- `apps/gateway/app/Http/Controllers/Api/InternalExecutorTokenController.php`, `apps/gateway/routes/api.php`: gateway token introspection endpoint.
- `apps/gateway/app/Services/Operations/OperationTokenIntrospector.php`: gateway-side token validation service.
- `apps/e2e/app/E2E/Support/E2ECurrentCheckout.php`, `apps/e2e/app/E2E/Support/DockerTopologyProvider.php`, `apps/e2e/app/E2E/Support/DockerTopologyBuilder.php`: Docker source-mounted topology support.
- `apps/e2e/app/E2E/Support/IncusHost.php`, `apps/e2e/app/E2E/Support/IncusTopologyBuilder.php`, `apps/e2e/app/E2E/Support/IncusTopologyTemplate.php`, `apps/e2e/app/Console/Commands/E2EIncusCommand.php`: Incus source-mounted topology support.
- Matching tests under `apps/gateway/tests`, `apps/cli/tests`, and `apps/e2e/tests`.

---

### Task 1: Align Product Docs

**Files:**
- Modify: `apps/docs/content/architecture.md`
- Modify: `apps/docs/content/tech-stack.md`
- Modify: `apps/docs/content/concepts.md`
- Modify: `apps/docs/content/domains/1_node/node-concepts.md`
- Modify: `apps/docs/content/execution-lanes.md`
- Modify: `apps/docs/content/testing/README.md`

- [ ] **Step 1: Update authority docs before code**

Update docs to state:

```markdown
- Source-mounted Docker/Incus topologies are development/E2E lanes.
- Production installs still use the native CLI binary artifact.
- In source-mounted nodes, `/usr/local/bin/orbit` points directly at
  `<source>/apps/cli/orbit`.
- Node-local mutable Orbit state lives under `~/.config/orbit`.
- Internal executor commands verify operation tokens through the gateway API.
- Nodes do not store executor token signing material.
```

- [ ] **Step 2: Remove contradictory wording**

Replace any blanket statement that every node must run `orbit-runtime` with
role-aware wording:

```markdown
The gateway runs `orbit-runtime` for the API and scheduler. Workload nodes run
the public Orbit CLI as a gateway client and run workloads in role-specific
runtime containers. Any remaining workload-node `orbit-runtime` usage is a
compatibility concern, not the source-mounted live topology contract.
```

- [ ] **Step 3: Run docs lint**

Run:

```bash
composer docs-lint
```

Expected: two `{"tool":"librarian","result":"passed"}` lines with zero issues.

- [ ] **Step 4: Commit**

Run:

```bash
git add apps/docs/content/architecture.md apps/docs/content/tech-stack.md apps/docs/content/concepts.md apps/docs/content/domains/1_node/node-concepts.md apps/docs/content/execution-lanes.md apps/docs/content/testing/README.md
git commit -m "Document source-mounted topology contract"
```

---

### Task 2: Move Gateway Mutable State To Config Root

**Files:**
- Create: `apps/gateway/bootstrap/orbit_paths.php`
- Modify: `apps/gateway/bootstrap/app.php`
- Modify: `bin/install-orbit`
- Test: `apps/gateway/tests/Feature/Runtime/GatewayStateRootTest.php`
- Test: `apps/gateway/tests/Feature/Runtime/InstallOrbitDockerFirstTest.php`

- [ ] **Step 1: Write failing gateway bootstrap path tests**

Create `apps/gateway/tests/Feature/Runtime/GatewayStateRootTest.php`:

```php
<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('resolves gateway env database and storage under ORBIT_CONFIG_ROOT', function (): void {
    $configRoot = sys_get_temp_dir().'/orbit-config-root-'.bin2hex(random_bytes(4));

    $process = new Process([
        PHP_BINARY,
        '-r',
        'putenv("ORBIT_CONFIG_ROOT='.$configRoot.'"); $paths = require "apps/gateway/bootstrap/orbit_paths.php"; echo json_encode($paths, JSON_THROW_ON_ERROR);',
    ], repo_path());

    $process->mustRun();

    $paths = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($paths['config_root'])->toBe($configRoot)
        ->and($paths['env_path'])->toBe($configRoot.'/gateway/.env')
        ->and($paths['database_path'])->toBe($configRoot.'/gateway/database')
        ->and($paths['storage_path'])->toBe($configRoot.'/gateway/storage');
});

it('falls back to HOME config root when ORBIT_CONFIG_ROOT is absent', function (): void {
    $home = sys_get_temp_dir().'/orbit-home-'.bin2hex(random_bytes(4));

    $process = new Process([
        PHP_BINARY,
        '-r',
        'putenv("ORBIT_CONFIG_ROOT"); putenv("HOME='.$home.'"); $paths = require "apps/gateway/bootstrap/orbit_paths.php"; echo json_encode($paths, JSON_THROW_ON_ERROR);',
    ], repo_path());

    $process->mustRun();

    $paths = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($paths['config_root'])->toBe($home.'/.config/orbit')
        ->and($paths['env_path'])->toBe($home.'/.config/orbit/gateway/.env');
});
```

- [ ] **Step 2: Run failing test**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Runtime/GatewayStateRootTest.php
```

Expected: FAIL because `apps/gateway/bootstrap/orbit_paths.php` does not exist.

- [ ] **Step 3: Add bootstrap path helper**

Create `apps/gateway/bootstrap/orbit_paths.php`:

```php
<?php

declare(strict_types=1);

$configRoot = getenv('ORBIT_CONFIG_ROOT');

if (! is_string($configRoot) || trim($configRoot) === '') {
    $home = getenv('HOME');

    if (! is_string($home) || trim($home) === '') {
        $home = '/home/orbit';
    }

    $configRoot = rtrim($home, '/').'/.config/orbit';
}

$configRoot = rtrim($configRoot, '/');
$gatewayRoot = $configRoot.'/gateway';

return [
    'config_root' => $configRoot,
    'gateway_root' => $gatewayRoot,
    'env_path' => $gatewayRoot.'/.env',
    'database_path' => $gatewayRoot.'/database',
    'storage_path' => $gatewayRoot.'/storage',
];
```

- [ ] **Step 4: Wire Laravel bootstrap to external env/database/storage**

Modify `apps/gateway/bootstrap/app.php` so it loads paths before app creation:

```php
$gatewayRoot = dirname(__DIR__);
$orbitPaths = require __DIR__.'/orbit_paths.php';

$app = Application::configure(basePath: $gatewayRoot)
    ->withProviders(require __DIR__.'/providers.php', withBootstrapProviders: false)
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();

$app
    ->useEnvironmentPath(dirname($orbitPaths['env_path']))
    ->loadEnvironmentFrom(basename($orbitPaths['env_path']))
    ->useDatabasePath($orbitPaths['database_path'])
    ->useStoragePath($orbitPaths['storage_path'])
    ->usePublicPath($gatewayRoot.'/public');
```

- [ ] **Step 5: Update installer state-root setup**

In `bin/install-orbit`, add near the top:

```bash
CONFIG_ROOT="${ORBIT_CONFIG_ROOT:-$HOME/.config/orbit}"
GATEWAY_STATE_DIR="$CONFIG_ROOT/gateway"
GATEWAY_ENV_FILE="$GATEWAY_STATE_DIR/.env"
GATEWAY_DATABASE_DIR="$GATEWAY_STATE_DIR/database"
GATEWAY_STORAGE_DIR="$GATEWAY_STATE_DIR/storage"
```

Update `read_env_var` and `write_env_var` to use `$GATEWAY_ENV_FILE` instead
of `$TARGET_DIR/apps/gateway/.env`.

Update `bootstrap_runtime` directory creation:

```bash
run mkdir -p "$GATEWAY_STATE_DIR" "$GATEWAY_DATABASE_DIR" "$GATEWAY_STORAGE_DIR"

if [ ! -f "$GATEWAY_ENV_FILE" ]; then
    run cp "$TARGET_DIR/apps/gateway/.env.example" "$GATEWAY_ENV_FILE"
fi

if [ ! -f "$GATEWAY_DATABASE_DIR/database.sqlite" ]; then
    run touch "$GATEWAY_DATABASE_DIR/database.sqlite"
fi
```

Add `ORBIT_CONFIG_ROOT` env and config-root bind mount to `docker run` calls
that boot or run `orbit-runtime`:

```bash
--env "ORBIT_CONFIG_ROOT=$CONFIG_ROOT" \
--mount "type=bind,source=$CONFIG_ROOT,target=$CONFIG_ROOT" \
```

- [ ] **Step 6: Extend installer tests**

In `apps/gateway/tests/Feature/Runtime/InstallOrbitDockerFirstTest.php`, assert
the installer no longer creates source-tree gateway state and mounts config
root:

```php
expect($installer)->toContain('GATEWAY_ENV_FILE="$GATEWAY_STATE_DIR/.env"')
    ->and($installer)->toContain('--env "ORBIT_CONFIG_ROOT=$CONFIG_ROOT"')
    ->and($installer)->toContain('--mount "type=bind,source=$CONFIG_ROOT,target=$CONFIG_ROOT"')
    ->and($installer)->not->toContain('$TARGET_DIR/apps/gateway/database/database.sqlite');
```

- [ ] **Step 7: Run focused tests**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Runtime/GatewayStateRootTest.php apps/gateway/tests/Feature/Runtime/InstallOrbitDockerFirstTest.php
```

Expected: PASS.

- [ ] **Step 8: Format and commit**

Run:

```bash
bin/orbit-gateway-vendor-bin pint --dirty --format agent
git add apps/gateway/bootstrap/orbit_paths.php apps/gateway/bootstrap/app.php bin/install-orbit apps/gateway/tests/Feature/Runtime/GatewayStateRootTest.php apps/gateway/tests/Feature/Runtime/InstallOrbitDockerFirstTest.php
git commit -m "Move gateway state to Orbit config root"
```

---

### Task 3: Make `apps/cli/orbit` The Source Entrypoint

**Files:**
- Modify: `apps/cli/orbit`
- Modify: `apps/cli/config/orbit.php`
- Modify: `bin/orbit`
- Test: `apps/cli/tests/Feature/CliEntrypointTest.php`
- Test: `apps/gateway/tests/Feature/InstallOrbitLauncherTest.php`

- [ ] **Step 1: Write failing CLI entrypoint tests**

Create `apps/cli/tests/Feature/CliEntrypointTest.php`:

```php
<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('source entrypoint sets ORBIT_HOST_CWD from process cwd when absent', function (): void {
    $cwd = repo_path('apps/cli');

    $process = new Process([
        PHP_BINARY,
        repo_path('apps/cli/orbit'),
        'profile',
        '--json',
    ], $cwd, [
        'ORBIT_HOST_CWD' => false,
    ]);

    $process->run();

    expect($process->getCommandLine())->toContain('apps/cli/orbit');
});

it('source entrypoint does not require ORBIT_REPO', function (): void {
    $process = new Process([
        PHP_BINARY,
        repo_path('apps/cli/orbit'),
        '--version',
    ], repo_path(), [
        'ORBIT_REPO' => false,
    ]);

    $process->mustRun();

    expect($process->getOutput())->toContain('Orbit');
});
```

- [ ] **Step 2: Run failing test**

Run:

```bash
bin/orbit-cli-pest --compact apps/cli/tests/Feature/CliEntrypointTest.php
```

Expected: FAIL until the entrypoint owns launcher env setup or current tests
need small assertion adjustment based on exact `profile --json` behavior.

- [ ] **Step 3: Move launcher env setup into `apps/cli/orbit`**

At the top of `apps/cli/orbit`, after `declare(strict_types=1);`, add:

```php
$hostCwd = getenv('ORBIT_HOST_CWD');

if (! is_string($hostCwd) || $hostCwd === '') {
    $cwd = getcwd();
    $hostCwd = is_string($cwd) && $cwd !== '' ? $cwd : dirname(__DIR__, 2);
    putenv("ORBIT_HOST_CWD={$hostCwd}");
    $_SERVER['ORBIT_HOST_CWD'] = $hostCwd;
}

putenv('ORBIT_APP=cli');
$_SERVER['ORBIT_APP'] = 'cli';
```

- [ ] **Step 4: Remove executor shared-secret config from CLI env config**

In `apps/cli/config/orbit.php`, remove:

```php
'executor' => [
    'shared_secret' => env('ORBIT_EXECUTOR_SECRET'),
    'node_identity' => env('ORBIT_NODE_IDENTITY'),
],
```

- [ ] **Step 5: Keep `bin/orbit` as a convenience wrapper only**

Simplify `bin/orbit` to exec the source entrypoint without reading gateway env
or executor secrets:

```bash
#!/usr/bin/env bash

set -Eeuo pipefail

resolve_launcher_path() {
    local source directory
    source="${BASH_SOURCE[0]}"

    while [ -L "$source" ]; do
        directory="$(cd "$(dirname "$source")" >/dev/null 2>&1 && pwd)"
        source="$(readlink "$source")"

        if [[ "$source" != /* ]]; then
            source="${directory}/${source}"
        fi
    done

    cd "$(dirname "$source")" >/dev/null 2>&1 && pwd
}

launcher_directory="$(resolve_launcher_path)"
repo_root="$(cd "${launcher_directory}/.." >/dev/null 2>&1 && pwd)"

exec "${repo_root}/apps/cli/orbit" "$@"
```

- [ ] **Step 6: Update launcher tests**

In `apps/gateway/tests/Feature/InstallOrbitLauncherTest.php`, replace assertions
that expect `ORBIT_EXECUTOR_SECRET` export with assertions that the wrapper does
not read `apps/gateway/.env` and executes `apps/cli/orbit`.

Use this assertion shape:

```php
expect($launcher)->toContain('exec "${repo_root}/apps/cli/orbit" "$@"')
    ->and($launcher)->not->toContain('ORBIT_EXECUTOR_SECRET')
    ->and($launcher)->not->toContain('apps/gateway/.env');
```

- [ ] **Step 7: Run focused tests**

Run:

```bash
bin/orbit-cli-pest --compact apps/cli/tests/Feature/CliEntrypointTest.php
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/InstallOrbitLauncherTest.php
```

Expected: PASS.

- [ ] **Step 8: Format and commit**

Run:

```bash
cd apps/cli && vendor/bin/pint --dirty --format agent
bin/orbit-gateway-vendor-bin pint --dirty --format agent
git add apps/cli/orbit apps/cli/config/orbit.php bin/orbit apps/cli/tests/Feature/CliEntrypointTest.php apps/gateway/tests/Feature/InstallOrbitLauncherTest.php
git commit -m "Make CLI source entrypoint direct"
```

---

### Task 4: Add Gateway Operation Token Introspection

**Files:**
- Create: `apps/gateway/app/Services/Operations/OperationTokenIntrospector.php`
- Create: `apps/gateway/app/Http/Controllers/Api/InternalExecutorTokenController.php`
- Modify: `apps/gateway/routes/api.php`
- Test: `apps/gateway/tests/Feature/Http/Api/InternalExecutorTokenControllerTest.php`
- Test: `apps/gateway/tests/Unit/Services/Operations/OperationTokenIntrospectorTest.php`

- [ ] **Step 1: Write failing introspector unit test**

Create `apps/gateway/tests/Unit/Services/Operations/OperationTokenIntrospectorTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Operations\OperationTokenFactory;
use App\Services\Operations\OperationTokenIntrospector;
use Orbit\Core\Security\OperationTokenSigner;

it('allows a valid token for the authenticated target node and command', function (): void {
    $node = Node::factory()->create(['name' => 'app-dev-1', 'status' => 'active']);
    $factory = new OperationTokenFactory(new OperationTokenSigner, 'gateway-secret', 120, fn (): int => time());
    $token = $factory->mint('operation-1', 'app-dev-1', 'internal:executor:verify')->toString();

    $result = (new OperationTokenIntrospector('gateway-secret'))->introspect(
        token: $token,
        command: 'internal:executor:verify',
        authenticatedNode: $node,
    );

    expect($result['allowed'])->toBeTrue()
        ->and($result['operation_id'])->toBe('operation-1');
});

it('denies a token for a different node', function (): void {
    $node = Node::factory()->create(['name' => 'app-dev-2', 'status' => 'active']);
    $factory = new OperationTokenFactory(new OperationTokenSigner, 'gateway-secret', 120, fn (): int => time());
    $token = $factory->mint('operation-1', 'app-dev-1', 'internal:executor:verify')->toString();

    $result = (new OperationTokenIntrospector('gateway-secret'))->introspect(
        token: $token,
        command: 'internal:executor:verify',
        authenticatedNode: $node,
    );

    expect($result['allowed'])->toBeFalse()
        ->and($result['reason'])->toBe('target_node_mismatch');
});
```

- [ ] **Step 2: Run failing unit test**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Operations/OperationTokenIntrospectorTest.php
```

Expected: FAIL because the service does not exist.

- [ ] **Step 3: Implement introspector service**

Create `apps/gateway/app/Services/Operations/OperationTokenIntrospector.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\Node;
use Orbit\Core\Security\OperationToken;
use Orbit\Core\Security\OperationTokenVerifier;
use Throwable;

final readonly class OperationTokenIntrospector
{
    public function __construct(
        private string $secret,
    ) {}

    /**
     * @return array{allowed: bool, reason: string|null, operation_id: string|null}
     */
    public function introspect(string $token, string $command, Node $authenticatedNode): array
    {
        try {
            $parsed = OperationToken::parse($token);
        } catch (Throwable) {
            return ['allowed' => false, 'reason' => 'invalid_token', 'operation_id' => null];
        }

        if ($parsed->node !== $authenticatedNode->name) {
            return ['allowed' => false, 'reason' => 'target_node_mismatch', 'operation_id' => $parsed->id];
        }

        if ($parsed->command !== $command) {
            return ['allowed' => false, 'reason' => 'command_mismatch', 'operation_id' => $parsed->id];
        }

        $valid = (new OperationTokenVerifier)->verify(
            secret: $this->secret,
            token: $parsed,
            expectedNode: $authenticatedNode->name,
            expectedCommand: $command,
        );

        return [
            'allowed' => $valid,
            'reason' => $valid ? null : 'invalid_token',
            'operation_id' => $parsed->id,
        ];
    }
}
```

- [ ] **Step 4: Add API controller and route**

Create `apps/gateway/app/Http/Controllers/Api/InternalExecutorTokenController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Node;
use App\Services\Operations\OperationTokenIntrospector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class InternalExecutorTokenController
{
    public function __invoke(Request $request, OperationTokenIntrospector $introspector): JsonResponse
    {
        /** @var Node $node */
        $node = $request->user();

        $validated = $request->validate([
            'operation_token' => ['required', 'string'],
            'command' => ['required', 'string', 'starts_with:internal:'],
        ]);

        $result = $introspector->introspect(
            token: $validated['operation_token'],
            command: $validated['command'],
            authenticatedNode: $node,
        );

        return response()->json([
            'success' => [
                'data' => $result,
            ],
        ]);
    }
}
```

Register in `apps/gateway/routes/api.php` inside the authenticated middleware
group:

```php
Route::post('/internal-executor/token/verify', InternalExecutorTokenController::class);
```

Bind the service in `AppServiceProvider`:

```php
$this->app->bind(OperationTokenIntrospector::class, fn (): OperationTokenIntrospector => new OperationTokenIntrospector(
    secret: $this->operationTokenSecret(),
));
```

- [ ] **Step 5: Add feature API test**

Create `apps/gateway/tests/Feature/Http/Api/InternalExecutorTokenControllerTest.php`
using existing API identity helpers from sibling API tests. Assert:

```php
expect($response->json('success.data.allowed'))->toBeTrue();
```

and for a wrong node:

```php
expect($response->json('success.data.allowed'))->toBeFalse()
    ->and($response->json('success.data.reason'))->toBe('target_node_mismatch');
```

- [ ] **Step 6: Run focused gateway tests**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Operations/OperationTokenIntrospectorTest.php apps/gateway/tests/Feature/Http/Api/InternalExecutorTokenControllerTest.php
```

Expected: PASS.

- [ ] **Step 7: Format and commit**

Run:

```bash
bin/orbit-gateway-vendor-bin pint --dirty --format agent
git add apps/gateway/app/Services/Operations/OperationTokenIntrospector.php apps/gateway/app/Http/Controllers/Api/InternalExecutorTokenController.php apps/gateway/routes/api.php apps/gateway/app/Providers/AppServiceProvider.php apps/gateway/tests/Unit/Services/Operations/OperationTokenIntrospectorTest.php apps/gateway/tests/Feature/Http/Api/InternalExecutorTokenControllerTest.php
git commit -m "Add gateway internal executor token verification"
```

---

### Task 5: Switch CLI Internal Guard To Gateway Verification

**Files:**
- Modify: `apps/cli/app/Services/Executor/OperationTokenGuard.php`
- Modify: `apps/cli/app/Providers/OperationTokenGuardServiceProvider.php`
- Modify: `apps/cli/app/Services/GatewayApiClient.php`
- Test: `apps/cli/tests/Feature/InternalExecutorCommandTest.php`
- Test: `apps/cli/tests/Feature/Commands/Internal/VerifyExecutorCommandTest.php`

- [ ] **Step 1: Update failing CLI tests**

In `apps/cli/tests/Feature/InternalExecutorCommandTest.php`, replace
`configureOperationTokenGuard()` shared-secret setup with a fake
`GatewayApiClient` binding:

```php
app()->instance(App\Services\GatewayApiClient::class, new class extends App\Services\GatewayApiClient {
    public function __construct() {}

    public function post(string $path, array $payload = []): array
    {
        expect($path)->toBe('/internal-executor/token/verify');

        return [
            'success' => [
                'data' => [
                    'allowed' => true,
                    'reason' => null,
                    'operation_id' => 'operation-verify-1',
                ],
            ],
        ];
    }
});
```

Add a denied fake for invalid-token tests:

```php
return [
    'success' => [
        'data' => [
            'allowed' => false,
            'reason' => 'invalid_token',
            'operation_id' => null,
        ],
    ],
];
```

- [ ] **Step 2: Run failing CLI tests**

Run:

```bash
bin/orbit-cli-pest --compact apps/cli/tests/Feature/InternalExecutorCommandTest.php apps/cli/tests/Feature/Commands/Internal/VerifyExecutorCommandTest.php
```

Expected: FAIL because `OperationTokenGuard` still requires local shared secret.

- [ ] **Step 3: Replace local guard constructor**

Modify `apps/cli/app/Services/Executor/OperationTokenGuard.php`:

```php
public function __construct(
    private GatewayApiClient $gateway,
) {}
```

Replace local HMAC verification with:

```php
$response = $this->gateway->post('/internal-executor/token/verify', [
    'operation_token' => $compactToken,
    'command' => $expectedCommand,
]);

$allowed = $response['success']['data']['allowed'] ?? false;

if ($allowed !== true) {
    throw new OperationTokenGuardException;
}
```

Keep parse-free validation on the CLI side limited to required string presence;
the gateway owns semantic validation.

- [ ] **Step 4: Update provider**

Modify `apps/cli/app/Providers/OperationTokenGuardServiceProvider.php`:

```php
$this->app->singleton(OperationTokenGuard::class, fn ($app): OperationTokenGuard => new OperationTokenGuard(
    gateway: $app->make(GatewayApiClient::class),
));
```

Remove `OperationTokenVerifier` import and nullable string helper.

- [ ] **Step 5: Run CLI tests**

Run:

```bash
bin/orbit-cli-pest --compact apps/cli/tests/Feature/InternalExecutorCommandTest.php apps/cli/tests/Feature/Commands/Internal/VerifyExecutorCommandTest.php apps/cli/tests/Feature/InternalDatabaseQueryLocalCommandTest.php apps/cli/tests/Feature/InternalWorkspaceAdapterLookupCommandTest.php
```

Expected: PASS.

- [ ] **Step 6: Search for removed secret usage**

Run:

```bash
rg -n "ORBIT_EXECUTOR_SECRET|executor\\.shared_secret|OperationTokenVerifier" apps/cli bin apps/gateway/tests/Feature/InstallOrbitLauncherTest.php
```

Expected: no production CLI or launcher matches. Gateway minting secret
`ORBIT_OPERATION_TOKEN_SECRET` remains.

- [ ] **Step 7: Format and commit**

Run:

```bash
cd apps/cli && vendor/bin/pint --dirty --format agent
git add apps/cli/app/Services/Executor/OperationTokenGuard.php apps/cli/app/Providers/OperationTokenGuardServiceProvider.php apps/cli/app/Services/GatewayApiClient.php apps/cli/tests/Feature/InternalExecutorCommandTest.php apps/cli/tests/Feature/Commands/Internal/VerifyExecutorCommandTest.php apps/cli/tests/Feature/InternalDatabaseQueryLocalCommandTest.php apps/cli/tests/Feature/InternalWorkspaceAdapterLookupCommandTest.php
git commit -m "Verify internal executor tokens through gateway"
```

---

### Task 6: Remove Executor Shared Secret From Installer And Bootstrap

**Files:**
- Modify: `bin/install-orbit`
- Modify: `apps/gateway/app/Services/OrbitHostInstaller.php`
- Modify: `apps/e2e/app/E2E/Support/E2ECurrentCheckout.php`
- Test: `apps/gateway/tests/Feature/Runtime/InstallOrbitDockerFirstTest.php`
- Test: `apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php`
- Test: `apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php`

- [ ] **Step 1: Write/update tests to reject executor secret writes**

Update the three test files to assert:

```php
expect($script)->not->toContain('ORBIT_EXECUTOR_SECRET')
    ->and($script)->toContain('ORBIT_OPERATION_TOKEN_SECRET');
```

Use `$installer`, `$script`, or generated command variable names already present
in each test.

- [ ] **Step 2: Run failing focused tests**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Runtime/InstallOrbitDockerFirstTest.php apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php
```

Expected: FAIL because current scripts still write or assert
`ORBIT_EXECUTOR_SECRET`.

- [ ] **Step 3: Remove executor secret writers**

In `bin/install-orbit`, remove `ensure_executor_secret` behavior and every
`ORBIT_EXECUTOR_SECRET` export/write. Keep `ensure_operation_token_secret`.

In `apps/gateway/app/Services/OrbitHostInstaller.php`, stop writing
`ORBIT_EXECUTOR_SECRET` into remote env archives.

In `apps/e2e/app/E2E/Support/E2ECurrentCheckout.php`, stop seeding
`ORBIT_EXECUTOR_SECRET` and seed only `ORBIT_OPERATION_TOKEN_SECRET`.

- [ ] **Step 4: Run focused tests**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Runtime/InstallOrbitDockerFirstTest.php apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php
```

Expected: PASS.

- [ ] **Step 5: Format and commit**

Run:

```bash
bin/orbit-gateway-vendor-bin pint --dirty --format agent
git add bin/install-orbit apps/gateway/app/Services/OrbitHostInstaller.php apps/e2e/app/E2E/Support/E2ECurrentCheckout.php apps/gateway/tests/Feature/Runtime/InstallOrbitDockerFirstTest.php apps/gateway/tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php
git commit -m "Remove node executor shared secret"
```

---

### Task 7: Add Docker Source-Mounted Topology Mode

**Files:**
- Modify: `apps/e2e/app/E2E/Support/E2ECurrentCheckout.php`
- Modify: `apps/e2e/app/E2E/Support/DockerTopologyProvider.php`
- Modify: `apps/e2e/app/E2E/Support/DockerTopologyBuilder.php`
- Test: `apps/e2e/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php`
- Test: `apps/e2e/tests/Feature/E2ESupport/DockerTopologyProviderTest.php`
- Test: `apps/e2e/tests/Feature/Topology/PreparedTopologyContractTest.php`

- [ ] **Step 1: Write failing Docker topology assertions**

In `apps/e2e/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php`, assert the
source wrapper links directly to CLI:

```php
expect($script)->toContain('ln -sfn')
    ->and($script)->toContain('/apps/cli/orbit')
    ->and($script)->not->toContain('/bin/orbit-binary')
    ->and($script)->not->toContain('/bin/orbit');
```

In Docker provider tests, assert source mount arguments include the checkout
path and config-root mount:

```php
expect($command)->toContain('type=bind,source=')
    ->and($command)->toContain('target=/home/orbit/orbit')
    ->and($command)->toContain('ORBIT_CONFIG_ROOT=/home/orbit/.config/orbit');
```

- [ ] **Step 2: Run failing E2E support tests**

Run:

```bash
cd apps/e2e && vendor/bin/pest --compact tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php tests/Feature/E2ESupport/DockerTopologyProviderTest.php
```

Expected: FAIL because Docker support still copies/overlays source and wrapper
scripts assume the old launcher path.

- [ ] **Step 3: Implement Docker source mount**

Update Docker topology creation so each node container gets:

```bash
--mount type=bind,source="$CURRENT_WORKTREE",target=/home/orbit/orbit
--env ORBIT_CONFIG_ROOT=/home/orbit/.config/orbit
```

During node setup, link:

```bash
sudo ln -sfn /home/orbit/orbit/apps/cli/orbit /usr/local/bin/orbit
```

Keep gateway runtime source path mounted to the same checkout path and mount
`/home/orbit/.config/orbit` into `orbit-runtime`.

- [ ] **Step 4: Ensure source tree stays clean**

Add a test helper assertion that generated Docker topology setup does not write:

```text
apps/gateway/.env
apps/gateway/database/database.sqlite
apps/gateway/storage/app/orbit
```

Use command-string assertions first; add a real Docker E2E assertion only after
the command-string tests pass.

- [ ] **Step 5: Run Docker support tests**

Run:

```bash
cd apps/e2e && vendor/bin/pest --compact tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php tests/Feature/E2ESupport/DockerTopologyProviderTest.php tests/Feature/Topology/PreparedTopologyContractTest.php
```

Expected: PASS.

- [ ] **Step 6: Format and commit**

Run:

```bash
cd apps/e2e && vendor/bin/pint --dirty --format agent
git add apps/e2e/app/E2E/Support/E2ECurrentCheckout.php apps/e2e/app/E2E/Support/DockerTopologyProvider.php apps/e2e/app/E2E/Support/DockerTopologyBuilder.php apps/e2e/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php apps/e2e/tests/Feature/E2ESupport/DockerTopologyProviderTest.php apps/e2e/tests/Feature/Topology/PreparedTopologyContractTest.php
git commit -m "Mount source in Docker live topologies"
```

---

### Task 8: Add Incus Source-Mounted Live Topology Mode

**Files:**
- Modify: `apps/e2e/app/E2E/Support/IncusHost.php`
- Modify: `apps/e2e/app/E2E/Support/IncusTopologyBuilder.php`
- Modify: `apps/e2e/app/E2E/Support/IncusTopologyTemplate.php`
- Modify: `apps/e2e/app/Console/Commands/E2EIncusCommand.php`
- Test: `apps/e2e/tests/Feature/E2ESupport/IncusTopologyBuilderTest.php`
- Test: `apps/e2e/tests/Feature/E2ESupport/IncusTopologyTemplateTest.php`
- Test: `apps/e2e/tests/Feature/E2ESupport/Commands/E2EIncusCommandTest.php`

- [ ] **Step 1: Write failing Incus source-mount tests**

In `IncusTopologyTemplateTest`, assert generated Incus config adds a disk
device for the current worktree:

```php
expect($script)->toContain('incus config device add')
    ->and($script)->toContain('source=')
    ->and($script)->toContain('path=/home/orbit/orbit');
```

In `E2EIncusCommandTest`, assert `--live` uses source-mounted checkout roles:

```php
expect($output)->toContain('Source-mounted checkout')
    ->and($output)->toContain('/home/orbit/orbit/apps/cli/orbit');
```

- [ ] **Step 2: Run failing Incus support tests**

Run:

```bash
cd apps/e2e && vendor/bin/pest --compact tests/Feature/E2ESupport/IncusTopologyBuilderTest.php tests/Feature/E2ESupport/IncusTopologyTemplateTest.php tests/Feature/E2ESupport/Commands/E2EIncusCommandTest.php
```

Expected: FAIL because Incus still copies or overlays current checkout rather
than mounting the live worktree.

- [ ] **Step 3: Implement Incus disk mount setup**

For each retained live VM, add or update an Incus disk device:

```bash
incus config device add "$instance" orbit-source disk source="$CURRENT_WORKTREE" path=/home/orbit/orbit shift=true
```

If the device already exists, update it:

```bash
incus config device set "$instance" orbit-source source="$CURRENT_WORKTREE"
incus config device set "$instance" orbit-source path=/home/orbit/orbit
```

Use the existing Incus command builder/runner style in `IncusTopologyTemplate`
or `IncusHost`; do not shell out ad hoc from unrelated classes.

- [ ] **Step 4: Link source CLI in VMs**

During Incus node setup, run:

```bash
sudo ln -sfn /home/orbit/orbit/apps/cli/orbit /usr/local/bin/orbit
sudo install -d -m 0700 -o orbit -g orbit /home/orbit/.config/orbit /home/orbit/.config/orbit/gateway
```

Ensure gateway `orbit-runtime` receives:

```bash
ORBIT_CONFIG_ROOT=/home/orbit/.config/orbit
--mount type=bind,source=/home/orbit/.config/orbit,target=/home/orbit/.config/orbit
```

- [ ] **Step 5: Run Incus support tests**

Run:

```bash
cd apps/e2e && vendor/bin/pest --compact tests/Feature/E2ESupport/IncusTopologyBuilderTest.php tests/Feature/E2ESupport/IncusTopologyTemplateTest.php tests/Feature/E2ESupport/Commands/E2EIncusCommandTest.php
```

Expected: PASS.

- [ ] **Step 6: Format and commit**

Run:

```bash
cd apps/e2e && vendor/bin/pint --dirty --format agent
git add apps/e2e/app/E2E/Support/IncusHost.php apps/e2e/app/E2E/Support/IncusTopologyBuilder.php apps/e2e/app/E2E/Support/IncusTopologyTemplate.php apps/e2e/app/Console/Commands/E2EIncusCommand.php apps/e2e/tests/Feature/E2ESupport/IncusTopologyBuilderTest.php apps/e2e/tests/Feature/E2ESupport/IncusTopologyTemplateTest.php apps/e2e/tests/Feature/E2ESupport/Commands/E2EIncusCommandTest.php
git commit -m "Mount source in Incus live topologies"
```

---

### Task 9: Preserve Artifact Verification

**Files:**
- Modify: `apps/e2e/app/E2E/Support/OrbitCliBinaryBundle.php`
- Modify: `apps/e2e/tests/Feature/Binary/BinarySelfTest.php`
- Modify: `apps/e2e/tests/Feature/E2ESupport/Commands/E2EPrepareDockerRuntimeCommandTest.php`
- Modify: `apps/docs/content/testing/README.md`

- [ ] **Step 1: Add artifact-lane regression assertions**

In binary and runtime preparation tests, assert artifact paths do not depend on
source-mounted entrypoints:

```php
expect($output)->toContain('orbit-binary')
    ->and($output)->not->toContain('apps/cli/orbit');
```

For runtime image tests:

```php
expect($command)->toContain('docker/orbit-runtime/Dockerfile')
    ->and($command)->toContain('orbit-runtime:prepared-current');
```

- [ ] **Step 2: Run artifact focused tests**

Run:

```bash
cd apps/e2e && vendor/bin/pest --compact tests/Feature/Binary/BinarySelfTest.php tests/Feature/E2ESupport/Commands/E2EPrepareDockerRuntimeCommandTest.php
```

Expected: PASS after assertions reflect the actual artifact command output.

- [ ] **Step 3: Update testing docs**

In `apps/docs/content/testing/README.md`, keep the lane order explicit:

```markdown
Source-mounted Docker and Incus lanes are the feature feedback loops.
The artifact lane builds the native CLI binary and runtime image after source
lanes pass; it catches packaging and installer regressions.
```

- [ ] **Step 4: Run docs and focused tests**

Run:

```bash
composer docs-lint
cd apps/e2e && vendor/bin/pest --compact tests/Feature/Binary/BinarySelfTest.php tests/Feature/E2ESupport/Commands/E2EPrepareDockerRuntimeCommandTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

Run:

```bash
git add apps/e2e/app/E2E/Support/OrbitCliBinaryBundle.php apps/e2e/tests/Feature/Binary/BinarySelfTest.php apps/e2e/tests/Feature/E2ESupport/Commands/E2EPrepareDockerRuntimeCommandTest.php apps/docs/content/testing/README.md
git commit -m "Preserve artifact verification lane"
```

---

### Task 10: Integrated Verification

**Files:**
- No new files.
- Verify: source-mounted unit/feature coverage, Docker E2E, Incus live smoke,
  artifact lane.

- [ ] **Step 1: Run broad quality check**

Run:

```bash
composer quality-check
```

Expected: PASS.

- [ ] **Step 2: Run Docker E2E**

Run:

```bash
composer test:e2e:docker
```

Expected: PASS. Confirm output shows source-mounted checkout setup rather than
native binary download for live/source topology paths.

- [ ] **Step 3: Run Incus retained live smoke**

Run:

```bash
composer e2e:incus -- --live --topology=operator_gateway_app-dev_app-prod --dry-run
```

Expected: output includes source-mounted checkout roles and release command.

Then run a real retained topology only when local Incus capacity is available:

```bash
composer e2e:incus -- --live --topology=operator_gateway_app-dev_app-prod
```

Expected: retained topology starts, `/usr/local/bin/orbit` in each VM resolves
to `/home/orbit/orbit/apps/cli/orbit`, gateway state is under
`/home/orbit/.config/orbit`, and the printed release command reaps it.

- [ ] **Step 4: Run artifact lane**

Run:

```bash
composer test:e2e:provision
```

Expected: PASS. This proves production-like installer/runtime behavior remains
covered separately from source-mounted development lanes.

- [ ] **Step 5: Final source cleanliness check**

Run:

```bash
git status --short
find apps/gateway -maxdepth 3 \( -name '.env' -o -name 'database.sqlite' \) -print
```

Expected: no unexpected tracked or untracked gateway runtime state appears in
the source tree. Existing user-owned untracked files may remain.

---

## Open Questions

None. The design decisions needed for implementation are captured in
`docs/superpowers/specs/2026-06-01-source-mounted-live-topologies-design.md`.
