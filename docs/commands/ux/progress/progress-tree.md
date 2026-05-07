# Progress Tree

Custom Orbit progress renderer. The default for any human-rendered command
that may take longer than one second.

## Use When

- The command performs remote calls, SSH, network I/O, package
  installation, process startup or shutdown, WireGuard changes,
  destructive mutation, or multi-step gateway writes.
- The command's progress decomposes into named operator-facing steps.

## Avoid When

- The command is a fast read-only path that completes below one second.
  Render the result directly with no progress UI.
- The command performs a single short async wait without meaningful
  sub-steps. Use a [spinner](spinner.md).

## Contract

- Primitive name in renderer docs: `progress-tree`.
- The renderer doc's `## Progress Tree` section defines the initial step
  list and the lifecycle for any labels that change at runtime.
- The full tree renders immediately after input resolution, with all steps
  in idle `○` state, before any side effects start.
- Only the currently active step animates `○`/`◉`. Completed steps show
  `●` (green success, red failure, orange warning/skip).
- Idle rows are dimmed. Active rows and completed rows keep full-strength
  label text; dim text is reserved for waiting rows and secondary skip or
  warning messages.
- The footer prints dim `Working...` until completion, then a concrete
  full-strength success result such as `Successfully removed node 'app-1'.`.
  Failure footers are red. Final footers are never dim.
- Step labels are product-level user feedback. Do not expose storage or
  backend implementation labels such as `Write gateway intent` or
  `Enact runtime artifacts`.
- Tense lifecycle: pending uses imperative (`Resolve target`), active uses
  present-participle (`Resolving target`), completed uses past
  (`Resolved target`).

## Anatomy

```text
  ┌  Removing node 'app-1'
  │
  ○  Resolve target
  │
  ◉  Authorize removal
  │
  ●  Removed node from gateway registry
  │
  └  Successfully removed node 'app-1'
```

| State | Icon | Icon Color | Label / Message Treatment |
| --- | --- | --- | --- |
| Idle / waiting | `○` | dim | dim label |
| In progress | `○`/`◉` alternating | cyan | full-strength label |
| Success | `●` | green | full-strength label, optional full-strength message |
| Failure | `●` | red | full-strength label, red message |
| Warning / skip | `●` | orange | full-strength label, dim secondary message |

Do not dim active or completed labels. A running row should be visually present
while its icon animates, and a completed row should stay readable in the final
tree.

The footer follows the same rule: `Working...` is dim while work is pending,
but the final success footer is full-strength text and the final failure footer
is red.

## Implementation

The canonical entry point is `WithStepTree::runStepTree($title, $steps,
$jsonData, $doneFooter, $failFooter)`. ANSI constants, animation frames,
and box-drawing logic live in `SpinnerTreeRenderer` and the `WithStepTree`
trait. See
[`.agents/skills/command-designer/references/terminal-output.md`](../../../../.agents/skills/command-designer/references/terminal-output.md)
for the trait API, ANSI reference, parallel and async patterns, and the
gateway-streamed SSE shape.

`HasStepOutput` is banned for new or touched commands. `intro()` from
Laravel Prompts is banned as a tree header (the `┌ Title` line is the
header).

## Pao

Orbit disables `laravel/pao` output cleaning by default because progress trees
are a CLI contract, not cosmetic decoration. When debugging tree rendering in a
context where Pao may still be active, run the command with `PAO_DISABLE=1` so
agent-observed output preserves box drawing, status glyphs, and ANSI state.

## Example

```php
return $this->runStepTree('Removing node \'app-1\'', [
    [
        'label' => 'Resolving target',
        'doneLabel' => 'Resolved target',
        'run' => fn (): string => 'app-1',
    ],
    [
        'label' => 'Authorizing removal',
        'doneLabel' => 'Authorized removal',
        'run' => fn (): string => 'ok',
    ],
    [
        'label' => 'Removing node from gateway registry',
        'doneLabel' => 'Removed node from gateway registry',
        'run' => fn (): string => 'gateway updated',
    ],
], jsonData: ['action' => 'removed']);
```

## Reference Implementations

- `TldResolveCommand` — sequential `runStepTree`.
- `GatewayConnectCommand` — simple sequential `runStepTree`.
- `NodeUpdateCommand` — low-level `WithStepTree`.
- `DeployCommand` — low-level `WithStepTree` with custom rendering.

## Cross References

- [Skill: terminal output](../../../../.agents/skills/command-designer/references/terminal-output.md)
  for trait API, ANSI codes, async patterns, and SSE event shapes.
- [`spinner`](spinner.md) for the single-line variant.
