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
  argv, shell concatenation and workflow inputs cannot carry them. An explicitly
  closed empty `execution_secrets` object carries no values. SourceControl's
  `repository_identity` is the established public host/org/repo identifier; this
  exact field name is public, unlike SSH or bootstrap identity material.
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
not passing. The agent rule examines executable command positions in shell and
Python subprocess calls, including sudo, shell and SSH wrappers. Service units
such as `orbit-agent.service`, artifact paths passed to stat/copy/digest tools and
configuration metadata are not agent invocations. Actual executable calls still
use the observer/action inventory; unresolved commands remain unknown.

Workflow metrics separate clean, baseline-accepted and unknown workflows. A clean
workflow has known steps and no findings in its own source or reachable local
behavior dependencies. Static coverage is not a runtime canary. Each run emits
versioned JSON with its commit and units, plus a `SHAPE-GATE` summary on one line.

Nick permits exactly two Gateway execution primitives from steps: GitHub
installation-token mint and certificate signing (CSR plus operation id to a public
bundle). The gate allows only these exact POST route templates:

- `/api/v1/direct-operations/{operation}/github-token`
- `/api/v1/direct-operations/{operation}/certificates/sign`

Root confirmed both route contracts on 2026-10-31. The operation segment must be a
positive numeric id. The native Python request fixture proves
its numeric check precedes the request and rejects reassignment after that check. It does not exempt
a client, module, endpoint prefix or file. Other Gateway HTTP calls from steps,
including failure reporters, remain forbidden. The `gateway-http` rule covers the client and its aliases and imports;
workflow coverage follows local dependencies, so helper modules and failure jobs
remain part of the audit. A registered step that reaches a Gateway helper has
its own file/rule finding, so new steps cannot inherit a historical exception.
Intent arrives as non-secret workflow inputs, vault
names and key names identify secrets, and step outputs carry results.

The required jobs are `Swamp shape gate`, `Swamp workflow validate` and
`Swamp Deno tests`. The reusable workflow accepts `working-directory` and
`base-ref`. Its bytes can be copied to Orbit's root `.github/workflows/` and called
with `working-directory: swamp`; lane24 adds it to `Required checks`. Every
workflow must validate on the checksum-verified runtime pin. All Operations Deno
tests run with four workers, including disposable database effect tests.

Swamp CI must never receive account credentials. The helper downloads the public,
checksum-verified official artifact and launches it with an isolated configuration
and a clean environment. Official e459 blocks `workflow validate` at its account
startup gate before reading local extensions. Account verification uses
`GET /api/whoami`; vendoring extensions does not remove that gate. The pinned
command advertises no offline validation option.

The callable reports this exact blocker as `SWAMP AUTH UNAVAILABLE` and
`validation_ran=false`, with zero workflows validated. It fails normally. Only
the caller-proven initial Orbit import PR or its first main push may set
`allow-auth-unavailable: true`; the callable also checks the repository and event.
Shape, placement and Deno checks remain required. Schema, download, checksum and
other runtime failures never receive this exception. This is an explicit import
limitation, not a claim that native validation passed.

The gate prints current debt separately from base comparison. Retained historical
agent actions are grandfathered; only growing counts or new action evidence fail.
Unknown agent targets remain unknown findings. A fresh PR also cannot reuse a
stale allowance after the base source reduced its debt. The baseline must match
current findings, so removals reduce the checked-in list in the same PR.

For the history-preserving monorepo import only, the caller may pass the recorded
Swamp source SHA when the actual comparison base has no `swamp/.swamp.yaml`.
This applies to the import PR and its first main push. The checker accepts the
unprefixed source tree only when that commit is an ancestor and its repository
identity matches. Subsequent runs compare the actual Orbit base under `swamp/`.
Lane24 records the import provenance and selects this one-time base.

The scanner reads tracked files. Stage the imported inventory and baseline before
a local import check. When the preserved source predates the gate, the checker
derives initial limits from that source; a gate-source comparison base is not
needed. The import fixtures cover both the PR and its first main push.

The only Gateway wait exception is in the Orbit release runner: it may wait up
to ten minutes for Swamp serve health before failing Caddy publication. It does
not allow Swamp-step polling or Gateway wait/retry machinery elsewhere.

The TS checker owns the endpoint policy and passes it to the Python AST helper.
Native shell URLs use the same policy. The signer fixture is copied from lane0c
model `orbit-certificate-signing-http`; altered methods, paths, unchecked ids and
extra callbacks are rejected.
