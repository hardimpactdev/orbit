# nckrtl.com

Private maintenance config and schedules for Orbit Project 20. Routine runs
weekly on Monday at 02:00 UTC; detection runs daily at 03:00 UTC. Both call the
shared Gateway client and the official software factory. Composer and JavaScript
work runs through their shared extensions on the task group's worker.

The config is in `mod.ts`. It asserts `composer check`, branch `main`, both
package managers at `.`, and the repository's seven-day release-age policy. The
tracked script path remains part of the existing config identity; workers do not
execute it. No maintenance package is needed by this extension.

Delivered with the private orbit-swamp deployment bundle. Do not publish to the
registry without the orchestrator's approval. See `docs/project-extensions.md`
for admission, schedule controls, evidence and rollout.

Read the config without starting a task:

```bash
swamp behavior run @nckrtl/project-nckrtl-com configuration project-nckrtl-com
```

The two namespaced workflows can be paused on the server during reconciliation:

```bash
swamp workflow schedule pause @nckrtl/project-nckrtl-com/routine --reason "Reconcile active work" --server wss://swamp.orbit
swamp workflow schedule pause @nckrtl/project-nckrtl-com/detect --reason "Reconcile active work" --server wss://swamp.orbit
```

Resume them with the matching `swamp workflow schedule resume` command after
reconciliation. Use the operator's stored server credentials; never copy secrets
into this package or a command argument.
