# Command Documentation

Use this reference when creating, converting, or reviewing Orbit command docs.
The goal is authoritative, implementation-agnostic command documentation that
future implementation and tests can follow without guessing.

## Directory Shape

Use the [`node:new`](../../../../docs/domains/1_node/1_node-new/) shape for
commands with non-trivial behavior:

```text
docs/domains/N_family-singular/
├── README.md
├── N_command-name/
│   ├── command-name.md
│   └── technical/
│       ├── 1_command-name.md
│       ├── 2_command-name_on-client.md
│       ├── 3_command-name_on-gateway-node.md
│       ├── 4_command-name_<topology-or-capability>.md
│       ├── 5.1_command-name_input-mode_interactive.md
│       ├── 5.2_command-name_input-mode_non-interactive.md
│       ├── 6.1_command-name_output-render_human.md
│       └── 6.2_command-name_output-render_json.md
└── <family-singular>-doctor.md
```

Converted command families do not use flat numbered command files. Every public
command lives in a numbered command directory with at least a public command
page, canonical technical contract, and renderer contracts. Add input-mode,
execution-location, topology, capability, destructive-consent, cross-node, or
E2E companion files when those contracts need separate ownership. Location
files describe transport or locality differences; they do not grant or deny
authorization by node role.

Command names use the longest command prefix before the colon. For compound
command groups, keep the compound prefix intact and put only the action after
the colon: `workspace-setup-step:add`, `workspace-teardown-step:add`,
`cf-dns:list`, `cf-cache-rule:add`, `vpn-client:list`, and
`vpn-web-ui:change-password`. Do not rewrite these as
`workspace:setup-step-add`, `cf:dns-list`, or `vpn:client-list`.

Run `composer docs-lint` after changing converted command documentation. The
docs linter is the executable structure contract for converted command docs; if
the command documentation structure changes, update the linter first and then
migrate docs until the linter passes.

## Authority Precedence During Conversion

When converting later commands in a family, do not ask the user to re-decide a
question that an already-authoritative command contract in that family has
settled. Copy the established rule unless the later command has a documented
reason to differ.

Examples:

- `node:new` is the authoritative shape for node command structure until a newer
  node command contract explicitly supersedes it.
- If `node:new` says a gateway-known peer needs `node:new` on its gateway grant,
  later node commands inherit the identity-and-grants model unless their
  contract names one of the architecture's authorization exception classes.
- If an app-domain contract says an app-node self-grant contains `app:read` but
  not `app:write`, later app commands inherit those scoped permissions. Local
  path context can resolve a default target, but it must not be described as
  authorization for neighboring commands.
- If an established family doctor file owns concrete issue codes and action
  maps, command files link to it instead of redefining that behavior.

Track unresolved questions outside the project. Do not encode guesses into
authoritative docs; once a decision is made, update the contract itself so the
product behavior is not hidden in sidecar notes.

## Technical Slot Map

| Slot | Meaning |
| --- | --- |
| `1_command-name.md` | Canonical technical contract. |
| `2_command-name_on-client.md` | Typed gateway-client behavior when a separate location contract is useful. |
| `3_command-name_on-gateway-node.md` | Gateway-location transport and gateway implicit-authority behavior when it differs. |
| `4_command-name_<topology-or-capability>.md` | Optional target topology or capability behavior. This slot never defines authorization. |
| `5.1_command-name_input-mode_interactive.md` | Interactive input-mode contract. |
| `5.2_command-name_input-mode_non-interactive.md` | Non-interactive input-mode contract. |
| `6.1_command-name_output-render_human.md` | Human output renderer contract. |
| `6.2_command-name_output-render_json.md` | JSON output renderer contract. |
| `07+` | Command-specific companion contracts, only when needed. |

Skip unused slots only when that behavior does not exist for the command. Do not
reuse a reserved slot for a different concern.

## Ownership Boundaries

- `command-name.md` is the public operator page: purpose, usage, examples,
  plain-language behavior, output summary, requirements, related commands, and a
  link to the canonical technical contract when the command uses a directory.
- `technical/1_command-name.md` is the canonical technical contract: signature,
  input contract, mode-neutral input resolution, behavior, failure semantics,
  doctor relationship, high-level test mapping, and a link back to the public
  command page.
- The canonical input contract owns which fields exist, when they are required
  or forbidden, defaults, and value validation.
- Input-mode files own how those rules are collected, prompted, retried, or
  failed for a concrete invocation mode.
- Location/topology files such as `2_command-name_on-client.md`,
  `3_command-name_on-gateway-node.md`, and command-specific slot-4 companions
  own transport, requested target role, platform, or topology differences. The
  canonical contract still owns peer identity and authorization.
