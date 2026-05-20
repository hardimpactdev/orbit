# Operation Concepts

This document defines operation-command-domain vocabulary and invariants. It supports the operation command contracts; it does not override the [Architecture](../../architecture.md).

## Domain and scope

These terms define the scope and structure of the operation command domain. The domain is cross-cutting; it does not create a separate state family, and operational history reads live in the activity family.

- **Operation command domain:** Cross-cutting command domain for updating Orbit installations, orchestrating doctor convergence, and running request profiling diagnostics.
- **Cross-family workflow:** Operation command path that reads, writes, verifies, restores, or adopts state owned by product families.
- **Local operation command:** Operation command whose side effects are limited to the caller machine.
- **Fleet-changing operation command:** Operation command that changes Orbit installations beyond the caller machine through gateway-owned authority.

Product families remain the owners of configuration, reality, issue codes, and repair or adoption behavior. A local command that extends beyond the caller machine must document a gateway-mediated fleet path. Fleet-changing commands also run through node access policy and documented gateway-to-node execution paths.

## Updates

These terms describe the update workflow and its components.

- **Local update:** `update` sequence that changes only the current Orbit checkout: source pull, dependency installation, and local Orbit migrations.
- **Fleet update:** `update:all` sequence that updates the caller-local checkout and selected active non-local managed Orbit installations.
- **Update target:** One selected Orbit installation in an update workflow.
- **Update step:** Ordered local checkout update action: source pull, dependency installation, or migration execution.
- **Target result:** Per-update-target outcome preserved for renderers.

Fleet update runs through gateway-owned authority, with remote execution via `RemoteShell` — see [tech-stack.md#gateway-to-node](../../tech-stack.md#gateway-to-node). Clients are never remote update targets. A target succeeds only when all required update steps succeed; target results include both successful and failed targets when a fleet update partially fails.

## Doctor

These terms describe the `doctor` command and its components.

### Orchestration and scope

These terms cover what a doctor run resolves, authorizes, and dispatches.

- **Doctor orchestration:** Global `doctor` command responsibility for scope resolution, authorization, mode selection, family dispatch, output envelopes, generic issue kinds, and generic failure behavior.
- **Doctor scope:** Resolved and authorized filter set for a doctor run, including selected families, node or self scope, app scope, and workspace scope.

### Modes

These terms describe the four doctor execution modes and how they combine.

- **Doctor mode:** One of `verify`, `interactive`, `restore`, or `adopt`.
- **Doctor verify mode:** Default mode. Compares gateway configuration and node reality only.
- **Doctor interactive mode:** `--fix`. Prompts per finding to restore, adopt, skip, or view details.
- **Doctor force modes:** `--restore` applies safe gateway configuration to node reality. `--adopt` records compatible node reality into gateway configuration. `--fix`, `--restore`, and `--adopt` are mutually exclusive.

### Contracts, kinds, and actions

These terms describe family-owned doctor contracts and the actions they produce.

- **Family doctor contract:** Family-owned contract for probe layers, issue codes, diagnostics, restore/adopt action maps, and test mapping.
- **Doctor issue kind:** Generic relationship between gateway configuration and observed reality: `missing`, `extra`, `divergent`, or `unverifiable`.
- **Doctor action:** Recorded restore or adopt attempt owned by a family doctor contract.

Families own the concrete issue codes that produce doctor kinds. Doctor actions may complete, skip, fail, or conflict; remaining drift must stay visible after action execution.

### Doctor permissions

These terms describe how doctor modes map to scoped node-access permissions.

- **Doctor verify permission:** `doctor:verify`. Required to read drift in
  doctor verify mode. Included in the `operator` preset and the `agent`
  self preset by default.
- **Doctor restore permission:** `doctor:restore`. Required to apply gateway
  configuration to node reality through `doctor --restore` or
  `doctor --fix` with the restore direction. Not included in the `operator`
  preset by default.
- **Doctor adopt permission:** `doctor:adopt`. Required to record observed
  node reality into gateway configuration through `doctor --adopt` or
  `doctor --fix` with the adopt direction. Not included in the `operator`
  preset by default.
- **Operator preset doctor boundary:** Authorization rule that the
  `operator` preset includes `doctor:verify` but excludes both
  `doctor:restore` and `doctor:adopt` and every `firewall_rule:write`
  permission. Restore, adopt, and firewall writes require an admin-class
  preset or explicit permissions on the grant.

## Activity

Activity logging and history reads live in the activity family. See [`docs/domains/17_activity/activity-concepts.md`](../17_activity/activity-concepts.md).

## Profiling

These terms describe the `profile` command and its components.

- **Profile target:** Orbit-managed app or workspace route resolved from a target argument, app option, URL, hostname, absolute path, or local app/workspace context.
- **Profile request origin:** Location that performs the timed HTTP request — currently the caller machine or the gateway.
- **Baseline profile result:** One timed HTTP `GET` result with timing, status, byte count, URL, headers, completion state, and failure diagnostics.
- **Toolbar enrichment:** Optional profile data decoded from an app response's Toolbar summary header.
- **Toolbar auth mode:** Explicit profile authentication mode carried by request headers: guest, first user, or a specific user id.

Arbitrary internet URLs are outside the profile command contract. The gateway identifies the calling peer role and route reachability to select the origin. Toolbar enrichment augments the baseline result without changing baseline timing measurements.

## Boundaries

These terms define the outer limits of the operation command domain.

- **Operation-domain boundaries:** Operation commands own cross-family
  orchestration, update workflows, and request profiling diagnostics.
- **Operation-domain exclusions:** Operation commands do not own durable
  operation configuration, invent operation-family drift, replace family doctor
  contracts, grant node access, create product-family records, or treat update
  or profile success as proof of state-family convergence.
- **Operation activity boundary:** Activity-history reads belong to the
  activity family.
