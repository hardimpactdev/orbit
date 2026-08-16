# Todo 742 retained-Incus runtime receipt

- Candidate: `6227bbddfdbe34cdbb215c54cfe06a582b8d9887`
- Venue: `retained-incus`
- Environment: `dev-fixture`
- Topology: `dev-b8a852` (`operator_gateway_app-dev`, candidate checkout role `app-dev`)
- Target: `orbit-e2e-dev-b8a852-dev`
- Solo terminal: project 2, process 2422 (`todo-742-runtime-dev-b8a852`)
- Runtime checkout: `/home/orbit/orbit-run`
- Launcher: `/home/orbit/orbit-run/apps/cli/orbit`
- Result: `passed`

## Candidate identity

The retained checkout matched the candidate worktree for the three target-side implementation files:

- `LocalCodexAppConfigAction.php`: `672acf81f0037bb9cea6d67078317696451c2f32aa314c3fc7f70ae931079c46`
- `LocalCodexAppConfigMutation.php`: `3b44ae1c24decfa54f8a12f76b1c349b9813096bee69f4aeebd2f37e440d0481`
- `LocalCodexAppConfigStore.php`: `82252e5e36e2bf65a73b69927f9ef1f0942d2edd9b514848f5f7b9f1178331ec`

The VM launcher resolved to the runtime checkout. The topology was acquired from the clean candidate worktree after `SECRET_SCAN: PASS`.

## Expected

One target-side Codex App config action must serialize a concurrent add and remove, commit coherent JSON without losing either effect, preserve unrelated config, skip replacement for an unchanged merge, release the persistent sibling lock after each action, remove temporary files, and keep apply failure as a success warning.

## Observed

- A controlling process held `config.json.lock` while add and remove started. Both command processes remained live and blocked (`concurrent_waiters=2`) until that lock was released.
- Add exited 0 with `changed=true`. Remove exited 0 with `changed=true` and `removed=true`.
- The final config decoded as JSON. It contained labels `add-me,keep-me`, did not contain `remove-me`, and preserved `unrelated.keep=true`.
- Both concurrent results returned `codex_app.apply_failed` in `success.meta.warnings[]` because the Linux fixture has no `open` executable (`exit_code=127`). The committed config remained coherent.
- A nonblocking exclusive lock succeeded after the concurrent actions (`lock_cleanup_after_concurrency=1`). No `config.json.tmp.*` file remained (`temp_cleanup=1`).
- Repeating the same add exited 0 with `changed=false` and the same apply warning. The config SHA-256 and inode stayed unchanged (`unchanged_inode_and_bytes=1`, inode `32`).
- A final nonblocking exclusive lock succeeded (`lock_cleanup_final=1`).

## Structured receipt

`candidate=6227bbddfdbe34cdbb215c54cfe06a582b8d9887; venue=retained-incus; environment=dev-fixture; target=orbit-e2e-dev-b8a852-dev; expected=serialized concurrent add and remove with coherent config unchanged-file preservation lock and temporary-file cleanup and apply-warning success semantics; observed=both waiters serialized both effects survived JSON and unrelated data stayed coherent unchanged retry preserved inode and bytes locks were available and apply failures returned success warnings; result=passed; evidence=.orbit/evidence/todo-742-codex-atomic-runtime.md`
