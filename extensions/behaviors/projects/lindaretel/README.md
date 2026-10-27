# lindaretel

Private maintenance config and schedules for Orbit Project 18. The shared
Composer/JavaScript extensions perform package operations on the task worker.
This extension contains no maintenance commands or PR-writing logic.

Config comes from
[hardimpactdev/lindaretel at 9f596317ac17](https://github.com/hardimpactdev/lindaretel/tree/9f596317ac1730836774bf47018e4ec835e15c5f)
and the Gateway's Project settings. The frozen source evidence is in
tests/fixtures/project-extensions/fleet.json.

Read the config without starting work:

```bash
swamp behavior run @nckrtl/project-lindaretel configuration project-lindaretel
```

Schedules use UTC: routine "0 4 * * 1", detection "0 5 * * *". Pause a schedule
while reconciling active work:

```bash
swamp workflow schedule pause @nckrtl/project-lindaretel/detect --reason "Reconcile active work" --server wss://swamp.orbit
```

Deploy with the private orbit-swamp bundle after review and merge. Registry
publication needs the orchestrator's approval. See docs/project-extensions.md
for task admission, issue records, skip reasons and live acceptance.
