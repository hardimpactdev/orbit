# E2E Fast Topologies Contract

> **For agentic workers:** Use `docs/testing/README.md` and
> `docs/testing/e2e/**` as authority before changing E2E code, tests, or
> prepared artifacts.

**Goal:** Keep Orbit E2E fast by composing the smallest prepared topology needed
for each feature test.

**Architecture:** Feature E2E starts from prepared Docker role images or
prepared Incus role templates. Provisioning E2E is the single Incus superset
gate.

**Tech Stack:** Laravel 13, Pest 4, Docker role images, Incus role templates,
`orbit-base-ubuntu-26.04`, process-scoped topology caching, and current checkout
overlays.

## Current Topology Kinds

Use the smallest topology that covers the behavior under test:

| Kind | Roles |
| --- | --- |
| `operator` | operator |
| `operator_gateway` | operator, gateway |
| `operator_gateway_app-dev` | operator, gateway, app-dev |
| `operator_gateway_app-dev_app-prod` | operator, gateway, app-dev, app-prod |
| `operator_gateway_agent` | operator, gateway, agent |
| `operator_gateway_app-prod_ingress` | operator, gateway, app-prod carrying ingress |

## Docker Artifacts

Docker prepares composable role images:

- `orbit-e2e:operator_base`
- `orbit-e2e:gateway_base`
- `orbit-e2e:app-dev_base`
- `orbit-e2e:app-prod_base`
- `orbit-e2e:agent_base`

Branch and worktree overrides use the same role tag shape with a custom artifact
set. The provider resolves each role independently and falls back to the
matching `_base` image when a role-specific override is absent.

## Incus Artifacts

Incus uses `orbit-base-ubuntu-26.04` as the only supported base image. Prepared
role templates are:

- `orbit-template-operator-base`
- `orbit-template-gateway-base`
- `orbit-template-app-dev-base`
- `orbit-template-app-prod-base`
- `orbit-template-agent-base`

Prepared snapshots use `clean-<source-topology>-<artifact-set>`. Feature tests
boot only the requested roles.

## Commands

Prepare role artifacts:

```bash
composer e2e:prepare-docker-hosts -- --force operator_gateway_app-dev_app-prod_agent
composer e2e:prepare-topology -- --force operator_gateway_app-dev_app-prod_agent
```

Run feature E2E:

```bash
composer test:e2e
composer test:e2e:docker
composer test:e2e:incus
composer test:e2e:topology-contract
```

Run the provision gate:

```bash
composer test:e2e:provision
```
