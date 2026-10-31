# ai-cli-proxy

Private maintenance config and schedules for Orbit Project 51. The shared
Composer/JavaScript extensions perform package operations on the task worker.
This extension contains no maintenance commands or PR-writing logic.

Config comes from
[nckrtl/ai-cli-proxy at d8b8f364f052](https://github.com/nckrtl/ai-cli-proxy/tree/d8b8f364f0529ea1601353139acd0cdbd0cf1a55)
and the Gateway's Project settings. The frozen source evidence is in
tests/fixtures/project-extensions/fleet.json.

Read the config without starting work:

```bash
swamp behavior run @nckrtl/project-ai-cli-proxy configuration project-ai-cli-proxy
```

Schedules use UTC: routine "0 3 * * 1", detection "0 4 * * *". Pause a schedule
while reconciling active work:

```bash
swamp workflow schedule pause @nckrtl/project-ai-cli-proxy/detect --reason "Reconcile active work" --server wss://swamp.orbit
```

Deploy with the private orbit-swamp bundle after review and merge. Registry
publication needs the orchestrator's approval. See docs/project-extensions.md
for task admission, issue records, skip reasons and live acceptance.
