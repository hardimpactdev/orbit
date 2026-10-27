# fitta

Private maintenance config and schedules for Orbit Project 15. The shared
Composer/JavaScript extensions perform package operations on the task worker.
This extension contains no maintenance commands or PR-writing logic.

Config comes from
[nckrtl/fitta at dc5a6949f69d](https://github.com/nckrtl/fitta/tree/dc5a6949f69dee4582bb1d7ef1e66cdd40d376e6)
and the Gateway's Project settings. The frozen source evidence is in
tests/fixtures/project-extensions/fleet.json.

Read the config without starting work:

```bash
swamp behavior run @nckrtl/project-fitta configuration project-fitta
```

Schedules use UTC: routine "40 3 * * 1", detection "40 4 * * *". Pause a
schedule while reconciling active work:

```bash
swamp workflow schedule pause @nckrtl/project-fitta/detect --reason "Reconcile active work" --server wss://swamp.orbit
```

Deploy with the private orbit-swamp bundle after review and merge. Registry
publication needs the orchestrator's approval. See docs/project-extensions.md
for task admission, issue records, skip reasons and live acceptance.
