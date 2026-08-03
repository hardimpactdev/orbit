# Retained topology proof: token/transport contract

## Identity

- Feature commit: `d03ea5785394575edeb775698ed3c1c392473bbd`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-token-transport-contract`
- Topology id: `dev-04ed41`
- Kind: `operator_gateway_app-dev`
- Provider: `incus`
- Host: `beast`
- Instances: operator=`orbit-e2e-dev-04ed41-operator`, gateway=`orbit-e2e-dev-04ed41-gateway`, dev=`orbit-e2e-dev-04ed41-dev`
- Source path: `/tmp/orbit-e2e-sources/codex-token-transport-contract-incus-8cb547fdf81b/retained/dev-04ed41`
- Checkout roles overlay: operator, gateway, app-dev at `/home/orbit/orbit-run`
- Acquired: `composer e2e:incus -- --start --topology=operator_gateway_app-dev --json`
- Release: `composer e2e:incus -- --stop --id=dev-04ed41`

## Commands exercised

1. Operator: `orbit doctor --node=app-dev-1 --json` (exit 1 drift_detected; internal executor dispatches still succeeded)
2. Operator: `orbit doctor --node=gateway --json` (exit 1 drift_detected; force_remote_host dispatches still succeeded for selected host-bound probes)
3. Gateway: `orbit activity:list --include-internal --limit=200 --json`

## Node roles

- `app-dev-1`: app-dev + database (active)
- `gateway`: gateway + router + vpn (active)
- `operator-1`: operator (no workload roles)

## Assertion 1 — app-dev Agent push

Representative operation_id=`tool.probe-php-cli` and `node.reachable` on subject `app-dev-1`.

| id | type | transport | status | exit | command_line |
| --- | --- | --- | --- | --- | --- |
| 260 | agent_push.completed | agent_push | succeeded | 0 | `` |
| 259 | agent_push.dispatching | agent_push | dispatching | None | `'/home/orbit/.local/bin/orbit' internal:tool:run-script --operation-token=<redacted> --json` |
| 226 | agent_push.completed | agent_push | succeeded | 0 | `` |
| 225 | agent_push.dispatching | agent_push | dispatching | None | `'/home/orbit/.local/bin/orbit' internal:executor:verify --operation-token=<redacted> --json` |
| 215 | agent_push.completed | agent_push | succeeded | 0 | `` |
| 214 | agent_push.dispatching | agent_push | dispatching | None | `'/home/orbit/.local/bin/orbit' internal:tool:run-script --operation-token=<redacted> --json` |
| 207 | agent_push.completed | agent_push | succeeded | 0 | `` |
| 206 | agent_push.dispatching | agent_push | dispatching | None | `'/home/orbit/.local/bin/orbit' internal:tool:run-script --operation-token=<redacted> --json` |

Result: paired `agent_push.dispatching`/`agent_push.completed` with `properties.transport=agent_push`, `status=succeeded`, `exit_code=0`.
Binary path in audit line is host launcher `/home/orbit/.local/bin/orbit` (Agent push lane).

## Assertion 2 — gateway force_remote_host

Representative operation_id=`process-runtime-containers.probe` and `tool.probe-many` on subject `gateway`.

| id | type | transport | status | exit | command_line |
| --- | --- | --- | --- | --- | --- |
| 276 | ssh_bootstrap.run | ssh_bootstrap | succeeded | 0 | `` |
| 274 | force_remote_host.completed | force_remote_host | succeeded | 0 | `` |
| 273 | ssh_bootstrap.run | ssh_bootstrap | succeeded | 0 | `` |
| 272 | force_remote_host.dispatching | force_remote_host | dispatching | None | `'/usr/local/bin/orbit-cli' internal:tool:run-script --operation-token=<redacted> --json` |
| 270 | ssh_bootstrap.run | ssh_bootstrap | succeeded | 0 | `` |
| 268 | force_remote_host.completed | force_remote_host | succeeded | 0 | `` |
| 267 | ssh_bootstrap.run | ssh_bootstrap | succeeded | 0 | `` |
| 266 | force_remote_host.dispatching | force_remote_host | dispatching | None | `'/usr/local/bin/orbit-cli' internal:app-runtime-containers:probe --operation-token=<redacted> --json` |

Result: `force_remote_host.dispatching` → `ssh_bootstrap.run` → `force_remote_host.completed` with `status=succeeded` / `exit_code=0`.
Substrate row `ssh_bootstrap.run` is the lower-level host shell audit between the RemoteLocalExecutor pair.

## Assertion 3 — activity transport pairing

- Workload lane: `agent_push.dispatching` + `agent_push.completed`, `properties.transport=agent_push`.
- Host-bound gateway lane: `force_remote_host.dispatching` + `force_remote_host.completed`, `properties.transport=force_remote_host`, interleaved with `ssh_bootstrap.run` (`properties.transport=ssh_bootstrap`).

## Assertion 4 — secret redaction in feature-proof rows

- Selected-row unredacted operation tokens: False
- Selected-row APP_KEY= assignments: False
- Selected-row gateway-secret literals: False
- Selected-row `--operation-token=<redacted>` markers: 6
- Full activity dump also contained historical bake rows of type `local_executor.completed` for `internal:websocket-runtime` / `websocket-runtime.app-key:ensure` with an `app_key` value in stdout_summary. Those are outside the feature proof lanes (not `agent_push`/`force_remote_host`) and predate the doctor proof window.

## Harness limits / closest real boundary

- Doctor overall exit was drift_detected (expected on a fresh retained topology); proof uses successful internal executor activity pairs, not doctor healthy=true.
- Gateway `internal:node-security-posture:probe` took `gateway_local` (not force_remote_host) and failed with `/usr/local/bin/orbit-cli: not found` inside the container lane. That probe path is a separate host-boundary classification gap; force_remote_host success is proven via tool/runtime-container probes that did force the host boundary.
- operation_runs SQLite CLI (`sqlite3`) is not installed in the gateway VM; activity list API is the durable proof surface used.
- Shared allowlisted env mint/verify equality remains unit-proven in focused Pest; retained topology proves live transport lanes and activity labeling with redacted tokens.

## Selected activity JSON

```json
[
  {
    "id": 276,
    "occurred_at": "2026-08-03T07:14:30+00:00",
    "correlation_id": null,
    "type": "ssh_bootstrap.run",
    "effect": "write",
    "subject": {
      "type": "node",
      "name": "gateway"
    },
    "actor": null,
    "command": null,
    "description": "ssh_bootstrap.run",
    "properties": {
      "lane": "internal",
      "category": "remote_execution",
      "transport": "ssh_bootstrap",
      "node": "gateway",
      "script_sha256": "0d17bdc6a9f877f412a8b0d1356e80f4b37b239ed1cebc933280080a91dbc091",
      "input_sha256": "a157703309dfe2278eadf4ee5d7ecbf27bd9f8fd381e058e787273541ac9064a",
      "metadata_keys": [
        "ORBIT_OPERATION_ID"
      ],
      "cwd_set": true,
      "strict": false,
      "timeout": 915,
      "exit_code": 0,
      "duration_ms": 1165,
      "status": "succeeded"
    },
    "channel": "api"
  },
  {
    "id": 274,
    "occurred_at": "2026-08-03T07:14:29+00:00",
    "correlation_id": null,
    "type": "force_remote_host.completed",
    "effect": "write",
    "subject": {
      "type": "node",
      "name": "gateway"
    },
    "actor": null,
    "command": "internal:tool:run-script",
    "description": "Force remote host operation succeeded",
    "properties": {
      "lane": "internal",
      "transport": "force_remote_host",
      "status": "succeeded",
      "operation_id": "tool.probe-many",
      "target_node_id": 1,
      "target_node_name": "gateway",
      "exit_code": 0,
      "stdout_summary": "{\"success\":{\"data\":{\"exit_code\":0,\"stdout\":\"\",\"stderr\":\"\",\"duration_ms\":17},\"meta\":[]}}\n",
      "stderr_summary": "",
      "duration_ms": 1111
    },
    "channel": "api"
  },
  {
    "id": 273,
    "occurred_at": "2026-08-03T07:14:29+00:00",
    "correlation_id": null,
    "type": "ssh_bootstrap.run",
    "effect": "write",
    "subject": {
      "type": "node",
      "name": "gateway"
    },
    "actor": null,
    "command": null,
    "description": "ssh_bootstrap.run",
    "properties": {
      "lane": "internal",
      "category": "remote_execution",
      "transport": "ssh_bootstrap",
      "node": "gateway",
      "script_sha256": "d680aa8fe489ced825f5e7b4cb24e95ad76bfe79581c92b18e97546a47fd0917",
      "input_sha256": "0ab9634148ccf82b2d2c0110c3db7ab6c704e39e5173e43444787d7612ebd22f",
      "metadata_keys": [
        "ORBIT_OPERATION_ID"
      ],
      "cwd_set": true,
      "strict": false,
      "timeout": 915,
      "exit_code": 0,
      "duration_ms": 1111,
      "status": "succeeded"
    },
    "channel": "api"
  },
  {
    "id": 272,
    "occurred_at": "2026-08-03T07:14:28+00:00",
    "correlation_id": null,
    "type": "force_remote_host.dispatching",
    "effect": "write",
    "subject": {
      "type": "node",
      "name": "gateway"
    },
    "actor": null,
    "command": "internal:tool:run-script",
    "description": "Force remote host operation dispatching",
    "properties": {
      "lane": "internal",
      "transport": "force_remote_host",
      "status": "dispatching",
      "operation_id": "tool.probe-many",
      "target_node_id": 1,
      "target_node_name": "gateway",
      "arguments": [],
      "command_options": [],
      "command_line": "'/usr/local/bin/orbit-cli' internal:tool:run-script --operation-token=<redacted> --json"
    },
    "channel": "api"
  },
  {
    "id": 270,
    "occurred_at": "2026-08-03T07:14:28+00:00",
    "correlation_id": null,
    "type": "ssh_bootstrap.run",
    "effect": "write",
    "subject": {
      "type": "node",
      "name": "gateway"
    },
    "actor": null,
    "command": null,
    "description": "ssh_bootstrap.run",
    "properties": {
      "lane": "internal",
      "category": "remote_execution",
      "transport": "ssh_bootstrap",
      "node": "gateway",
      "script_sha256": "528ad89decc980ad7bf3293692545776b4e515cd18495512a95c2c133b91f47c",
      "input_sha256": "f04653180f18cbbc6fafe00590f85365f130155856e39c28b670b25c6905d718",
      "metadata_keys": [
        "ORBIT_OPERATION_ID"
      ],
      "cwd_set": true,
      "strict": false,
      "timeout": 915,
      "exit_code": 0,
      "duration_ms": 1313,
      "status": "succeeded"
    },
    "channel": "api"
  },
  {
    "id": 268,
    "occurred_at": "2026-08-03T07:14:27+00:00",
    "correlation_id": null,
    "type": "force_remote_host.completed",
    "effect": "write",
    "subject": {
      "type": "node",
      "name": "gateway"
    },
    "actor": null,
    "command": "internal:app-runtime-containers:probe",
    "description": "Force remote host operation succeeded",
    "properties": {
      "lane": "internal",
      "transport": "force_remote_host",
      "status": "succeeded",
      "operation_id": "process-runtime-containers.probe",
      "target_node_id": 1,
      "target_node_name": "gateway",
      "exit_code": 0,
      "stdout_summary": "{\"success\":{\"data\":{\"status\":\"present\",\"containers\":[],\"error\":\"\",\"stdout\":\"orbit-container-scan:present\\n\"},\"meta\":[]}}\n",
      "stderr_summary": "",
      "duration_ms": 1292
    },
    "channel": "api"
  },
  {
    "id": 267,
    "occurred_at": "2026-08-03T07:14:27+00:00",
    "correlation_id": null,
    "type": "ssh_bootstrap.run",
    "effect": "write",
    "subject": {
      "type": "node",
      "name": "gateway"
    },
    "actor": null,
    "command": null,
    "description": "ssh_bootstrap.run",
    "properties": {
      "lane": "internal",
      "category": "remote_execution",
      "transport": "ssh_bootstrap",
      "node": "gateway",
      "script_sha256": "b79088ebfb33b3b0649d2be54db2c05214995d80d3f2dd9d64bfdbd7f1de564d",
      "metadata_keys": [
        "ORBIT_OPERATION_ID"
      ],
      "cwd_set": true,
      "strict": true,
      "timeout": 30,
      "exit_code": 0,
      "duration_ms": 1292,
      "status": "succeeded"
    },
    "channel": "api"
  },
  {
    "id": 266,
    "occurred_at": "2026-08-03T07:14:25+00:00",
    "correlation_id": null,
    "type": "force_remote_host.dispatching",
    "effect": "write",
    "subject": {
      "type": "node",
      "name": "gateway"
    },
    "actor": null,
    "command": "internal:app-runtime-containers:probe",
    "description": "Force remote host operation dispatching",
    "properties": {
      "lane": "internal",
      "transport": "force_remote_host",
      "status": "dispatching",
      "operation_id": "process-runtime-containers.probe",
      "target_node_id": 1,
      "target_node_name": "gateway",
      "arguments": [],
      "command_options": [],
      "command_line": "'/usr/local/bin/orbit-cli' internal:app-runtime-containers:probe --operation-token=<redacted> --json"
    },
    "channel": "api"
  },
  {
    "id": 260,
    "occurred_at": "2026-08-03T07:14:16+00:00",
    "correlation_id": null,
    "type": "agent_push.completed",
    "effect": "write",
    "subject": {
      "type": "node",
      "name": "app-dev-1"
    },
    "actor": null,
    "command": "internal:tool:run-script",
    "description": "Agent push operation succeeded",
    "properties": {
      "lane": "internal",
      "transport": "agent_push",
      "status": "succeeded",
      "operation_id": "tool.probe-php-cli",
      "target_node_id": 5,
      "target_node_name": "app-dev-1",
      "exit_code": 0,
      "stdout_summary": "{\"success\":{\"data\":{\"exit_code\":0,\"stdout\":\"8.5|8.5.8|1|8.5.8|1|1|1|1\\n8.4|8.4.21|1|8.4.21|1|1|1|1\\n8.3|8.3.31|1|8.3.31|1|1|1|1\\n\",\"stderr\":\"\",\"duration_ms\":475},\"meta\":[]}}",
      "stderr_summary": "",
      "duration_ms": 1813
    },
    "channel": "api"
  },
  {
    "id": 259,
    "occurred_at": "2026-08-03T07:14:15+00:00",
    "correlation_id": null,
    "type": "agent_push.dispatching",
    "effect": "write",
    "subject": {
      "type": "node",
      "name": "app-dev-1"
    },
    "actor": null,
    "command": "internal:tool:run-script",
    "description": "Agent push operation dispatching",
    "properties": {
      "lane": "internal",
      "transport": "agent_push",
      "status": "dispatching",
      "operation_id": "tool.probe-php-cli",
      "target_node_id": 5,
      "target_node_name": "app-dev-1",
      "arguments": [],
      "command_options": [],
      "command_line": "'/home/orbit/.local/bin/orbit' internal:tool:run-script --operation-token=<redacted> --json"
    },
    "channel": "api"
  },
  {
    "id": 226,
    "occurred_at": "2026-08-03T07:14:01+00:00",
    "correlation_id": null,
    "type": "agent_push.completed",
    "effect": "write",
    "subject": {
      "type": "node",
      "name": "app-dev-1"
    },
    "actor": null,
    "command": "internal:executor:verify",
    "description": "Agent push operation succeeded",
    "properties": {
      "lane": "internal",
      "transport": "agent_push",
      "status": "succeeded",
      "operation_id": "node.reachable",
      "target_node_id": 5,
      "target_node_name": "app-dev-1",
      "exit_code": 0,
      "stdout_summary": "{\"success\":{\"data\":{\"operation_id\":\"d0650f16-eced-47cf-b2b8-fcec84d4d086\",\"node\":\"app-dev-1\",\"command\":\"internal:executor:verify\"},\"meta\":[]}}",
      "stderr_summary": "",
      "duration_ms": 797
    },
    "channel": "api"
  },
  {
    "id": 225,
    "occurred_at": "2026-08-03T07:14:01+00:00",
    "correlation_id": null,
    "type": "agent_push.dispatching",
    "effect": "write",
    "subject": {
      "type": "node",
      "name": "app-dev-1"
    },
    "actor": null,
    "command": "internal:executor:verify",
    "description": "Agent push operation dispatching",
    "properties": {
      "lane": "internal",
      "transport": "agent_push",
      "status": "dispatching",
      "operation_id": "node.reachable",
      "target_node_id": 5,
      "target_node_name": "app-dev-1",
      "arguments": [],
      "command_options": [],
      "command_line": "'/home/orbit/.local/bin/orbit' internal:executor:verify --operation-token=<redacted> --json"
    },
    "channel": "api"
  },
  {
    "id": 215,
    "occurred_at": "2026-08-03T07:13:07+00:00",
    "correlation_id": null,
    "type": "agent_push.completed",
    "effect": "write",
    "subject": {
      "type": "node",
      "name": "app-dev-1"
    },
    "actor": null,
    "command": "internal:tool:run-script",
    "description": "Agent push operation succeeded",
    "properties": {
      "lane": "internal",
      "transport": "agent_push",
      "status": "succeeded",
      "operation_id": "tool.probe-php-cli",
      "target_node_id": 5,
      "target_node_name": "app-dev-1",
      "exit_code": 0,
      "stdout_summary": "{\"success\":{\"data\":{\"exit_code\":0,\"stdout\":\"8.5|8.5.8|1|8.5.8|1|1|1|1\\n8.4|8.4.21|1|8.4.21|1|1|1|1\\n8.3|8.3.31|1|8.3.31|1|1|1|1\\n\",\"stderr\":\"\",\"duration_ms\":503},\"meta\":[]}}",
      "stderr_summary": "",
      "duration_ms": 1223
    },
    "channel": "api"
  },
  {
    "id": 214,
    "occurred_at": "2026-08-03T07:13:06+00:00",
    "correlation_id": null,
    "type": "agent_push.dispatching",
    "effect": "write",
    "subject": {
      "type": "node",
      "name": "app-dev-1"
    },
    "actor": null,
    "command": "internal:tool:run-script",
    "description": "Agent push operation dispatching",
    "properties": {
      "lane": "internal",
      "transport": "agent_push",
      "status": "dispatching",
      "operation_id": "tool.probe-php-cli",
      "target_node_id": 5,
      "target_node_name": "app-dev-1",
      "arguments": [],
      "command_options": [],
      "command_line": "'/home/orbit/.local/bin/orbit' internal:tool:run-script --operation-token=<redacted> --json"
    },
    "channel": "api"
  },
  {
    "id": 207,
    "occurred_at": "2026-08-03T07:12:46+00:00",
    "correlation_id": null,
    "type": "agent_push.completed",
    "effect": "write",
    "subject": {
      "type": "node",
      "name": "app-dev-1"
    },
    "actor": null,
    "command": "internal:tool:run-script",
    "description": "Agent push operation succeeded",
    "properties": {
      "lane": "internal",
      "transport": "agent_push",
      "status": "succeeded",
      "operation_id": "tool.probe-php-cli",
      "target_node_id": 5,
      "target_node_name": "app-dev-1",
      "exit_code": 0,
      "stdout_summary": "{\"success\":{\"data\":{\"exit_code\":0,\"stdout\":\"8.5|8.5.8|1|8.5.6|0|0|0|0\\n8.4|8.4.21|1|8.4.21|0|0|0|0\\n8.3|8.3.31|1|8.3.31|0|0|0|0\\n\",\"stderr\":\"\",\"duration_ms\":4443},\"meta\":[]}}",
      "stderr_summary": "",
      "duration_ms": 6534
    },
    "channel": "api"
  },
  {
    "id": 206,
    "occurred_at": "2026-08-03T07:12:40+00:00",
    "correlation_id": null,
    "type": "agent_push.dispatching",
    "effect": "write",
    "subject": {
      "type": "node",
      "name": "app-dev-1"
    },
    "actor": null,
    "command": "internal:tool:run-script",
    "description": "Agent push operation dispatching",
    "properties": {
      "lane": "internal",
      "transport": "agent_push",
      "status": "dispatching",
      "operation_id": "tool.probe-php-cli",
      "target_node_id": 5,
      "target_node_name": "app-dev-1",
      "arguments": [],
      "command_options": [],
      "command_line": "'/home/orbit/.local/bin/orbit' internal:tool:run-script --operation-token=<redacted> --json"
    },
    "channel": "api"
  }
]
```

