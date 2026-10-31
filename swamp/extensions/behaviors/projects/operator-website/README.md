# operator-website

Private maintenance config and schedules for Orbit Project 47. The shared
Composer/JavaScript extensions perform package operations on the task worker.
This extension contains no maintenance commands or PR-writing logic.

Config comes from
[nckrtl/operator-website at 2a33ba586533](https://github.com/nckrtl/operator-website/tree/2a33ba586533c61a6aa140eaca7f543c8814480d)
and the Gateway's Project settings. The frozen source evidence is in
tests/fixtures/project-extensions/fleet.json.

Read the config without starting work:

```bash
swamp behavior run @nckrtl/project-operator-website configuration project-operator-website
```

Schedules use UTC: routine "20 3 * * 1", detection "20 4 * * *". Pause a
schedule while reconciling active work:

```bash
swamp workflow schedule pause @nckrtl/project-operator-website/detect --reason "Reconcile active work" --server wss://swamp.orbit
```

Deploy with the private orbit-swamp bundle after review and merge. Registry
publication needs the orchestrator's approval. See docs/project-extensions.md
for task admission, issue records, skip reasons and live acceptance.
