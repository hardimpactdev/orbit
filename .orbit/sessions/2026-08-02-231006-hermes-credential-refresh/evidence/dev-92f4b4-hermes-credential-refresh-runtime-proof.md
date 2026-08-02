# Retained topology runtime proof — Hermes credential refresh on reconfigure

- Tip: `e64389ee7a68afc63b5fbf94524f0350ad509d0d` (feature) / merge tip `d2f3bdba49482212335f0e058b23194bfa9da7dc`
- Topology: `dev-92f4b4` (kind `operator_gateway_agent`, host Beast)
- Roles/instances: `orbit-e2e-dev-92f4b4-operator`, `orbit-e2e-dev-92f4b4-gateway`, `orbit-e2e-dev-92f4b4-agent`
- Checkouts: operator/gateway/agent at `/home/orbit/orbit-run`
- Feature under test: after successful tool reconfigure, ToolReconfigurer re-runs
  credentialsScript and replaces stored NodeTool credential fields; success
  payload does not include credential values; related process restart still runs.

## Source sync

From `/Users/nckrtl/orbit/.worktrees/codex-hermes-credential-refresh`:

```bash
ORBIT_E2E_INCUS_SOURCE_PATH=/tmp/orbit-e2e-sources/codex-live-topology-intent-incus-70557d6e7ad5 \
ORBIT_E2E_DEV_TOPOLOGY_MANIFEST_DIRECTORY=/Users/nckrtl/orbit/.worktrees/codex-hermes-credential-refresh/apps/e2e/var/dev-topology \
composer e2e:incus -- --sync --id=dev-92f4b4
```

Result:

```text
Synced retained Incus topology [dev-92f4b4].
Kind: operator_gateway_agent
Host: beast
Source path: /tmp/orbit-e2e-sources/codex-live-topology-intent-incus-70557d6e7ad5/retained/dev-92f4b4
```

Gateway lease-http container (`orbit-gateway-e2e-topology-lease-http`, source
`/srv/orbit` from `/home/orbit/orbit-run`) contains `refreshCredentialsFromScript`
in `ToolReconfigurer.php` (`code_has_refresh=yes`).

## Setup on disposable topology

1. Hermes binary/home already present on agent; dashboard credential files exist
   under `/home/agent/.hermes/` mode `0600` owner `agent:agent`.
2. `orbit-hermes-dashboard` unit `active`.
3. Gateway SQLite `/home/orbit/.config/orbit/gateway.sqlite` had no hermes
   `node_tools` row; seeded row for `agent-1` with stale install-time
   generated-password placeholder fields (url/auth_mode/username/auth secret).

## Reconfigure exercise (gateway service path)

Inside `orbit-gateway-e2e-topology-lease-http`:

```text
app(ToolReconfigurer::class)->reconfigure(tool: hermes, node: agent-1)
```

Observed:

```text
SUCCESS_ACTION=reconfigured
PROCESS_ACTION=restarted
PROCESS name=orbit-hermes-dashboard runtime=systemd tool=hermes action=restarted
PAYLOAD_HAS_GENERATED=no
PAYLOAD_HAS_AUTH_FIELD_KEY=no
STILL_PLACEHOLDER=no
```

Stored credential fields after reconfigure: JSON object replaced (no longer the
install-time generated-password placeholder). Related process restarted.
Success payload did not include auth secret values.

Credentials script on this topology returned empty auth secret because
agent-push cannot traverse `/home/agent` (`0700`); file remains `0600`
agent:agent. Focused Pest covers non-empty secret replacement; disposable
topology proves store replacement + restart + non-leak. Not a production
mutation.

## Agent host post-proof

```text
active
dashboard credential file mode 0600 owner agent:agent
```

## Broader quality (merge tip)

- Artifact: `.orbit/quality-gates/quality-check-2026-08-02T210933Z-62f6739df6ec.json`
- `composer quality-check` exit 0 at clean HEAD `d2f3bdba49482212335f0e058b23194bfa9da7dc`
