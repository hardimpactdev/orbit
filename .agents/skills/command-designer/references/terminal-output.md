# Terminal Output

Use this reference for the implementation mechanics behind Orbit's terminal
output: ANSI codes, animation patterns, traits, durable operation progress, and
the exact transitional SSE surface. Primitive selection (which renderer or prompt to use, and when) is
product-authority and lives in
[`apps/docs/content/ux/commands/`](../../../../apps/docs/content/ux/commands/README.md).

For the visible behavior of:

- single-entity detail output → see [`apps/docs/content/ux/commands/details/show-detail.md`](../../../../apps/docs/content/ux/commands/details/show-detail.md);
- read-only list output → see [`apps/docs/content/ux/commands/lists/table.md`](../../../../apps/docs/content/ux/commands/lists/table.md);
- interactive row selection → see [`apps/docs/content/ux/commands/lists/data-table-prompt.md`](../../../../apps/docs/content/ux/commands/lists/data-table-prompt.md);
- prompts (text, confirm, select, etc.) → see [`apps/docs/content/ux/commands/inputs/`](../../../../apps/docs/content/ux/commands/inputs/README.md);
- multi-step progress and spinners → see [`apps/docs/content/ux/commands/progress/`](../../../../apps/docs/content/ux/commands/progress/README.md).

This file owns implementation patterns only.

## Core Principle: Instant Output

The user must see output the moment a command runs. Never make the user stare at
a blank terminal while work happens in the background.

Human-rendered commands that may take longer than one second must show an
in-progress tree when run interactively. This includes commands that perform
remote calls, SSH, network I/O, package installation, process startup/shutdown,
WireGuard changes, destructive mutation, or multi-step gateway writes.

Prompting is not a progress state. After prompts complete, render the full tree
before starting side effects.

1. Render the full tree immediately with all steps in `○` idle state.
2. Then start the work and animate `○`/`◉` on active steps.
3. Update each line in place when its step completes.

The tree is the loading state. Fast steps resolve instantly; slow steps keep
pulsing until done.

## Status Icons

| State | Icon | Color | Source |
| --- | --- | --- | --- |
| Idle/waiting | `○` | dim | `SpinnerTreeRenderer::DIM` |
| In progress | `○`/`◉` alternating | cyan | `SpinnerTreeRenderer::SPINNER_FRAMES` |
| Success | `●` | green | `LifecycleSummaryRenderer::success()` |
| Failure | `●` | red | `LifecycleSummaryRenderer::failure()` |
| Warning/skip | `●` | orange | `LifecycleSummaryRenderer::skipped()` |

`Orbit\Core\Progress\SpinnerTreeRenderer` (`packages/core/src/Progress/`) is
the canonical source of ANSI color constants, spinner frames, box-drawing, and
cursor movement. `Orbit\Core\Progress\LifecycleSummaryRenderer` formats the
per-row result lines (dot, padded label, message). Hand-rolled renderers such
as `DoctorPanelRenderer` reuse these constants instead of redefining them.

Footer text is dim only while the command is pending (`Working...`). Final
success footers use `ACCENT` (full-strength) text, and final failure footers
use `RED` text; `StepTree::finishFooter()` owns that rule.

```php
SpinnerTreeRenderer::DIM;    // "\e[38;5;242m"
SpinnerTreeRenderer::ACCENT; // "\e[97m"
SpinnerTreeRenderer::GREEN;  // "\e[32m"
SpinnerTreeRenderer::RED;    // "\e[31m"
SpinnerTreeRenderer::ORANGE; // "\e[38;5;208m"
SpinnerTreeRenderer::RESET;  // "\e[39m"
```

## Execution Patterns

Choose the pattern based on how the work executes. All four run through the
shared progress-tree engine in `packages/core/src/Progress/`, so animation
mechanics (forked ticker process, decorated-output detection, deterministic
plain output in tests) are never re-implemented per command.

### Pattern 1: Sequential Client-Side Steps

