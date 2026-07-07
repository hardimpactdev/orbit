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

- **Local update:** `update` sequence that changes only the current Orbit CLI
  installation. It checks the latest available release first, skips when the
  installed version is current, and never updates the local CLI past the
  gateway's version.
- **Production local update:** Local update path that replaces the native CLI
  binary and relinks the host launcher.
- **Source-dev local update:** Local update path that changes the mounted source
  checkout.
- **Fleet update:** `update:all` sequence that checks the target release and
  fleet versions, skips finalized GitHub releases when all selected
  installations are current, and reapplies topology-candidate assets for live
  release testing.
- **Fleet update source:** Topology-candidate assets are resolved from the
  release manifest URL selected by the gateway, or from a stable candidate
  channel.
- **Fleet update order:** The gateway updates first as the fleet version ceiling.
  The caller-local CLI and selected active workload Orbit installations then
  update as fan-out targets through a durable gateway-owned operation.
- **Operation event journal:** Durable ordered event log for a gateway-owned
  operation. Events carry a per-run sequence. The SSE event id is that sequence,
  `Last-Event-ID` replays only events with a greater sequence, and live
  followers stay connected with heartbeat comments until a terminal `complete`
  or `error` event is persisted.
- **Immutable update plan:** Persisted plan keyed by `operation_run_id`.
  Captures target version, gateway image digest, manifest snapshot, CLI artifact
  hashes, Orbit Agent artifact hashes for agent-capable Linux nodes, and
  required role images so CLI and Orbit Agent artifacts update from one
  immutable source of truth.
- **Update lease:** Expiring lease row for mutually exclusive update work, such
  as `fleet:update-all`, `gateway`, `scheduler`, or an individual node update.
- **Update target:** One selected Orbit installation in an update workflow.
- **Update step:** Ordered local installation update action: native CLI artifact update or source-mounted checkout refresh, launcher verification, containerized dependency installation, or migration execution.
- **Target result:** Per-update-target outcome preserved for renderers.

Fleet update runs through gateway-owned authority. The CLI starts a gateway
operation, follows its event journal over SSE, and updates the caller-local CLI
after the gateway phase succeeds. The gateway persists the immutable update
plan, starts a one-shot runner from the target `orbit-gateway` image, and the
runner owns the read-only fleet-version probe, finalized-release all-current
short-circuit, candidate reapply path, gateway replacement, scheduled service
recovery, workload fan-out, and final verification. Clients are never remote
update targets. A target succeeds only when all required update steps succeed;
target results include both successful and failed targets when a fleet update
partially fails.

The update plan selects both the Orbit CLI artifact and Orbit Agent artifact for
an agent-capable Linux node. The current Orbit Agent bootstrap still owns first
install and service unit creation; fleet update replaces and restarts an
existing node-local Agent binary but does not sign, notarize, or produce
platform-native packages.

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
