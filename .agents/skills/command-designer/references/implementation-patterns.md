# Implementation Patterns

Use this reference when implementing Orbit commands in PHP after the command
contract is clear.

## Conventions

- Commands must not call `exec()`, `shell_exec()`, `proc_open()`, `passthru()`,
  or `system()` directly. Use service classes, actions, or platform handlers
  that wrap process execution.
- Once a command family has DTOs in `App\Data\CommandResponses\`, all new
  commands in that family must use them.
- All command files must declare `strict_types=1`.
- `$hidden = true` is required on deprecated aliases.
- Use the repository's Laravel/PHP skills and formatting rules for PHP changes.

## Response DTOs

New command response shapes use `spatie/laravel-data` DTOs from
`App\Data\CommandResponses\`. DTOs are `final class` extending
`Spatie\LaravelData\Data` with `public readonly` constructor properties.

Call `->toArray()` when passing to `respondWithSuccess()` or
`respondWithData()`. The DTO defines the canonical JSON shape; human rendering
consumes the same array.

```php
final class NodeRemovedData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $action,
    ) {}
}

return $this->respondWithSuccess(
    (new NodeRemovedData(name: $name, action: 'removed'))->toArray(),
);
```

Do not return ad-hoc arrays once a command family has DTOs.

## Platform Handlers

Use `HasPlatformHandlers` and handler classes in
`app/Console/Commands/Handlers/{OS}/` for behavior that differs per operating
system.

The command implements `HasPlatformHandlers` and returns a `platformHandlers()`
map of OS value to handler class string. `OrbitCommand::execute()` resolves the
correct handler via `app(Platform::class)->os()` and calls
`handler->handle($command)`. Handlers implement `PlatformHandler`.

```php
public function platformHandlers(): array
{
    return [
        OperatingSystem::Mac->value => MacDnsAddHandler::class,
        OperatingSystem::Linux->value => LinuxDnsAddHandler::class,
    ];
}
```

## Abstract Bases Vs Traits

Use abstract base classes when a command family shares the same
`execute()`/`handle()` flow and differs only by configuration, such as lifecycle
phase, verb, or handler class. The abstract base centralizes control flow;
subclasses provide variant-specific values through abstract methods.

Use traits for cross-cutting behavior needed independently across unrelated
commands: `WithJsonOutput`, `WithSpinner`, `WithStepTree`, `ResolvesApp`.

```php
abstract class AbstractWorkspaceStepAddCommand extends OrbitCommand
{
    abstract protected function phase(): WorkspaceLifecyclePhase;
}

final class WorkspaceSetupStepAddCommand extends AbstractWorkspaceStepAddCommand
{
    protected function phase(): WorkspaceLifecyclePhase
    {
        return WorkspaceLifecyclePhase::Setup;
    }
}
```

## App Resolution

Use `ResolvesApp` for commands that accept `--app` and `--node`. It calls
`AppInstanceResolver::resolveLocalFirst()`, which checks the local node first,
falls back to gateway lookup, and uses interactive input mode for a
disambiguation prompt when multiple matches exist.

In non-interactive input mode, including when `--json` is present, the prompt is
suppressed and a validation error is returned.

```php
use ResolvesApp;

public function handle(): int
{
    $app = $this->resolveApp();

    // $app is ResolvedAppInstanceData.
}
```

## Gateway-Executed Commands

CLI callers reach the gateway over the typed HTTPS API through WireGuard. Do not
forward commands by SSH, do not serialize a whole CLI command into a generic
`/cli` endpoint, and do not fake remote progress by wrapping a blocking JSON
call in a local spinner.

The CLI command contract remains the product surface. Prompting and
non-interactive validation stay caller-side. The typed gateway API is transport.

SSH to the gateway is reserved for gateway infrastructure administration that
explicitly requires SSH, such as VPN administration exceptions documented in the
architecture. Gateway-owned command mutations that can be authenticated and
authorized through the typed API, such as `node:remove`, use HTTPS through
WireGuard from control nodes instead of requiring gateway SSH access.

Human progress for non-gateway callers uses structured gateway events and local
rendering. See [`terminal-output.md`](terminal-output.md#pattern-5-gateway-executed-commands-with-local-rendering).

## Implementation Checklist

1. Read the authoritative command docs before implementation.
2. Verify the requested behavior matches the docs. If it does not, flag the
   conflict and update docs before changing implementation.
3. Resolve input through the selected input mode before side effects.
4. Keep JSON and human renderers separate, backed by the same DTO/data shape.
5. Use progress trees for human-rendered work that can take longer than one
   second.
6. Keep tests implementation-agnostic: assert command inputs, outputs,
   persisted state, side-effect boundaries, and external calls.
