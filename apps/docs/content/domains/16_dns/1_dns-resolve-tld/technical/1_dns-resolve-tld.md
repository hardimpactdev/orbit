# Technical Contract: `orbit dns:resolve-tld [tld] [target]`

[Back to public `dns:resolve-tld` documentation.](../dns-resolve-tld.md)

**Owner:** `dns`.

**Effects:** `read`, `write`, `destructive`, `local-only`, `stream`.

**Prerequisites:**
- The command is running on a non-gateway client/control machine.
- The caller platform has a local resolver backend that Orbit supports.
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

## Input Resolution

1. Resolve `dns_resolve_tld.tld` from `[tld]` or the selected input mode.
2. Resolve the selected path.
   - If `--reset` is absent, resolve `dns_resolve_tld.target`.
   - If `--reset` is present, reject any supplied `target`.
3. Validate field-local input.
4. For reset, resolve destructive consent.
   - Interactive input mode prompts unless `--force` is supplied.
   - Non-interactive input mode requires `--force`.
5. Select the output renderer and start local resolver work.

## Input Mode Contracts

- [Interactive input mode](5.1_dns-resolve-tld_input-mode_interactive.md)
- [Non-interactive input mode](5.2_dns-resolve-tld_input-mode_non-interactive.md)

## Behavior Contract

### Local Resolver Rules

- Write only the resolver configuration that Orbit manages on the caller machine for the selected TLD.
- Resolve path: configure `*.{tld}` to resolve to the supplied IP address
  through the platform resolver backend.
- Reset path: remove only the local resolver override that Orbit manages for the selected TLD.
- Use stable Orbit-managed file labels or config blocks so repeated runs
  converge the same local mapping.
- Return success when the requested mapping already exists or the requested
  reset is already absent.
- Refresh or restart the local resolver backend only when the platform requires
  it for the change to take effect.

### Development DNS Boundary Rules

- `dns:resolve-tld` is not the source of truth for app-role development TLDs.
- Development DNS mappings owned by the gateway for `*.nodes.tld` are created by
  `node:new --role=app-dev` and repaired by
  `doctor --family=node --restore`.
- This command must not write gateway configuration, node records, app routes, proxy
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
- Mutate gateway configuration or node reality.
- Inspect or repair the development DNS mappings that the gateway owns.
- Create arbitrary per-host DNS mappings.
- Query or mutate Cloudflare or public DNS.
- Bypass platform-specific privilege checks.

## Renderer Contracts

- [Human renderer](6.1_dns-resolve-tld_output-render_human.md)
- [JSON renderer](6.2_dns-resolve-tld_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Not supported on gateway | The command is run on a gateway node. | Failure before prompts or side effects |
| Destructive consent missing | `--reset` is selected in non-interactive mode without `--force`, or the interactive confirmation is rejected. | Failure before side effects |
| Unsupported platform | The caller platform has no supported local resolver backend. | Failure before resolver writes |
| Resolver write failed | Orbit-managed resolver configuration cannot be written or removed. | Failure |
| Resolver refresh failed | Configuration was written but the resolver backend could not be refreshed. | Failure with local file side effect already applied |

## Doctor Relationship

- `dns:resolve-tld` manages only the resolver overrides on the caller machine.
- `doctor --family=node --self` verifies development TLD readiness for the node family
  and the DNS mappings the gateway owns. It is not a substitute for
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
| `apps/gateway/tests/Feature/Commands/Dns/DnsResolveTldCommandTest.php` | Command contract: gateway-node rejection, field validation, local resolver write behavior, reset behavior, idempotent convergence, unsupported-platform failure, no gateway configuration writes, no public DNS writes, and no arbitrary per-host mappings. |
| `apps/gateway/tests/Feature/Commands/Dns/DnsResolveTldInteractiveInputModeTest.php` | Interactive input mode: TTY selection, `--json` opt-out, prompt order, prompt IDs, labels, primitives, field validation, reset confirmation, `--force` confirmation bypass, and prompt abort behavior. |
| `apps/gateway/tests/Feature/Commands/Dns/DnsResolveTldNonInteractiveInputModeTest.php` | Non-interactive input mode: no-prompt selection, `--json` forcing non-interactive mode, missing input failures, forbidden target with `--reset`, `--reset` requiring `--force`, and invalid value failures. |
| `apps/gateway/tests/Feature/Commands/Dns/DnsResolveTldJsonRendererTest.php` | JSON renderer selection, success envelope, resolved/reset/already-converged statuses, every `error.code` value, error metadata, and `--json` forcing non-interactive mode. |
| `apps/gateway/tests/Feature/Commands/Dns/DnsResolveTldHumanRendererTest.php` | Human renderer progress trees, resolved success prose, already-resolved prose, reset prose, already-absent prose, validation failure prose, unsupported-platform prose, resolver failure prose, and absence of JSON envelopes in human mode. |
| `apps/gateway/tests/E2E/Ephemeral/DnsResolveTldTest.php` | Real local resolver configuration and reset against an ephemeral supported client platform. |
