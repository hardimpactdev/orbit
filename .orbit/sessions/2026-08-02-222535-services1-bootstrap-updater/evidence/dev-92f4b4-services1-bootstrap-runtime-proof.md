# Retained topology runtime proof — Services1 bootstrap two-stage updater

- Tip: `ff39519d91874160f952231efb96fce0627b682a`
- Topology: `dev-92f4b4` (kind `operator_gateway_agent`, host Beast)
- Roles: operator/gateway/agent at `/home/orbit/orbit-run`
- Feature changes exercised: gateway `FleetUpdateNodeInstaller` two-stage contract
- CLI artifacts (archived candidates; CLI code identical to tip; gateway owns the two-stage change):
  - old PHP-dependent CLI: `/Users/nckrtl/orbit/.orbit/release-candidates/20260802T180857Z-effe7a1e0/orbit-linux-x64`
    sha256 `2f8bec39d8a32aad709a33b7b75794264b9b68f04207f2a5fee73181f896e02e`
  - new PHP-free CLI: `/Users/nckrtl/orbit/.orbit/release-candidates/20260802T193507Z-ded4d3296/orbit-linux-x64`
    sha256 `e1b6f722500bd905f3a1c995f45fe2cb2c2f11e71ecdb03b24501c0c41da78ae`
  - agent binary: same new candidate
    sha256 `d33cb301468e274797f3c8c4e30bfb82aaa623dc6dde7b18f452d2658b21a5de`

## Source sync

From `/Users/nckrtl/orbit/.worktrees/codex-services1-bootstrap-updater` only:

```bash
ORBIT_E2E_INCUS_SOURCE_PATH=/tmp/orbit-e2e-sources/codex-live-topology-intent-incus-70557d6e7ad5 \
ORBIT_E2E_DEV_TOPOLOGY_MANIFEST_DIRECTORY=/Users/nckrtl/orbit/.worktrees/codex-services1-bootstrap-updater/apps/e2e/var/dev-topology \
composer e2e:incus -- --sync --id=dev-92f4b4
```

Result:

```text
Synced retained Incus topology [dev-92f4b4].
Kind: operator_gateway_agent
Host: beast
Source path: /tmp/orbit-e2e-sources/codex-live-topology-intent-incus-70557d6e7ad5/retained/dev-92f4b4
```

Gateway source marker after sync: `FleetUpdateNodeInstaller` contains
`requiresBootstrapCliStage` / `cliOnlyInstallPayloadJson` / stage-2 step messages.

`codex/live-topology-intent` worktree was not mutated for this proof (remained on
`496f895cdce72e3b12264fd98ed114668ec02ff5`).

## Topology recovery notes (disposable, restored)

After overlay sync, TLS lease caddy was down (`:80` bind conflict with host PHP
gateway). Restored disposable TLS with host-network caddy reverse-proxying
`https://10.6.0.2` → `http://10.6.0.2:80` and enabled
`ORBIT_TRUST_WIREGUARD_PROXY_HEADER=true` with `header_up X-Orbit-WireGuard-Ip
{remote_host}` so agent-push token verify identifies `agent-1` (not gateway).
This is topology infrastructure recovery, not product code under test.

## Agent-1 preconditions (old CLI + PHP sentinel)

On `orbit-e2e-dev-92f4b4-agent`:

1. Installed old candidate binary as configured launcher:

```bash
install -m 0755 /tmp/orbit-proof-artifacts/orbit-linux-x64-old \
  /home/orbit/.local/bin/orbit-binary-old
ln -sfn /home/orbit/.local/bin/orbit-binary-old /home/orbit/.local/bin/orbit
```

Verified: `~/.local/bin/orbit` → `orbit-binary-old`, `orbit --version` 0.1.190.

2. `/usr/local/bin/php` was absent. Installed failing sentinel (PATH prefers
   `/usr/local/bin` before `/usr/bin`):

```sh
#!/bin/sh
echo "php sentinel: host php must not be required" >&2
exit 127
```

3. Restarted `orbit-agent`; service active. `command -v php` →
   `/usr/local/bin/php` (sentinel).

## Two-stage upgrade (gateway WorkloadNodeUpdater → agent-1)

Gateway tinker invoked the real fleet workload path only (not full gateway image
replacement):

- Created operation run + topology-candidate plan targeting CLI/agent artifacts
  from candidate `20260802T193507Z-ded4d3296` (S3 URLs; agent downloads directly).
- Plan included `agent_artifacts` and role images so bootstrap stage is required.
- Ran `app(WorkloadNodeUpdater::class)->update($run, $plan)`.

### Operation evidence

```text
OPERATION_RUN_ID=4f002663-39ef-4e8f-bf5d-6b6895775bbb
RESULTS=[{"target":"agent-1","node":"agent-1","roles":["agent"],"status":"completed","doctor_issues":null}]
EVENT|step|{"key":"workload.agent-1","status":"running","message":"Updating workload node agent-1"}
EVENT|step|{"key":"workload.agent-1","status":"running","message":"Installing CLI 0.1.190"}
EVENT|step|{"key":"workload.agent-1","status":"running","message":"Installing Orbit Agent artifact"}
EVENT|step|{"key":"workload.agent-1","status":"running","message":"Recording installed CLI"}
EVENT|step|{"key":"workload.agent-1","status":"running","message":"Running doctor"}
EVENT|step|{"key":"workload.agent-1","status":"done","message":"Workload node agent-1 updated"}
```

Two-stage contract on the journal:

1. Stage 1 journal: `Installing CLI 0.1.190` — old process installs candidate CLI only.
2. Stage 2 journal: `Installing Orbit Agent artifact` — second internal install
   through the newly installed CLI with full Agent/config payload.

Gateway-recorded installed identity:

- CLI sha256 `e1b6f722500bd905f3a1c995f45fe2cb2c2f11e71ecdb03b24501c0c41da78ae`
  path `/home/orbit/orbit/bin/orbit-binary-e1b6f722500b`
- Agent sha256 `d33cb301468e274797f3c8c4e30bfb82aaa623dc6dde7b18f452d2658b21a5de`
  path `/home/orbit/.local/bin/orbit-agent`

## Post-install host verification (agent-1)

```text
~/.local/bin/orbit -> /home/orbit/orbit/bin/orbit-binary-e1b6f722500b
CLI_SHA256=e1b6f722500bd905f3a1c995f45fe2cb2c2f11e71ecdb03b24501c0c41da78ae
AGENT_SHA256=d33cb301468e274797f3c8c4e30bfb82aaa623dc6dde7b18f452d2658b21a5de
orbit --version => 0.1.190 (Installed at 02-08-2026 - 20:23)
agent.toml + ca/root.crt present (mode 644, owner orbit)
orbit-agent systemd active
php sentinel still first on PATH and still exit 127 after success
  => install completed without requiring host PHP
```

## Cleanup (topology not left degraded)

```bash
rm -f /usr/local/bin/php
systemctl restart orbit-agent
```

After cleanup: `orbit-agent` active; real `/usr/bin/php` remains for the host;
launcher still points at new candidate CLI binary.

## Verdict

Runtime proof **passed** for the two-stage Services1 bootstrap updater on
disposable retained topology `dev-92f4b4` / `agent-1`.
