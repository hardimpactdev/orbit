---
title: "Swamp evaluation ledger"
description: "Runtime and worker constraints observed in Swamp that shape Orbit workflow design."
---

# Swamp evaluation ledger

Orbit defaults to Ansible phases for machine execution ([Port a command to the Swamp shape](/swamp/porting) and [`swamp/docs/swamp-shape.md`](https://github.com/hardimpactdev/orbit/blob/main/swamp/docs/swamp-shape.md)). This page records Swamp runtime constraints that still shape how workflows and playbooks are structured. Recheck any constraint after upgrading the pinned Swamp runtime in `swamp/.swamp.yaml`.

## Dispatch and worker constraints

These runtime behaviors explain why Orbit groups machine work into coarse Ansible phases instead of fine-grained steps:

- **Cold-start dispatch overhead:** Each `swamp worker exec-dispatch` launches a compiled Deno binary. Splitting one Node operation into many tiny Swamp steps multiplies dispatch latency; grouping related machine work into one Ansible phase avoids that cost.
- **Shared Deno compile cache (`/tmp/deno-compile-swamp.cache`):** Running `swamp` as `root` under `UMask=0077` leaves cache subdirectories unreadable by non-root workers; Swamp role convergence repairs permissions.
- **Unix socket path length:** SSH multiplexing control sockets live under `/tmp/swamp-ssh/<name>` to stay within Linux's 108-byte `sun_path` limit.

## Workflow expression and input quirks

Keep these evaluator behaviors in mind when authoring `swamp/workflows/*.yaml`:

- **Eager input evaluation:** `vault.get` and static step inputs can evaluate before step guards skip a step; pass optional secrets through bounded inputs or guarded child workflows.
- **Nullable `anyOf` inputs:** Declare nullable types explicitly on `anyOf` schemas so Swamp input validation accepts `null`.
- **Expression closing braces:** Swamp ends `${{ ... }}` at the first `}}`; avoid nested `}}` inside workflow expressions (checked by the expression-end fixture).
- **Working directory under `sudo -H`:** Use `env -C <dir>` rather than assuming `sudo -H` preserves the caller's working directory.
