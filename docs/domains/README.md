# Command Contracts

Command contracts define Orbit's stable product surface. They describe ideal
behavior, not current implementation and not migration history.

The architecture defines the world commands operate in. Command contracts define the command surface inside that world.

Before adding or changing a command:

1. Update `docs/architecture.md` if the change affects Orbit's architecture or
   domain model.
2. Update `docs/tech-stack.md` for changes to implementation shape, backend boundaries, process manager behavior, transport edges, or scheduler mechanics.
3. Update each command contract in this directory whose behavior the change
   affects.
4. Confirm the command contracts remain consistent with each other.
5. Implement code to match the contract.

After changing converted command documentation, run `composer docs-lint`. Use a
scoped path such as `composer docs-lint -- --path=docs/domains/1_node` while a
different domain is still mid-conversion. Librarian is the executable structure
contract for converted command docs, with Orbit-specific registries under
`config/librarian-command-docs/`. When the documentation structure changes,
update the Librarian rule or Orbit registry first, then migrate docs until it
passes.

## Contract Rules

These rules govern every command contract in this directory.

- Commands are the product contract for humans, LLM agents, CI, and shell
  automation. Future UI behavior belongs in typed API or service contracts, not
  command renderer contracts.
- The typed API is gateway transport, not the primary user-facing contract.
- Every command that returns structured data has a JSON contract.
- Human output and JSON output represent the same result.
- Commands must state which domain owns the behavior.
- In non-operation command families, public command names must start with that
  family's command prefix. For example, `1_node` contains `node:*` commands,
  `2_gateway` contains `gateway:*` commands, `16_dns` contains `dns:*`
  commands, and `5_app` contains `app:*` commands. `11_operation` is the
  exception for miscellaneous operational commands such as `doctor`, `update`,
  and `activity:*`.
- When a domain has compound command prefixes, use the longest compound prefix
  before the colon and put only the action after the colon. Examples:
  `workspace-setup-step:add`, `workspace-teardown-step:add`, `cf-dns:list`,
  `cf-cache-rule:add`, and `vpn-client:list`. Do not collapse these into
  split `family:compound-action` spellings.
- Documentation domains and doctor state families are related but not
  interchangeable. A command belongs to a documentation domain; drift
  convergence belongs to a stable state family such as `node`, `app`,
  `workspace`, `process`, `proxy`, `firewall_rule`, `tool`,
  `schedule`, or `database_connection`.
- Tool and capability command families are explicitly admitted product
  surfaces, not generated from the tool catalog. Generic lifecycle, inventory,
  logs, credentials, update, reload, and reconfiguration stay under `tool:*`.
  A tool-specific or capability-specific family is valid only when it owns a
  distinct Orbit workflow whose natural operator vocabulary is the tool or
  capability name. `php:*` owns PHP image selection across apps and
  workspaces; future Redis data-plane operations may use
  `redis:*`. Database connection inventory, env convergence, schema
  inspection, audited SQL execution, and database backup/restore workflows
  belong to `database:*` instead of `mysql:*` or `postgres:*` command
  families. `s3:*` owns role-backed object-storage publication and service
  credentials for the RustFS-backed S3 role; generic RustFS lifecycle remains
  under `tool:*`.
- Commands must state whether they mutate gateway configuration, apply node artifacts, stream runtime data, or only read state.
- The CLI is a thin gateway client. Every command call is a request to the
  gateway over HTTPS, regardless of which machine the operator runs it on. The
  gateway authenticates the WireGuard peer, applies authorization, and owns all
  durable Orbit state. The CLI gathers input, calls the gateway, and renders the
  response.
- Authorization is the gateway's responsibility. The CLI does not gate or scope
  commands by caller role locally. The gateway authorizes every request through
  the grant edge from the authenticated WireGuard peer to the command's resource
  and the scoped permission set stored on that grant. There is no built-in
  caller role; denials are surfaced as `error.code=authorization_failed` with
  `error.meta.missing_permission` identifying the required permission.
