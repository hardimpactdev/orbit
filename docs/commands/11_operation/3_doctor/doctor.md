# `orbit doctor`

[Back to Operation commands.](../README.md)

Verify gateway intent against observed node reality, then optionally repair or
adopt supported drift.

`doctor` is Orbit's convergence command. It orchestrates state-family probes for
families such as `node`, `app`, `workspace`, `process`, `proxy`,
`schedule`, `tool`, and `firewall_rule`. The global command owns scope
resolution, mode selection, authorization, exit status, and output envelopes.
Family doctor contracts own concrete probe facts, issue codes, and safe
fix/adopt maps.

## Usage

```bash
orbit doctor [--app=<app>] [--workspace=<workspace>] [--node=<node>|--self] [--family=<family>] [--fix|--adopt] [--json]
```

## Examples

```bash
orbit doctor
orbit doctor --family=node --self
orbit doctor --family=app --app=docs --fix
orbit doctor --family=workspace --app=docs --workspace=feature-api --json
```

## Arguments And Options

- `--family`: Limit the run to one product state family. Repeatable.
- `--node`: Limit the run to one gateway-known node.
- `--self`: Limit the run to the caller's gateway-known node identity.
- `--app`: Limit the run to one app and the family facts owned by that app.
- `--workspace`: Limit the run to one workspace and its owned facts.
- `--fix`: Re-apply gateway intent to node reality for family-declared safe
  repair actions.
- `--adopt`: Adopt compatible observed node reality into gateway intent for
  family-declared adoption actions.
- `--json`: Output JSON.

## What Happens

`doctor` resolves a scope, authorizes that scope on the gateway, runs the
matching family probes, and reports the final diagnostic.

In verify mode, it compares only. With `--fix`, it runs family-declared safe
repair actions from gateway intent to node reality. With `--adopt`, it runs
family-declared adoption actions from observed node reality to gateway intent.

`--fix` and `--adopt` are mutually exclusive. `--fix` must not silently mutate
gateway intent. `--adopt` may mutate gateway intent only because the operator
selected adoption explicitly.

App-node callers may run authorized verify-mode doctor checks. App-node callers
may not initiate generic `--fix` or `--adopt` writes unless a family doctor
contract documents a narrow app-node exception.

## Output

Human output shows the selected mode and resolved scope before the result, then
prints a formatted diagnostic report. Healthy output must still say what was
checked; an empty result is not enough because unselected families and nodes may
not have been inspected. Drift output groups issues by family and kind, and
`--fix` or `--adopt` output includes an action table for completed, skipped,
failed, or conflicted actions.

JSON output uses the shared command envelope. Healthy diagnostics are returned
under `success.data.doctor`. Drift or probe/action failures return the same
doctor diagnostic under `error.data.doctor`.

When no drift or probe errors remain, `doctor` exits successfully. When drift
remains, a probe fails, scope cannot be resolved, or the selected mode is not
supported for the chosen issues, `doctor` exits failed and returns the
diagnostic payload.

## Family Contracts

The global `doctor` command owns generic orchestration. Family doctor contracts
own concrete issue codes and action maps:

- [`doctor --family=node`](../../1_node/node-doctor.md)
- [`doctor --family=tool`](../../3_tool/tool-doctor.md)
- [`doctor --family=firewall_rule`](../../4_firewall/firewall-doctor.md)
- [`doctor --family=app`](../../5_app/app-doctor.md)
- [`doctor --family=workspace`](../../6_workspace/workspace-doctor.md)
- [`doctor --family=process`](../../7_process/process-doctor.md)
- [`doctor --family=proxy`](../../8_proxy/proxy-doctor.md)
- [`doctor --family=schedule`](../../9_schedule/schedule-doctor.md)

## Related Commands

- [`update`](../1_update/update.md) - update only the local Orbit checkout
- [`update:all`](../2_update-all/update-all.md) - update Orbit installations
  before running convergence checks

## Technical Contract

See [`doctor` technical contract](technical/1_doctor.md).
