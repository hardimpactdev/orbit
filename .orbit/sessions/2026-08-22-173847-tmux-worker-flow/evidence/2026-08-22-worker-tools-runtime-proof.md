# Worker tools runtime proof (acceptance criterion 9)

Date: 2026-08-22. Machine: nick.local (macOS, tmux 3.6a). Worktree: `/Users/nckrtl/orbit/.worktrees/tmux-worker-flow`, tmux session `feat-tmux-worker-flow`.

## Spawn with the new tool

Command: `bin/orbit-worker-spawn --role=docs --cli=grok --brief=.orbit/workers/briefs/docs-1.md --name=docs-1 --json`

Output (exit 0):

```json
{"id":"docs-1","role":"docs","cli":"grok","command":["grok","--yolo"],"tmux":{"session":"feat-tmux-worker-flow","window":"docs-1","pane_id":"%4","pane_pid":69077},"cwd":"/Users/nckrtl/orbit/.worktrees/tmux-worker-flow","brief":"/Users/nckrtl/orbit/.worktrees/tmux-worker-flow/.orbit/workers/briefs/docs-1.md","status":"spawned","heartbeat_at":"2026-08-22T10:26:13Z","note":"","started_at":"2026-08-22T10:26:13Z","exited_at":null,"provider_ref":null,"handoff":null}
```

The worker (Grok 4.6) accepted the one-line bootstrap and reported through `bin/orbit-worker-heartbeat` within seconds (`bin/orbit-worker-status`: `docs-1 docs grok working 3s yes Starting docs-1: reading spec, inventory, and current harness files`).

## Watch with the new tool

Command: `bin/orbit-worker-watch --ignore=impl-1,impl-2,impl-3 --interval=30 --stale=900 --timeout=7200` (run in the background by the orchestrator; no worker output was read while waiting).

Output (exit 0, 13 minutes later, one JSON line):

```json
{"event":"handoff","id":"docs-1","status":"handoff","note":"done","handoff":"/Users/nckrtl/orbit/.worktrees/tmux-worker-flow/.orbit/workers/handoff/docs-1.md","log":"/Users/nckrtl/orbit/.worktrees/tmux-worker-flow/.orbit/workers/logs/docs-1.log","seen_at":"2026-08-22T10:39:21Z"}
```

The worker registered its report with `bin/orbit-worker-handoff docs-1 <file>` and `bin/orbit-worker-heartbeat docs-1 --status=handoff --note=done`; the handoff file exists at the path above.

## Roster at that moment

```
ID           ROLE     CLI      STATUS     AGE    ALIVE  NOTE
impl-1       impl     grok     handoff    9m     yes    done (owner recorded)
impl-2       impl     grok     handoff    9m     yes    done (owner recorded)
impl-3       impl     grok     handoff    4m     yes    done (owner recorded)
docs-1       docs     grok     handoff    31s    yes    done
```

A follow-up was later routed to impl-3 with `bin/orbit-worker-send impl-3 '<one line>'` and watched with `bin/orbit-worker-watch --ignore=impl-1,impl-2,docs-1`.

## Re-bound to the final candidate `9cb87a48c` (fix rounds 1 and 2 applied)

Command: `bin/orbit-worker-spawn --role=proof --cli=grok --brief=.orbit/workers/briefs/proof-1.md --name=proof-1 --json` (exit 0), using the readiness-polled spawn and the `tmux.socket` field added in the fix rounds:

```json
{"id":"proof-1","role":"proof","cli":"grok","command":["grok","--yolo"],"tmux":{"session":"feat-tmux-worker-flow","window":"proof-1","pane_id":"%7","pane_pid":23018,"socket":null},"cwd":"/Users/nckrtl/orbit/.worktrees/tmux-worker-flow","brief":"/Users/nckrtl/orbit/.worktrees/tmux-worker-flow/.orbit/workers/briefs/proof-1.md","status":"spawned","heartbeat_at":"2026-08-22T11:57:50Z","note":"","started_at":"2026-08-22T11:57:50Z","exited_at":null,"provider_ref":null,"handoff":null}
```

Command: `bin/orbit-worker-watch --ignore=impl-1,impl-2,impl-3,docs-1,review-1,review-2 --interval=15 --stale=600 --timeout=1800` (exit 0, 32 seconds later):

```json
{"event":"handoff","id":"proof-1","status":"handoff","note":"proof done","handoff":"/Users/nckrtl/orbit/.worktrees/tmux-worker-flow/.orbit/workers/handoff/proof-1.md","log":"/Users/nckrtl/orbit/.worktrees/tmux-worker-flow/.orbit/workers/logs/proof-1.log","seen_at":"2026-08-22T11:58:22Z"}
```

The worker's own report (`.orbit/workers/handoff/proof-1.md`) records HEAD `9cb87a48c`, a clean tree, the `bin/orbit-worker-status` roster, and a registry-driven `bin/orbit-agent-session-archive --only-ok` read, all executed inside the spawned window.

## Re-bound to candidate `5e90116c1` (fix round 3 applied)

