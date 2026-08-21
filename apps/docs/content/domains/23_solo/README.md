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

## Commands

The generated command catalog currently exposes these Solo commands.

### Agent tools

- [`solo:agent-tool:list`](24_solo-agent-tool-list/solo-agent-tool-list.md)

### Locks

- [`solo:lock:acquire`](56_solo-lock-acquire/solo-lock-acquire.md)
- [`solo:lock:release`](57_solo-lock-release/solo-lock-release.md)
- [`solo:lock:status`](55_solo-lock-status/solo-lock-status.md)

### Processes

- [`solo:process:clear-output`](21_solo-process-clear-output/solo-process-clear-output.md)
- [`solo:process:close`](23_solo-process-close/solo-process-close.md)
- [`solo:process:input`](16_solo-process-input/solo-process-input.md)
- [`solo:process:list`](13_solo-process-list/solo-process-list.md)
- [`solo:process:output`](15_solo-process-output/solo-process-output.md)
- [`solo:process:rename`](22_solo-process-rename/solo-process-rename.md)
- [`solo:process:restart`](20_solo-process-restart/solo-process-restart.md)
- [`solo:process:show`](14_solo-process-show/solo-process-show.md)
- [`solo:process:spawn`](17_solo-process-spawn/solo-process-spawn.md)
- [`solo:process:start`](18_solo-process-start/solo-process-start.md)
- [`solo:process:stop`](19_solo-process-stop/solo-process-stop.md)

### Projects

- [`solo:project:create`](9_solo-project-create/solo-project-create.md)
- [`solo:project:delete`](12_solo-project-delete/solo-project-delete.md)
- [`solo:project:list`](5_solo-project-list/solo-project-list.md)
- [`solo:project:rename`](10_solo-project-rename/solo-project-rename.md)
- [`solo:project:select`](11_solo-project-select/solo-project-select.md)
- [`solo:project:show`](6_solo-project-show/solo-project-show.md)
- [`solo:project:stats`](8_solo-project-stats/solo-project-stats.md)
- [`solo:project:status`](7_solo-project-status/solo-project-status.md)

### Scratchpads

- [`solo:scratchpad:append`](30_solo-scratchpad-append/solo-scratchpad-append.md)
- [`solo:scratchpad:append-section`](31_solo-scratchpad-append-section/solo-scratchpad-append-section.md)
- [`solo:scratchpad:archive`](34_solo-scratchpad-archive/solo-scratchpad-archive.md)
- [`solo:scratchpad:clear`](35_solo-scratchpad-clear/solo-scratchpad-clear.md)
- [`solo:scratchpad:create`](28_solo-scratchpad-create/solo-scratchpad-create.md)
- [`solo:scratchpad:delete`](36_solo-scratchpad-delete/solo-scratchpad-delete.md)
- [`solo:scratchpad:edit`](32_solo-scratchpad-edit/solo-scratchpad-edit.md)
- [`solo:scratchpad:find`](27_solo-scratchpad-find/solo-scratchpad-find.md)
- [`solo:scratchpad:list`](25_solo-scratchpad-list/solo-scratchpad-list.md)
- [`solo:scratchpad:rename`](33_solo-scratchpad-rename/solo-scratchpad-rename.md)
- [`solo:scratchpad:show`](26_solo-scratchpad-show/solo-scratchpad-show.md)
- [`solo:scratchpad:write`](29_solo-scratchpad-write/solo-scratchpad-write.md)

### Services

- [`solo:service:list`](49_solo-service-list/solo-service-list.md)

### Timers

- [`solo:timer:cancel`](52_solo-timer-cancel/solo-timer-cancel.md)
- [`solo:timer:list`](50_solo-timer-list/solo-timer-list.md)
- [`solo:timer:pause`](53_solo-timer-pause/solo-timer-pause.md)
- [`solo:timer:resume`](54_solo-timer-resume/solo-timer-resume.md)
- [`solo:timer:set`](51_solo-timer-set/solo-timer-set.md)

### Todos

- [`solo:todo:comment:add`](46_solo-todo-comment-add/solo-todo-comment-add.md)
- [`solo:todo:comment:delete`](48_solo-todo-comment-delete/solo-todo-comment-delete.md)
- [`solo:todo:comment:update`](47_solo-todo-comment-update/solo-todo-comment-update.md)
- [`solo:todo:complete`](41_solo-todo-complete/solo-todo-complete.md)
- [`solo:todo:create`](39_solo-todo-create/solo-todo-create.md)
- [`solo:todo:delete`](43_solo-todo-delete/solo-todo-delete.md)
- [`solo:todo:list`](37_solo-todo-list/solo-todo-list.md)
- [`solo:todo:lock`](44_solo-todo-lock/solo-todo-lock.md)
- [`solo:todo:reopen`](42_solo-todo-reopen/solo-todo-reopen.md)
- [`solo:todo:show`](38_solo-todo-show/solo-todo-show.md)
- [`solo:todo:unlock`](45_solo-todo-unlock/solo-todo-unlock.md)
- [`solo:todo:update`](40_solo-todo-update/solo-todo-update.md)

### General tools

- [`solo:tools`](4_solo-tools/solo-tools.md)

## State Ownership

The Solo command domain does not own a state family. It hands off drift checks to existing doctor families:

- `doctor --family=node` verifies node and gateway extension state.
- `doctor --family=process` verifies Orbit-managed process state that may be inspected through Solo process views.
- `doctor --family=tool` verifies installed tool state, including configured gateway tools.
