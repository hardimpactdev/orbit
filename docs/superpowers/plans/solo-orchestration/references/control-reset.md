# Control Reset

Use only for corrupt, duplicated, missing, or unreadable coordination state.

1. Delete coordination todos tagged both `solo-orchestration` and `supervision`.
2. Create one coordination todo:
   - title: `SUP-1 Coordinate Solo porting loop`
   - priority: `high`
   - tags: `solo-orchestration`, `supervision`, `pipeline`
3. Create `run_id` as `<YYYY-MM-DD>-current-<coordination_todo_id>`.
4. Update `control-config.md` with the new `run_id` and coordination todo id.
