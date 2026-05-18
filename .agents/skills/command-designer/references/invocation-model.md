# Invocation Model

Use this reference when designing command input modes, prompt behavior,
`--json`, JSON envelopes, failure metadata, or destructive confirmation rules.

## Input Modes And Output Renderers

Every command has input modes and output renderers. Side effects start only
after the selected input mode has resolved and validated required fields, but
field validation and path eligibility run incrementally as soon as their needed
fields are known.

| Invocation | Input mode | Output renderer |
| --- | --- | --- |
| TTY without `--json` | Interactive | Human |
| TTY with `--json` | Non-interactive | JSON |
| No TTY without `--json` | Non-interactive | Human |
| No TTY with `--json` | Non-interactive | JSON |

Input modes:

| Mode | Used when | Missing required input |
| --- | --- | --- |
| Interactive input mode | TTY and `--json` is not present. | Prompt before side effects. |
| Non-interactive input mode | No TTY, or `--json` is present. | Fail before side effects with a validation message. |

Output renderers:

| Renderer | Used when | Contract |
| --- | --- | --- |
| Human renderer | Default CLI output. | Progress trees, prose errors, summaries, and next steps. |
| JSON renderer | `--json` is present. | Stable discriminated JSON envelope with either `success` or `error` as the only top-level key. |

`--json` is an output renderer selection that also disables terminal prompts and
forces non-interactive input mode, even when the process has a TTY.

## JSON Envelope

Every JSON response has exactly one top-level key: `success` or `error`.
Long-lived stream commands are the exception because they emit multiple frames
rather than one response; each stream command must document its frame shape,
terminal error behavior, and whether failures before the stream opens still use
the standard envelope.

- `success.data` contains the command-specific payload. Use an empty object only
  when a successful JSON response intentionally has no structured result.
- `success.meta` is optional machine-readable execution context that is not the
  payload, such as selected scope, pagination, warnings, or resolved entity
  references.
- `error.code` is required on every failure and must be stable enough for tests,
  scripts, agents, and future API adapters.
- `error.message` is required on every failure and contains human-readable
  prose. Automation must not parse it.
- `error.meta` is required on every failure and contains machine-readable
  failure context. Use an empty object only when no stable context exists.
- `error.data` is allowed only when the command contract explicitly documents a
  diagnostic or partial-result payload, such as `doctor` issues after drift was
  found.
- `error.meta` replaces loose failure details. Use it only for stable facts
  automation needs to classify or recover from a failure, such as `field`,
  `reason`, `step`, `exit_code`, `conflicts`, or `partial_state`.

List JSON payloads should use a plural array key for the listed resources, such
as `success.data.apps` or `success.data.processes`. Include
`success.data.context` only when the command must resolve a single execution
scope before listing, such as app/workspace context for `process:list`. Do not
add a `context` object merely to echo optional filters on fleet or registry
queries; stable filter echoes belong in `success.meta` when the command contract
needs them.

## Exit Status

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

## Shared Error-Code Vocabulary

Use the shared error codes below unless a command needs a more specific
domain-owned code:

| Code | Use for | Required metadata |
| --- | --- | --- |
| `validation_failed` | Missing required input, malformed input, unsupported scalar values, and static validation failures. | `error.meta.field` when one field caused the failure. |
| `caller_role_not_allowed` | The local caller role is not permitted to invoke this command path. | `error.meta.caller_role`. |
| `authorization_failed` | The caller role may invoke the command, but the authenticated identity is not authorized for the resolved target. | Target identifiers when stable, such as `app`, `node`, or `target`. |
| `gateway_unavailable` | A configured non-gateway caller cannot reach the gateway API required for the command. | Stable connection context when available. |

Use `caller_role_not_allowed` only for the local caller role being invalid for
the command path before command input is resolved. Use a domain-specific target
eligibility code, such as `app.ineligible_node`, when the caller may run the
command but the resolved target cannot satisfy the command's domain rules.

