# house-italy

Private maintenance config and schedules for Orbit Project 17. The shared
Composer/JavaScript extensions perform package operations on the task worker.
This extension contains no maintenance commands or PR-writing logic.

Config comes from
[nckrtl/house-italy at 37367f60c1e1](https://github.com/nckrtl/house-italy/tree/37367f60c1e1edf4946f5f48b148173555b7df91)
and the Gateway's Project settings. The frozen source evidence is in
tests/fixtures/project-extensions/fleet.json.

Read the config without starting work:

```bash
swamp behavior run @nckrtl/project-house-italy configuration project-house-italy
```

Schedules use UTC: routine "50 3 * * 1", detection "50 4 * * *". Pause a
schedule while reconciling active work:

```bash
swamp workflow schedule pause @nckrtl/project-house-italy/detect --reason "Reconcile active work" --server wss://swamp.orbit
```

Deploy with the private orbit-swamp bundle after review and merge. Registry
publication needs the orchestrator's approval. See docs/project-extensions.md
for task admission, issue records, skip reasons and live acceptance.
