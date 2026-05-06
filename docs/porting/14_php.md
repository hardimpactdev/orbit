# 14_php — PHP Workstream

Detail file for the PHP command family. Top-level command status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/14_php/`.

## Status

Not started. The family port is blocked until the supporting prerequisites are
in place:

- `docs/abstractions/14_php.md` does not yet exist; the abstraction seed must
  be authored before any implementation todo is promoted.
- The activity-logging contract allowlist cannot include `php:*` until the
  command surface is implemented.

## Commands

- [ ] `php:list`
- [ ] `php:use`
