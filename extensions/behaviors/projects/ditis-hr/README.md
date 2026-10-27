# ditis-hr

Private maintenance config and schedules for Orbit Project 13. The shared
Composer/JavaScript extensions perform package operations on the task worker.
This extension contains no maintenance commands or PR-writing logic.

Config comes from
[hardimpactdev/ditis-hr at 61ffc1bf2a4a](https://github.com/hardimpactdev/ditis-hr/tree/61ffc1bf2a4ae8e1593a0a1939dcd66260b03afd)
and the Gateway's Project settings. The frozen source evidence is in
tests/fixtures/project-extensions/fleet.json.

Read the config without starting work:

```bash
swamp behavior run @nckrtl/project-ditis-hr configuration project-ditis-hr
```

Schedules use UTC: routine "30 4 * * 1", detection "30 5 * * *". Pause a
schedule while reconciling active work:

```bash
swamp workflow schedule pause @nckrtl/project-ditis-hr/detect --reason "Reconcile active work" --server wss://swamp.orbit
```

Deploy with the private orbit-swamp bundle after review and merge. Registry
publication needs the orchestrator's approval. See docs/project-extensions.md
for task admission, issue records, skip reasons and live acceptance.
