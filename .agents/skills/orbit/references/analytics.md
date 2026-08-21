# Analytics Commands

Manage the fleet-singleton Plausible CE analytics runtime. Spec:
[`apps/docs/content/domains/20_analytics/`](../../../../apps/docs/content/domains/20_analytics/).

## `orbit analytics:update`

Update the Plausible CE process version on an active analytics-role node.

```bash
orbit analytics:update --requested-version=<version> [--node=<node>] [--json]
```

Use a plain semantic version such as `3.2.2`. When `--node` is omitted, Orbit
selects the fleet's singleton active analytics node. The caller needs
`process:update` permission on that node.

This updates the Plausible process row and applies its runtime change. It does
not change instance analytics bindings, public tracking hosts, Plausible site
configuration, or tracking script installation.
