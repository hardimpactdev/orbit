# Managed Operator Node Codex Design

Date: 2026-06-22
Status: Approved design; pending implementation plan

## Problem

Codex App project registration is local to an operator machine, while the
Orbit app path usually lives on a separate app-dev node. For example, the
Codex project entry for `hauser-design` is stored on the Mac running Codex App,
but points at `/home/nckrtl/apps/hauser-design` on `beast`.

The existing Agent IDE adapter model does not fit this shape. `opencode` and
`polyscope` are app-node adjacent adapters; Codex App is an operator-machine
tool that points at remote SSH projects.

Orbit already has the right base model:

- `node:new --operator` creates a roleless active node identity.
- `Node::isOperator()` treats active roleless nodes as operators.
- `nodes.user`, `nodes.platform`, `nodes.wireguard_address`, and SSH host-key
  columns already exist.
- Gateway remote shell already uses SSH and prefers `wireguard_address` over
  `host`.

The missing behavior is allowing an operator node to opt in to gateway SSH
management, then allowing supported tools and Codex project operations to
target that operator node explicitly.

## Goals

- Let an operator run `orbit node:manage` locally to opt the current operator
  node into gateway SSH access.
- Reuse existing node columns instead of adding a role, management object,
  transport field, or managed flag.
- Reach operator nodes over their stored WireGuard peer address when the
  gateway SSHes into them.
- Allow explicit `--node=<operator-node>` targeting for tools and Codex
  project operations when the caller is authorized.
- Add a narrow `codex-app` tool and Codex project registration operation for
  Codex App's local project config.

## Non-Goals

- Do not add an `operator` or `workstation` node role.
- Do not add a stored `managed=true` flag.
- Do not model transport selection. This design assumes SSH.
- Do not use public hostnames such as `nicks-mac.local` for gateway-to-operator
  SSH when a WireGuard address exists.
- Do not make Codex App an Agent IDE adapter for `agent-ide:message`.
- Do not introduce arbitrary file management on operator machines.

## Current Findings

- Operator nodes already exist as roleless active nodes. The model method
  `Node::isOperator()` returns true when no active role assignments exist.
- `node:new --operator` currently stores both `host` and `wireguard_address`
  as the assigned WireGuard address, stores `platform=unknown`, and stores a
  default SSH user.
- `SshCommandBuilder::hostForNode()` returns `wireguard_address` before `host`
  outside Docker E2E and explicit public-host paths.
- `RemoteHostExecutor` uses `SshCommandBuilder::enforceForNode()`, so normal
  remote shell already uses `nodes.user` and pinned `host_key_*` material.
- `ToolRegistry` and API target resolution currently filter explicit `--node`
  targets through role-based `activeToolHostNodeIds()`, which excludes
  roleless operator nodes.
- Platform detection exists for the local process. Operator platform detection
  needs the same idea, but executed locally by `node:manage` or remotely after
  SSH access is established.

## Design

### Local opt-in command

Add:

```bash
orbit node:manage [--user=<user>] [--json]
```

This command runs on the operator machine being managed. It is a local opt-in
operation, not a gateway-initiated takeover.

Behavior:

1. Resolve the current local Orbit node identity from existing local
   configuration and gateway authentication.
2. Require that the resolved node is active and roleless.
3. Resolve the SSH login user from `--user`, or detect the current OS user.
4. Fetch the gateway management SSH public key from the gateway. This should
   reuse the gateway's existing steady-state SSH identity, currently derived
   from `~/.ssh/id_ed25519` during node provisioning.
5. Install that public key into the local user's `~/.ssh/authorized_keys`
   idempotently.
6. Detect the local platform and report it to the gateway.
7. Ask the gateway to persist `nodes.user` and `nodes.platform` for the caller
   node.
8. Ask the gateway to pin the node SSH host key using the node's
   `wireguard_address`, storing the existing `host_key_*` fields.
9. Ask the gateway to verify SSH reachability to the node over WireGuard.

If verification fails, the command fails with the same class of SSH
unreachable error used for other targeted nodes. There is no special
"operator not managed" state.

### Management state

No new management state is stored.

An operator node is effectively usable for remote operations when the existing
state is sufficient:

- active node
- no active role assignments
- non-empty `wireguard_address`
- correct `user`
- detected `platform`
- pinned SSH host key
- gateway SSH key installed for that user
- SSH reachable from the gateway

Commands should not check a `managed` flag. They should target the node and let
normal SSH, platform, authorization, and tool eligibility checks succeed or
fail.

