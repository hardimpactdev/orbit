# Technical Contract: `orbit dns:resolve-tld [tld] [target]`

[Back to public `dns:resolve-tld` documentation.](../dns-resolve-tld.md)

**Owner:** `dns`.

**Effects:** `read`, `write`, `destructive`, `local-only`, `stream`.

**Prerequisites:**
- The local caller role can be resolved as `control`.
- The caller platform has an Orbit-supported local resolver backend.
- The process has the local OS privileges required to update resolver
  configuration and refresh the resolver backend.

**Post-input path eligibility:**
- The reset path requires destructive consent before local resolver files are
  removed.

## Signature

```bash
orbit dns:resolve-tld [tld] [target] [--reset] [--force] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `tld` | `[tld]` | Always. Interactive input mode may prompt when omitted. | Never. | None. | Single lowercase DNS label without a leading dot. |
| `target` | `[target]` | `--reset` is absent. Interactive input mode may prompt when omitted. | `--reset` is present. | None. | IPv4 or IPv6 address. |
| `reset` | `--reset` | Optional. | Never. | `false`. | Selects the local resolver removal path. |
| `force` | `--force` | Required for `--reset` in non-interactive input mode. | `--reset` is absent. | `false`. | Destructive consent for reset. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Caller Role Behavior

`dns:resolve-tld` resolves the caller role from the local node role setting
before it reads command inputs or renders prompts.

| Caller role | Behavior |
| --- | --- |
| `control` | Allowed. Mutates only caller-local resolver state. |
| `gateway` | Invalid. Gateway development DNS mappings are gateway-owned node readiness, not local control-node resolver overrides. |
| `app` | Invalid. App nodes receive gateway-managed runtime and DNS artifacts; this command does not create an app-node write exception. |
| `unknown` | Invalid local context. Fail before prompts, input validation, or side effects. |

## Input Resolution

1. Resolve caller role.
   - If the local role setting is unset or `null`, resolve caller role as
     `control`.
   - If caller role is `gateway` or `app`, fail before prompts or side effects.
   - If the local role setting contains an unsupported value or cannot be read,
     fail before prompts or side effects.
2. Resolve `dns_resolve_tld.tld` from `[tld]` or the selected input mode.
3. Resolve the selected path.
   - If `--reset` is absent, resolve `dns_resolve_tld.target`.
   - If `--reset` is present, reject any supplied `target`.
4. Validate field-local input.
5. For reset, resolve destructive consent.
   - Interactive input mode prompts unless `--force` is supplied.
   - Non-interactive input mode requires `--force`.
6. Select the output renderer and start local resolver work.

## Input Mode Contracts

- [Interactive input mode](5.1_dns-resolve-tld_input-mode_interactive.md)
- [Non-interactive input mode](5.2_dns-resolve-tld_input-mode_non-interactive.md)

## Behavior Contract

### Local Resolver Rules

- Write only Orbit-managed caller-local resolver configuration for the selected
  TLD.
- Resolve path: configure `*.{tld}` to resolve to the supplied IP address
  through the platform resolver backend.
- Reset path: remove only the Orbit-managed local resolver override for the
  selected TLD.
- Use stable Orbit-managed file labels or config blocks so repeated runs
  converge the same local mapping.
- Return success when the requested mapping already exists or the requested
  reset is already absent.
- Refresh or restart the local resolver backend only when the platform requires
  it for the change to take effect.

### Development DNS Boundary Rules

- `dns:resolve-tld` is not the source of truth for app-node development TLDs.
- Gateway-owned development DNS mappings for `*.nodes.tld` are created by
  `node:new --role=app --environment=development` and repaired by
  `doctor --fix --family=node --restore`.
- This command must not write gateway intent, node records, app routes, proxy
  routes, Cloudflare records, or public DNS.

### Destructive Reset Rules

- `--reset` removes local resolver state and is a destructive path.
- Interactive reset requires an explicit confirmation prompt unless `--force`
  is supplied.
- Non-interactive reset requires `--force`.
- `--json` forces non-interactive input mode but does not imply destructive
  consent.

### Scope Boundaries

`dns:resolve-tld` must not:
- Mutate gateway intent or node reality.
- Inspect or repair gateway-owned development DNS mappings.
- Create arbitrary per-host DNS mappings.
- Query or mutate Cloudflare or public DNS.
- Bypass platform-specific privilege checks.

## Renderer Contracts

- [Human renderer](6.1_dns-resolve-tld_output-render_human.md)
- [JSON renderer](6.2_dns-resolve-tld_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Caller role not allowed | Invoked from a gateway or app caller. | Failure before prompts or side effects |
| Local context invalid | The local node role setting is unreadable or unsupported. | Failure before prompts or side effects |
| Validation failed | Required input is missing, forbidden input is supplied, or a field value is malformed. | Failure before side effects |
| Destructive consent missing | `--reset` is selected in non-interactive mode without `--force`, or the interactive confirmation is rejected. | Failure before side effects |
| Unsupported platform | The caller platform has no supported local resolver backend. | Failure before resolver writes |
| Resolver write failed | Orbit-managed resolver configuration cannot be written or removed. | Failure |
| Resolver refresh failed | Configuration was written but the resolver backend could not be refreshed. | Failure with local file side effect already applied |

## Doctor Relationship

- `dns:resolve-tld` manages caller-local resolver overrides only.
- `doctor --family=node --self` verifies node-family development TLD readiness
  and gateway-owned development DNS mappings. It is not a substitute for
  listing local resolver overrides.

## Activity Logging

The local CLI command emits an activity entry for successful and failed local
resolver mutation attempts. Activity logging is best-effort and must not
change the documented command result.

| Field | Value |
| --- | --- |
| Type | `dns:resolve-tld` |
| Effect | `write` for resolve path; `destructive` for reset path. |
| Subject | `none`; the command mutates caller-local resolver files and does not own a durable DNS entity. |
| Properties | `tld`, `target` for resolve path, `action` (`resolve` or `reset`), `status`, `changed`, and `resolver_backend` when known. No resolver file contents, process output, public DNS responses, gateway records, or secrets. |
| Description | derived |

## Test Mapping

Required split contract tests:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Dns/DnsResolveTldCommandTest.php` | Command contract: caller-role eligibility, field validation, local resolver write behavior, reset behavior, idempotent convergence, unsupported-platform failure, no gateway intent writes, no public DNS writes, and no arbitrary per-host mappings. |
| `tests/Feature/Commands/Dns/DnsResolveTldInteractiveInputModeTest.php` | Interactive input mode: TTY selection, `--json` opt-out, prompt order, prompt IDs, labels, primitives, field validation, reset confirmation, `--force` confirmation bypass, and prompt abort behavior. |
| `tests/Feature/Commands/Dns/DnsResolveTldNonInteractiveInputModeTest.php` | Non-interactive input mode: no-prompt selection, `--json` forcing non-interactive mode, missing input failures, forbidden target with `--reset`, `--reset` requiring `--force`, and invalid value failures. |
| `tests/Feature/Commands/Dns/DnsResolveTldJsonRendererTest.php` | JSON renderer selection, success envelope, resolved/reset/already-converged statuses, every `error.code` value, error metadata, and `--json` forcing non-interactive mode. |
| `tests/Feature/Commands/Dns/DnsResolveTldHumanRendererTest.php` | Human renderer progress trees, resolved success prose, already-resolved prose, reset prose, already-absent prose, validation failure prose, unsupported-platform prose, resolver failure prose, and absence of JSON envelopes in human mode. |
| `tests/E2E/Ephemeral/DnsResolveTldTest.php` | Real local resolver configuration and reset against an ephemeral supported control-node platform. |
