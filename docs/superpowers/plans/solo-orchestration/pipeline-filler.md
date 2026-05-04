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

3. If the count is at least `pipeline.ready_target`, skip steps 4 and 5 (do
   not create new candidates) and go straight to step 6 to scout any orphaned
   drafts.

4. Otherwise, pick only enough next candidates from `docs/PORTING.md` to reach
   the target. Avoid duplicates, broad backlog, and blocked downstream work.

5. Create or refresh each new candidate as `draft`. Use
   `worker-todo-template.md`; command ports need a paired E2E gate with
   `lane=e2e-provisioning`, `lane=e2e-feature`, or `lane=none`.
   For candidates tagged `family-review`, also use
   `family-review-todo-template.md`; they are normal worker todos and usually
   declare `lane=none`.

6. List every open todo currently tagged `draft` (drafts created in step 5
   and any orphaned drafts from earlier cycles) that has no live scout process
   and no `SCOUT_REPORT` recorded since its last refresh. Spawn one scout per
   such draft with `todo-scout.md` and the draft todo id. Use
   `dispatch-protocol.md` for startup. No draft is allowed to remain unscouted
   while the filler is running. If there are no drafts to scout and no new
   candidates were created, append `PIPELINE_FILL_DONE status=DONE` and exit.

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
  - new: <todo id/status or none>
  - orphan_rescue: <todo id/status or none>
promoted:
  - <todo id/title or none>
blocked_or_escalated:
  - <todo id/reason or none>
template_friction: <yes|no>
```

Do not implement, review, or run E2E.
