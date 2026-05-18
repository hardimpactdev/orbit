# Operation Concepts

This document defines operation-command-domain vocabulary and invariants. It supports the operation command contracts; it does not override the [Architecture](../../architecture.md).

## Domain and scope

These terms define the scope and structure of the operation command domain.

- **Operation command domain:** The cross-cutting command domain for updating Orbit installations, orchestrating doctor convergence, and running request profiling diagnostics. It does not create a separate state family. Operational history reads live in the activity family.
- **Cross-family workflow:** Operation command path that reads, writes, verifies, restores, or adopts state owned by one or more product families. Those families remain the owners of configuration, reality, issue codes, and repair or adoption behavior.
- **Local operation command:** Operation command whose side effects are limited to the caller machine. A command that extends beyond the caller machine must document a gateway-mediated fleet path.
- **Fleet-changing operation command:** Operation command that changes Orbit installations beyond the caller machine. It runs through gateway-owned authority, node access policy, and documented gateway-to-node execution paths.

## Updates

These terms describe the update workflow and its components.

- **Local update:** `update` sequence that changes only the current Orbit checkout: fast-forward source pull, dependency installation, and local Orbit migrations.
- **Fleet update:** `update:all` sequence that updates the caller-local checkout and selected active non-local managed Orbit installations through gateway-owned authority. Remote execution uses gateway-to-app-node `RemoteShell`. Operator nodes are never remote update targets.
- **Update target:** One selected Orbit installation in an update workflow, such as the caller-local checkout, the gateway checkout, or an app-node checkout selected from gateway node configuration.
- **Update step:** Ordered local checkout update action: source pull, dependency installation, or migration execution. A target succeeds only when all required update steps succeed.
- **Target result:** Per-update-target outcome preserved for renderers. Includes both successful and failed targets when a fleet update partially fails.

## Doctor

These terms describe the `doctor` command and its components.

**Orchestration and scope:**

- **Doctor orchestration:** Global `doctor` command responsibility for scope resolution, authorization, mode selection, family dispatch, output envelopes, generic issue kinds, and generic failure behavior.
- **Doctor scope:** Resolved and authorized filter set for a doctor run, including selected families, node or self scope, app scope, and workspace scope.

**Modes:**

- **Doctor mode:** One of `verify`, `interactive`, `restore`, or `adopt`.
- **Doctor verify mode:** Default mode. Compares gateway configuration and node reality only.
- **Doctor interactive mode:** `--fix`. Prompts per finding to restore, adopt, skip, or view details.
- **Doctor force modes:** `--restore` applies safe gateway configuration to node reality. `--adopt` records compatible node reality into gateway configuration. `--fix`, `--restore`, and `--adopt` are mutually exclusive.

**Contracts, kinds, and actions:**

- **Family doctor contract:** Family-owned contract for probe layers, concrete issue codes, diagnostic details, restore action maps, adopt action maps, and test mapping for that family.
- **Doctor issue kind:** Generic relationship between gateway configuration and observed reality: `missing`, `extra`, `divergent`, or `unverifiable`. Families own the concrete issue codes that produce these kinds.
- **Doctor action:** Recorded restore or adopt attempt owned by a family doctor contract. Actions may complete, skip, fail, or conflict. Remaining drift must stay visible after action execution.

## Activity

Activity logging and history reads live in the activity family. See [`docs/commands/17_activity/activity-concepts.md`](../17_activity/activity-concepts.md).

## Profiling

These terms describe the `profile` command and its components.

- **Profile target:** Orbit-managed app or workspace route resolved from a target argument, app option, URL, hostname, absolute path, or local app/workspace context.
- Arbitrary internet URLs are outside the profile command contract.
- **Profile request origin:** Location that performs the timed HTTP request — currently the caller machine or the gateway. The gateway identifies the calling peer role and route reachability to select the origin.
- **Baseline profile result:** One timed HTTP `GET` result with network timing, response status, byte count, effective URL, headers, completion state, and request failure diagnostics when applicable.
- **Toolbar enrichment:** Optional profile data decoded from an app response's Toolbar summary header. It augments the baseline result without changing baseline timing measurements.
- **Toolbar auth mode:** Explicit profile authentication mode carried by request headers: guest, first user, or a specific user id.

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