### Tool targeting

Explicit `--node=<name>` targeting should resolve any active, visible node,
including roleless operator nodes.

Default tool-node discovery and list views may keep their existing role-based
tool-host defaults, but an explicitly named active operator node must not fail
with "Expected a visible tool node name" solely because it has no workload role.

Tool execution still requires catalog eligibility. Existing Linux/server tools
should not silently become supported on macOS operator nodes. Add or extend
tool-definition eligibility so a tool can declare supported node kinds and
platform families.

Initial policy:

- `codex-app` supports active operator nodes on macOS.
- Existing role-bound tools keep their current role/platform expectations.
- Existing general tools do not gain operator-node support unless they opt in.

### Codex App tool

Add a `codex-app` tool. It represents the local Codex desktop application and
its local project registry, not an app-node Agent IDE adapter.

Capabilities:

- probe whether Codex App local configuration is present
- optionally install or guide installation when a supported installation path
  exists
- add a project entry
- remove a project entry
- list managed/known project entries

The tool operates on the operator node over gateway SSH after `node:manage`
has made that SSH path available.

### Codex project operation

Add an app-facing operation such as:

```bash
orbit app:codex add <app> --node=<operator-node>
orbit app:codex remove <app> --node=<operator-node>
orbit app:codex list --node=<operator-node>
```

The command resolves:

- the Orbit app and its owning app-dev node
- the app path on the app-dev node
- the Codex SSH alias for that app-dev node
- the target operator node that owns the local Codex App config

The first version can default Codex SSH alias to the Orbit node name, for
example `beast`. A later enhancement can add an explicit per-node Codex SSH
alias override if a user's Codex remote alias differs from the Orbit node name.

The remote operation on the operator node edits:

```text
~/.codex/codex-app/config.json
```

It must merge entries idempotently into this shape:

```json
{
  "version": 1,
  "remoteConnections": [
    {
      "sshAlias": "beast",
      "projects": [
        {
          "remotePath": "/home/nckrtl/apps/hauser-design",
          "label": "hauser-design"
        }
      ]
    }
  ]
}
```

After a successful write, apply the Codex config through:

```text
codex://codex-app/apply-config
```

On macOS this likely means running `open codex://codex-app/apply-config` in the
operator user's session. If no GUI session is available, the command should
return a structured warning or failure that clearly states the config was
written but the app could not be signaled.

## Telegram / Agent Flow

The target product flow is:

```text
Telegram or agent node
  -> orbit app:new hauser-design --node=beast
  -> orbit app:codex add hauser-design --node=nicks-mac
  -> gateway SSHes into nicks-mac over WireGuard
  -> operator node updates Codex App config
  -> operator node applies codex://codex-app/apply-config
```

This gives remote automation the ability to create an app and register it in
the user's local Codex App without the user manually running a project command
after creation.

## Error Handling

- If the operator node has no WireGuard address, fail as an incomplete node
  record.
- If the SSH user is missing or wrong, fail with ordinary SSH unreachable or
  authentication diagnostics.
- If host-key material is missing during normal remote operations, fail through
  the existing strict host-key path. `node:manage` is responsible for pinning
  the key during opt-in.
- If the selected tool does not support the target node platform or kind, fail
  with `tool.unsupported_on_node`.
- If the Codex config file is malformed, preserve the original file and fail
  before writing unless a safe recovery path is explicitly added later.
- If the Codex config write succeeds but the deep link cannot be applied,
  report that split result instead of hiding the partial success.
- If a user targets an unmanaged or unreachable operator node, do not invent a
  special operator-specific error. Report the same reachability/authentication
  failure used for any other node.

## Testing

Focused tests should cover:

- `node:manage` resolves the caller node, detects or accepts `--user`, writes
  `nodes.user` and `nodes.platform`, installs the gateway SSH key
  idempotently, pins the host key using `wireguard_address`, and verifies SSH.
- `node:manage` rejects non-operator role-bearing nodes.
- Explicit tool `--node` resolution accepts an active roleless operator node.
- Existing implicit tool-node listing and fallback behavior do not accidentally
  broaden beyond documented defaults.
- Tool eligibility rejects unsupported tool/platform combinations.
- `codex-app` config merge creates, updates, and preserves unrelated
  connections and projects idempotently.
- `app:codex add` resolves app path, app-dev node, Codex SSH alias, and
  operator node correctly.
- Codex apply reports clear success, warning, and failure states.

No live Codex App dependency is required for the first automated test suite.
Use fakes for remote shell and deep-link application.

