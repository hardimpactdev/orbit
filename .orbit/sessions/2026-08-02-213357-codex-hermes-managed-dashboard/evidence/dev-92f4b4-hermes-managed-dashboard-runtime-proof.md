# Retained topology runtime proof — Hermes managed dashboard

- Tip: `4ef1aee14c8d6737f362279cd0dc616182abf7f8`
- Topology: `dev-92f4b4` (kind `operator_gateway_agent`, host Beast)
- Checkouts: operator/gateway/agent at `/home/orbit/orbit-run`
- Sync: source files refreshed to tip (PROCESS_NAME present on operator/agent/gateway checkouts); full `composer e2e:incus -- --sync` later hit gateway lease restart naming and busy-mount issues; lease-http was recreated as `orbit-gateway-e2e-topology-lease-http` on host network serving source-mounted code at http://10.6.0.2:80.
- Operator gateway config for this proof: `http://10.6.0.2` (self_mode wireguard_http).

## tool:install limitation (not exercised)

`tool:install hermes --node=agent-1` fails on this retained topology with the already-known agent runtime_user preflight / agent-push transport false negative (`node.agent_unreachable` / token verify / push path). This proof deliberately does **not** claim install/reconfigure CLI success; live fleet after release is the true `tool:reconfigure` venue.

## Manual process converge

1. Gateway registry: `process:add orbit-hermes-dashboard ... --node=agent-1 --tool=hermes --runtime=systemd --restart-policy=always` succeeded (intent row + tool=hermes). Initial remote unit apply warned `process.runtime_unit_apply_failed` while old `hermes-dashboard` still held port 8080.
2. Agent host: stopped/disabled legacy `hermes-dashboard.service`; installed/enabled `orbit-hermes-dashboard.service` with tip-equivalent ExecStart (file-loaded basic auth env; `hermes dashboard --host 0.0.0.0 --port 8080 --no-open`).
3. `systemctl enable --now orbit-hermes-dashboard` → active; journal `HERMES_DASHBOARD_READY port=8080`.
4. Gateway `process:remove hermes-dashboard` / `process:restart` returned HTTP 500 (agent-push path still degraded on this topology); registry still lists legacy `hermes-dashboard` row with tool=null, but unit is inactive. Live unit is `orbit-hermes-dashboard`.

## Proof results

### Process running + restart

- `systemctl is-active orbit-hermes-dashboard` → `active`
- Before restart MainPID=7817; after sleep MainPID=7817 (`PID_STABLE=yes`)
- `systemctl restart orbit-hermes-dashboard` → MainPID=7865; still `active`; listener on `0.0.0.0:8080` (hermes pid 7870)

### Host hermes.agent → login redirect

From agent and operator to `http://127.0.0.1:8080` / `http://10.6.0.6:8080` with `Host: hermes.agent`:

```
HTTP/1.1 302 Found
location: /login?next=%2F
server: uvicorn
```

No Host-header 400 JSON.

### Credential files 0600

```
mode=600,owner=agent:agent  /home/agent/.hermes/dashboard.password
mode=600,owner=agent:agent  /home/agent/.hermes/dashboard.secret
mode=600,owner=agent:agent  /home/agent/.hermes/dashboard.public_url
```

### ActiveState guard / PID stability

- `systemctl show -p ActiveState` → `active`
- Guard classification `active|activating|reloading` → `skip_stop` (do not run `hermes dashboard --stop` while managed unit is live)
- PID stable while unit remains active without restart

## Broader quality (source tip)

- `.orbit/quality-gates/quality-check-2026-08-02T191905Z-b250c137471e.json`
- exit 0, `git.dirty=false`, commit `4ef1aee14c8d6737f362279cd0dc616182abf7f8`

## Independent review

- BLAST_RADIUS: complete
- HUMAN_JUDGMENT: not-required
- VERDICT: PASS
- Non-blocking residuals noted by reviewer: tool:update does not restart related process (live rollout uses tool:reconfigure; TLD unchanged); warning payload shape lacks API assertion

## Raw capture fragments

Agent proof log excerpt:

```
=== unit/active ===
active,running,7817
MAIN_PID_BEFORE=7817
=== credentials 0600 ===
mode=600,owner=agent:agent
mode=600,owner=agent:agent
mode=600,owner=agent:agent
PUBLIC_URL=https://hermes.agentn
=== Host hermes.agent unauthenticated ===
HTTP/1.1 302 Found
date: Sun, 02 Aug 2026 19:31:15 GMT
server: uvicorn
content-length: 0
location: /login?next=%2F

BODY_HEAD=
=== ActiveState guard simulation ===
UNIT_STATE=active
GUARD=skip_stop
MAIN_PID_AFTER_SLEEP=7817
PID_STABLE=yes
=== process restart via systemctl ===
MAIN_PID_AFTER_RESTART=7865
active
LISTEN 0      2048         0.0.0.0:8080       0.0.0.0:*    users:(("hermes",pid=7870,fd=7))                       
HTTP/1.1 302 Found
date: Sun, 02 Aug 2026 19:31:21 GMT
server: uvicorn
content-length: 0
location: /login?next=%2F

=== after restart still active ===
active
```