Use `runStepTree()` from `App\Commands\Concerns\WithStepTree` when the command
genuinely performs the phases itself (local resolution, then a write, then a
refresh). Each step's `run` closure executes in order; only the active row
animates.

```php
use App\Commands\Concerns\WithStepTree;

$outcome = $this->runStepTree('Setting up project', [
    [
        'label' => 'Changing CLI version',
        'doneLabel' => 'PHP CLI',
        'run' => fn (): string => 'PHP 8.5',
    ],
], doneFooter: 'Success');

return $outcome->isCompleted() ? self::SUCCESS : self::FAILURE;
```

A step closure returns a string to show as the result message and throws to
mark the step failed (red row, red footer, remaining steps never start). When
labels change on completion, use `doneLabel`.

### Pattern 2: Atomic Operation With Documented Phases

Use `runStepOperation()` from `WithStepTree` when the documented phases are all
enacted by a single atomic call — typically one gateway mutation. Every phase
row animates while the work runs and all rows settle green together on success.
On failure no row is falsely marked done; only the footer turns red. See
`NodeRemoveCommand` and `AppRemoveCommand`:

```php
$outcome = $this->runStepOperation(
    "Removing node '{$name}'",
    [
        ['label' => 'Validate removal', 'doneLabel' => 'Validated removal'],
        ['label' => 'Remove WireGuard peer', 'doneLabel' => 'Removed WireGuard peer'],
        ['label' => 'Remove node record', 'doneLabel' => 'Removed node record'],
    ],
    work: fn (): array => $this->removeNode($name),
    doneFooter: "Node '{$name}' removed",
);
```

Both helpers return an `Orbit\Core\Progress\StepTreeResult`; check
`isCompleted()` before rendering follow-up notes.

### Pattern 3: Durable Operation Progress (WebSocket)

Long-running gateway operations first return a durable operation descriptor.
The CLI then uses `App\Services\GatewayOperationStreamSubscriber` to replay the
journal and follow the private operations WebSocket channel. The gateway
persists every typed `Orbit\Core\Progress\ProgressEventType` frame before
publication:

| Event | Purpose |
| --- | --- |
| `tree` | Declares the title and ordered step list before work starts. |
| `step` | Updates one step's status and message. |
| `complete` | Terminates successfully and may carry command data. |
| `error` | Terminates as a command failure with structured error context. |

In human mode the frames drive an animated
`Orbit\Core\Progress\StreamedStepTree` locally; in `--json` mode intermediate
frames are silent and only the terminal frame is emitted; with `--stream-json`
every frame is emitted as a JSON line. Replay and live delivery share the same
renderer and deduplicate by journal cursor. See `DeployRunCommand`:

```php
use App\Services\GatewayOperationStreamSubscriber;

$response = $this->gatewayPost('/api/deploy/run', $payload);
$operationId = data_get($response, 'success.data.operation.uuid');

app(GatewayOperationStreamSubscriber::class)->subscribe(
    $operationId,
    null,
    fn (array $frame): mixed => $this->renderOperationFrame($frame),
);
```

`StreamsGatewayProgress` and direct Server-Sent Events remain only on
exact transitional command surfaces. Do not use them for new operation-backed
commands; port those consumers to the durable operations WebSocket.

Do not fake remote progress by wrapping a blocking JSON call in a local
spinner, and do not forward commands by SSH. Prompting remains a caller-side
input-mode concern.

### Pattern 4: Custom Renderers

Commands with genuinely bespoke output (parallel doctor families, live panels)
build their own renderer on top of the shared primitives instead of redefining
ANSI mechanics: `SpinnerTreeRenderer::spinnerFrames()` for animation frames,
`SpinnerTreeRenderer` constants for colors, and
`LifecycleSummaryRenderer::success()/failure()/skipped()/idle()/spinnerLine()`
for row formatting. See `App\Services\Doctor\DoctorPanelRenderer`.

## Detail And List Commands

