# platform11

Private maintenance config and schedules for Orbit Project 22. The shared
Composer/JavaScript extensions perform package operations on the task worker.
This extension contains no maintenance commands or PR-writing logic.

Config comes from
[hardimpactdev/platform11 at cb0d7a56b393](https://github.com/hardimpactdev/platform11/tree/cb0d7a56b3932cdf30cbbed943385be8926821d0)
and the Gateway's Project settings. The frozen source evidence is in
tests/fixtures/project-extensions/fleet.json.

Read the config without starting work:

```bash
swamp behavior run @nckrtl/project-platform11 configuration project-platform11
```

Schedules use UTC: routine "40 4 * * 1", detection "40 5 * * *". Pause a
schedule while reconciling active work:

```bash
swamp workflow schedule pause @nckrtl/project-platform11/detect --reason "Reconcile active work" --server wss://swamp.orbit
```

Deploy with the private orbit-swamp bundle after review and merge. Registry
publication needs the orchestrator's approval. See docs/project-extensions.md
for task admission, issue records, skip reasons and live acceptance.
