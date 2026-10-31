# orbit-website

Private maintenance config and schedules for Orbit Project 33. The shared
Composer/JavaScript extensions perform package operations on the task worker.
This extension contains no maintenance commands or PR-writing logic.

Config comes from
[nckrtl/orbit-website at 3ce37e1ddc5e](https://github.com/nckrtl/orbit-website/tree/3ce37e1ddc5ef241d33de18c8c261b609a43fd6d)
and the Gateway's Project settings. The frozen source evidence is in
tests/fixtures/project-extensions/fleet.json.

Read the config without starting work:

```bash
swamp behavior run @nckrtl/project-orbit-website configuration project-orbit-website
```

Schedules use UTC: routine "40 2 * * 1", detection "40 3 * * *". Pause a
schedule while reconciling active work:

```bash
swamp workflow schedule pause @nckrtl/project-orbit-website/detect --reason "Reconcile active work" --server wss://swamp.orbit
```

Deploy with the private orbit-swamp bundle after review and merge. Registry
publication needs the orchestrator's approval. See docs/project-extensions.md
for task admission, issue records, skip reasons and live acceptance.
