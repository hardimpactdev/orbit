# Orbit Feature Loop

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-fix-gateway-host-token-context`
- Branch: `codex/fix-gateway-host-token-context`

## Goal

`force_remote_host` operation-token minting must match the host CLI verification
payload (cwd + allowlisted environment), so gateway-host Doctor checks no longer
fail with RemoteShell `invalid_token` after the one-use token is correctly left
unconsumed for host verification.

Live failure context (post stage-1 fix still broken):
- Candidate `20260802T152407Z-6b7a657d1` (includes stage-1 ddcdfc82f / 0c11e8e4)
- `orbit doctor --node=gateway --json` and `orbit doctor --node=ingress1 --json`
  fail forced host commands with RemoteShell `invalid_token`
- Forced examples: `internal:wireguard-self-route`, `internal:runtime-backend:probe`,
  `internal:tool:run-script` via `ssh_bootstrap`
- Ordinary gateway-local `internal:app-runtime-containers:probe` succeeds

Earlier candidate `20260802T145313Z-8f54774b5` failed for pre-consumed tokens
(stage-1 fixed consumption; this stage fixes command-context mismatch).

## Scope

- Owned: `apps/gateway/app/Services/RemoteShell/RemoteLocalExecutor.php`,
  `apps/gateway/tests/Unit/Services/RemoteShell/RemoteLocalExecutorTest.php`,
  `apps/docs/content/generated/transitional-ssh-inventory.json` (if marker lines move),
  `.orbit/loop.md`
- Constraints: TDD; smallest production change; focused Pest + Mago format;
  no `composer test:e2e*`; do not put APP_KEY/signing material on SSH command line
  or remote env; preserve unrelated work; commit on this branch
- Out of scope: live deploy/retained-topology proof (Codex owns full-gate/review/
  deploy/live-verify); E2E lanes; broader RemoteHostExecutor env export redesign

## Proof

- Verification:
  - focused: passed
    - red: `bin/orbit-gateway-pest --compact --filter='mints force_remote_host command context matching the host CLI verification payload'` failed before fix — SSH command lacked host-home `cd`, so minted context (cwd null + APP_KEY + ORBIT_CONFIG_PATH) could not match host CLI verification (getcwd home + HOME only)
    - green: same filter + prior force_remote_host pre-auth test + ordinary gateway-local authorize test (3 passed); full `RemoteLocalExecutorTest.php` 47 passed / 305 assertions
    - mago: `bin/orbit-gateway-vendor-bin mago format --check` and `mago analyze app/Services/RemoteShell/RemoteLocalExecutor.php` clean after PHPDoc shape fix
    - inventory: regenerated after PHPDoc-only line shift (call_line 1410 to 1438, marker_line 1409 to 1437); `--check` up to date
  - broader: passed - clean exact quality receipt `.orbit/quality-gates/quality-check-2026-08-02T155303Z-ba6594a76ffb.json` for commit `a081db15eeb52d44393d62478b6868593d533bf0`, exit 0, duration 231s, git dirty false. Earlier full gate on tip `f065ace46806a4c48ff61382be531a2b05f19e3e` failed only gateway_mago_analyze because normalizeForceRemoteHostTransportOptions was typed as a loose string-keyed mixed array and widened downstream option shapes (13 errors); fixed by precise transport-options PHPDoc param/return shape on tip a081db15e (no baseline or suppress).
  - runtime: passed - automated host-boundary command-context simulation in the focused RemoteLocalExecutor regression (minted force_remote_host token context hash matches host CLI verification payload of host-home cwd and HOME-only allowlisted env, with operation token unconsumed and no APP_KEY on the SSH command line); ordinary gateway-local authorize path retained; immediate post-merge live candidate doctor verification remains required
- Blast radius: complete - evidence=force_remote_host mint/normalize path review in RemoteLocalExecutor plus retained ordinary gateway-local authorize/bind_application_key coverage and regenerated transitional SSH inventory for the provisioning-ssh host-dispatch marker; result=change is narrow to force_remote_host token context (host-home cwd, no APP_KEY bind, no invented ORBIT_CONFIG_PATH) without altering agent-push secrets export policy or ordinary gateway-local pre-auth
- Review: passed - independent exact-tip review of `a081db15eeb52d44393d62478b6868593d533bf0` found no findings - human-judgment=not-required
- Reviewed feature tip: a081db15eeb52d44393d62478b6868593d533bf0
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: a081db15eeb52d44393d62478b6868593d533bf0
- Accepted main tip: 6b7a657d14c8c2192b5bd0085ca1f20eb5cb259e

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
