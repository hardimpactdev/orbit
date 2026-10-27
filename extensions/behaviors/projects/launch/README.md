# launch

Private maintenance config and schedules for Orbit Project 32. The shared
Composer/JavaScript extensions perform package operations on the task worker.
This extension contains no maintenance commands or PR-writing logic.

Config comes from
[nckrtl/launch at a3342aeba750](https://github.com/nckrtl/launch/tree/a3342aeba7504333a20b58159fd6de6bcd3186e4)
and the Gateway's Project settings. The frozen source evidence is in
tests/fixtures/project-extensions/fleet.json.

Read the config without starting work:

```bash
swamp behavior run @nckrtl/project-launch configuration project-launch
```

Schedules use UTC: routine "20 2 * * 1", detection "20 3 * * *". Pause a
schedule while reconciling active work:

```bash
swamp workflow schedule pause @nckrtl/project-launch/detect --reason "Reconcile active work" --server wss://swamp.orbit
```

Deploy with the private orbit-swamp bundle after review and merge. Registry
publication needs the orchestrator's approval. See docs/project-extensions.md
for task admission, issue records, skip reasons and live acceptance.
