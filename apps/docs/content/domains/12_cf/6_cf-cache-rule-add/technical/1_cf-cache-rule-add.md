# Technical Contract: `orbit cf-cache-rule:add <project> [--json]`

[Back to public `cf-cache-rule:add` documentation.](../cf-cache-rule-add.md)

**Owner:** `cf`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity has `cf:cache:rule:add` on the gateway.
- The gateway has a Cloudflare API token configured.
- A concrete production instance is resolvable and has an instance-owned domain
  that resolves to a Cloudflare zone.

## Signature

```bash
orbit cf-cache-rule:add <project> [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `project` | Argument `project` | `Always.` | `Never.` | `None.` | Concrete production instance selector: dotted `project.instance`, or bare project shorthand only when exactly one instance is visible. The resolved instance must own a Cloudflare-backed public domain. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Behavior Contract

### Instance Zone Resolution Rules

- Resolves a concrete production instance from the argument (exact dotted
  selector first; bare project shorthand only when exactly one instance is
  visible).
- Uses that instance's instance-owned public domain to resolve a Cloudflare
  zone. Projects store no server, path, URL, or domain.
- Fails before provider mutation when the resolved instance has no Cloudflare-
  backed domain.
- Code follow-up: the gateway zone resolver may still read a legacy
  project-level domain field; product contract is instance-owned domain only.

### Cache Rule Rules

- Creates or converges the Cloudflare cache settings ruleset for the zone.
- Installs a rule that enables cache settings and respects origin
  `Cache-Control` headers.
- Treats an equivalent existing Orbit-managed rule as successful convergence.
- Does not create custom cache behavior for specific paths.

### Scope Boundaries

`cf-cache-rule:add` writes Cloudflare provider cache policy only. It must not
mutate instance deployment steps, process state, proxy routes, or DNS records.

## Renderer Contracts

- [Human renderer](6.1_cf-cache-rule-add_output-render_human.md)
- [JSON renderer](6.2_cf-cache-rule-add_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Cloudflare unavailable | The gateway token is missing, invalid, or Cloudflare cannot be reached. | `error.code=cloudflare_unavailable` |

## Doctor Relationship

`cf-cache-rule:add` may support instance performance policy, but it does not create a
Cloudflare doctor family. Instance-domain and deployment health remain owned by
[`doctor --family=instance`](../../../5_project/instance-doctor.md). Ingress route health
remains owned by [`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md).

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Cloudflare/CloudflareWriteCommandsTest.php` | CLI POST forwarding to `/api/cloudflare/cache-rules/{project}` and JSON success envelope passthrough. |

There is no gateway-side coverage for this command contract: authorization denial, instance lookup, zone resolution, cache rule convergence, provider authorization, provider failures, and instance/proxy mutation guards remain coverage gaps. CLI forwarding and envelope passthrough are covered by the linked CLI test above.

Renderer-specific test mapping lives in:

- [`6.1_cf-cache-rule-add_output-render_human.md`](6.1_cf-cache-rule-add_output-render_human.md#test-mapping)
- [`6.2_cf-cache-rule-add_output-render_json.md`](6.2_cf-cache-rule-add_output-render_json.md#test-mapping)
