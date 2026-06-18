# Metrics Commands

Metrics commands manage Orbit's host-resource observability surface. The command
family is `metrics:*`.

The backend technologies are Prometheus, Grafana, and node-exporter. They are
not the product model.

## Domain Rules

These rules govern what the metrics command family owns and what it may not
touch.

- The metrics command family owns the `metrics:*` command prefix.
- The `metrics` role is optional and disabled by default.
- `metrics:enable` assigns the `metrics` role to an existing node or
  reconverges an existing metrics role, then converges the role baseline.
- `metrics:disable` removes the `metrics` role from a node after explicit
  `--force` consent.
- The private fleet endpoint is `https://metrics.orbit`. It exposes Grafana
  through router-owned private routing only.
- Prometheus and Grafana run as Docker Swarm process definitions on the metrics
  node. node-exporter is recorded as a host binary tool and host systemd
  process definition on the metrics node and every active workload node
  selected by the fleet update target selector.
- The metrics role records protected firewall intent for each Ubuntu
  node-exporter host so Prometheus can scrape TCP port 9100 through the private
  WireGuard interface. It never opens node-exporter on a public interface.
- The first metrics slice tracks host resources only. It does not claim
  container-specific, app-specific, database-specific, or dynamic scrape
  discovery coverage.
- Metrics can run on a dedicated node or be co-located with any non-agent role,
  including a Debian gateway/router node.
- The metrics command family coordinates node, tool, process, firewall rule,
  and proxy state. It does not own an independent `doctor --family=metrics`
  state family.

## Permissions

Metrics commands use the state-family permissions for the state they coordinate.

- `metrics:enable` requires `role:add` on the target node.
- `metrics:disable` requires `role:remove` on the target node.
- `metrics:status` requires `process:read` on each returned metrics node.
- `metrics:credentials` requires `tool:credentials` on the selected active
  metrics node.

The serving node is the target or selected active metrics node. Authorization
failures use `authorization_failed` with standard `missing_permission`
metadata.

## State Ownership

The metrics command domain coordinates state owned by other families:

- [`node`](../1_node/README.md) owns the `metrics` role assignment and role
  readiness. Role assignment drift is verified and repaired through
  `doctor --family=node`.
- [`tool`](../3_tool/README.md) owns the Docker substrate capability expected
  on metrics role nodes and the node-exporter host binary capability expected
  on metrics and active workload nodes. Tool capability drift is verified and
  repaired through `doctor --family=tool`.
- [`process`](../7_process/README.md) owns Prometheus, Grafana, and
  node-exporter process definitions, runtime artifacts, lifecycle, logs, and
  runtime drift on the metrics node and workload nodes. Metrics runtime drift is
  verified and repaired through
  `doctor --family=process`.
- [`firewall_rule`](../4_firewall/firewall.md) owns private node-exporter scrape
  access on Ubuntu exporter hosts. The metrics baseline records protected
  `orbit-metrics-node-exporter` rules allowing the metrics node to reach TCP
  port 9100 on the WireGuard interface, and firewall drift is verified and
  repaired through `doctor --family=firewall_rule`.
- [`proxy`](../8_proxy/README.md) owns the router-owned `metrics.orbit` route
  and derived proxy/TLS artifacts. Route drift is verified and repaired through
  `doctor --family=proxy`.

The metrics command domain does not own a state family in v1.

## Concepts

These concepts define the vocabulary used by metrics command contracts.

- [Metrics Concepts](metrics-concepts.md)

## Commands

The `metrics:*` family covers enablement, removal, status, and Grafana
credentials.

1. [`orbit metrics:enable --node=<node>`](1_metrics-enable/metrics-enable.md)
2. [`orbit metrics:disable --node=<node> --force`](2_metrics-disable/metrics-disable.md)
3. [`orbit metrics:status`](3_metrics-status/metrics-status.md)
4. [`orbit metrics:credentials`](4_metrics-credentials/metrics-credentials.md)

## Non-Goals

V1 does not include alert rules, notification channels, custom dashboards,
distributed Prometheus, remote write, fleet-wide scrape discovery, app metrics,
container metrics, database metrics, public Grafana ingress, or per-user
Grafana accounts.

## Related

- [`orbit node:*`](../1_node/README.md)
- [`orbit process:*`](../7_process/README.md)
- [`orbit proxy:*`](../8_proxy/README.md)
- [`orbit tool:*`](../3_tool/README.md)