Command: `bin/orbit-worker-spawn --role=proof --cli=grok --brief=.orbit/workers/briefs/proof-2.md --name=proof-2 --json` (exit 0; `started_at` `2026-08-22T12:33:15Z`, pane `%9`, `tmux.socket` null). Command: `bin/orbit-worker-watch --ignore=impl-1,impl-2,impl-3,docs-1,review-1,review-2,review-3,proof-1 --interval=15 --stale=600 --timeout=1800` (exit 0, 31 seconds later):

```json
{"event":"handoff","id":"proof-2","status":"handoff","note":"proof done","handoff":"/Users/nckrtl/orbit/.worktrees/tmux-worker-flow/.orbit/workers/handoff/proof-2.md","log":"/Users/nckrtl/orbit/.worktrees/tmux-worker-flow/.orbit/workers/logs/proof-2.log","seen_at":"2026-08-22T12:33:46Z"}
```

`.orbit/workers/handoff/proof-2.md` records HEAD `5e90116c1`, the `bin/orbit-worker-status` roster, and a registry-driven `bin/orbit-agent-session-archive --only-ok` read, all executed inside the spawned window.

Hook fail-closed probes at this tip (classification only, payload on stdin of `bin/orbit-codex-pre-tool-use-hook`): quoted `<<` then kill => BLOCKED (unchained rule); `rg -n '<<' bin/ && tmux kill-session ...` => BLOCKED; quoted `<<` + `git merge main` + kill => BLOCKED "exactly one destructive boundary action is allowed; found 2"; unterminated `cat <<'EOF'` then kill => BLOCKED; a true documentation heredoc => silent pass (exit 0); `tmux killw` => BLOCKED "not an allowed LAND boundary".

## Re-bound to candidate `2aeee24a8` (fix round 4: heredoc excision removed, grammar over-blocks by design)

Command: `bin/orbit-worker-spawn --role=proof --cli=grok --brief=.orbit/workers/briefs/proof-3.md --name=proof-3 --json` (exit 0; `started_at` `2026-08-22T13:00:58Z`, pane `%11`). Command: `bin/orbit-worker-watch --ignore=impl-1,impl-2,impl-3,docs-1,review-1,review-2,review-3,review-4,proof-1,proof-2 --interval=15 --stale=600 --timeout=1800` (exit 0, 31 seconds later): `{"event":"handoff","id":"proof-3","status":"handoff","note":"proof done","handoff":"/Users/nckrtl/orbit/.worktrees/tmux-worker-flow/.orbit/workers/handoff/proof-3.md","log":"/Users/nckrtl/orbit/.worktrees/tmux-worker-flow/.orbit/workers/logs/proof-3.log","seen_at":"2026-08-22T13:01:29Z"}`. `.orbit/workers/handoff/proof-3.md` records HEAD `2aeee24a8`.

Hook probes at this tip (classification only, payload on stdin of `bin/orbit-codex-pre-tool-use-hook`), all exit 2 BLOCKED: quoted `<<` then kill; `rg -n '<<' bin/ && kill`; quoted `<<` + `git merge main` + kill ("found 2"); unterminated heredoc then kill; heredoc opener `&&` kill on one line; multi-line quoted string with `<<` then kill; a documentation heredoc containing the cleanup line (over-block by design, message names the file-write-tool workaround); `tmux killw`. Benign `tmux ls` exits 0.

## Re-bound to candidate `4cbcefd0c` (fix round 5: position-agnostic tmux boundary)

Command: `bin/orbit-worker-spawn --role=proof --cli=grok --brief=.orbit/workers/briefs/proof-4.md --name=proof-4 --json` (exit 0; `started_at` `2026-08-22T13:23:30Z`, pane `%13`). Command: `bin/orbit-worker-watch --ignore=impl-1,impl-2,impl-3,docs-1,review-1,review-2,review-3,review-4,review-5,proof-1,proof-2,proof-3 --interval=15 --stale=600 --timeout=1800` (exit 0, 31 seconds later): `{"event":"handoff","id":"proof-4","status":"handoff","note":"proof done","handoff":"/Users/nckrtl/orbit/.worktrees/tmux-worker-flow/.orbit/workers/handoff/proof-4.md","log":"/Users/nckrtl/orbit/.worktrees/tmux-worker-flow/.orbit/workers/logs/proof-4.log","seen_at":"2026-08-22T13:24:01Z"}`. `.orbit/workers/handoff/proof-4.md` records HEAD `4cbcefd0c`.

Hook probes at this tip (classification only, payload on stdin of `bin/orbit-codex-pre-tool-use-hook`), all exit 2 BLOCKED: quoted `<<` then kill; heredoc opener `&&` kill on one line; `echo x | tmux kill-session`; `sleep 1 & tmux kill-session`; `( tmux kill-session )`; `TMUX= tmux kill-session`; `nohup tmux kill-server`; `xargs tmux killw`; `tmux ls | tmux kill-session`; `tmux killw`. Exit 0 (pass): a quoted mention `echo "tmux kill-session -t =feat-x"` and benign `tmux ls`. The owner's own Bash commands whose text merely mentioned these forms (a commit message, an evidence append) were blocked by the gate and had to go through file-write tools, demonstrating the over-block-by-design behavior.

