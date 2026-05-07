# Terminal Output

Use this reference for the implementation mechanics behind Orbit's terminal
output: ANSI codes, animation patterns, traits, and the gateway-streamed SSE
shape. Primitive selection (which renderer or prompt to use, and when) is
product-authority and lives in
[`docs/commands/ux/`](../../../../docs/commands/ux/README.md).

For the visible behavior of:

- read-only list output → see [`docs/commands/ux/lists/table.md`](../../../../docs/commands/ux/lists/table.md);
- interactive row selection → see [`docs/commands/ux/lists/data-table-prompt.md`](../../../../docs/commands/ux/lists/data-table-prompt.md);
- prompts (text, confirm, select, etc.) → see [`docs/commands/ux/inputs/`](../../../../docs/commands/ux/inputs/README.md);
- multi-step progress and spinners → see [`docs/commands/ux/progress/`](../../../../docs/commands/ux/progress/README.md).

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

| State | Icon | Color | ANSI |
| --- | --- | --- | --- |
| Idle/waiting | `○` | dim | `\e[38;5;242m○\e[39m` |
| In progress | `○`/`◉` alternating | cyan | `WithSpinner::$spinnerFrames` |
| Success | `●` | green | `\e[32m●\e[39m` |
| Failure | `●` | red | `\e[31m●\e[39m` |
| Warning/skip | `●` | orange | `\e[38;5;208m●\e[39m` |

`SpinnerTreeRenderer` is the canonical source of ANSI color constants and
box-drawing logic. Commands using `WithStepTree` get constants from the trait.
Hand-rolled handlers define them locally only when a command truly needs custom
rendering.

Footer text is dim only while the command is pending (`Working...`). Final
success footers use accent/full-strength text, and final failure footers use
red text.

```php
private const string DIM = "\e[38;5;242m";
private const string ACCENT = "\e[97m";
private const string GREEN = "\e[32m";
private const string RED = "\e[31m";
private const string ORANGE = "\e[38;5;208m";
private const string RESET = "\e[39m";
```

## Execution Patterns

Choose the pattern based on how steps execute.

### Pattern 1: Sequential Steps

Use `runStepTree()` from `WithStepTree` for steps that run in order. This is the
preferred default for long-running write commands.

```php
use App\Concerns\WithJsonOutput;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;

return $this->runStepTree('Title', $steps, $jsonData);
```

Only the active step animates. Idle steps remain dim. When labels change on
completion, use `doneLabel`.

```php
$steps = [
    [
        'label' => 'Changing CLI version',
        'doneLabel' => 'PHP CLI',
        'run' => fn () => 'PHP 8.5',
    ],
];
```

### Pattern 2: Parallel Independent Checks

Use `WithSpinner::runAllWithSpinners()` for independent checks, such as doctor
families. Results appear as each check finishes.

```php
$this->runAllWithSpinners(
    array_map(fn (array $item) => $item['check'], $items),
    fn (int $i, string $frame) => $updateLine($i, "  {$frame}  ..."),
    function (int $i, mixed $result): void {
        // render result line
    },
    function (int $i, \Throwable $e): void {
        // render error line
    },
);
```

### Pattern 3: Async Processes With Polling

Commands that launch async OS processes and poll for completion manage their
own animation loop. Always use `self::$spinnerFrames` from `WithSpinner`.

```php
$frames = self::$spinnerFrames;

do {
    // Check completed processes.
    // Animate pending items with $frames[$tick++ % count($frames)].
    usleep(300_000);
} while (in_array(false, $completed, true));
```

### Pattern 4: Async HTTP

Commands that run concurrent HTTP requests can use `curl_multi_*`; the
`curl_multi_select()` timeout doubles as the animation interval.

### Pattern 5: Gateway-Executed Commands With Local Rendering

CLI callers reach the gateway over the typed HTTPS API through WireGuard. Do not
forward commands by SSH, do not serialize a whole CLI command into a generic
`/cli` endpoint, and do not fake remote progress by wrapping a blocking JSON
call in a local spinner.

Gateway-executed commands have these paths:

1. Gateway-local human: execute directly and render the normal progress tree.
2. Gateway-local JSON: execute directly and return the documented JSON envelope.
3. Non-gateway JSON: send a command-specific typed gateway request and print the
   gateway JSON envelope without ANSI or spinner output.
4. Non-gateway human: open the command-specific typed progress stream, consume
   structured gateway events, and render the normal Orbit progress tree locally.

Remote human progress uses Server-Sent Events:

| Event | Purpose |
| --- | --- |
| `tree` | Declares the title and ordered step list before work starts. |
| `step` | Updates one step's status and message. |
| `complete` | Terminates successfully and may carry command data. |
| `error` | Terminates as a command failure with structured error context. |

Prompting remains a caller-side input-mode concern.

## Info And List Commands