- `Effects` describe command behavior, not authorization scopes. Authorization
  is node-grant based and belongs in `Prerequisites` and failure semantics.
- Commands must state how they interact with `orbit doctor`.
- Command contracts use ideal Orbit vocabulary, not backend-specific names.
- Default list/show commands over gateway-owned state must be gateway database
  reads. They may include durable gateway history, such as process events or run
  history, but must not synchronously SSH to nodes or invoke live backend probes
  unless the command has an explicit live flag or stream/log contract. Live flags
  are command-specific exceptions; adding one command's live flag does not imply
  the same flag exists on sibling list/show commands in other domains.
- Commands follow the shared invocation model below. Command-specific contracts
  define required inputs and renderer output; they must not duplicate generic
  input-mode behavior as command-specific failure semantics.
- Missing required input belongs to input-mode contracts and renderer contracts,
  not canonical command-domain failure semantics, unless the omitted input
  selects a documented alternate sub-action such as showing current state or
  prompting from authorized choices.
- Commands that accept an app-role target should resolve it in this order:
  explicit `--node`, app/workspace ownership, then interactive input prompt or
  non-interactive input failure.
- Renderer and prompt primitive selection is governed by
  [`docs/ux/commands/`](../ux/commands/README.md). Renderer docs and input-mode docs name
  a primitive from that tree and link to the matching page. Implementation
  mechanics live in `.agents/skills/command-designer/SKILL.md`.
- Technical command contracts must use the prompt IDs and Laravel Prompts
  primitive names admitted by [`docs/ux/commands/inputs/`](../ux/commands/inputs/README.md).
  Renderer contracts use primitives admitted by
  [`docs/ux/commands/lists/`](../ux/commands/lists/README.md) and
  [`docs/ux/commands/progress/`](../ux/commands/progress/README.md). Symfony Console
  `$this->ask`, `$this->confirm`, `$this->choice`, `$this->secret`, and
  `$this->table` are banned in renderer and input-mode docs.
- Public command pages that have a command directory must link to their
  canonical technical contract. Canonical technical contracts must link back to
  the public command page.
- Technical command contracts must name the required test files that enforce
  the contract. If a referenced test file does not exist, create it before
  implementing or changing the behavior it covers.
- Command contract tests must be implementation-agnostic. They assert expected
  inputs, outputs, side-effect boundaries, persisted contract state, and
  externally visible calls. They must not assert internal services, private
  methods, handler names, or temporary implementation structure.
- Migration mappings from old commands belong in contraction audits, not here.
- Backend-shaped import or sync commands are not stable command contracts. Migration adoption must be explicit through `doctor --adopt` or live outside permanent command docs.
- Upgrade work belongs in Laravel migrations, `orbit doctor --restore`, or explicit `orbit doctor --adopt`.
- Public versioned migration commands and helper commands for one-off upgrades are not part of the stable command surface.

## Documentation Structure

Each numbered domain directory contains:

- `README.md`: domain rules and the ordered command index.
- `N_command-name/command-name.md`: public-facing command documentation. Every
  converted family command uses a numbered command directory, even when the
  command is simple.
- `N_command-name/technical/`: internal command behavior contracts, prompt
  contracts, failure semantics, progress shape, JSON edge cases, and test
  mapping for that command. Multiple technical files must be numbered, with
  `1_command-name.md` as the canonical contract, linked back to the public
  command page, and ordered companion files after it.
- `N_command-name/technical/5.1_command-name_input-mode_interactive.md` and
  `5.2_command-name_input-mode_non-interactive.md`: optional input-mode-specific
  command contracts. Use when interactive and non-interactive invocation have
  enough behavior or tests to deserve separate ownership.
- `N_command-name/technical/6.1_command-name_output-render_human.md` and
  `6.2_command-name_output-render_json.md`: optional renderer-specific command
  contracts. Use when human output and JSON output have enough behavior or
  tests to deserve separate ownership.
- `<family-singular>-doctor.md`: optional family-level doctor contract for probe, drift, restore, and adopt behavior. Use a family-specific signature, such as `node-doctor.md` for `doctor --family=node`.
- `internal/`: optional subdirectory for internal Orbit machinery commands.

