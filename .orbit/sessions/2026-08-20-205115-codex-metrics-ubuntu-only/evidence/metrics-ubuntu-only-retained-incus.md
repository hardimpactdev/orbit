# Metrics Ubuntu-only retained Incus proof

- Candidate: `cf688dc0f7e496ba76f744567985242ec41424f9`
- Venue: `retained-incus`
- Environment: `dev-fixture`
- Topology: `dev-90b6a4`, kind `operator_gateway`, provider `incus`, host `beast`
- Solo terminal: project `113`, process `2565` (`metrics-retained-incus-proof`)
- Runtime checkout: `/home/orbit/orbit-run`
- Launcher: `/home/orbit/orbit-run/apps/cli/orbit`

## Candidate identity

The retained source overlay does not contain Git metadata. The runtime hashes
matched the exact candidate worktree:

```text
d7225fb847f7a7cd3addb843f195c68cf9903562dafd8e2f8f70d72c46ad503b  apps/gateway/app/Services/Nodes/Roles/NodeRoleRegistry.php
a14f11dd7324adc8b60348ee98201dfc67571a2e9c8f6f8c6b1f40e8c425d232  apps/gateway/app/Services/Nodes/Roles/RoleBaselines/MetricsRoleBaseline.php
```

The Solo terminal confirmed `PWD=/home/orbit/orbit-run` and resolved the
launcher to `/home/orbit/orbit-run/apps/cli/orbit` before the proof.

## Runtime assertion

The disposable gateway fixture platform was changed to `debian_12` in the
retained gateway database. The candidate CLI then reported the changed fixture
through `apps/cli/orbit node:list --json` and ran:

```text
apps/cli/orbit node role:add gateway metrics --json
```

Observed result:

```json
{"error":{"code":"validation_failed","message":"Role 'metrics' does not support platform 'debian_12'.","meta":{"role":"metrics"}}}
```

The command exited with status 1. A follow-up
`apps/cli/orbit node role:list gateway --json` returned only the existing
`gateway`, `router`, and `vpn` roles. No Metrics assignment was persisted.

Expected: an actual candidate CLI request rejects Metrics assignment on a
Debian fixture before persistence.

Observed: exact `validation_failed` rejection, nonzero exit, and no Metrics
role in the follow-up assignment list.

Result: passed.
