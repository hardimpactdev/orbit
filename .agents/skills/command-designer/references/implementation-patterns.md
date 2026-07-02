# Implementation Patterns

Use this reference when implementing Orbit commands in PHP after the command
contract is clear.

## Conventions

- Commands must not call `exec()`, `shell_exec()`, `proc_open()`, `passthru()`,
  or `system()` directly. Process execution lives in service classes under
  `apps/cli/app/Services/` (for example `GatewayApiClient`,
  `LocalPlatformDetector`, `LocalDatabaseQueryAction`); commands call the
  service.
- All command files must declare `strict_types=1`.
- `protected $hidden = true;` is required on internal or deprecated commands
  that must not appear in the public command list (see
  `Internal/WorkspaceAdapterUpdateCommand`).
- Use the repository's Laravel/PHP skills and formatting rules for PHP changes.

## Command Bases

Every public command extends one of three abstract bases in
`apps/cli/app/Commands/`. All three pull in
`App\Commands\Concerns\EmitsCanonicalEnvelopes`, which owns the JSON envelope
and input-mode helpers.

| Base | Use for |
| --- | --- |
| `GatewayCommand` | Commands that read or mutate gateway registry state. Provides `gateway()`, `gatewayGet()`, `gatewayPost()`, `gatewayPut()`, `gatewayPatch()`, `gatewayDelete()`, and `renderGatewayFailure()`. |
| `LocalOnlyCommand` | Commands that mutate only caller-local config or environment. No gateway accessor. |
| `BootstrapGatewayCommand` | Commands that must run before a gateway API exists. |

`OrbitCommand` still exists as a deprecated backward-compatible alias of
`GatewayCommand`; new commands must extend one of the three bases directly.

## JSON Envelopes

There is no DTO layer for command responses. The canonical envelope shape is
built by `Orbit\Core\Http\JsonEnvelope` (`packages/core/src/Http/`):
`JsonEnvelope::success($data, $meta)` and
`JsonEnvelope::failure($code, $message, $meta)`.

Commands use the `EmitsCanonicalEnvelopes` helpers instead of hand-building
arrays:

- `renderSuccess($data, $meta)` emits the success envelope in `--json` mode and
  a plain `key: value` fallback in human mode. When `$data` already contains an
  inbound gateway `success` envelope, it is unwrapped so the CLI never emits
  `success.success` — gateway-backed commands can pass the gateway response
  verbatim.
- `renderFailure($code, $message, $meta, $data)` emits the error envelope (or
  `code: message` prose in human mode) and returns `self::FAILURE`.
- `renderGatewayFailure($exception)` on `GatewayCommand` maps a
  `GatewayApiException` to the gateway's own error code/message/meta when the
  gateway returned an envelope, and to `cliFailureCode()` otherwise.

```php
try {
    $response = $this->gatewayGet('/api/apps', $this->filledQuery(['node' => $node]));
} catch (GatewayApiException $exception) {
    return $this->renderGatewayFailure($exception);
}

if ($this->wantsJson()) {
    return $this->renderSuccess($response);
}
```

Human renderers consume the same response array; do not build a second shape
for human output.

## Platform Differences

There is no per-OS handler-class registry. OS-specific behavior lives in
service classes, and `App\Services\Platform\LocalPlatformDetector` resolves the
current platform identifier (`macos_…`, `ubuntu_…`, or the lowercase
`PHP_OS_FAMILY`). Branch on the detector inside a service, not inside a
command, and keep the command contract identical across platforms.

## Abstract Bases Vs Traits

Use abstract base classes when a command family shares the same `handle()`
flow and differs only by configuration such as lifecycle phase or verb. The
abstract base centralizes control flow; subclasses provide variant-specific
values through abstract methods. The real exemplar is
`App\Commands\Workspace\AbstractWorkspaceStepAddCommand`:

```php
abstract class AbstractWorkspaceStepAddCommand extends WorkspaceGatewayCommand
{
    abstract protected function phase(): string;

    abstract protected function phaseLabel(): string;
}

final class WorkspaceSetupStepAddCommand extends AbstractWorkspaceStepAddCommand
{
    protected function phase(): string
    {
        return 'setup';
    }
}
```

Use traits in `apps/cli/app/Commands/Concerns/` for cross-cutting behavior
needed independently across unrelated commands: `EmitsCanonicalEnvelopes`,
`WithStepTree`, `StreamsGatewayProgress`, `RendersShowDetails`,
`ResolvesHostContext`, `ResolvesDefaultNode`,
`PromptsForGatewayRegistryEntities`.

## App And Node Resolution

Commands that accept `--app` and `--node` resolve them with
`ResolvesHostContext`:

- `stringArgument()` / `stringOption()` read trimmed non-empty values.
- `targetNodeOptionOrDefault()` prefers `--node`, defers to `--app` scoping,
  and otherwise falls back to the local default node from `OrbitConfigStore`.
- `hostCwd()` and `appFromOrbitMarker()` resolve the app from the caller's
  working directory marker when no explicit selector was given.
- `filledQuery()` drops null values before building gateway query payloads.

`ResolvesDefaultNode::nodeArgumentOrDefault()` covers the simpler
argument-or-default-node case.

When the value is still missing in interactive input mode, prompt with
`PromptsForGatewayRegistryEntities` (`promptForVisibleApp()`,
`promptForVisibleNode()`, `promptForVisibleWorkspace()`,
`promptForVisibleSchedule()`), which fetch the registry and render a
`Laravel\Prompts\datatable` selection. In non-interactive input mode, including
when `--json` is present, prompts are suppressed and the command returns a
`validation_failed` error before side effects.

## Gateway-Executed Commands

CLI callers reach the gateway over the typed HTTPS API through WireGuard. Do not
forward commands by SSH, do not serialize a whole CLI command into a generic
`/cli` endpoint, and do not fake remote progress by wrapping a blocking JSON
call in a local spinner.

The CLI command contract remains the product surface. Prompting and
non-interactive validation stay caller-side. The typed gateway API is
transport: `GatewayCommand::gatewayGet()`/`gatewayPost()`/… wrap
`GatewayApiClient`, and long-running mutations stream progress through
`StreamsGatewayProgress` (see
[`terminal-output.md`](terminal-output.md#pattern-3-gateway-streamed-progress-sse)).

SSH to the gateway is reserved for gateway infrastructure administration that
explicitly requires SSH, such as VPN administration exceptions documented in the
architecture. Gateway-owned command mutations that can be authenticated and
authorized through the typed API, such as `node:remove`, use HTTPS through
WireGuard from control nodes instead of requiring gateway SSH access.

## Implementation Checklist

1. Read the authoritative command docs before implementation.
2. Verify the requested behavior matches the docs. If it does not, flag the
   conflict and update docs before changing implementation.
3. Resolve input through the selected input mode before side effects.
4. Keep JSON and human renderers separate, backed by the same response array.
5. Use progress trees for human-rendered work that can take longer than one
   second.
6. Keep tests implementation-agnostic: assert command inputs, outputs,
   persisted state, side-effect boundaries, and external calls.
