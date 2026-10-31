# operator

Private maintenance config and schedules for Orbit Project 48. The shared
Composer/JavaScript extensions perform package operations on the task worker.
This extension contains no maintenance commands or PR-writing logic.

Config comes from
[nckrtl/operator at a27158a0bd0d](https://github.com/nckrtl/operator/tree/a27158a0bd0da86e83af50dd314227fa976f840e)
and the Gateway's Project settings. The frozen source evidence is in
tests/fixtures/project-extensions/fleet.json.

Read the config without starting work:

```bash
swamp behavior run @nckrtl/project-operator configuration project-operator
```

Schedules use UTC: routine "30 3 * * 1", detection "30 4 * * *". Pause a
schedule while reconciling active work:

```bash
swamp workflow schedule pause @nckrtl/project-operator/detect --reason "Reconcile active work" --server wss://swamp.orbit
```

Deploy with the private orbit-swamp bundle after review and merge. Registry
publication needs the orchestrator's approval. See docs/project-extensions.md
for task admission, issue records, skip reasons and live acceptance.
