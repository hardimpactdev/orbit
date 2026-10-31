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

Inline operational scripts have a 20-line per-file budget before grandfathering.
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