The shared [`docs/ux/commands/`](../ux/commands/README.md) tree lists the admitted
renderer and prompt primitives (lists, inputs, progress) and the rules for
picking between them. Renderer and input-mode docs link into this tree
instead of redescribing primitives inline.

Command groups with hidden or internal machinery commands include an `Internal Commands` section in their `README.md` that links to the `internal/` subdirectory. Public command lists remain separate to maintain visibility distinctions.

Flat numbered command files are not valid in converted command families. If a
legacy family is being ported, each public command must be converted into the
directory shape with at least a public command page, canonical technical
contract, and renderer contracts. Add companion technical files for caller-role
authorization, topology, input-mode, destructive consent, cross-node, or E2E
behavior when those contracts need separate ownership.

### Domains and state families

Command directories are documentation domains. State families are doctor and
convergence families. They often align, but they are not the same concept.

Stable state families are `node`, `app`, `workspace`, `process`,
`proxy`, `firewall_rule`, `tool`, `schedule`, and `database_connection`.
These are the keys accepted by `doctor --family=<family>` and the values
carried by warning or doctor `family` fields. Machine-readable issue and
warning codes use singular product prefixes, such as `node.wireguard_peer_missing`,
`app.runtime_container_missing`, `workspace.path_missing`,
`process.runtime_unit_missing`, `proxy.route_extra`, `schedule.unit_extra`, and
`database_connection.env_missing`.

Security is a cross-family section pattern, not a command domain or state
family. Do not add a `security` command domain or `doctor --family=security`.
Security issue codes live under the family that owns the state being checked,
such as `node.security.*`, `app.security.*`, or `workspace.security.*`.

Warning `family` is `null` only for command-owned warnings that are not doctor
issue codes and do not point at `doctor` as the recovery command. Warning codes
that a command owns still use the singular product prefix for the command's domain.

Family issue-code condition names should use the product relationship term for
that family, such as `app.owner_node_invalid`,
`workspace.parent_app_invalid`, or `process.owner_app_invalid`. Do not
normalize these into a generic parent/owner vocabulary when the domain model
uses a more specific relationship name.

Other documentation domains, such as operations, deployments, VPN
administration, PHP runtime, and agent IDE commands, may call or affect state
families without becoming state families themselves.

Converted documentation domains that are not state families must include a
`## State Ownership` section in their family README. That section states that
the command domain does not own a state family and names the state-family
doctor handoff for any durable Orbit state or health it affects.

When converting a command, state both the documentation domain that owns the user-facing command page and the state family or families whose configuration/reality the command reads, writes, verifies, restores, or adopts.

### Authorization

