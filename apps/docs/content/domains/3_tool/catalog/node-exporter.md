# Tool Catalog: `node-exporter`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the node-exporter tool's identity, backend, and support
model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `node-exporter` |
| Label | node-exporter |
| Backend | host binary at `/usr/local/bin/node_exporter` |
| Support model | Role baseline tool for the `metrics` role and active Ubuntu workload nodes scraped by metrics |
| Category | `observability` |

## Capabilities

`node-exporter` supports `tool:install`, `tool:remove`, `tool:update`, safe
doctor fix, and safe doctor adopt. The tool installs the host binary that the
`node-exporter` systemd process uses.

Start, stop, restart, and logs for node-exporter belong to the related
`node-exporter` process row and the process family.

## Credentials

`node-exporter` does not support `tool:credentials`.

## Orbit Notes

The `metrics` role baseline records node-exporter tool intent on the metrics
node and every active Ubuntu workload node that metrics convergence scrapes. It also
records node-owned `systemd` process intent for the same nodes.

Prometheus and Grafana stay Docker Swarm services owned by process rows on the
metrics role node. The tool row for node-exporter represents only the host
binary capability required by the node-exporter process.

## Doctor Relationship

`doctor --family=tool` owns the node-exporter host binary capability and safe
repair/adoption boundaries. The related `systemd` process lifecycle belongs to
the process family.
