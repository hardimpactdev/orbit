# Swamp shape gate

Every pull request and main push runs `Swamp shape`. Existing violations are
listed by file and rule in `config/swamp-shape-baseline.json`. Entries and their
counts may only decrease. Moving code does not transfer an exception. CI also
runs the base revision's checker, so changing a rule or exclusion does not relax
that PR's check.

The gate implements Nick's migration policy using these manual references:

- [Workflows](https://swamp-club.com/manual/reference/workflows): placed steps,
  guards, allowFailure, resume, concurrency, queueTimeout and manual approval
  replace application polling, waits and locks.
- [Behavior definitions](https://swamp-club.com/manual/reference/behavior-definitions):
  `command/shell` runs an action script; TypeScript reads intent and records typed
  results. Distinct commands are evidence for the multi-action heuristic, not a
  proof of the business meaning of an action.
- [Vaults](https://swamp-club.com/manual/reference/vaults): resolve private values
  with `vault.get`. Our pilot restricts them to whole `task.inputs.env` values;
  argv, shell concatenation and workflow inputs cannot carry them.
- [Data](https://swamp-club.com/manual/reference/data): outputs are persisted.
  Do not write private material to resources, files or logs.

Each embedded operational program has a 20-line threshold before grandfathering.
Independent short strings are not combined to cross it. Script metrics count each
contributing source literal once, including when a wrapper passes it to Python.
The scanner resolves TypeScript literals, templates, tagged templates,
concatenation, constant aliases and joins. It inspects nested subprocesses and
reports evidence for multi-command behavior files. Custom sleeps, timer helpers,
polling, retries and lock calls are counted separately. Agent calls are checked
against an explicit observer/action inventory; dynamic agent targets are unknown,
not passing.

Workflow metrics separate clean, baseline-accepted and unknown workflows. A clean
workflow has known steps and no findings in its own source or reachable local
behavior dependencies. Static coverage is not a runtime canary. Each run emits
versioned JSON with its commit and units, plus a `SHAPE-GATE` summary on one line.

Current PLAN also forbids Gateway HTTP calls from any step, including failure
reporters. The `gateway-http` rule covers the client and its aliases and imports;
workflow coverage follows local dependencies, so helper modules and failure jobs
remain part of the audit. Intent arrives as non-secret workflow inputs, vault
names and key names identify secrets, and step outputs carry results.

The required jobs are `Swamp shape gate`, `Swamp workflow validate` and
`Swamp Deno tests`. The reusable workflow accepts `working-directory` and
`base-ref`. Its bytes can be copied to Orbit's root `.github/workflows/` and called
with `working-directory: swamp`; lane24 adds it to `Required checks`. Every
workflow must validate on the checksum-verified runtime pin. All Operations Deno
tests run with four workers, including disposable database effect tests.

Runtime validation requires the existing Swamp account credentials. The old
repo has `SWAMP_API_KEY` and `SWAMP_SIGNIN_TOKEN`; Orbit needs an approved transfer
before the caller can use this workflow. The reusable workflow declares both
secrets. Pass them with `secrets: inherit` from the approved caller. No credential
is printed, copied from local config or passed as argv.

The gate prints current debt separately from base comparison. Retained historical
agent actions are grandfathered; only growing counts or new action evidence fail.
Unknown agent targets remain unknown findings. A fresh PR also cannot reuse a
stale allowance after the base source reduced its debt. The baseline must match
current findings, so removals reduce the checked-in list in the same PR.
