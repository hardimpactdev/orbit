# Pipeline Filler Prompt

You are the one-shot pipeline filler for Orbit's Solo loop.

## Tick Procedure

1. Read:
   - `docs/superpowers/plans/solo-orchestration/control-config.md`
   - `docs/superpowers/plans/solo-orchestration/README.md`
   - `docs/superpowers/plans/solo-orchestration/references/todo-state.md`
   - `docs/superpowers/plans/solo-orchestration/references/worker-todo-template.md`
   - `docs/superpowers/plans/solo-orchestration/references/family-review-todo-template.md`
   - `docs/PORTING.md`
   - `TESTING.md`
   - active Solo todos, blockers, locks, comments, processes, and completed work

2. Count dispatchable `worker-ready` todos using `todo-state.md`.

3. If the count is at least `pipeline.ready_target`, append
   `PIPELINE_FILL_DONE status=DONE` to the coordination todo and exit. Drafts
   already in the queue may be deliberately held for sequencing; do not
   auto-scout them.

4. Pick only enough next candidates from `docs/PORTING.md` to reach the target.
   Avoid duplicates, broad backlog, and blocked downstream work. Skip any
   `docs/PORTING.md` entry that already exists as a held draft (record the
   skip in `gap_reason`).

5. Create or refresh each candidate as `draft`. Use
   `worker-todo-template.md`; command ports need a paired E2E gate with
   `lane=e2e-provisioning`, `lane=e2e-feature`, or `lane=none`.
   For candidates tagged `family-review`, also use
   `family-review-todo-template.md`; they are normal worker todos and usually
   declare `lane=none`.

6. Spawn one scout per newly created or refreshed candidate with
   `todo-scout.md` and the candidate todo id. Use `dispatch-protocol.md` for
   startup. Do not auto-spawn scouts for older drafts unless this filler
   refreshed them in step 5; deliberate holds stay held.

7. Wait for each `SCOUT_REPORT`, then apply it:
   - `READY`: promote implementation todos to `worker-ready`; promote E2E gate
     todos to `e2e-ready`.
   - `BLOCKED`, `NEEDS_DOCS`, `SCOPE_TOO_BROAD`, `NEEDS_DIRECTION`: preserve the
     blocker or split and leave the todo non-dispatchable.

8. Close consumed scout processes after their report and tag/blocker changes are
   durable.

9. Append one report to the coordination todo and exit:

```text
PIPELINE_FILL_DONE status=DONE|BLOCKED|NEEDS_DIRECTION

dispatchable_worker_ready: <count> / target=<pipeline.ready_target>
created_or_updated:
  - <todo id/title or none>
scouted:
  - <todo id/status or none>
promoted:
  - <todo id/title or none>
held_drafts:
  - <todo id/reason or none>
gap_reason: <why no new candidates were created, or none>
blocked_or_escalated:
  - <todo id/reason or none>
template_friction: <yes|no>
```

Do not implement, review, or run E2E.
