# treadmill-website

Private maintenance config and schedules for Orbit Project 54. The shared
Composer/JavaScript extensions perform package operations on the task worker.
This extension contains no maintenance commands or PR-writing logic.

Config comes from
[nckrtl/treadmill-website at 756c216f767f](https://github.com/nckrtl/treadmill-website/tree/756c216f767fad953debba582d460efd96a4e677)
and the Gateway's Project settings. The frozen source evidence is in
tests/fixtures/project-extensions/fleet.json.

Read the config without starting work:

```bash
swamp behavior run @nckrtl/project-treadmill-website configuration project-treadmill-website
```

Schedules use UTC: routine "30 2 * * 1", detection "30 3 * * *". Pause a
schedule while reconciling active work:

```bash
swamp workflow schedule pause @nckrtl/project-treadmill-website/detect --reason "Reconcile active work" --server wss://swamp.orbit
```

Deploy with the private orbit-swamp bundle after review and merge. Registry
publication needs the orchestrator's approval. See docs/project-extensions.md
for task admission, issue records, skip reasons and live acceptance.
