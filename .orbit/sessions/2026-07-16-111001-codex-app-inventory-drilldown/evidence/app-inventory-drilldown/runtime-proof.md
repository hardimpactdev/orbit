# App inventory drill-down runtime proof

- Topology id: `dev-887d9a`
- Topology kind: `operator_gateway`
- Provider: retained Incus on `beast`
- Candidate tip: `bbdfe5d36d1f0975e222096fa5533b48da6742b1`
- Source-mounted roles: `operator`, `gateway`
- Operator VM: `orbit-e2e-dev-887d9a-operator`
- Gateway VM: `orbit-e2e-dev-887d9a-gateway`
- Checkout path: `/home/orbit/orbit-run`

The initial retained Incus `operator_gateway_app-dev` topology was attempted
twice. Both acquisitions failed before a topology manifest was retained because
the prepared dev agent could not connect to the gateway token verifier at
`10.6.0.2`. The API/CLI inventory feature does not depend on a running
downstream workload process, so proof continued on a healthy retained Incus
`operator_gateway` topology with concrete placement records seeded into the
source-mounted gateway.

The gateway was seeded with one logical app named `atlas`, two visible Orbit app
instances (`development` and `production`), one workspace under each instance,
and an app-scoped dependency audit with findings.

## Commands

```text
ssh beast "incus exec orbit-e2e-dev-887d9a-operator -- sudo -u orbit bash -lc 'cd /home/orbit/orbit-run && orbit app:list'"
ssh beast "incus exec orbit-e2e-dev-887d9a-operator -- sudo -u orbit bash -lc 'cd /home/orbit/orbit-run && orbit app:list --json'"
ssh beast "incus exec orbit-e2e-dev-887d9a-operator -- sudo -u orbit bash -lc 'cd /home/orbit/orbit-run && orbit app:show atlas'"
ssh beast "incus exec orbit-e2e-dev-887d9a-operator -- sudo -u orbit bash -lc 'cd /home/orbit/orbit-run && orbit app:show atlas --json'"
```

## Result

- `app:list` exited 0 and rendered one `Apps` DataList item with repository,
  `Instances: 2`, and `Workspaces: 2`.
- `app:list --json` exited 0 and returned one canonical app plus sibling
  inventory `{app: atlas, instance_count: 2, workspace_count: 2}`.
- `app:show atlas` exited 0 and rendered the `development` and `production`
  instance rows, their nested workspace rows, every instance/workspace URL, and
  `APP DEPS` as `findings (2 danger, 14 warning)` only on instance rows.
- `app:show atlas --json` exited 0 and returned two nested instances and two
  nested workspaces.

PTY artifacts:

- `.orbit/evidence/app-inventory-drilldown/dev-887d9a/app-list/summary.txt`
- `.orbit/evidence/app-inventory-drilldown/dev-887d9a/app-list/transcript.txt`
- `.orbit/evidence/app-inventory-drilldown/dev-887d9a/app-list/chunks.jsonl`
- `.orbit/evidence/app-inventory-drilldown/dev-887d9a/app-list-json/transcript.txt`
- `.orbit/evidence/app-inventory-drilldown/dev-887d9a/app-show/summary.txt`
- `.orbit/evidence/app-inventory-drilldown/dev-887d9a/app-show/transcript.txt`
- `.orbit/evidence/app-inventory-drilldown/dev-887d9a/app-show/chunks.jsonl`
- `.orbit/evidence/app-inventory-drilldown/dev-887d9a/app-show-json/transcript.txt`
