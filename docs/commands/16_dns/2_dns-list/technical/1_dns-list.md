# Technical Contract: `orbit dns:list`

[Back to public `dns:list` documentation.](../dns-list.md)

**Owner:** `dns`.

**Effects:** `read`, `local-only`.

**Prerequisites:**
- The caller is authorized as a `control` role peer.
- The caller platform is Linux or macOS.

## Signature

```bash
orbit dns:list [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. Select the output renderer.
2. Read the local resolver state that Orbit manages.

No input-mode-specific contracts are required. The command has no prompts or
required arguments.

## Behavior Contract

### Local Resolver Read Rules

- Read only the resolver overrides that Orbit manages on the caller machine.
- Linux and macOS callers are supported for read-only inspection.
- Return an empty successful result when no resolver overrides managed by Orbit exist.
- Include the TLD, target IP address, source, resolver backend, and status for
  each entry when available.
- Report resolver backend status only from local configuration or local backend
  checks; do not query gateway or app nodes.

### Scope Boundaries

`dns:list` must not:
- Mutate local resolver configuration.
- Query or mutate gateway configuration, node records, app routes, proxy routes,
  Cloudflare records, or public DNS.
- Inspect the development DNS mappings that the gateway owns.
- Repair local resolver drift.

## Renderer Contracts

- [Human renderer](6.1_dns-list_output-render_human.md)
- [JSON renderer](6.2_dns-list_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Unsupported platform | The caller platform is neither Linux nor macOS. | Failure before local resolver reads |
| Resolver read failed | Orbit-managed local resolver state cannot be inspected. | Failure |

No local DNS overrides is success with an empty result.

## Doctor Relationship

- `dns:list` reads caller-local resolver overrides.
- `doctor --family=node --self` verifies development TLD readiness for the node family
  and the DNS mappings that the gateway owns. It is not a local DNS listing
  command.

## Activity Logging

The local CLI command emits an activity entry for successful and failed local
resolver list attempts. Activity logging is best-effort and must not change
the documented command result.

| Field | Value |
| --- | --- |
| Type | `dns:list` |
| Effect | `read` |
| Subject | `none`; the command reads caller-local resolver files and does not own a durable DNS entity. |
| Properties | `count` and `resolver_backend` when local resolver state is read. No resolver file contents, public DNS responses, gateway records, or secrets. |
| Description | derived |

## Test Mapping

Required split contract tests:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Dns/DnsListCommandTest.php` | Command contract: caller-role eligibility, Linux and macOS local resolver read behavior, empty result success, unsupported-platform failure, resolver read failure, read-only guarantee, no gateway configuration reads, and no public DNS reads. |
| `tests/Feature/Commands/Dns/DnsListJsonRendererTest.php` | JSON renderer selection, success envelope, empty result shape, resolver entry DTO shape, every `error.code` value, and `--json` forcing non-interactive mode. |
| `tests/Feature/Commands/Dns/DnsListHumanRendererTest.php` | Human renderer local DNS summary, empty result prose, no-progress-tree behavior, caller-role denial prose, unsupported-platform prose, resolver read failure prose, and absence of JSON envelopes in human mode. |
| `tests/E2E/DnsListTest.php` | Incus-backed Linux operator-node feature gate: install the current checkout into a disposable control VM, seed an Orbit-managed local resolver override, and verify `php artisan dns:list --json` reports it. |
