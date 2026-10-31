# laravel-toolbar-site

Private maintenance config and schedules for Orbit Project 28. The shared
Composer/JavaScript extensions perform package operations on the task worker.
This extension contains no maintenance commands or PR-writing logic.

Config comes from
[hardimpactdev/laravel-toolbar-site at 9484e29531e2](https://github.com/hardimpactdev/laravel-toolbar-site/tree/9484e29531e2af920ac92821147f14c490b7ed43)
and the Gateway's Project settings. The frozen source evidence is in
tests/fixtures/project-extensions/fleet.json.

Read the config without starting work:

```bash
swamp behavior run @nckrtl/project-laravel-toolbar-site configuration project-laravel-toolbar-site
```

Schedules use UTC: routine "10 3 * * 1", detection "10 4 * * *". Pause a
schedule while reconciling active work:

```bash
swamp workflow schedule pause @nckrtl/project-laravel-toolbar-site/detect --reason "Reconcile active work" --server wss://swamp.orbit
```

Deploy with the private orbit-swamp bundle after review and merge. Registry
publication needs the orchestrator's approval. See docs/project-extensions.md
for task admission, issue records, skip reasons and live acceptance.