Info/detail commands and list commands do not use progress trees unless they
do slow external work. Primitive selection lives in
[`apps/docs/content/ux/commands/lists/`](../../../../apps/docs/content/ux/commands/lists/README.md):
read-only list output uses
[`Laravel\Prompts\table`](../../../../apps/docs/content/ux/commands/lists/table.md) and
interactive row selection uses
[`Laravel\Prompts\datatable`](../../../../apps/docs/content/ux/commands/lists/data-table-prompt.md)
(see `PromptsForGatewayRegistryEntities` for the shared registry-selection
prompts). Show/detail commands use the shared
[`show-detail`](../../../../apps/docs/content/ux/commands/details/show-detail.md) primitive
implemented by `App\Commands\Concerns\RendersShowDetails`.

### Display Conventions

- No `intro()` headers; the table or tree structure is the header.
- Combine `user` and `host` into `user@host` for display, but keep separate
  fields in JSON.
- Prefix TLDs with `.` in display.
- Use `—` for null or empty values (`RendersShowDetails::showDetailValue()`
  already does this).
- Use `<family nice label singular>: <slug-target>` as the detail title, such
  as `App: docs` or `Database connection: ditis-hr`.

### Documentation Fixtures

Use this canonical fixture set unless the command needs a different case:

| Entity | Fixture |
| --- | --- |
| Gateway node | `gateway-1`, WireGuard `10.6.0.2` |
| Control node | `control-1`, WireGuard `10.6.0.8` |
| Development app node | `app-1`, WireGuard `10.6.0.7`, TLD `.test` |
| Production app node | `app-2` |

Use raw public IPs only when the example demonstrates SSH/bootstrap endpoints,
gateway endpoint initialization, or explicit public metadata.

### Show Command Example

```php
use App\Commands\Concerns\RendersShowDetails;

$this->renderShowDetails("App: {$app['name']}", [
    'Domain' => $app['domain'],
    'Environment' => $app['environment'],
    'Node' => $app['node'],
]);
```

### List Command Example

```php
use function Laravel\Prompts\table;

table(
    headers: ['ROLE', 'NAME', 'ENVIRONMENT', 'PLATFORM', 'STATUS'],
    rows: array_map(fn (array $node): array => [
        $node['role'],
        $node['name'],
        $node['environment'] ?? '—',
        $node['platform'],
        $node['status'],
    ], $nodes),
);
```

## Dual Output

Every command with visual output must support both paths:

- JSON path: no ANSI, no spinners; check `wantsJson()` before rendering any
  tree and respond through `renderSuccess()`/`renderFailure()`.
- Human path: full human rendering with progress where applicable.
- Non-decorated fallback for tests and piped output: `StepTree` and
  `SpinnerTreeRenderer` detect undecorated output themselves, fork no ticker
  process, strip ANSI, and write only settled rows, keeping output
  deterministic.

## Building A Progress Tree

Step definitions are arrays with `label`, optional `doneLabel`, and (for
`runStepTree`) a `run` closure:

```php
$steps = [
    [
        'label' => 'In-progress label',
        'doneLabel' => 'Done label',
        'run' => function (): string {
            return 'Result message';
        },
    ],
];
```

A `run` closure returns a string result message (or any value; non-strings
render no message) and throws to fail the step. There is no skip return
convention in the engine; commands that need a warning/skip row render it
through a custom renderer with `LifecycleSummaryRenderer::skipped()`.

Prefer the trait helpers for commands:

| Method | Purpose |
| --- | --- |
| `WithStepTree::runStepTree($title, $steps, $doneFooter, $failFooter)` | Sequential steps; returns `StepTreeResult`. |
| `WithStepTree::runStepOperation($title, $phases, $work, $doneFooter, $failFooter)` | One atomic operation behind documented phases. |

`$doneFooter` may be a string or a closure resolved after the run, so it can
reflect values captured during the work (see `NodeRemoveCommand`'s
drift-aware footer).

Use the low-level core primitives only when a command truly needs custom
rendering:

