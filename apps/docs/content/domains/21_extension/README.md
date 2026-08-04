# Extension Commands

Extension commands manage whether optional built-in Orbit command and gateway
API families are enabled. Extensions are built into this Orbit release; this
domain does not define downloadable or third-party extension installation.

## State Ownership

The extension command domain does not own a state family. Extension state has
two scopes.

- Local node extension state is stored in the caller's CLI configuration and
  decides whether extension command families appear on that node's normal
  `orbit list` output.
- Gateway extension state is stored in the gateway database and decides whether
  gateway routes for that extension may run after identity and grant checks.

The scopes are intentionally separate. A workload node can have Codex App or
Solo installed without enabling that extension's command family locally unless
that node itself needs to run those commands.

[`doctor --family=node`](../1_node/node-doctor.md) owns node identity, local
configuration reachability, and gateway reachability. Extension commands do not
create extension-family doctor issues.

## Domain Rules

These rules constrain all extension commands.

- Extension slugs are registry-defined. The built-in slugs in this release are
  `cloudflare`, `codex`, and `solo`.
- `extension:enable <slug>` with no `--node` enables that extension locally on
  the node where the command runs.
- `extension:enable <slug> --node=gateway` enables the gateway-side state for
  that extension.
- When local enablement succeeds but gateway state is disabled, interactive
  callers may choose to enable the gateway as part of the same command.
  Non-interactive and JSON callers must pass `--gateway` or receive
  `extension_gateway_enable_required`.
- `--node=gateway` is the only remote node target in this slice. Workload-node
  remote enablement is out of scope.
- Disabled extension commands are hidden from normal command discovery. Direct
  invocation of a known disabled extension command returns
  `extension_disabled` with `meta.scope=local`.
- When gateway extension state is disabled, extension API calls return
  `extension_disabled` with `meta.scope=gateway` only after the caller identity
  and grant checks pass.
- The `solo` extension advertises its planned command catalog as registered
  Orbit commands. Local enablement controls whether those `solo:*` commands
  appear on the node where `orbit` runs.
- Solo commands use flat names with colon-separated segments, such as
  `solo:project:list`, `solo:process:list`, `solo:scratchpad:list`, and
  `solo:todo:list`. Grouped noun/subcommand signatures such as
  `solo:project list` are not part of the Solo catalog.
- Gateway Solo proxy routes live under `/api/solo/**` and run only when gateway
  extension state has enabled `solo`.
- Gateway Solo read routes require the caller to hold `solo:*` on the resolved
  target node.
  Gateway Solo mutation routes require operation-specific Solo permissions such
  as `solo:scratchpad:write`, `solo:todo:delete`, or `solo:timer:*`. Calls that
  lack the required grant fail with `authorization_failed`.
- Disabled gateway Solo proxy calls fail with `extension_disabled` and
  `meta.scope=gateway` after identity and grant checks pass.
- Gateway Solo proxy routes record Orbit API activity for Solo operations.
- Each `solo:*` command resolves an explicit target, local `node:default`, or
  the caller node, in that order. The gateway calls its own Solo loopback
  directly and uses Agent push for an eligible non-gateway target. Orbit
  exposes neither Solo localhost ports nor an SSH transport choice.
- Read-only and implemented mutating Solo CLI commands call the gateway Solo
  proxy when local `solo` is enabled. Live topology acceptance is deferred to a
  later implementation slice.

## Solo Command Catalog

The `solo` extension reserves the following command names. Direct invocation
while local `solo` is disabled fails with `extension_disabled` and
`meta.scope=local`.

Implemented read-only and mutating commands call `/api/solo/**` when local
`solo` is enabled. They support `--json` with the shared one-top-level-key
Orbit envelope and render human output for terminal operators. Gateway,
authorization, and upstream Solo errors are mapped into the same CLI error
envelope.

Registered Solo commands that are not implemented yet remain reserved. When
local `solo` is enabled, those commands fail with `solo_command_deferred` and
`meta.scope=local`.

## Solo Gateway Proxy

The gateway proxy owns read and mutation routes for the implemented CLI command
set. The first representative read routes are:

- `GET /api/solo/tools`
- `GET /api/solo/projects`

All `/api/solo/**` routes are gateway API routes, not WireGuard-exposed Solo
ports. They use the gateway extension enablement gate for `solo`, log Orbit
activity, and forward to the resolved target's configured loopback Solo API.
Gateway targets are local calls; eligible non-gateway targets use Agent push.
Read routes require `solo:*`; mutation routes require the narrow
permission declared for the operation. Upstream unavailability is reported as
`solo_upstream_unavailable`; malformed upstream payloads or missing loopback
configuration are reported as `validation_failed`.

The implemented read-only CLI set is:

