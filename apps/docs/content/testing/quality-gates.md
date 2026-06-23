# Quality gates

Use the smallest verification command that proves the change while developing.
Escalate only when the change crosses a wider contract boundary.

## Default gates

Run these gates while developing unless the change needs a narrower file or
filter first.

```bash
bin/orbit-gateway-pest --compact
bin/orbit-gateway-vendor-bin pint --dirty --format agent
composer docs-lint
```

Run `composer quality-check` before handing off a change that should be broadly
safe. That gate fans out docs linting, PHPStan, Rector dry-run, Pint, and the
default Pest suite across each app and package.

`composer quality-check`, `composer quality-check:fix`, and E2E lanes that run
against the prepared source checkout write local timing artifacts under
`.orbit/quality-gates/`. The `:fix` lane records the same evidence shape with
`mode=fix` so triage can distinguish read-only checks from auto-fix runs without
rerunning the gate.

Gate names for prepared-source E2E are:

| Command | Gate |
|---------|------|
| `composer test:e2e` | `e2e` |
| `composer test:e2e:docker` | `e2e-docker` |
| `composer test:e2e:docker:canary` | `e2e-docker-canary` |
| `composer test:e2e:incus` | `e2e-incus` |

Inspect existing artifacts with:

```bash
composer quality-gate:analyze
```

The analyzer reads `.orbit/quality-gates/` only. It reports missing evidence,
recent run durations, and warning-only baseline observations when a local
baseline exists. It does not rerun `composer quality-check` or E2E lanes.

Before merging a worktree, inspect the existing timing evidence with:

```bash
composer quality-gate:final-check
```

The final check wraps the analyzer and highlights stale evidence, latest gate
exits that were non-zero, and local baseline observations that remain
warning-only. Without explicit `--gate` arguments, it analyzes the gates that
already have artifacts in this worktree. It does not warn about missing E2E
lanes that were not run, and it does not rerun `composer quality-check`, Pest,
Docker E2E, Incus E2E, or provider provision lanes. When no timing artifacts
exist, it exits successfully and reports that timing regression analysis was
skipped so the feature owner can decide whether another gate run is needed.

## Failure and timing triage

When a quality gate fails or slows down, use the root
`.agents/skills/quality-gate-triage/SKILL.md` runbook before blaming product
code. Participating gate commands and future timing wrappers should leave timing
and command evidence under `.orbit/quality-gates/`, with supporting transcripts
under `.orbit/evidence/` when applicable. If no artifact exists yet, triage uses
the captured command output and reports the missing evidence as a baseline or
tooling action. A final analyzer inspects existing artifacts and should not
rerun expensive gates merely to classify a failure or slowdown.

Treat timing baselines as local and machine-specific first. Until a stable
baseline exists for the current lane and runner pool, classify timing deltas as
warning-only and record the missing-baseline action instead of calling a product
regression.

## E2E gates

Run `composer test:e2e` when behavior touches the integrated prepared topology.
Use `composer test:e2e:docker` for Docker-eligible feature tests and
`composer test:e2e:incus` for VM-feature behavior. These source-prepared lanes
write timing artifacts through `bin/quality-gate-run`; the wrapper preserves the
lane's exit code and does not change provider selection or argument forwarding.

Run feature E2E before any affected provider artifact/provision gate. The
prepared-topology lanes exercise the current source checkout and are the normal
behavior signal. Incus provision is final verification for fresh VM topology
preparation, installer behavior, `node:new`, WireGuard provisioning, or other
VM/provider setup behavior. Docker provision is only for Docker image shape,
prepared role images, Docker host artifact distribution, or Docker topology
preparation changes:

```bash
composer test:e2e:provision:docker
composer test:e2e:provision:incus
```

These commands may be run by separate agents in parallel only when both provider
gates are independently required and the matching provider feature lane is green.
The aggregate `composer test:e2e:provision` runs both provider provision
commands and is reserved for humans, not agents.

There is no standing live-node verification lane. Persistent gateway, operator,
and app nodes are diagnostic targets only.
