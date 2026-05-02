# Command Contracts

Command contracts define Orbit's stable product surface. They describe ideal
behavior, not current implementation and not migration history.

The blueprint defines the world commands operate in. Command contracts define
the command surface inside that world.

Before adding or changing a command:

1. Update `docs/BLUEPRINT.md` if the change affects Orbit's architecture or
   domain model.
2. Update the relevant command contract in this directory.
3. Confirm the command contracts remain consistent with each other.
4. Implement code to match the contract.

After changing converted command documentation, run `composer docs-lint`. Use a
scoped path such as `composer docs-lint -- --path=docs/commands/1_node` while a
different domain is still mid-conversion. The linter lives in
`tool/docs-linter/` and is the executable structure contract for converted
command docs. When the documentation structure changes, update the linter first,
then migrate docs until it passes.

## Contract Rules

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
- Documentation domains and doctor state families are related but not
  interchangeable. A command belongs to a documentation domain; drift
  convergence belongs to a stable state family such as `node`, `app`,
  `workspace`, `process`, `proxy_route`, `firewall_rule`, `tool`, or
  `schedule`.
- Commands must state whether they mutate gateway intent, enact node artifacts,
  stream runtime data, or only read state.
- Commands may be invoked from a control node, the gateway, or an app node. A
  non-gateway invocation is a client call to the gateway over HTTPS; app-node
  CLI commands may infer local app/workspace context but must not mutate durable
  Orbit state locally.
- App-node context is not write permission. App-node callers may run app read
  commands when authorized, but app-level writes, cross-node creation/adoption,
  destructive cleanup, pruning, and preference changes must be control/gateway
  actions unless a command documents a narrow exception.
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
- Commands that accept an app-node target should resolve it in this order:
  explicit `--node`, app/workspace ownership, local `node:default`, then
  interactive input prompt or non-interactive input failure.
- Command output and terminal UX conventions follow
  `.agents/skills/command-designer/SKILL.md`.
- Technical command contracts must use the prompt IDs and Laravel Prompts
  primitive names defined in `.agents/skills/command-designer/SKILL.md`.
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
- Backend-shaped import or sync commands are not stable command contracts.
  Migration adoption must be explicit through `doctor --adopt` or live outside
  permanent command docs.
- Upgrade work belongs in Laravel migrations, `orbit doctor --fix`, or explicit
  `orbit doctor --adopt`. Public versioned migration commands and one-off
  upgrade helper commands are not part of the stable command surface.

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
- `<family-singular>-doctor.md`: optional family-level doctor probe, drift,
  fix, and adopt contract when the family owns doctor behavior beyond
  individual commands. Match the family-specific command signature, such as
  `node-doctor.md` for `doctor --family=node`.
- `internal/`: optional subdirectory for internal Orbit machinery commands.

Command groups with hidden or internal machinery commands include an `Internal Commands` section in their `README.md` that links to the `internal/` subdirectory. Public command lists remain separate to maintain visibility distinctions.

Flat numbered command files are not valid in converted command families. If a
legacy family is being ported, each public command must be converted into the
directory shape with at least a public command page, canonical technical
contract, and renderer contracts. Add companion technical files for caller-role,
topology, input-mode, destructive consent, cross-node, or E2E behavior when
those contracts need separate ownership.

### Domains And State Families

Command directories are documentation domains. State families are doctor and
convergence families. They often align, but they are not the same concept.

Stable state families are `node`, `app`, `workspace`, `process`,
`proxy_route`, `firewall_rule`, `tool`, and `schedule`. These are the keys
accepted by `doctor --family=<family>` and the values carried by warning or
doctor `family` fields. Machine-readable issue and warning codes use singular
product prefixes, such as `node.wireguard_peer_missing`,
`app.fpm_config_missing`, `workspace.path_missing`, `process.runtime_unit_missing`,
`proxy_route.route_extra`, and `schedule.unit_extra`.

Warning `family` is `null` only for command-owned warnings that are not doctor
issue codes and do not point at `doctor` as the recovery command. Command-owned
warning codes still use the singular product prefix for the command's domain.

