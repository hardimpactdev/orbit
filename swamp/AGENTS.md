# Orbit operations in Swamp

Read the binding [Swamp shape guide](docs/swamp-shape.md) and its linked
[Swamp manual](https://swamp-club.com/manual) pages before writing, porting or
reviewing an operation. They override older repository guidance and conflicting
Recall atoms. Port and review kinds with the
[swamp-port skill](../.claude/skills/swamp-port/SKILL.md) and its
[porting reference](../docs/swamp/porting.md).

The guide owns the execution contract and per-PR checks. Do not copy existing
grandfathered implementations as templates. Use lane 11's native pilot once its
open points are settled. Until then, pause conversions and keep live-breakage
fixes minimal.

Use native workflows, placed one-action steps and Swamp defaults. Use existing
types when they fit. Keep reusable data in Swamp and connect steps through typed
inputs and outputs. Pin extension dependencies. Inspect native reports after a
failure before deciding how to resume.

The Gateway owns state, admission and terminal result projection. It supplies
full non-secret intent and vault key names. Steps do not read intent or report
progress over Gateway HTTP. The agent only observes and stays frozen.

No local documentation grants authority for credentials, DNS, production data
moves or deleting production resources. Follow the current execution plan for
ownership and approval.
