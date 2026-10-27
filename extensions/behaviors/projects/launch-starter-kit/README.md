# launch-starter-kit

Private maintenance config and schedules for Orbit Project 30. The shared
Composer/JavaScript extensions perform package operations on the task worker.
This extension contains no maintenance commands or PR-writing logic.

Config comes from
[nckrtl/launch-starter-kit at ff7889266555](https://github.com/nckrtl/launch-starter-kit/tree/ff78892665557b21e50b19d2f05fdc62d79fb1b2)
and the Gateway's Project settings. The frozen source evidence is in
tests/fixtures/project-extensions/fleet.json.

Read the config without starting work:

```bash
swamp behavior run @nckrtl/project-launch-starter-kit configuration project-launch-starter-kit
```

Schedules use UTC: routine "10 2 * * 1", detection "10 3 * * *". Pause a
schedule while reconciling active work:

```bash
swamp workflow schedule pause @nckrtl/project-launch-starter-kit/detect --reason "Reconcile active work" --server wss://swamp.orbit
```

Deploy with the private orbit-swamp bundle after review and merge. Registry
publication needs the orchestrator's approval. See docs/project-extensions.md
for task admission, issue records, skip reasons and live acceptance.