Info/detail commands and list commands do not use progress trees unless they
do slow external work. Primitive selection lives in
[`docs/commands/ux/lists/`](../../../../docs/commands/ux/lists/README.md):
read-only list output uses
[`Laravel\Prompts\table`](../../../../docs/commands/ux/lists/table.md) and
interactive row selection uses
[`Laravel\Prompts\datatable`](../../../../docs/commands/ux/lists/data-table-prompt.md).
Info/detail commands continue to use a key-value tree via
`WithHumanOutput::renderForHumans()`.

### Display Conventions

- No `intro()` headers; the table or tree structure is the header.
- Combine `user` and `host` into `user@host` for display, but keep separate
  fields in JSON.
- Prefix TLDs with `.` in display.
- Use `-` for null or empty values.
- Use the entity name as the tree title: `app-1`, not `Node: app-1`.

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

### Info Command Example

```php
$displayData = [
    'address' => "{$data['user']}@{$data['host']}",
    'tld' => $data['tld'] ? ".{$data['tld']}" : '-',
    'environment' => $data['environment'],
    'status' => $data['status'],
];

$this->renderForHumans($displayData, $data['name'], treeRowSeparatorLines: 1);
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

- JSON path: no ANSI, no spinners; use JSON envelope helpers.
- Human path: full human rendering with progress where applicable.
- Non-decorated fallback for tests: use plain text output without ANSI.

## Building A Progress Tree

Step callables return a string: success message, `fail:...` for failure, or
`skip:...` for warning/skip.

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

Prefer `runStepTree()` for sequential commands:

```php
return $this->runStepTree('Setting up project', $steps, [
    'action' => 'setup',
], doneFooter: 'Success');
```

Use low-level `WithStepTree` helpers only when the command needs custom
rendering:

| Method | Purpose |
| --- | --- |
| `runStepTree($title, $steps, $jsonData, $doneFooter, $failFooter)` | Full JSON/plain/decorated rendering. |
| `stepTreeLabelWidth($steps, $key)` | Max label width for padding. |
| `renderStepTree($title, $steps)` | Render header, dim placeholders, and footer. |
| `stepTreeUpdater($count)` | Returns updater closure for ANSI cursor updates. |
| `stepTreeSpinner($frame, $label, $width)` | Format spinner animation line. |
| `stepSuccess($label, $width, $message)` | Green success line. |
| `stepFailed($label, $width, $message)` | Red failure line. |
| `stepSkipped($label, $width, $message)` | Orange warning/skip line. |
| `finishStepTree($message)` | Update footer, restore cursor, add trailing newline. |

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

| Trait | Purpose | When to use |
| --- | --- | --- |
| `WithStepTree` | Step tree rendering, cursor updates, result formatting. | Any command with tree-style output. |
| `WithSpinner` | Spinner frames and spinner runners. | Any command with async progress. |
| `WithJsonOutput` | JSON helpers and `respondWithSuccess()`. | Every command with structured output. |
| `WithHumanOutput` | Key-value tree blocks. | Info/detail commands. |
| `ResolvesApp` | `--app` and `--node` resolution. | Commands accepting app and node options. |

## Reference Implementations

| Command | Pattern |
| --- | --- |
| `TldResolveCommand` | Sequential `runStepTree`. |
| `TrustCommand` | Sequential `runStepTree` with multiple step sets. |
| `GatewayConnectCommand` | Simple sequential `runStepTree`. |
| `DeployCommand` | Low-level `WithStepTree` with custom rendering. |
| `NodeUpdateCommand` | Low-level `WithStepTree`. |
| `DoctorCommand` | Parallel checks. |
| `RestartCommand`, `StartCommand`, `StopCommand` | Async process polling. |
| `LinkCommand` | Sequential spinner runner. |

## Banned Output Pattern

`HasStepOutput` is banned for new or touched commands. Replace it with
tree-style rendering using status dots.

When migrating:

1. Replace `use HasStepOutput` with `WithSpinner` and, when needed,
   `WithJsonOutput`.
2. Remove `Laravel\Prompts\intro`; the tree `┌ Title` is the header.
3. Split JSON and human paths.
4. Render the full tree instantly, then execute with the correct spinner
   pattern.
5. Use green `●` for success, red `●` for failure, orange `●` for warnings.
6. Put key context in labels from the start; append text only for results or
   failures.

## Anti-Patterns

- Do not use `HasStepOutput`.
- Do not use `intro()` from Laravel Prompts.
- Do not use `$this->table()` for list data; use `Laravel\Prompts\table` per
  [`docs/commands/ux/lists/table.md`](../../../../docs/commands/ux/lists/table.md).
- Do not use Symfony `$this->ask`, `$this->confirm`, `$this->choice`, or
  `$this->secret` for prompts; use the matching primitive in
  [`docs/commands/ux/inputs/`](../../../../docs/commands/ux/inputs/README.md).
- Do not hardcode spinner frames.
- Do not block without animation when a step can take more than one second.
- Do not skip the JSON path.
- Do not render results before the tree.
- Do not show redundant status text when the dot color already conveys state.
