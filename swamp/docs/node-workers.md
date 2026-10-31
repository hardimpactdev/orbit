# Workers on Node209

Node209 alone runs Swamp Serve and all workers. Every target Node uses official `@swamp/ssh` from209 with the approved existing key, full host pins and existing sudo. Node bootstrap installs no Swamp binary or worker and mints no worker token. Follow the [SSH guide](node-official-ssh.md) and [per-Node retirement plan](single-swamp-node-retirement.md).

Existing target workers and the logical1/18 proxy workers remain until accepted family placements, active/saved roots and worker-recreation paths are cleared. Do not infer clearance from an idle worker. Preserve schedule pauses and retained histories. Platform owns current Node209 maintenance; source fixes do not authorize runtime actions.

## Retired proxy enrollment entrypoints

The old proxy setup, preflight, enrollment and hourly rotation workflows are removed. Source can no longer recreate the dedicated1/18 proxy workers or renew their obsolete tokens through those paths. The existing native token metadata helpers remain for their separate owner. The old fleet renewal planner and its Gateway adapters are removed.

Existing installed units and credentials stay untouched by this source deletion. Runtime retirement still requires accepted family placement, recreation-path clearance and exact active/saved-root accounting. Keep209's runtime pools and approved SSH identity intact.