Family issue-code condition names should use the product relationship term for
that family, such as `app.owner_node_invalid`,
`workspace.parent_app_invalid`, or `process.owner_app_invalid`. Do not
normalize these into a generic parent/owner vocabulary when the domain model
uses a more specific relationship name.

Other documentation domains, such as operations, deployments, VPN
administration, PHP runtime, and agent IDE commands, may call or affect state
families without becoming state families themselves.

When converting a command, state both the documentation domain that owns the
user-facing command page and the state family or families whose intent/reality
the command reads, writes, verifies, fixes, or adopts.

### Caller Role Matrix

Commands with node-topology behavior should use the node caller-role vocabulary
from [`1_node/README.md`](1_node/README.md): `control`, `gateway`, `app`, and
`unknown`.

The canonical contract may summarize the caller-role matrix, but role-specific
files own detailed behavior. Do not repeat the local caller-role detection
mechanics in every command. Link to the node family README for the shared
contract and document only the command-specific consequence for each role.

If an authoritative node command already decides a caller-role rule, later node
commands inherit it unless they document a narrower exception.

### Technical Slot Map

When a command uses a `technical/` directory, reserve these slots:

| Slot | Meaning |
| --- | --- |
| `1_command-name.md` | Canonical technical contract. |
| `2_command-name_on-control-node.md` | Control caller behavior, when caller role changes command semantics. |
| `3_command-name_on-gateway-node.md` | Gateway caller behavior, when caller role changes command semantics. |
| `4_command-name_on-app-node.md` | App caller behavior, when caller role changes command semantics. |
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

Command docs do not keep in-repo ambiguity sidecar files. When requested
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

| Mode | Used when | Missing required input |
| --- | --- | --- |
| Interactive input mode | The command is running in a TTY and `--json` is not present. | Prompt before side effects, using the command's prompt mapping. |
| Non-interactive input mode | The command is running without a TTY, or `--json` is present. | Fail before side effects with a clear validation message. |

`--json` always disables prompts and uses non-interactive input mode, even when
the process has a TTY.

### Destructive Confirmation

Commands with `destructive` effects always require explicit destructive consent
before side effects begin.

- `--dry-run` is not a universal destructive-command flag. A destructive command
  may define it only when it has a meaningful discovery or planning phase whose
  result can be returned without side effects. Commands that operate on an
  explicit named target should use confirmation/`--force` unless their contract
  documents a preview-specific reason.
- Interactive input mode asks for confirmation unless `--force` is supplied.
- `--force` is explicit destructive consent and skips the interactive
  confirmation prompt.
- Non-interactive input mode requires `--force` because prompts are
  unavailable.
- `--json` never implies destructive consent. A TTY command with `--json` still
  uses non-interactive input mode, so destructive commands still require
  `--force`.

Interactive input mode re-prompts invalid field-local input until the value is
valid, the answer changes the path so the prompt is no longer needed, or the
user aborts. There is no generic retry cap unless the command contract
documents one. Prompt aborts such as Ctrl-C, EOF, or a primitive-supported
cancel action exit with the standard command failure status and no side
effects.

### Exit Status

Converted command contracts use the shared exit status policy unless a command
explicitly documents a command-specific exception in its canonical technical
contract and maps tests for that exception.

- `0`: success, including success-with-warnings.
- `1`: Orbit-handled command failure, including validation, authorization,
  gateway reachability, domain eligibility, and remote enactment failures.
- `2`: invalid CLI usage before Orbit can apply the command contract, such as
  an unknown option or malformed invocation rejected by the console runtime.

JSON `error.code` is the stable machine-readable classifier for command
failures. Do not create per-domain numeric exit classes such as "validation
error = 2" or "remote enactment failure = 3" in command docs.

### Invocation Matrix

| Invocation | Input mode | Output renderer |
| --- | --- | --- |
| TTY without `--json` | Interactive | Human |
| TTY with `--json` | Non-interactive | JSON |
| No TTY without `--json` | Non-interactive | Human |
| No TTY with `--json` | Non-interactive | JSON |