Do not invent synonyms such as `missing_input`, `missing_argument`,
`validation.missing_input`, `unauthorized`, `auth.unauthorized_role`, or
`consent_required` in new or touched command contracts. Domain-specific codes
such as `app.path_collision` or `node.wireguard_peer_missing` are still
appropriate when they describe a specific product condition. Product-domain
codes use dotted singular `<domain>.<condition>` form, such as
`app.not_found`, `node.wireguard_peer_missing`, or
`workspace.path_missing`, rather than snake-case domain prefixes or plural
family prefixes. Doctor family selector keys and warning `family` values are
singular (`node`, `app`, `workspace`, `process`, `proxy`,
`firewall_rule`, `tool`, `schedule`); the machine-readable `code` beside
that family uses the singular product prefix.

Fields that are structural members of an entity object are serialized as `null`
when they are inapplicable for the returned entity. Purely optional metadata
fields are omitted when absent. When a structural field applies but the command
cannot determine its value yet, use a command-documented sentinel such as
`unknown` instead of omitting the field.

When a command family defines a canonical JSON entity DTO, every renderer in
that family that returns that entity must embed the canonical object unchanged
under the documented key. Command-specific state belongs beside the entity in
another object, not as ad hoc fields on the entity. The app family defines its
canonical `success.data.app` DTO in `docs/domains/5_app/README.md#app-json-entity`.

All commands that return data must support `--json`. `OrbitCommand` already
includes `wantsJson()`, `outputJsonSuccess()`, and `respondWithSuccess()`.
Commands do not need `WithJsonOutput` unless they are decoupled from
`OrbitCommand`.

Use `respondWithSuccess($dto->toArray(), fn () => ...)` for the common case. Use
`respondWithData()` when the command has a `renderHuman()` override and both
local and forwarded execution paths share the same rendering logic.

## Input Resolution

Command signatures should be friendly to interactive input mode. If a command
has required information, make the argument or option optional at the signature
level and let the selected input mode resolve the value before work starts.

- Interactive input mode prompts for every missing required value.
- Non-interactive input mode fails fast with a clear message when a required
  value is missing.
- `--json` never prompts; it uses non-interactive input mode and fails with
  structured error output when required input is missing.
- Do not begin side effects until all required inputs have been resolved and all
  path eligibility known so far has passed.

Use optional positional arguments in docs as `[name]`. In Laravel signatures,
use optional arguments or options and resolve them through the selected input
mode.

Use `CommandInputResolver` for the simple argument-or-prompt-or-fail pattern:
reads from an argument or option, falls back to a prompt callable in interactive
input mode, and throws in non-interactive input mode when absent. Complex
resolution involving database queries, multi-step prompts, or conditional
branching stays inline in the command.

```php
$name = app(CommandInputResolver::class)->requiredString(
    $this,
    'name',
    isArgument: true,
    prompt: fn () => \Laravel\Prompts\text('App name'),
);
```

## Terminal Prompt Mapping

Technical command contracts must describe every terminal prompt with a stable
prompt ID and a Laravel Prompts primitive.

| Primitive | Use for |
| --- | --- |
| `text` | Short free-form strings such as names, hosts, paths, domains, commands, and versions. |
| `textarea` | Multi-line free-form input such as scripts, config snippets, or notes. |
| `password` | Secrets that must not echo in the terminal. |
| `confirm` | Boolean decisions. Use sparingly; prefer explicit options for argument callers. |
| `select` | Exactly one value from a fixed list. |
| `multiselect` | Multiple values from a fixed list. |
| `search` | One value from a dynamic list where typing filters remote or local choices. |
| `suggest` | Free-form text with dynamic suggestions where values outside the suggestion list are allowed. |

Prompt IDs use dot-separated command scope and field names:

```text
node_new.name
node_new.role
app_new.php_version
tool_install.node
```

Each prompt in a technical command contract uses this shape:

