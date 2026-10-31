# Workers on Node209

Node209 alone runs Swamp Serve and all workers. Every target Node uses official `@swamp/ssh` from209 with the approved existing key, full host pins and existing sudo. Node bootstrap installs no Swamp binary or worker and mints no worker token. Follow the [SSH guide](node-official-ssh.md) and [per-Node retirement plan](single-swamp-node-retirement.md).

Existing target workers and the logical1/18 proxy workers remain until accepted family placements, active/saved roots and worker-recreation paths are cleared. Do not infer clearance from an idle worker. Preserve schedule pauses and retained histories. Platform owns current Node209 maintenance; source fixes do not authorize runtime actions.

## Paused legacy credential rotation

The retained rotation adapter uses `/home/orbit/apps/orbit-monorepo-staging/checkout/swamp` for both native CLI `--repo-dir` and process working directory. It must not index the retired `/home/orbit/apps/orbit-swamp/default` checkout. This path correction changes no token or renewal policy and does not run or resume rotation.

The legacy hourly rotation remains paused. Its retained admissions and non209 worker recreation paths remain retirement dependencies; the single-Node decision does not authorize their replay. Keep209's own runtime pools and the existing SSH identity intact. The former target-worker issuer request is cancelled.
