# 10_deploy — Deploy Workstream

Detail file for the deploy command family. Top-level command status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/10_deploy/`.

## Status

Not started. The family port is blocked until the supporting prerequisites are
in place:

- `docs/abstractions/10_deploy.md` does not yet exist; the abstraction seed
  must be authored before any implementation todo is promoted.
- The activity-logging contract allowlist cannot include `deploy:*` until the
  command surface is implemented.

## Commands

- [ ] `deploy-step-add`
- [ ] `deploy-step-list`
- [ ] `deploy-step-remove`
- [ ] `deploy-run`
- [ ] `deploy-history`
- [ ] `deploy-log`