| Area | Implemented read-only commands |
| --- | --- |
| Tools | `solo:tools`, `solo:agent-tool:list` |
| Projects | `solo:project:list`, `solo:project:show`, `solo:project:status`, `solo:project:stats` |
| Processes | `solo:process:list`, `solo:process:show`, `solo:process:output` |
| Scratchpads | `solo:scratchpad:list`, `solo:scratchpad:show`, `solo:scratchpad:find` |
| Todos | `solo:todo:list`, `solo:todo:show` |
| Coordination | `solo:service:list`, `solo:timer:list`, `solo:lock:status` |

The implemented mutating CLI set is:

| Area | Implemented mutating commands |
| --- | --- |
| Projects | `solo:project:create`, `solo:project:rename`, `solo:project:select`, `solo:project:delete` |
| Processes | `solo:process:input`, `solo:process:spawn`, `solo:process:start`, `solo:process:stop`, `solo:process:restart`, `solo:process:clear-output`, `solo:process:rename`, `solo:process:close` |
| Scratchpads | `solo:scratchpad:create`, `solo:scratchpad:write`, `solo:scratchpad:append`, `solo:scratchpad:append-section`, `solo:scratchpad:edit`, `solo:scratchpad:rename`, `solo:scratchpad:archive`, `solo:scratchpad:clear`, `solo:scratchpad:delete` |
| Todos | `solo:todo:create`, `solo:todo:update`, `solo:todo:complete`, `solo:todo:reopen`, `solo:todo:delete`, `solo:todo:lock`, `solo:todo:unlock`, `solo:todo:comment:add`, `solo:todo:comment:update`, `solo:todo:comment:delete` |
| Coordination | `solo:lock:acquire`, `solo:lock:release`, `solo:timer:set`, `solo:timer:cancel`, `solo:timer:pause`, `solo:timer:resume` |

| Area | Commands |
| --- | --- |
| Setup and introspection | `solo:status`, `solo:help`, `solo:tools`, `solo:smoke-test`, `solo:feedback` |
| Projects | `solo:project:list`, `solo:project:show`, `solo:project:status`, `solo:project:stats`, `solo:project:create`, `solo:project:rename`, `solo:project:select`, `solo:project:delete` |
| Processes | `solo:process:list`, `solo:process:show`, `solo:process:output`, `solo:process:search`, `solo:process:ports`, `solo:process:select`, `solo:process:input`, `solo:process:spawn`, `solo:agent:spawn`, `solo:process:start`, `solo:process:stop`, `solo:process:restart`, `solo:process:clear-output`, `solo:process:rename`, `solo:process:close`, `solo:command:start-all`, `solo:command:stop-all`, `solo:command:restart-all` |
| Agent tools | `solo:agent-tool:list`, `solo:agent:setup` |
| Scratchpads | `solo:scratchpad:list`, `solo:scratchpad:show`, `solo:scratchpad:find`, `solo:scratchpad:create`, `solo:scratchpad:write`, `solo:scratchpad:append`, `solo:scratchpad:append-section`, `solo:scratchpad:edit`, `solo:scratchpad:rename`, `solo:scratchpad:archive`, `solo:scratchpad:clear`, `solo:scratchpad:delete`, `solo:scratchpad:tags`, `solo:scratchpad:tag:add`, `solo:scratchpad:tag:remove`, `solo:scratchpad:load-file`, `solo:scratchpad:save-file`, `solo:scratchpad:transfer` |
| Todos | `solo:todo:list`, `solo:todo:show`, `solo:todo:create`, `solo:todo:update`, `solo:todo:complete`, `solo:todo:reopen`, `solo:todo:delete`, `solo:todo:lock`, `solo:todo:unlock`, `solo:todo:transfer`, `solo:todo:tags`, `solo:todo:tag:add`, `solo:todo:tag:remove`, `solo:todo:blocker:add`, `solo:todo:blocker:remove`, `solo:todo:blockers:set`, `solo:todo:comment:list`, `solo:todo:comment:add`, `solo:todo:comment:update`, `solo:todo:comment:delete` |
| Coordination, timers, and readiness | `solo:lock:status`, `solo:lock:acquire`, `solo:lock:release`, `solo:timer:list`, `solo:timer:set`, `solo:timer:cancel`, `solo:timer:pause`, `solo:timer:resume`, `solo:timer:idle-any`, `solo:timer:idle-all`, `solo:service:list`, `solo:port:wait` |

## Commands

The extension family has three commands.

1. [`orbit extension:list`](1_extension-list/extension-list.md)
2. [`orbit extension:enable <extension>`](2_extension-enable/extension-enable.md)
3. [`orbit extension:disable <extension>`](3_extension-disable/extension-disable.md)

## Related

- [`orbit cf-*`](../12_cf/README.md)
- [`orbit codex:app`](../22_codex/1_codex-app/codex-app.md)
