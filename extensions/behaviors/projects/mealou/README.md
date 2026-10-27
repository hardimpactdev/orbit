# mealou

Private maintenance config and schedules for Orbit Project 19. The shared
Composer/JavaScript extensions perform package operations on the task worker.
This extension contains no maintenance commands or PR-writing logic.

Config comes from
[hardimpactdev/mealou at ddab70273357](https://github.com/hardimpactdev/mealou/tree/ddab70273357ad9302373732d875f60cf17d0e0a)
and the Gateway's Project settings. The frozen source evidence is in
tests/fixtures/project-extensions/fleet.json.

Read the config without starting work:

```bash
swamp behavior run @nckrtl/project-mealou configuration project-mealou
```

Schedules use UTC: routine "0 5 * * 1", detection "0 6 * * *". Pause a schedule
while reconciling active work:

```bash
swamp workflow schedule pause @nckrtl/project-mealou/detect --reason "Reconcile active work" --server wss://swamp.orbit
```

Deploy with the private orbit-swamp bundle after review and merge. Registry
publication needs the orchestrator's approval. See docs/project-extensions.md
for task admission, issue records, skip reasons and live acceptance.
