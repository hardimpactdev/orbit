# 15_agent-ide — Agent IDE Workstream

Detail file for the agent-ide command family. Top-level command status lives
in [`PORTING.md`](PORTING.md). Doc authority: `docs/commands/15_agent-ide/`.

## Status

Not started. The family port is blocked until the remaining supporting
prerequisites are in place:

- `docs/abstractions/15_agent-ide.md` exists and must be read with
  `docs/abstractions/cross-cutting.md` before implementing Agent IDE command
  slices.
- The activity-logging contract allowlist cannot include `agent-ide:*` until
  the command surface is implemented.

## Commands

- [~] `agent-ide:message`
  - [x] Command docs are converted into the current split-file shape.
  - [x] Family abstraction seed exists.
  - [!] Implementation is blocked until the first `agent-ide:message` slice
    defines the clean-rebuild adapter delivery contract and fake adapter driver
    test seam. Next concrete action: implement a gateway-local in-memory slice
    that resolves an explicit app target, resolves the effective adapter through
    current app/node intent, delivers through a container-bound fake adapter
    driver, and returns the documented JSON success/error envelopes without
    creating sessions or mutating app/workspace/process/node/tool state.
