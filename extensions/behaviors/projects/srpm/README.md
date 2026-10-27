# srpm

Private maintenance config and schedules for Orbit Project 25. The shared
Composer/JavaScript extensions perform package operations on the task worker.
This extension contains no maintenance commands or PR-writing logic.

Config comes from
[hardimpactdev/srpm at a3144e31109b](https://github.com/hardimpactdev/srpm/tree/a3144e31109bfb4ed775a50924bf084676fe629e)
and the Gateway's Project settings. The frozen source evidence is in
tests/fixtures/project-extensions/fleet.json.

Read the config without starting work:

```bash
swamp behavior run @nckrtl/project-srpm configuration project-srpm
```

Schedules use UTC: routine "10 4 * * 1", detection "10 5 * * *". Pause a
schedule while reconciling active work:

```bash
swamp workflow schedule pause @nckrtl/project-srpm/detect --reason "Reconcile active work" --server wss://swamp.orbit
```

Deploy with the private orbit-swamp bundle after review and merge. Registry
publication needs the orchestrator's approval. See docs/project-extensions.md
for task admission, issue records, skip reasons and live acceptance.