```markdown
### `node_new.role`

| Property | Value |
| --- | --- |
| Primitive | `select` |
| Label | `Node role` |
| Source | `--role` |
| Required | yes |
| Choices | `gateway`, `app`, `control` |
| Default | none |
| Validation timing | When `--role` is read or when the prompt is submitted. |
| Invalid terminal behavior | Show validation message and re-prompt. |
```

Validation timing must be explicit. If a field can be validated when submitted,
validate it immediately and re-prompt in terminal mode. Do not defer obvious
field-local validation until later prompts have completed.

Invalid field-local prompt input re-prompts until the value is valid, the value
changes the selected path so the prompt is no longer needed, or the user aborts.
There is no generic retry cap unless the command contract explicitly documents
one. Prompt aborts such as Ctrl-C, EOF, or a primitive-supported cancel action
exit with the standard command failure status and must not start side effects.

## Caller-Role Denial

Caller-role denial is a pre-input prerequisite failure. A command that may only
run from control or gateway nodes must reject app-node callers before prompts,
forwarding, local writes, SSH, WireGuard changes, or other side effects.

The human message and JSON `error.message` must match exactly for the same
failure. Use these canonical caller-role messages unless a command documents a
narrower role-specific reason:

| Allowed caller roles | Message |
| --- | --- |
| `control` | `This command may only be run from a control node.` |
| `control`, `gateway` | `This command may only be run from a control or gateway node.` |
| Any known role, but local role is `unknown` | `The local Orbit caller role could not be resolved.` |

The JSON envelope carries the stable classifier
`error.code=caller_role_not_allowed` and `error.meta.caller_role`.

CLI availability on an app node does not grant write permission. App-node write
exceptions must be explicit in the command contract and should be narrow,
workflow-specific paths rather than inherited permissions for neighboring app or
node commands.

## Destructive Confirmation

Destructive commands such as node removal, WireGuard teardown, gateway reset,
reprovisioning, or cascading unlink operations always require explicit
destructive consent before side effects begin.

- Interactive input mode asks a confirmation prompt unless `--force` is
  supplied.
- `--force` is explicit destructive consent and skips the interactive
  confirmation prompt.
- Non-interactive input mode requires `--force` because prompts are
  unavailable.
- `--json` must never imply destructive consent. A TTY command with `--json`
  still uses non-interactive input mode, so destructive commands still require
  `--force`.
- Automation must pass `--force` explicitly.
- The command contract must document the prompt ID, prompt label, `--force`
  behavior, missing-consent failure, cancellation failure, and tests for both
  prompt consent and `--force` consent.

```php
protected $signature = 'node:remove {name?} {--force}';

public function handle(): int
{
    $name = $this->resolveNodeName();

    if (! $this->isInteractiveInput() && ! $this->option('force')) {
        return $this->failWithMessage('Use --force to remove this node.');
    }

    if (
        $this->isInteractiveInput()
        && ! $this->option('force')
        && ! \Laravel\Prompts\confirm("Remove node {$name}? This cannot be undone.", default: false)
    ) {
        return $this->failWithMessage('Operation cancelled.');
    }

    // proceed
}
```

## Partial Enactment

When a command writes intent but cannot finish enactment, return an actionable
failure unless that command contract explicitly documents a success-with-warning
shape. JSON errors should include stable recovery metadata such as
`error.meta.next_command` when there is a known repair command, for example
`doctor --family=node --fix`.

When a command documents success-with-warning after intent is written, warnings
live under `success.meta.warnings[]` with stable `code`, `family`, `message`,
and `next_command` fields. Use family doctor issue codes and a non-null
`family` for repairable drift instead of inventing command-local drift
vocabulary. Use `family: null` only for command-owned warnings that are not
doctor issue codes, and document those codes in the command renderer.

Use `next_command` only for a single machine-runnable recovery command attached
to a warning or error. Store it as the Orbit subcommand string without the
`orbit` binary prefix, for example `doctor --family=node --fix`; renderers may
prepend `orbit` for human display. A successful command may return
`next_steps[]` only when it is a human onboarding checklist rather than a repair
command contract; those items are prose steps and may include commands inside
the text.
