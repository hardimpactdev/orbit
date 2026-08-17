# Solo 754 retained-Incus proof plan

Candidate: `3761c3ec015a642c6d0efa885bfe847eb6ea51a6`

This proof is pending. Do not record runtime as passed until one Solo terminal
captures the complete candidate-bound receipt and transcript.

1. From this exact worktree, run `bin/orbit-secret-scan`. Reject every
   nonignored untracked file before source sync.
2. Do not invoke any `composer test:e2e*` command. Acquire the smallest
   source-mounted topology that exercises the operator CLI, gateway SQLite/API,
   and one app-serving node:
   `composer e2e:incus -- --start --topology=operator_gateway_app-dev --checkout-roles=operator,gateway,dev`.
   Reuse an existing compatible retained topology only after syncing it with
   `composer e2e:incus -- --sync --id=<id>` from this worktree.
3. Open one Solo terminal in the retained operator VM. Run all Orbit commands
   from `/home/orbit/orbit-run`. Capture `pwd`, `git rev-parse HEAD`,
   `readlink /usr/local/bin/orbit`, and `orbit --version`. The HEAD must equal
   the candidate above and the launcher must execute
   `/home/orbit/orbit-run/apps/cli/orbit`.
4. Through normal retained-topology workflows, create one app with a concrete
   primary Instance and one Workspace under that same Instance. Enable public
   analytics and public WebSocket hosts for the Instance. Record the Instance,
   App, Workspace, and serving Node IDs before reading route rows.
5. Capture the exact Gateway rows for these four route families:
   primary App (`owner_type=app`), Workspace (`owner_type=workspace`), public
   analytics (`owner_type=app-analytics`), and public WebSocket
   (`owner_type=app-websocket`). Prove every row stores the same positive
   `instance_id`. Also prove the App and serving Node derive from that Instance,
   and the Workspace row stores the matching `workspace_id`. Re-run each normal
   convergence action and prove idempotency: one row per domain and unchanged
   `instance_id` values.
6. Restart the retained Gateway service. Re-read all four rows and prove the
   same `instance_id`, App, Workspace, and serving Node identity survived the
   stop-restart boundary.
7. In the Solo terminal, capture `orbit proxy:list --filter=instance --json`.
   Confirm the route projects `owner.type=instance`, the concrete Instance
   identity, and the derived serving target. Capture the human list output too.
   Confirm `--filter=app` fails with the documented invalid-filter error.
8. Exercise one fail-closed case in an isolated gateway fixture: remove or
   soft-delete the persisted Instance without substituting another Instance.
   Confirm query/probe output reports the missing owner and does not select a
   same-App or same-domain candidate. Restore or recreate the isolated fixture
   before closing the terminal.
9. Copy the Gateway SQLite database to a disposable file. Bind the isolated
   Gateway process to that copy only. Create a pre-migration fixture for the
   primary App, Workspace, public analytics, and public WebSocket families with
   convergent legacy identity evidence. Run exactly
   `2026_08_16_231522_persist_proxy_route_instance_ownership.php` forward and
   prove that it adds and fills the positive `instance_id` for all four rows
   without changing owner type, App, Workspace, domain, or Node. Add one
   ambiguous or malformed legacy fixture and prove the migration stops before
   schema mutation with an actionable error instead of guessing an Instance.
10. On a disposable post-migration copy, roll back exactly
    `2026_08_16_231522_persist_proxy_route_instance_ownership.php`. Prove each
    of the four rows preserves its validated positive Instance identity in
    `config.instance_id` before the column is removed. Re-apply the same
    migration and prove all four `instance_id` values are restored exactly,
    with owner type, App, Workspace, domain, and Node unchanged. Reject the
    proof if either direction guesses an owner or mutates a non-Instance
    family. Do not run migration or rollback experiments against the retained
    Gateway database.
11. Record a six-surface proof matrix in the transcript: primary App,
    Workspace, public analytics, public WebSocket, forward migration, and
    rollback/re-apply. Each row must name its fixture or route row IDs, expected
    Instance ID, observed Instance ID or preserved config hint, command, and
    pass/fail result. A missing or failed matrix row keeps runtime proof pending.
12. Store the transcript and one structured receipt below `.orbit/evidence/`.
   The receipt must bind `candidate`, `git_tree_clean=true`, topology ID,
   terminal identity, the four family row IDs and Instance IDs, restart result,
   forward-migration result, rollback/up restoration result, all six matrix
   results, and every artifact path. Update
   `.orbit/loop.md` on one line with:
   `candidate=3761c3ec015a642c6d0efa885bfe847eb6ea51a6; venue=retained-incus; environment=dev-fixture; command=orbit proxy:list --filter=instance --json; expected=primary app, Workspace, public analytics, and public WebSocket rows persist one exact Instance through restart and rollback/up with no fallback; observed=<concise result>; result=passed; evidence=.orbit/evidence/<exact-regular-file>`.
13. Reap a topology acquired only for this proof with
   `composer e2e:incus -- --stop --id=<id>`. Do not run any
   `composer test:e2e*` command.