Authorization is gateway-owned. The gateway authenticates the caller's
WireGuard peer identity and applies the scoped permission set stored on the
grant that connects the caller to the command's resource. The CLI does not
detect or branch on a caller role; there is no built-in role projection.
See [Architecture: Authentication and
authorization](../architecture.md#authentication-and-authorization).

Technical contracts do not carry a dedicated Authorization section. When a
command requires specific permissions, state those permissions in
Prerequisites and surface authorization failures as
`error.code=authorization_failed` with `error.meta` describing the missing
permission. Family READMEs may document family-wide permission rules.

### Authorization Metadata

Gateway API controllers declare command authorization with the
`App\Http\Authorization\RequiresPermission` PHP attribute. The attribute names
the required permission and the serving-node resolution mode:
`Gateway`, `Target`, `AppOwning`, `WorkspaceOwning`, or `Caller`.

This metadata is an implementation hook for gateway middleware. It does not
change command documentation structure and does not turn deployment-context
companion files into authorization gates. Routes without the attribute are
handled as bootstrap, deployment-context, authenticated-ungated, or not yet
migrated routes according to the authorization matrix.

### Technical Slot Map

When a command uses a `technical/` directory, reserve these slots. The
`*_on-client.md`, `*_on-gateway-node.md`, and `*_on-app-role.md` slots are
deployment-context companion contracts: they describe distinct command
behavior or rendering depending on where the CLI is running locally
(client vs. gateway-local vs. node carrying an app role). They are not
authorization gates — authorization is grants-only — but they capture real
context-specific behavior that varies with deployment context.

| Slot | Meaning |
| --- | --- |
| `1_command-name.md` | Canonical technical contract. |
| `2_command-name_on-client.md` | Behavior or rendering specific to a CLI running on a client. |
| `3_command-name_on-gateway-node.md` | Behavior or rendering specific to a CLI running on the gateway host. |
| `4_command-name_on-app-role.md` | Behavior or rendering specific to a CLI running on a node carrying an app role. |
| `5.1_command-name_input-mode_interactive.md` | Interactive input-mode contract. |
| `5.2_command-name_input-mode_non-interactive.md` | Non-interactive input-mode contract. |
| `6.1_command-name_output-render_human.md` | Human output renderer contract. |
| `6.2_command-name_output-render_json.md` | JSON output renderer contract. |
| `07+` | Command-specific companion contracts, only when needed. |

Skip unused slots only when that behavior does not exist for the command. Do not
reuse a reserved slot for a different concern. Technical file prefixes are
unique slot numbers within that command's `technical/` directory; they do not
reuse the parent command directory ordinal.

## External Decision Tracking

Command docs do not keep sidecar files for tracking in-repo ambiguity. When requested
behavior, existing docs, implementation evidence, tests, or product vocabulary
disagree, track the unresolved question outside the project. Once the user
decides, update the authoritative command docs directly. Do not leave product
behavior only in decision-history notes.

## Invocation Model

Every command has an input contract, an input mode, and one or more output
renderers. Input modes resolve inputs and renderers present results. Side
effects begin only after required inputs have been resolved and validated, but
field validation and path eligibility run incrementally as soon as their needed
fields are known.

### Input Modes

Use this table to determine which input mode applies and how missing input is handled.

| Mode | Used when | Missing required input |
| --- | --- | --- |
| Interactive input mode | The command is running in a TTY and `--json` is not present. | Prompt before side effects, using the command's prompt mapping. |
| Non-interactive input mode | The command is running without a TTY, or `--json` is present. | Fail before side effects with a clear validation message. |

`--json` always disables prompts and uses non-interactive input mode, even when
the process has a TTY.

### Destructive Confirmation

Commands with `destructive` effects always require destructive consent before
side effects begin.

- `--dry-run` is not a universal destructive-command flag. A destructive command
  may define it only when it has a meaningful discovery or planning phase whose
  result can be returned without side effects. Commands that operate on an
  explicit named target should use confirmation/`--force` unless their contract
  documents a preview-specific reason.
- Interactive input mode asks for confirmation unless `--force` is supplied.
  The prompt is rendered after target and subject resolution and before any
  side effect.
- Non-interactive input mode without `--force` fails before side effects with
  `validation_failed`, `meta.field=force`, and
  `meta.reason=destructive_consent_required`.
- `--force` is explicit destructive consent in any mode and skips the
  interactive confirmation prompt.
- `--json` selects the JSON renderer and forces non-interactive input mode
  only. It does not imply destructive consent.
- `--json` does not change any other semantic: it does not create hidden target
  fallbacks, skip validation, bypass authorization, or bypass `--force`.

Progress trees for destructive commands still start after input resolution and
destructive consent, before cleanup side effects. Pre-confirmation gateway reads
for target or subject resolution are allowed.

Interactive input mode re-prompts invalid input for the current field until the
value is valid, the answer changes the path so the prompt is no longer needed,
or the user aborts. There is no generic retry cap unless the command contract
documents one. Prompt aborts such as Ctrl-C, EOF, or a cancel action supported
by the primitive exit with the standard command failure status and no side
effects.

### Exit Status

Converted command contracts use the shared exit status policy unless a command
explicitly documents a command-specific exception in its canonical technical
contract and maps tests for that exception.

- `0`: success, including success-with-warnings.
- `1`: Orbit-handled command failure, including validation, authorization, gateway reachability, domain eligibility, and remote apply failures.
- `2`: invalid CLI usage before Orbit can apply the command contract, such as an unknown option or malformed invocation rejected by the console runtime.

JSON `error.code` is the stable machine-readable classifier for command failures. Do not create numeric exit codes that vary by domain, such as "validation error = 2" or "remote apply failure = 3", in command docs.

### Invocation Matrix

This table maps each invocation context to the input mode and output renderer that apply.

| Invocation | Input mode | Output renderer |
| --- | --- | --- |
| TTY without `--json` | Interactive | Human |
| TTY with `--json` | Non-interactive | JSON |
| No TTY without `--json` | Non-interactive | Human |
| No TTY with `--json` | Non-interactive | JSON |

### Output Renderers

Every command supports one or both of these renderers, selected by invocation context.

| Renderer | Used when | Contract |
| --- | --- | --- |
| Human renderer | Default CLI output. | Progress trees, prose errors, summaries, and next steps. |
| JSON renderer | `--json` is present. | Stable discriminated JSON envelope with either `success` or `error` as the only top-level key. |

### JSON Envelope

Every JSON response uses one discriminated top-level command envelope. The
top-level object must contain exactly one key: `success` or `error`.
Long-lived stream commands are the exception because they emit multiple frames
rather than one response. A stream command that supports `--json` must document
its frame shape, terminal error behavior, and whether failures before the stream
opens still use the standard envelope.

| Field | Required when | Meaning |
| --- | --- | --- |
| `success.data` | Success. | Command-specific payload. Use an empty object only when a successful JSON response intentionally has no structured result. |
| `success.meta` | Success, when useful. | Optional machine-readable execution context that is not the payload, such as selected scope, pagination, warnings, or resolved entity references. |
| `error.code` | Failure. | Stable machine-readable failure code. Required on every failure. |
| `error.message` | Failure. | Human-readable failure message. Automation must not parse this field. |
| `error.meta` | Failure. | Machine-readable failure context. Required on every failure; use an empty object only when no stable context exists. |
| `error.data` | Failure, only when documented. | Optional command-specific diagnostic or partial-result payload, such as `doctor` issues after drift was found. |

`error.meta` replaces loose failure details. Use it only for stable facts
automation needs to classify or recover from a failure, such as `field`,
`reason`, `step`, `exit_code`, `conflicts`, or `partial_state`.

### JSON Action Fields

When a JSON payload includes an `action` field, the values are a command-local
enum, not a global Orbit enum. The field describes the command operation or
sub-action that completed, such as `created`, `removed`, `set`, `clear`, or
`show`.

Idempotence state must not be encoded as an `already_*` action value. Keep the
operation in `action` and expose the idempotence detail in a separate stable
field, such as `already_granted` or `already_absent`. Commands with explicit
convergence semantics may use `converged` when the command contract defines
convergence as the successful no-op outcome.

Use shared failure vocabulary unless a domain-specific code is needed:

| Code | Use for |
| --- | --- |
| `validation_failed` | Missing required input, malformed input, unsupported scalar values, and static validation failures. Use `error.meta.field` when one field caused the failure. |
| `authorization_failed` | The authenticated peer's grant does not include the permission(s) required for the command's resource. Use `error.meta.missing_permission` or `error.meta.reason` to identify the missing authority. |
| `gateway_unavailable` | The CLI cannot reach the gateway API required for the command. |

Do not introduce new synonyms such as `missing_input`, `missing_argument`,
`validation.missing_input`, `unauthorized`, or role-denial-specific legacy
codes in new or touched contracts.

Fields that are structural members of an entity object are serialized as `null`
when they are inapplicable for the returned entity. Purely optional metadata
fields are omitted from the payload when absent and are not serialized as
`null`.
When a structural field applies but the command cannot determine its value yet,
use a command-documented sentinel such as `unknown` instead of omitting the
field.

### Contract Boundaries

Each contract section below owns a distinct layer of command behavior.

- The input contract states which fields exist, when they are required, when
  they are forbidden, defaults, and validation rules.
- The input resolution flow states the order in which fields are resolved and
  validated, using prompt IDs for fields that can be collected by the terminal
  prompt flow.
- Interactive input mode contracts state prompt IDs, primitives, labels,
  choices, defaults, and invalid prompt behavior.
- Non-interactive input mode contracts state missing-input, forbidden-input, and
  no-prompt behavior.
- Companion technical files inherit `Owner` and `Effects` from the canonical
  technical contract unless they explicitly override them. Omit identical owner
  and effects stanzas from companion files.
- Human output renderer contracts own progress trees, exact human-rendered
  strings, prose errors, summaries, and next steps.
- Every human output renderer file must include `## Progress Tree`. If a
  command may take longer than one second in normal, remote, destructive,
  networked, or degraded execution, the renderer defines the tree rendered
  after input resolution and before side effects begin. If no tree is rendered,
  the renderer explains why the command is expected to stay below one second
  and performs no slow external work.
- JSON output renderer contracts own envelopes, payload data shapes, error
  codes, error messages, and error metadata.
- Family doctor contracts own probe layers, drift kinds, restore behavior, adopt behavior, and doctor test mapping.
- Doctor sections in individual command contracts link to the family doctor contract and describe only the drift or repair relationships that the command creates.
- Failure semantics describe command/domain failures after input resolution:
  invalid combinations, authorization failures, network failures, remote
  execution failures, partial provisioning, drift, and exit codes.
- Missing required input is input-mode behavior, not command-domain failure
  semantics.

## Common Failures

Every gateway-call command can produce these failures. The Failure Semantics
section in each command contract must not restate them; document only
command-specific failures.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed | A required input is missing, invalid, or forbidden alongside another option. | `error.code=validation_failed` |
| Gateway unavailable | The CLI cannot reach the gateway API. | `error.code=gateway_unavailable` |
| Authorization failed | The authenticated peer's grant does not include the permission(s) required for the command's resource. | `error.code=authorization_failed` with `error.meta.reason` and (when applicable) `error.meta.missing_permission`. |

Per-family failure codes (`cloudflare_unavailable`, `vpn_backend_unavailable`,
`gateway_ssh_unavailable`, and similar) live in the family READMEs or in the
canonical command contract that introduces them.

## Public Command Page Template

Public command pages are operator documentation. They explain what the command
does and how to use it, without internal test mapping or implementation
constraints.

````markdown
# `orbit domain:verb ...`

Short user-facing summary.

## Usage

```bash
orbit domain:verb [argument] [--option=<value>]
```

## Examples

Common commands a user can copy.

## Arguments And Options

- User-facing argument and option descriptions.

## What Happens

Plain-language behavior and important boundaries.

## Output

Human output summary and JSON availability.

## Requirements

Operator prerequisites and related setup commands.

## Related Commands

Related commands a user may want to run before or after this one.
````

## Technical Command Contract Template

Technical command contracts live under a command's `technical/` directory. They
are internal implementation and test contracts. They must be precise enough that
tests can assert command behavior directly from the document.

````markdown
# Technical Contract: `orbit domain:verb ...`

**Owner:** Domain or state family that owns the behavior.

**Effects:** Read, write, stream, destructive, internal, local-only,
gateway-admin, or none.

`write` is an umbrella effect. The canonical behavior contract must state whether the command writes gateway configuration, CLI client configuration, node-runtime reality, or a combination. Effects are for observable behavior, not permission scope.

**Prerequisites:**
State that must already exist before command input resolution or side effects
begin.

## Signature

Canonical signature. Required inputs should still be optional at the shell
signature level when interactive input mode can prompt for them.

## Input Contract

Arguments and options with required conditions, forbidden conditions, defaults,
and validation rules.

## Input Resolution

Ordered, mode-neutral resolution of fields and validation. Reference prompt
IDs instead of repeating terminal prompt details.

If path eligibility depends on resolved command input, document the
post-input path eligibility rules and apply each one as soon as the fields
needed for that rule are known, and always before side effects. Do not keep
prompting after a blocker is already knowable. In interactive input mode,
correctable blockers should show a validation message at the current
corrective prompt so the user can change course or cancel. Caller-role
authorization is evaluated by the gateway, not by the CLI's input resolution
flow.

## Input Mode Contracts

Links to interactive and non-interactive input mode contracts, when they are
split into separate files. Omit this section entirely when the command has no
input-mode-specific contracts; the shared [Invocation Model](#invocation-model)
applies.

## Behavior Contract

What the command does, what it does not do, what state it reads or writes, and what node artifacts it applies.

## Renderer Contracts

Links to human and JSON renderer contracts, when they are split into separate
files.

## Failure Semantics

Command-specific failures only. Do not restate the [Common
Failures](#common-failures) that apply to every gateway-call command. Document
remote-execution failures, partial-success behavior, and exit codes that are
not already covered there.

## Doctor Relationship

What drift this command may create or resolve, and which family verifies it.

## Test Mapping

Required test file paths and the primary behavior each file owns. Include
in-memory feature/unit tests and E2E tests when the command crosses process,
node, or network boundaries. If a test file is listed here and missing, it is
planned coverage that must be created when implementing the contract.

Tests should survive implementation changes. They should prove that documented
inputs produce documented outputs, side effects, and persisted state. A renderer
test may assert the shape of an error produced by another owner, but the
underlying rule remains owned by the input, role, or domain test listed for that
rule.
````

The canonical effects vocabulary is maintained in
[command-designer](../../.agents/skills/command-designer/references/command-documentation.md#effects).
Command docs should combine only those effects and should use `none` only for a
companion path that is denied before prompts and side effects.

## Test Suites

Command docs may reference these suite layers:

| Suite | Meaning |
| --- | --- |
| `tests/Unit/` | In-memory behavior for pure services, probes, DTOs, and value objects. |
| `tests/Feature/` | Command contract tests in the Laravel app context. Prefer these for input/output contracts, side-effect boundaries, and persisted state. |
| `tests/E2E/` | Real VM tests that create, alter, or destroy disposable hosts, identities, or state. These require explicit ephemeral infrastructure configuration from [`TESTING.md`](../../TESTING.md). |

E2E tests prove the contract works through real process, network, and node
boundaries. They should remain smoke-level and must not replace the primary
in-memory contract owner listed in each command's test mapping.

## Domains

Domains are ordered by dependency: nodes define fleet membership, gateway
defines control-plane authority and trust, tools and firewall rules establish
node capabilities and network policy, and apps and app-owned runtime behavior
build on top of that foundation.

### Foundation domains

These domains define the fleet, control-plane authority, and node capabilities.
They also define the core app/workspace foundation.

1. [Nodes](1_node/README.md)
2. [Gateway](2_gateway/README.md)
3. [Tools](3_tool/README.md)
4. [Firewall](4_firewall/README.md)
5. [Apps](5_app/README.md)
6. [Workspaces](6_workspace/README.md)

Processes, proxy, and database support those foundation domains:

7. [Processes](7_process/README.md)
8. [Proxy](8_proxy/README.md)
9. [Database](18_database/README.md)

### Runtime workflow domains

These domains coordinate scheduled tasks, deployments, and cross-family
operations on top of the foundation.

10. [Schedules](9_schedule/README.md)
11. [Deployments](10_deploy/README.md)
12. [Operations](11_operation/README.md)

### Runtime integration and observability domains

These domains integrate Orbit with Cloudflare, VPN, PHP runtimes, agent IDEs,
DNS, activity logs, and the object-storage workflows owned by the S3 role.

13. [Cloudflare](12_cf/README.md)
14. [VPN Administration](13_vpn/README.md)
15. [PHP Runtime](14_php/README.md)
16. [Agent IDE](15_agent-ide/README.md)
17. [DNS](16_dns/README.md)
18. [Activity](17_activity/README.md)
19. [S3](19_s3/README.md)
