# Solo Extension Commands

The Solo command domain documents the Orbit CLI commands exposed by the optional Solo extension. Local extension state controls whether `orbit solo:*` commands appear and run on the node where the CLI is invoked. Gateway extension state controls whether `/api/solo/**` routes execute.

Solo traffic enters through typed gateway HTTPS. An explicit `--node` selects
that node; otherwise the CLI uses local `node:default`, then the authenticated
caller node. The gateway authorizes the resolved target. It calls gateway
loopback directly or uses Agent push to a non-gateway target's local loopback.
Orbit exposes neither Solo ports nor an SSH transport choice.

## Destructive consent

`solo:project:delete`, `solo:process:close`, `solo:scratchpad:clear`,
`solo:scratchpad:delete`, `solo:todo:delete`, and
`solo:todo:comment:delete` are destructive commands. Orbit first resolves the
target node and validates every required resource argument. It then applies the
shared destructive-consent contract:

- `--force` supplies consent without a prompt.
- Interactive human mode prompts after target resolution and before the gateway
  request. Declining performs no request.
- JSON mode and every other non-interactive invocation require `--force`.
- Missing or declined consent returns `error.code=validation_failed` with
  `error.meta.field=force` and
  `error.meta.reason=destructive_consent_required`.

## Hard `--force` requirement

Some Solo mutating commands mark `forceRequired` in the shipped catalog and
reject every invocation without `--force` in any mode — interactive prompts
are not offered. Missing `--force` returns `error.code=validation_failed` with
`error.meta.reason=force_required` and no `error.meta.field`.

Commands currently under this hard gate include:

- `solo:process:stop`
- `solo:process:restart`
- `solo:process:clear-output`
- `solo:scratchpad:archive`

This reason is Solo-specific and is not the shared
`destructive_consent_required` vocabulary. Aligning these commands to the
shared interactive destructive contract is a product follow-up; until then,
automation must supply `--force`.

## State Ownership

The Solo command domain does not own a state family. It hands off drift checks to existing doctor families:

- `doctor --family=node` verifies node and gateway extension state.
- `doctor --family=process` verifies Orbit-managed process state that may be inspected through Solo process views.
- `doctor --family=tool` verifies installed tool state, including configured gateway tools.