## Re-bound to candidate `1d2da5a08` (fix round 6: identity marker at line start)

Command: `bin/orbit-worker-spawn --role=proof --cli=grok --brief=.orbit/workers/briefs/proof-5.md --name=proof-5 --json` (exit 0; `started_at` `2026-08-22T13:56:06Z`, pane `%15`). Command: `bin/orbit-worker-watch --ignore=impl-1,impl-2,impl-3,docs-1,review-1,review-2,review-3,review-4,review-5,review-6,proof-1,proof-2,proof-3,proof-4 --interval=15 --stale=600 --timeout=1800` (exit 0, 31 seconds later): `{"event":"handoff","id":"proof-5","status":"handoff","note":"proof done","handoff":"/Users/nckrtl/orbit/.worktrees/tmux-worker-flow/.orbit/workers/handoff/proof-5.md","log":"/Users/nckrtl/orbit/.worktrees/tmux-worker-flow/.orbit/workers/logs/proof-5.log","seen_at":"2026-08-22T13:56:37Z"}`. `.orbit/workers/handoff/proof-5.md` records HEAD `1d2da5a08`.

Lane-close capture on this feature's real workers at this tip (owner-run, exit 0 each): `bin/orbit-agent-session-capture docs-1` => `{"status":"ok","provider":"grok","worker_id":"docs-1",...}`; `bin/orbit-agent-session-capture review-1` => `{"status":"ok","provider":"claude","worker_id":"review-1",...}`; `bin/orbit-agent-session-capture impl-2` => `{"status":"ok","provider":"grok","worker_id":"impl-2",...}`.

## Re-bound to candidate `66145e956` (fix round 7: boundary-matched archive joins, registry cli authority)

Command: `bin/orbit-worker-spawn --role=proof --cli=grok --brief=.orbit/workers/briefs/proof-6.md --name=proof-6 --json` (exit 0; `started_at` `2026-08-22T14:22:05Z`, pane `%17`). Command: `bin/orbit-worker-watch --ignore=impl-1,impl-2,impl-3,docs-1,review-1,review-2,review-3,review-4,review-5,review-6,review-7,proof-1,proof-2,proof-3,proof-4,proof-5 --interval=15 --stale=600 --timeout=1800` (exit 0, 46 seconds later): `{"event":"handoff","id":"proof-6","status":"handoff","note":"proof done","handoff":"/Users/nckrtl/orbit/.worktrees/tmux-worker-flow/.orbit/workers/handoff/proof-6.md","log":"/Users/nckrtl/orbit/.worktrees/tmux-worker-flow/.orbit/workers/logs/proof-6.log","seen_at":"2026-08-22T14:22:51Z"}`. `.orbit/workers/handoff/proof-6.md` records HEAD `66145e956`.

Lane-close capture on real workers at this tip (owner-run, exit 0): `bin/orbit-agent-session-capture docs-1` => `status: ok` (grok); `bin/orbit-agent-session-capture review-1` => `status: ok` (claude).

## Re-bound to candidate `02ac571f3` (fix round 8: archive resolves transcripts by primary identity)

Command: `bin/orbit-worker-spawn --role=proof --cli=grok --brief=.orbit/workers/briefs/proof-7.md --name=proof-7 --json` (exit 0; `started_at` `2026-08-22T15:00:47Z`, pane `%19`). Command: `bin/orbit-worker-watch --ignore=impl-1,impl-2,impl-3,docs-1,review-1,review-2,review-3,review-4,review-5,review-6,review-7,review-8,proof-1,proof-2,proof-3,proof-4,proof-5,proof-6 --interval=15 --stale=600 --timeout=1800` (exit 0, 31 seconds later): `{"event":"handoff","id":"proof-7","status":"handoff","note":"proof done","handoff":"/Users/nckrtl/orbit/.worktrees/tmux-worker-flow/.orbit/workers/handoff/proof-7.md","log":"/Users/nckrtl/orbit/.worktrees/tmux-worker-flow/.orbit/workers/logs/proof-7.log","seen_at":"2026-08-22T15:01:18Z"}`. `.orbit/workers/handoff/proof-7.md` records HEAD `02ac571f3`.

Archive resolution on this feature's real registry at this tip (owner-run, no `--marker`): `--worker-id=review-1` => `status ok`, artifact `.../7be4b950-fd6d-4f25-af2e-d241014de40a.jsonl` (the reviewer's own transcript, not the owner's); `impl-1` => `.../01a028ef-dbc2-.../signals.json`, `impl-2` => `.../01a028ef-dbca-.../signals.json`, `impl-3` => `.../01a028ef-dbc7-.../signals.json` (`partial`: its chat history was compacted past the bootstrap line after eight fix rounds, reported honestly rather than misattributed), `docs-1` => `.../01a02901-d51e-.../signals.json` — four distinct grok sessions. Lane-close capture `docs-1`/`review-1` => `status: ok`.
