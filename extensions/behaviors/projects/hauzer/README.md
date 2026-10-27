# hauzer

Private maintenance config and schedules for Orbit Project 16. The shared
Composer/JavaScript extensions perform package operations on the task worker.
This extension contains no maintenance commands or PR-writing logic.

Config comes from
[hardimpactdev/hauzer at ec9082cb8921](https://github.com/hardimpactdev/hauzer/tree/ec9082cb89214796533dbbbfcf050ba24da4e5f8)
and the Gateway's Project settings. The frozen source evidence is in
tests/fixtures/project-extensions/fleet.json.

Read the config without starting work:

```bash
swamp behavior run @nckrtl/project-hauzer configuration project-hauzer
```

Schedules use UTC: routine "50 4 * * 1", detection "50 5 * * *". Pause a
schedule while reconciling active work:

```bash
swamp workflow schedule pause @nckrtl/project-hauzer/detect --reason "Reconcile active work" --server wss://swamp.orbit
```

Deploy with the private orbit-swamp bundle after review and merge. Registry
publication needs the orchestrator's approval. See docs/project-extensions.md
for task admission, issue records, skip reasons and live acceptance.