| Primitive | Purpose |
| --- | --- |
| `SpinnerTreeRenderer::renderFrame($output, $title, $labels, $footer)` | Render header, idle rows, and footer; hides cursor. |
| `SpinnerTreeRenderer::updateLine($output, $index, $total, $content)` | Overwrite one row in place via ANSI cursor movement. |
| `SpinnerTreeRenderer::updateFooter($output, $content)` / `footerLine()` | Overwrite the `└` footer line. |
| `SpinnerTreeRenderer::hideCursor()` / `showCursor()` | Cursor visibility around animation. |
| `SpinnerTreeRenderer::spinnerFrames()` | The canonical `○`/`◉` cyan frames. |
| `LifecycleSummaryRenderer::success()/failure()/skipped()/idle()` | Colored dot + padded label + message row. |
| `LifecycleSummaryRenderer::spinnerLine($frame, $label, $width)` | Format the animated row. |

## ANSI Reference

```text
\e[?25l          hide cursor
\e[?25h          show cursor
\e[{N}A          move up N lines
\e[{N}B          move down N lines
\e[2K            clear current line
\r               carriage return
```

## Traits

All command-side traits live in `apps/cli/app/Commands/Concerns/`.

| Trait | Purpose | When to use |
| --- | --- | --- |
| `WithStepTree` | `runStepTree()` / `runStepOperation()` over the shared `StepTree` engine. | Any command with tree-style progress. |
| `StreamsGatewayProgress` | Consume exact transitional gateway SSE progress. | Existing marked SSE commands only; do not add consumers. |
| `EmitsCanonicalEnvelopes` | `wantsJson()`, `renderSuccess()`, `renderFailure()`, `allowsInteractiveInput()`. | Already included by every command base. |
| `RendersShowDetails` | Tree-shaped single-entity detail output. | Show/detail commands. |
| `PromptsForGatewayRegistryEntities` | `datatable` selection prompts for apps, nodes, workspaces, schedules. | Interactive entity selection. |
| `ResolvesHostContext` / `ResolvesDefaultNode` | `--app`/`--node`/default-node resolution helpers. | Commands accepting app and node options. |

## Reference Implementations

| Command | Pattern |
| --- | --- |
| `Node\NodeRemoveCommand` | `runStepOperation` with drift-aware closure footer. |
| `App\AppRemoveCommand` | `runStepOperation` plus `confirm()`/`--force` destructive consent. |
| `Deploy\DeployRunCommand` | Durable operation journal plus operations WebSocket subscription. |
| `Node\NodeNewCommand` | Transitional gateway SSE via `streamProgress()`. |
| `Workspace\WorkspaceSetupCommand` | Transitional gateway SSE via `streamProgress()`. |
| `Database\DatabaseShowCommand` | `show-detail` rendering through `RendersShowDetails`. |
| `Operation\DoctorCommand` | Custom parallel panel via `DoctorPanelRenderer`. |
| `App\AppListCommand` | `Laravel\Prompts\table` grouped list output. |

## Anti-Patterns

- Do not hand-roll step/echo progress output; drive the shared `StepTree`
  engine (or `StreamedStepTree` for gateway work) instead.
- Do not use `intro()` from Laravel Prompts; the tree `┌ Title` is the header.
- Do not use `$this->table()` for list data; use `Laravel\Prompts\table` per
  [`apps/docs/content/ux/commands/lists/table.md`](../../../../apps/docs/content/ux/commands/lists/table.md).
- Do not use Symfony `$this->ask`, `$this->confirm`, `$this->choice`, or
  `$this->secret` for prompts; use the matching primitive in
  [`apps/docs/content/ux/commands/inputs/`](../../../../apps/docs/content/ux/commands/inputs/README.md).
- Do not hardcode spinner frames; use `SpinnerTreeRenderer::spinnerFrames()`.
- Do not block without animation when a step can take more than one second.
- Do not skip the JSON path.
- Do not render results before the tree.
- Do not show redundant status text when the dot color already conveys state.
