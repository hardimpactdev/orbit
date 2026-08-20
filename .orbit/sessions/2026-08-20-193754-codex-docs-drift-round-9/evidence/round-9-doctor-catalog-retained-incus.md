# Round 9 Doctor catalog retained Incus proof

- Candidate: `6d299f4a5047a0fb2eab39065c8eba279b79d655`
- Topology: `dev-77cbc6`, kind `operator_gateway`, provider `incus`, host `beast`
- Inspected roles: retained operator and gateway node `gateway`
- Solo terminal: project `111`, process `2560` (`round-9-retained-doctor-proof`)
- Runtime checkout: `/home/orbit/orbit-run`
- Launcher: `/home/orbit/orbit-run/apps/cli/orbit`

## Candidate identity

The retained source overlay does not contain Git metadata. Exact hashes of the two changed runtime files matched the candidate worktree:

```text
7b9401e2e4030a16730b389b09772869b93ad1813f5522daefb1103adee7d1c8  apps/gateway/app/Services/Doctor/IssueCatalog/AppDoctorIssueDefinitions.php
ca69d5a2d489589e13eed63b1b07237f1e6e81531a7c297bb9c73e334c60e7bd  apps/gateway/app/Services/Doctor/IssueCatalog/ProxyDoctorIssueDefinitions.php
```

The retained operator's `orbit node:list --json` reported active `gateway` and `operator-1` nodes. The gateway carried active `gateway`, `router`, and `vpn` roles.

## Runtime assertion

Command executed from the Solo terminal:

```text
ssh beast "incus exec orbit-e2e-dev-77cbc6-operator -- sudo -u orbit bash -lc 'cd /home/orbit/orbit-run && apps/cli/orbit doctor --family=proxy --node=gateway --json 2>&1 | grep -oE \"proxy\\.[a-z_]+\" | sort -u'"
```

Observed public issue codes:

```text
proxy.caddy_container_missing
proxy.node_probe_failed
```

The integrated Doctor path emitted the reachable replacement `proxy.node_probe_failed`. It did not emit the removed dead definition `proxy.remote_shell_probe_failed`. The full JSON command completed through the candidate launcher and returned the expected `drift_detected` envelope for the fresh gateway because its proxy container was absent.

Result: passed.
