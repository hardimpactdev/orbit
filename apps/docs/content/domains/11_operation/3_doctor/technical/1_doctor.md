# Technical Contract: `orbit doctor`

[Back to public `doctor` documentation.](../doctor.md)

**Owner:** `operation`.

**Effects:** `read`, `stream`; `write` when `--fix`, `--restore`, or `--adopt` is used. `--dry-run` keeps bulk restore/adopt in `read` effect because it returns the action plan without applying fixers.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway identifies the calling WireGuard peer and authorizes the selected scope.
- Verify mode requires `doctor:verify` on the resolved target node.
- Resolution actions require the matching doctor permission on the resolved
  target node: `doctor:restore` for restore actions and `doctor:adopt` for
  adopt actions.

## Signature

```bash
orbit doctor [--instance=<project.instance>] [--workspace=<workspace>] [--node=<node>|--self|--all] [--family=<family>] [--key=<key>] [--fix|--restore|--adopt] [--dry-run] [--json|--stream-json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `family` | `--family` | Never. | Never. | The target node's resolved eligibility set: its role-derived base plus owned-fact and platform overlays. | Repeatable product family key: `node`, `instance`, `database_connection`, `firewall_rule`, `process`, `proxy`, `schedule`, `tool`, or `workspace`. `security` is not a valid family; security-section findings live under the owning family key. Must intersect with the target's resolved eligibility set. |
| `key` | `--key` | Never. | Never. | All issue keys from the selected family/families. | Single exact doctor issue-key filter. Filters reported drift after probes and before action planning. Does not imply or select a family. |
| `node` | `--node` | Never. | `--self` or `--all` is present. | The locally configured default node when one is selected; otherwise omitted with `self=true` so the caller node is selected. | Gateway-known node name. Selects the single target node. The literal value `all` is invalid; use `--all` for fleet verification. |
| `self` | `--self` | Never. | `--node` or `--all` is present. | `false`. | Forwarded to the gateway; the gateway resolves it to the calling peer's identified node. |
| `all` | `--all` | Never. | `--node`, `--self`, `--instance`, `--workspace`, `--fix`, `--restore`, or `--adopt` is present. | `false`. | Selects verify-only fleet mode across eligible active role-bearing nodes. This is the only fleet mode. |
| `instance` | `--instance` | Never. | A selected family contract forbids instance scoping. | Instances selected by each family contract after authorization and node/workspace filters. | Gateway-known `<project.instance>` selector; a bare project is accepted only when it resolves unambiguously to one concrete instance. |
| `workspace` | `--workspace` | Never. | A selected family contract forbids workspace scoping. | Workspaces selected by each family contract after authorization and node/instance filters. | Gateway-known workspace name, resolved inside instance scope when applicable. |
| `fix` | `--fix` | Never. | `--restore` or `--adopt` is present. | `false`. | Selects interactive resolution mode. Every attempted action must be declared safe by its family doctor contract. |
| `restore` | `--restore` | Never. | `--fix` or `--adopt` is present. | `false`. | Selects bulk restore mode (gateway configuration to node reality). |
| `adopt` | `--adopt` | Never. | `--fix` or `--restore` is present. | `false`. | Selects bulk adopt mode (node reality into gateway configuration). |
| `dry_run` | `--dry-run` | Never. | No `--restore` or `--adopt` flag is present. | `false`. | Returns planned bulk actions without invoking family fixers or adopters. |
| `json` | `--json` | Optional. | `--stream-json` is present. | `false`. | Selects the JSON renderer and non-interactive input mode. |
| `stream_json` | `--stream-json` | Optional. | `--json` or `--fix` is present. | `false`. | Selects the stream JSON renderer and non-interactive input mode. |

## Target Eligibility and Category Set

The rendered category set starts with the target node's active role
assignments, then adds families from gateway-owned facts and platform
eligibility. A displayed role label is derived output and grants nothing.

| Target role assignment state | Role-derived base categories |
| --- | --- |
| client with no active role | `Node` |
| active `gateway` role | `Node`, `Processes` |
| active `database` role only | `Node`, `Tools`, `Processes` |
| active `agent` role | `Node`, `Tools`, `Proxy routes`, `Processes` |
| active `router` role | `Node`, `Proxy routes`, `Processes` |
| active `app-dev` role | `Node`, `Instances`, `Workspaces`, `Processes`, `Proxy routes`, `Tools`, `Databases` |
| active `app-prod` role | `Node`, `Instances`, `Processes`, `Proxy routes`, `Tools`, `Databases` |
| active `ingress` role | `Node`, `Proxy routes`, `Tools`, `Processes` |
| active `websocket` role | `Node`, `Tools`, `Processes` |
| active `s3` role | `Node`, `Tools`, `Proxy routes`, `Processes` |
| active `metrics` role | `Node`, `Tools`, `Processes`, `Proxy routes` |
| active `vpn` or `analytics` role without another role-specific category | `Node`, `Processes` |

The `Processes`, `Proxy routes`, and `Databases` categories on an `app-prod`
target cover only production instance and node facts. They never admit workspace
rows, workspace-derived runtime units, workspace routes, workspace database
targets, or unsupported owner markers into a probe. An explicit workspace family or
scope is rejected before dispatch. An `app-prod` caller is also rejected before
using any workspace-adjacent doctor family against an `app-dev` target.

The gateway then adds these fact-derived overlays:

- `Tools` for owned tool rows or baseline tool capabilities, including DNS base
  configuration and runtime capability on an active gateway+VPN node;
- `Firewall` for any active Ubuntu target eligible to own Orbit-protected
  rules, including exporter rules; macOS is excluded;
- `Scheduling` for the gateway and every node targeted by at least one schedule
  definition, independent of workload role; gateway singleton checks run only
  at gateway scope, while target reachability and recent-run checks run at each
  selected workload target; and
- any other family admitted by valid gateway-owned facts for that selected
  node.

A narrow `--family` filter intersects with this resolved eligibility set.
Families outside it are rejected before probes; the renderer shows no
placeholder rows for ineligible families.

DNS findings render in their owning family rows: node projection findings in
`Node`, proxy projection findings in `Proxy routes`, and DNS base/runtime
findings in `Tools`. There is no separate DNS row or DNS state family.

## Input Resolution

1. Resolve option compatibility, including mutually exclusive machine-readable
   output flags.
2. Resolve mode: `verify` (no flag), `interactive` (`--fix`), `restore` (`--restore`), or `adopt` (`--adopt`). `--fix`, `--restore`, and `--adopt` are mutually exclusive. `--dry-run` is valid only with `--restore` or `--adopt`.
3. Resolve the target scope.
   - `--all` selects verify-only fleet mode. It is mutually exclusive with
     single-node, instance, and workspace scope.
   - `--self` is forwarded to the gateway; the gateway resolves it to the calling peer's identified node.
   - `--node=<node>` is forwarded to the gateway and resolved against gateway configuration.
   - `--node=all` is rejected with `validation_failed` before probes.
   - Omitted node scope first uses the locally configured default node when one
     is selected. When no default node is configured, the CLI sends `self=true`
     so the caller's identified node is selected.
   - `--self` combined with `--node` is rejected before forwarding.
4. Call the gateway to authorize the scope, derive the target's resolved eligibility set, and dispatch family probes.
   - In resolution modes, the gateway also attempts actions.
   - Family filters intersect with the resolved eligibility set. Active roles establish its base categories; owned facts and platform support add overlays.
   - Families outside the set are rejected by the gateway.
   - `--key` filters the resulting issue list to the exact key before action planning.
5. Render the gateway's diagnostic.

Input-mode-specific contracts are required for resolution modes:

- [Interactive input mode](5.1_doctor_input-mode_interactive.md)
- [Non-interactive input mode (bulk restore/adopt)](5.2_doctor_input-mode_non-interactive.md)

## Behavior Contract

### Family Dispatch Rules

- Run only product-family doctor probes. Backend-shaped implementation probes are folded into product families before they become public scope keys.
- Security is a cross-family issue-code section, not a product family. `node.security.*`, `instance.security.*`, `workspace.security.*`, and future firewall-owned `firewall_rule.security.*` findings dispatch through their owning families.
- Dispatch each selected family through its family doctor contract.
- Do not duplicate family issue codes, probe facts, restore actions, or adopt actions in the global command.
- Preserve family-owned diagnostic details for the selected output renderer.

### Mode Direction Rules

| Mode | Selected by | Direction | Meaning |
| --- | --- | --- | --- |
| `verify` | No mode flag. | Compare only. | Report where gateway configuration and observed node reality differ. |
| `interactive` | `--fix`. | Per-finding choice. | Present each finding and prompt for restore, adopt, skip, or details. |
| `restore` | `--restore`. | Gateway configuration to node reality. | Re-apply gateway configuration on nodes when the family declares the repair safe. |
| `adopt` | `--adopt`. | Node reality to gateway configuration. | Ingest compatible observed node reality into gateway configuration when the family declares adoption supported. |

`--fix` is the interactive driver, not a direction. The two directions are restore and adopt.

`--restore` is explicit repair-mode consent for family-declared safe actions. It is not permission for arbitrary destructive cleanup. Families must leave unsafe or ambiguous repair paths as reported issues with manual next steps.

`--adopt` is explicit adoption-mode consent for family-declared adoption actions. It is the only doctor mode that may intentionally mutate gateway configuration.

### Scope and Authorization

Cross-peer scope-resolution and grant requirements are owned by
[`7_doctor_scope-and-authorization.md`](7_doctor_scope-and-authorization.md).
Peer-specific authorization remains in the on-node companion contracts:
[`2_doctor_on-client.md`](2_doctor_on-client.md),
and [`3_doctor_on-gateway-node.md`](3_doctor_on-gateway-node.md).

Denial of the selected Doctor scope returns `authorization_failed` before any
family probe runs and is never represented as a Doctor issue. Once scope is
authorized, family probes validate persisted references against gateway state
independently of caller-visible row filtering. A record hidden from a caller is
an authorization concern, not repairable drift in that record.

### Result Classification Rules

- After the selected mode completes with no remaining issues or probe errors, return healthy success.
- After the mode completes with remaining issues, return a drift failure.
- In verify mode, do not change gateway configuration or node reality.
- In resolution modes (`interactive`, `restore`, `adopt`), record every attempted, completed, skipped, failed, or conflicted action.
- For node-scoped `restore`, Orbit runs a bounded multi-pass loop: after each
  apply pass, re-probe the same selected node/families/key/instance/workspace
  fence and apply any newly restorable genuine drift. Stop when no restorable
  genuine drift remains, when the restorable set is unchanged after a pass
  (`stop_reason=no_progress`), or when `max_passes` is reached.
- That final fresh observation is authoritative. An earlier action receipt with
  `status=completed`, `created`, or `updated` must not remove freshly observed
  remaining findings or allow `healthy=true` while issues remain.
- Restore may attach richer per-family action annotations (for example proxy,
  WebSocket, or DNS verification summaries) that mark matching actions as
  failed when drift remains. Those annotations never hide fresh issues.
- In dry-run mode, record planned actions with `status=planned`, leave issues unresolved, and return command success because no mutation was attempted. Dry-run and verify must not apply fixers/adopters or re-probe for resolution verification.
- A family probe error prevents a healthy result. Remote-shell failures and
  agent-push or local-executor transport exceptions both count: the failed
  family emits an Unverifiable `*.probe_failed` issue (or a family-specific
  equivalent) with disposition `blocked_inspection`, and later families continue for the same target.
- Exception: a family contract may define more specific recoverable behavior for that family's probe errors.
- Every emitted issue code must be registered in the family-owned Doctor issue
  catalog with disposition and family ownership. Genuine drift must declare a
  restore action. Unknown codes fail closed and never invent classification
  from name or substring heuristics.

### Issue Dispositions

Public issue disposition is independent of generic `kind`. Automation uses
disposition and `restore_action`, not summary prose.

| Disposition | Value | Restore behavior |
| --- | --- | --- |
| Genuine drift | `genuine_drift` | Safe deterministic restore is declared; node-scoped `--restore` may apply it across multi-pass convergence. |
| Blocked inspection/control | `blocked_inspection` | Report the blocker/prerequisite; do not invent a repair. |
| Invalid gateway intent | `invalid_intent` | Report only; never auto-repair by guessing intent. |
| Runtime incident | `runtime_incident` | Report only when no safe deterministic Doctor recovery path exists. |

Generic issue kinds remain:

- `missing`, `extra`, `divergent`, `unverifiable`

### Scope Boundaries

`doctor` must not:
- Invent a state family outside the stable family keys.
- Treat backend names such as Caddy, systemd, UFW, or package
  managers as public doctor families.
- Create new fleet membership, projects, instances, workspaces, processes, schedules, tools,
  proxy routes, or firewall rules unless the selected family explicitly declares
  a compatible adoption action.
- Hide remaining drift after a restore/adopt action receipt, including when the
  action reports completed, created, or updated.

Successful update commands are not doctor convergence; `doctor` must run its own selected family probes before reporting a healthy result.

## Family Doctor Implementation Contract

Every family doctor probe must expose the same service method surface so the
global `doctor` orchestrator can dispatch, verify, restore, and adopt
uniformly. Family-specific behavior lives in the family doctor doc.

| Method | Purpose |
| --- | --- |
| `key()` | Returns the **singular public** state family key (`node`, `instance`, `workspace`, `process`, `proxy`, `schedule`, `tool`, `firewall_rule`, `database_connection`). Plural keys are invalid and must be rejected by family-doctor tests. |
| `label()` | Returns the human-readable family label used by renderers. |
| `introspect(<owner>)` | Reads physical reality needed for ordinary drift checks. Returns the family-specific `ProbeSnapshot`. May return an empty snapshot when the family's diff layers do not need preloaded state. |
| `diff(<owner>, ProbeSnapshot $snapshot)` | Compares gateway configuration with snapshot reality into `DriftEntry` results. |
| `canReconcile()` | Returns whether `doctor --family=<F> --restore` is supported. |
| `reconcile(<owner>, DriftEntry $entry)` | Applies restore behavior for supported keys and throws for unsupported keys. |
| `canAdopt()` | Returns whether `doctor --family=<F> --adopt` is supported. |
| `snapshotForAdopt(<owner>)` | Reads adoption-specific proof such as identity artifacts, runtime readiness, or external substrate facts. |
| `adopt(<owner>, ProbeSnapshot $snapshot)` | Attempts supported adoption paths and returns `AdoptResult` rows with `updated`, `skipped`, or `conflict` actions. |

Family doctor contracts state their per-family `<owner>` type, `key()` value,
and which issue codes are restorable or adoptable. New probe layers add their
issue code to the family doctor doc, add focused Pest coverage in the family
probe test, and document restore/adopt behavior before the code starts
returning the new key.

## Issue Kinds

Generic doctor issue kinds describe the relationship between gateway configuration and observed reality:

- `missing`: gateway configuration expects reality that is absent.
- `extra`: reality exists without matching active gateway configuration.
- `divergent`: configuration and reality both exist but disagree.
- `unverifiable`: doctor cannot determine reality because a prerequisite is unavailable.

Family doctor contracts define the family-specific cases that produce these kinds.
Disposition (above) is the public outcome classification; kinds alone do not
decide restore eligibility.

## Renderer Contracts

- [Human renderer](6.1_doctor_output-render_human.md)
- [JSON renderer](6.2_doctor_output-render_json.md)
- [Stream JSON renderer](6.3_doctor_output-render_stream-json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Scope not found | A requested family, node, instance, or workspace scope cannot be resolved. | Failure before probes |
| Reserved fleet node value | `--node=all` or API `node=all` is supplied. | `validation_failed` before probes with `field=node` and `value=all` metadata |
| Fleet scope conflict | `--all` is combined with single-node, instance/workspace, or resolution-mode scope. | `validation_failed` before probes |
| Mode not supported | A selected family or issue does not support the requested `--restore` or `--adopt` action. | Failure with diagnostic payload when available |
| Probe failed | A family probe fails in a way that prevents a healthy result. | Failure with diagnostic payload |
| Drift detected | Drift remains after the selected mode completes. | Failure with diagnostic payload |
| Dry-run mode invalid | `--dry-run` is supplied without `--restore` or `--adopt`. | Failure before probes |
| Ambiguous JSON renderer | `--json` and `--stream-json` are supplied together. | Failure before gateway I/O |
| Interactive stream invalid | `--fix` and `--stream-json` are supplied together. | Failure before gateway I/O |

The shared exit status policy applies: `0` for healthy success, `1` for
Orbit-handled command failures, and `2` only for console-runtime invalid usage
before Orbit can apply this command contract.

## Doctor Relationship

The global `doctor` command owns orchestration, scoping, mode semantics, output envelopes, generic issue kinds, and generic failure behavior.

Family doctor contracts own:

- probe layers;
- concrete issue codes and issue details;
- restore action maps;
- adopt action maps;
- family test mapping.

Converted family doctor contracts:

- [`node-doctor.md`](../../../1_node/node-doctor.md)
- [`tool-doctor.md`](../../../3_tool/tool-doctor.md)
- [`firewall-doctor.md`](../../../4_firewall/firewall-doctor.md)
- [`instance-doctor.md`](../../../5_project/instance-doctor.md)
- [`workspace-doctor.md`](../../../6_workspace/workspace-doctor.md)
- [`process-doctor.md`](../../../7_process/process-doctor.md)
- [`proxy-doctor.md`](../../../8_proxy/proxy-doctor.md)
- [`schedule-doctor.md`](../../../9_schedule/schedule-doctor.md)
- [`database-doctor.md`](../../../18_database/database-doctor.md)

## Test Mapping

Required contract tests:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/DoctorRunControllerTest.php` | Gateway API input contract, `--key`, `--dry-run`, scope resolution, family-key validation, gateway authorization failures, exit-code semantics, JSON envelope, and family dispatch boundaries. |
| `apps/cli/tests/Feature/Commands/Operation/DoctorCommandTest.php` | CLI default-node payload resolution, caller fallback payload, `--all` payload, `--node=all` validation, renderer compatibility for `--json`, `--stream-json`, ambiguous renderer rejection, and `--fix --stream-json` rejection. |
| `apps/cli/tests/Feature/Commands/Operation/DoctorFixCommandTest.php` | CLI interactive `--fix` prompt flow, cancellation, selected issue forwarding, and `--json --fix` rejection. |
| `apps/gateway/tests/Unit/Services/Doctor/DoctorReportRunnerTest.php` | Role-aware category set per target active roles, universal process-family support for role-bearing nodes, app-dev/app-prod workspace split, `--family` rejection through scope validation, and per-node probe scoping for instance/workspace/proxy families. |
| `apps/gateway/tests/Unit/Services/Doctor/DoctorCompleteRolesReportingTest.php` | Additive `roles` arrays on single-node scope and fleet node summaries while preserving primary `role` and fleet `scope.role=fleet`. |
| `apps/gateway/tests/Feature/Http/Api/DoctorRunControllerTest.php` | Gateway API verify and fix endpoints, target node resolution from request body, caller authorization, and family dispatch over the API path. |
| `apps/gateway/tests/Unit/Services/Doctor/DoctorReportRunnerTest.php` | Per-target probe scoping, restore-mode action suppression, action failure recording, multi-pass restore convergence with precise action counts, and family dispatch through the in-process runner. |
| `apps/gateway/tests/Unit/Services/Doctor/DoctorIssueCatalogInventoryTest.php` | Family-owned issue catalog inventory: every family-doctor doc code is classified, genuine drift has a restore action, unknown codes fail closed, and no name-heuristic fallback remains. |
| `apps/gateway/tests/Unit/Services/Doctor/DoctorRestoreConvergenceTest.php` | Bounded multi-pass restore loop: continues until clean, stops on no-progress, and respects max passes. |

Test mapping for each family lives in its family doctor contract, such as
[`node-doctor.md`](../../../1_node/node-doctor.md#test-mapping).

Peer-specific behavior and test mapping live in:

- [`2_doctor_on-client.md`](2_doctor_on-client.md)
- [`3_doctor_on-gateway-node.md`](3_doctor_on-gateway-node.md)

## Activity Logging

The local CLI command emits a best-effort activity entry for successful and
failed doctor runs. The gateway API endpoints emit activity entries for remote
doctor orchestration requests.

| Field | Value |
| --- | --- |
| Type | `doctor` for local CLI; `api:POST /doctor/run` for gateway API verify transport |
| Effect | `read` for verify-mode and `--dry-run` runs; `write` for `--fix`, `--restore`, and `--adopt` mode orchestration that actually applies changes |
| Subject | `none` |
| Properties | `mode`, selected `families`, optional `key`, `dry_run`, `healthy`, and `issues` when available. Action counts and status when in resolution modes. API transport context is added by middleware. |
| Description | `Doctor verification run` for local CLI verify mode; `Doctor resolution run` for local CLI resolution modes; derived for gateway API |