### Output Renderers

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
| `caller_role_not_allowed` | The caller role is not permitted to invoke the command path. Use `error.meta.caller_role`. |
| `authorization_failed` | The caller role may invoke the command, but the authenticated identity is not authorized for the resolved target. |
| `gateway_unavailable` | A configured non-gateway caller cannot reach the gateway API required for the command. |

Do not introduce new synonyms such as `missing_input`, `missing_argument`,
`validation.missing_input`, `unauthorized`, or `auth.unauthorized_role` in new or
touched contracts.

Fields that are structural members of an entity object are serialized as `null`
when they are inapplicable for the returned entity. Purely optional metadata
fields are omitted from the payload when absent and are not serialized as
`null`.
When a structural field applies but the command cannot determine its value yet,
use a command-documented sentinel such as `unknown` instead of omitting the
field.

### Contract Boundaries

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
- Family doctor contracts own probe layers, drift kinds, fix behavior, adopt
  behavior, and doctor test mapping. Command-level doctor sections link to the
  family doctor contract and describe only command-created drift or repair
  relationships.
- Failure semantics describe command/domain failures after input resolution:
  invalid combinations, authorization failures, network failures, remote
  execution failures, partial provisioning, drift, and exit codes.
- Missing required input is input-mode behavior, not command-domain failure
  semantics.

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

`write` is an umbrella effect. The canonical behavior contract must state
whether the command writes gateway intent, local caller settings, node-runtime
reality, or a combination. Effects are for observable behavior, not permission
scope.

**Prerequisites:**
State that must already exist before command input resolution or side effects
begin.

## Signature

Canonical signature. Required inputs should still be optional at the shell
signature level when interactive input mode can prompt for them.

## Input Contract

Arguments and options with required conditions, forbidden conditions, defaults,
and validation rules.

## Caller Role Behavior

Behavior when invoked from a control node, gateway node, or app node.

## Input Resolution

Ordered, mode-neutral resolution of fields and validation. Reference prompt
IDs instead of repeating terminal prompt details.

If path eligibility depends on resolved command input, split prerequisites into
pre-input caller eligibility and post-input path eligibility. Apply each
post-input path eligibility rule as soon as the fields needed for that rule are
known, and always before side effects. Do not keep prompting after a blocker is
already knowable. In interactive input mode, correctable blockers should show a
validation message at the current corrective prompt so the user can change
course or cancel.

## Input Mode Contracts

Links to interactive and non-interactive input mode contracts, when they are split into
separate files.

## Behavior Contract

What the command does, what it does not do, what state it reads or writes, and
what node artifacts it enacts.

## Renderer Contracts

Links to human and JSON renderer contracts, when they are split into separate
files.

## Failure Semantics

Validation failures, remote execution failures, partial-success behavior, and
exit codes.

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
| `tests/E2E/Read/` | Real-node read-only smoke tests against an existing configured topology. Must not mutate fleet state. |
| `tests/E2E/Ephemeral/` | Real-node mutation tests that create, alter, or destroy disposable hosts, identities, or state. These require explicit ephemeral infrastructure configuration from [`TESTING.md`](../../TESTING.md). |

E2E tests prove the contract works through real process, network, and node
boundaries. They should remain smoke-level and must not replace the primary
in-memory contract owner listed in each command's test mapping.

## Domains

Domains are ordered by dependency: nodes define fleet membership, gateway
defines control-plane authority and trust, tools and firewall rules establish
node capabilities and network policy, and apps and app-owned runtime behavior
build on top of that foundation.

1. [Nodes](1_node/README.md)
2. [Gateway](2_gateway/README.md)
3. [Tools](3_tool/README.md)
4. [Firewall](4_firewall/README.md)
5. [Apps](5_app/README.md)
6. [Workspaces](6_workspace/README.md)
7. [Processes](7_process/README.md)
8. [Proxy](8_proxy/README.md)
9. Schedules (not yet converted)
10. Deployments (not yet converted)
11. [Operations](11_operation/README.md)
12. Cloudflare (not yet converted)
13. VPN Administration (not yet converted)
14. PHP Runtime (not yet converted)
15. Agent IDE (not yet converted)
16. [DNS](16_dns/README.md)
