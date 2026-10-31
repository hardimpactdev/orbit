# tankwerk

Private maintenance config and schedules for Orbit Project 26. The shared
Composer/JavaScript extensions perform package operations on the task worker.
This extension contains no maintenance commands or PR-writing logic.

Config comes from
[hardimpactdev/tankwerk at 8433beadf1a6](https://github.com/hardimpactdev/tankwerk/tree/8433beadf1a617abc0820caae37181ac13f2f8a3)
and the Gateway's Project settings. The frozen source evidence is in
tests/fixtures/project-extensions/fleet.json.

Read the config without starting work:

```bash
swamp behavior run @nckrtl/project-tankwerk configuration project-tankwerk
```

Schedules use UTC: routine "20 4 * * 1", detection "20 5 * * *". Pause a
schedule while reconciling active work:

```bash
swamp workflow schedule pause @nckrtl/project-tankwerk/detect --reason "Reconcile active work" --server wss://swamp.orbit
```

Deploy with the private orbit-swamp bundle after review and merge. Registry
publication needs the orchestrator's approval. See docs/project-extensions.md
for task admission, issue records, skip reasons and live acceptance.