- Companion technical files inherit `Owner` and `Effects` from
  `technical/1_command-name.md` unless they explicitly override them. Do not
  repeat identical `Owner` or `Effects` stanzas.
- `5.1_command-name_input-mode_interactive.md` owns prompts, prompt IDs,
  primitives, prompt validation, and retry behavior.
- `5.2_command-name_input-mode_non-interactive.md` owns no-prompt argument
  resolution, missing-input failures, and `--json` forcing non-interactive input
  mode.
- `6.1_command-name_output-render_human.md` owns progress trees, exact
  human-rendered strings, prose errors, summaries, and next steps.
- `6.2_command-name_output-render_json.md` owns JSON envelopes, data shapes,
  error codes, error messages, error metadata, and JSON examples. Link the
  shared JSON envelope contract instead of repeating generic `success`/`error`
  envelope prose in every renderer file.
- `<family-singular>-doctor.md` owns family-level probe facts, concrete issue
  codes, fix/adopt action maps, and test mapping for doctor behavior. Match the
  family-specific command signature, such as `node-doctor.md` for
  `doctor --family=node`. Command files link to it instead of duplicating the
  probe contract.

## Domains And State Families

Command directories are documentation domains. State families are doctor and
convergence families. They often align, but they are not the same concept.

Stable state families are `node`, `app`, `workspace`, `process`,
`proxy`, `firewall_rule`, `tool`, and `schedule`. These are the keys
accepted by `doctor --family=<family>` and the values carried by warning or
doctor `family` fields. Machine-readable issue and warning codes use singular
product prefixes, such as `node.wireguard_peer_missing`,
`app.fpm_config_missing`, `workspace.path_missing`, `process.runtime_unit_missing`,
`proxy.route_extra`, and `schedule.unit_extra`.

Documentation domains may also contain command groups that are not state
families, such as operations, deployments, VPN administration, PHP runtime, and
agent IDE commands. Those domains may call or affect state families, but they
must not invent new doctor family names unless the blueprint defines a product
family for them.

Tool-specific and capability-specific command families are explicitly admitted
product surfaces, not generated from tool catalog slugs. Generic lifecycle,
inventory, logs, credentials, update, reload, and reconfiguration stay under
`tool:*`. A separate family is valid only when it owns a distinct Orbit
workflow whose natural operator vocabulary is the tool or capability name:
`php:*` for PHP runtime selection, future `redis:*` for Redis-native
data-plane operations, and future `db:*` for database backup and restore
drivers. Do not create `mysql:*`, `postgres:*`, or other per-tool families just
because a catalog entry exists.

When converting a command, state both:

- the command documentation domain that owns the user-facing command page; and
- the state family or families whose intent/reality the command reads, writes,
  verifies, fixes, or adopts.

If the distinction is already established by an authoritative command in the
same domain, copy that wording instead of creating a fresh local formulation.

## Peer Identity And Authorization

Every gateway-backed command authenticates the caller through its WireGuard
peer identity. The canonical contract names the target and required permission;
the gateway decides access from its stored authorization state.

The default authorization class is a grant edge from the consuming peer to the
serving node plus the scoped permission required by the command. The only
alternatives are the architecture's four named exception classes:

- gateway implicit authority;
- pre-grants bootstrap;
- local-only;
- identity-gated self-management.

Node roles may determine target capability, placement, or execution transport,
but they are not generic caller eligibility. A workload node calling a command
against itself uses its explicit self-grant. Local path or project context may
resolve a target default, but it never authorizes that target.

Document authorization denial in the canonical and renderer contracts with
`authorization_failed`. When the required permission is known, include
`error.meta.reason=missing_permission`,
`error.meta.missing_permission=<permission>`, and the stable serving-node or
target identifier.

## Effects

Use `**Effects:**` for observable command behavior, not permission scopes.

| Effect | Meaning |
| --- | --- |
| `read` | Reads gateway intent, local settings, or runtime facts without mutating them. |
| `write` | Mutates durable Orbit intent, local settings, or node reality. The behavior contract must say which category applies: gateway intent write, local settings write, node-runtime write, or a combination. |
| `stream` | Emits long-running progress, logs, or event streams. |
| `destructive` | Deletes, resets, revokes, prunes, or overwrites state in a way that requires explicit operator consent through the shared destructive-confirmation model. |
| `internal` | Internal command surface, not a primary user-facing command. |
| `local-only` | Mutates or reads only local caller-machine state. |
| `gateway-admin` | Administers gateway-local infrastructure or gateway-owned administrative policy. |
| `none` | Companion-contract path has no effects because authorization or target eligibility rejects it before side effects. |

`write` is intentionally an umbrella effect for high-level scanning. Do not rely
on it alone to infer authorization or persistence behavior. The command's
behavior contract owns the precise write boundary.

