# Progress

Long-running commands give the operator immediate, animated feedback after
input resolution and before side effects begin. Two patterns:

- The custom Orbit [progress tree](progress-tree.md) is the default for any
  command that may exceed one second. Steps render as `┌`/`○`/`◉`/`●`/`└`
  with status colors.
- A single-line [spinner](spinner.md) is acceptable for sub-second waits or
  for the inner step of a tree where a separate full tree would be
  excessive.

## Selection Rule

| If the command... | Use |
| --- | --- |
| Has multiple discrete steps and may take more than one second | [progress tree](progress-tree.md) |
| Performs one short async wait without meaningful sub-steps | [spinner](spinner.md) |
| Is a fast read-only command (sub-second, no remote work) | No progress; render the result directly |

Read/list/show commands do not render progress. They render the table or
key-value tree directly. See [`table`](../lists/table.md).

## Pages

- [`progress-tree`](progress-tree.md) — custom Orbit dot tree (primary).
- [`spinner`](spinner.md) — single-line indeterminate spinner.
