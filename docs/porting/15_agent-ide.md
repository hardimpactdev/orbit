# 15_agent-ide — Agent IDE Workstream

Detail file for the agent-ide command family. Top-level command status lives
in [`PORTING.md`](PORTING.md). Doc authority: `docs/commands/15_agent-ide/`.

## Status

Not started. The family port is blocked until the supporting prerequisites are
in place:

- `docs/abstractions/15_agent-ide.md` does not yet exist; the abstraction seed
  must be authored before any implementation todo is promoted.
- The activity-logging contract allowlist cannot include `agent-ide:*` until
  the command surface is implemented.

## Commands

- [ ] `agent-ide:message`