Orbit node access is a binary grant between a consuming node and a serving node.
If a caller is not granted to a node, it cannot read that node or operate on it.

## Prerequisites And Path Eligibility

Command contract files must include `**Prerequisites:**` between `**Effects:**`
and behavior sections. Prerequisites describe state that must already exist
before command input resolution or side effects begin: network reachability,
WireGuard peer identity, authorization class, platform support, local trust, or
target availability.

When prerequisites depend on resolved command input, split them:

- `**Prerequisites:**` for facts known before target resolution, such as gateway
  reachability, peer identity, local-only execution context, and access to
  prompt or argument resolution.
- `**Post-input path eligibility:**` for checks that require resolved fields,
  such as required grant permission, requested target role, selected node, host,
  environment, destructive consent, or other target-specific eligibility.

Apply post-input path eligibility as soon as the needed fields are known and
always before side effects. In interactive input mode, show the validation
message as soon as the blocker is knowable, then let the user change course or
cancel. Do not ask for unrelated later input after a known blocker.

## Enactment Verification Vs Readiness

Commands that create, register, or re-apply intent may verify that the
command-owned enactment completed: gateway writes, SSH uploads, generated
artifacts, process reloads, and family handoffs. Do not present that as
application health or runtime readiness unless the command explicitly owns a live
readiness contract.

For apps, `app:new` and `app:register` verify command-owned enactment, not
application HTTP readiness. A newly created or adopted app may still need
project setup steps before it is healthy. Durable runtime health, production
health checks, and readiness drift belong to `doctor --family=app`.

Avoid generic fields such as `app.status: "ready"` on create/register results
unless the field is durable gateway intent and its meaning is documented. Prefer
command-outcome fields such as `result.action` or warning handoffs under
`success.meta.warnings[]`.

## Human Renderer Requirement

Every human output renderer file must include `## Progress Tree`.

- If the command can take longer than one second in normal, remote, destructive,
  networked, or degraded execution, define the in-progress tree rendered after
  input resolution and before side effects begin.
- If no in-progress tree is rendered, explicitly state that execution is
  expected to stay below one second and performs no slow external work.

Do not document progress as optional for long-running interactive commands. The
human renderer either defines the progress tree or explains why no tree is
needed for a fast local/read path.

Detailed tree rendering rules live in
[`terminal-output.md`](terminal-output.md).

## Test Mapping

Each technical file maps the tests for the behavior it owns. Do not put all
tests in the canonical contract when split files own specialized behavior.

Mapped tests must be implementation-agnostic: assert observable inputs, outputs,
side-effect boundaries, persisted contract state, and externally visible calls.
Do not tie contract tests to internal service classes, private methods, handler
names, or temporary implementation structure. A secondary test may assert how
another owner's behavior is rendered or wrapped, but it must not claim ownership
of that behavior.

If a required test file is missing, list it anyway. The shared command
documentation contract already requires creating that file before changing the
behavior it owns, so individual command files must not repeat that instruction.

## External Decision Tracking

Do not create in-repo ambiguity sidecar files. If conversion or review finds a
command-specific ambiguity, conflict, duplication, or missing product decision,
track that question outside the project. Once the user decides, update the
authoritative command docs directly so future implementation and tests can
follow the contract without reading decision-history notes.

## Doctor Convergence Commands

`orbit doctor` is Orbit's convergence command. Public family filters must use
product family keys: `node`, `app`, `workspace`, `process`,
`proxy`, `firewall_rule`, `tool`, and `schedule`.

Use `--self` for the caller's gateway-known node identity. Use `--node=<name>`
for an explicit target node; `--self` and `--node` are mutually exclusive.

Use `--adopt` for disaster recovery or fleet adoption flows that pull observed
node reality into gateway-tracked intent. Remote doctor runs use the typed
`POST /api/doctor/run` endpoint and return the stable doctor envelope: `mode`,
`healthy`, `scope`, `summary`, `issues`, and `actions`.

The generic `doctor` command contract owns mode semantics, scope resolution,
orchestration, exit codes, human/JSON output envelopes, and generic issue
kinds. Family doctor contracts, such as `node-doctor.md`, own probe facts,
concrete issue codes, fix/adopt action maps, and family test mapping.

## File Numbering

When one command has multiple technical contract files, keep them in numbered
order inside the command's `technical/` directory. Technical file prefixes are
slot numbers that are unique within that directory: `1_`, optional `2_` through
`4_` location/topology companions, `5.1_`, `5.2_`, `6.1_`, `6.2_`, and so on.
Do not reuse the parent command directory ordinal for technical files. Update
every internal link when adding or renaming numbered technical files.
