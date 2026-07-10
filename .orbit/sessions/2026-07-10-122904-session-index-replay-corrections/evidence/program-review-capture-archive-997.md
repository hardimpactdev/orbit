CHECKOUT_PROOF: /Users/nckrtl/orbit | main | 1f08ce59f9b8b4df8605dfdcd2cf15245d26303d | main...origin/main [ahead 17]; preserved modified .orbit/sessions/index.json, untracked session archives, and unrelated docs/superpowers plan

# Findings

P0: none.

## P1 - Archive refresh follows a destination symlink and deletes the target contents

Location: `bin/orbit-session-archive:94-102,159-178,355-385`

`resolveArchiveDirectory()` validates only the basename. `directoryHasEntries()`, `archiveLoopMatchesActive()`, and `clearDirectoryEntries()` then follow an `--archive-dir` symlink. If the linked directory contains a `loop.md` identical to the active loop, refresh mode recursively clears that external target before copying the new archive.

Independent reproduction on current `main`: a validly named `/tmp/.../2026-07-10-120000-symlink-refresh` symlink pointed at a victim directory containing identical `loop.md` plus `sentinel.txt`. `bin/orbit-session-archive --archive-dir=<symlink>` printed `mode: refreshed`, exited 0, kept the symlink, and reported `sentinel=deleted`; the victim was replaced with archive files.

Impact: an archive-root symlink or explicit destination symlink can delete unrelated user data outside `.orbit/sessions`. This is not limited to copying unintended evidence.

Smallest fix: reject a symlink at the archive destination and archive root; canonicalize the owned archive root; require generated/default destinations to be direct children of that root; and perform refresh through a canonical sibling temp/backup swap rather than traversing the current destination.

Attribution: pre-existing archive-refresh defect, not introduced by the named commits. Commit `b6832b747` added source-side directory-symlink guards but did not cover the destructive destination boundary, so the merged integrity hardening remains incomplete on current `main`.

## P2 - Refresh destroys the previous coherent archive before the replacement is complete

Location: `bin/orbit-session-archive:94-102,366-385,391-483`

Refresh mode calls `clearDirectoryEntries($archiveDir)` before any source copy succeeds. A normal permission, disk, pathname, or copy failure therefore destroys the only coherent archive and leaves a partial directory.

Independent reproduction on current `main`: an existing archive contained identical `loop.md` and `sentinel.txt`; the active source also contained an unreadable `evidence/fail.txt`. Refresh deleted the sentinel, copied `loop.md`, failed copying `fail.txt`, exited 2, and left only a partial archive (`loop.md` plus an empty `evidence/` directory).

Impact: the idempotent refresh contract can lose durable session evidence during an ordinary partial failure. Capture staging is atomic, but archive refresh is not.

Smallest fix: build the complete replacement in a unique sibling directory, validate required output, then use the same final-to-backup/temp-to-final/rollback pattern as capture staging. Refresh must never clear the current final before the new tree is complete.

Attribution: pre-existing behavior from the earlier archive helper, not introduced by the reviewed commits. It is nevertheless a current compatibility defect in the archive helper explicitly included in this review.

## P2 - Claude and Grok exact-marker capture is not bound to cwd or primary actor identity

Location: `bin/orbit-agent-session-capture:1000-1019,1048-1070,1102-1129`

Both provider functions receive `$cwd` but never use it. They accept the sole user-message marker anywhere in all provider sessions. Unlike the Codex path, they do not classify candidates by provider-owned cwd or standalone primary Solo identity before cardinality.

Independent reproduction on current `main`: a Solo row for Claude/Grok pointed at `target-worktree`, while the sole provider transcript lived under a foreign session and the Claude user row explicitly carried `cwd=<foreign-worktree>`. Both commands exited 0 and wrote `status: ok`; the Claude manifest named the foreign JSONL and the Grok manifest named the foreign session directory as the artifact.

Impact: a historical/reused marker or parent transcript can be retained as healthy evidence for the wrong lane. Finalization consumers see `status: ok`, so this can satisfy the capture gate with false provenance.

Smallest fix: add provider-specific ownership extraction before selection. Claude should require exact normalized row/project cwd and primary prompt identity. Grok should require exact `prompt_context.json`/session-root cwd and primary prompt identity. Missing ownership must be partial/failure, never healthy singleton selection. Add wrong-cwd and inherited-parent cases for both providers.

Attribution: the underlying selection gap pre-dates this range, but `0c4cd0002` changed the shared exact-marker behavior and claimed exact capture ownership while applying ownership classification only to Codex. It is a retained defect in that scoped hardening, not a new regression caused by its Codex changes.

## P2 - Any file named manifest.json suppresses fallback and is reported as validated staging

Location: `bin/orbit-session-archive:121-132,681-719`

`hasStagedCaptures()` returns true for any regular file named `manifest.json`; it does not parse JSON or validate schema, provider/path ownership, slug, process id, or status. The caller then prints `Preferring validated staged captures`, skips fallback extraction, and exits successfully.

Independent reproduction on current `main`: active staging contained `agent-sessions/codex/lane/manifest.json` with literal `not-json`. Archive creation exited 0, copied the malformed file, omitted the fallback aggregate manifest, and printed the validated-staging success message.

Impact: malformed or partial staging can produce false-positive archive success and suppress the only recovery path. A later finalization gate catches some named-lane cases, but that does not make the archive command's success or message truthful.

Smallest fix: validate every candidate manifest before staged precedence. Require parseable JSON and a recognized capture schema whose provider/slug match its path and whose status is recognized; for `status: ok`, require the expected coherent artifact set. Invalid staging should warn and fall back or fail explicitly according to the packet contract.

Attribution: pre-existing boolean manifest detection. `b6832b747` hardened temp-depth and directory-symlink discovery around this function but retained the false validation rule.

## P2 - Archive copy follows file symlinks and materializes external contents

Location: `bin/orbit-session-archive:391-420,432-470`

The new guard skips only entries satisfying `isLink() && isDir()`. A symlink to a file passes `isFile()` and `copy()` follows it, turning external content into a regular archived file.

Independent reproduction on current `main`: `.orbit/evidence/linked-secret.txt` symlinked to an external `/tmp/.../external-secret.txt`. Archive creation exited 0; the archive entry was a regular file containing `secret outside orbit`.

Impact: active evidence or agent-session file symlinks can pull arbitrary readable user files into an archive that may later be committed. This is a confidentiality and ownership-boundary failure.

Smallest fix: reject or skip every symlink before type checks, including file symlinks; use `lstat`/no-follow semantics and reassert source type at copy time. Add root and nested file-symlink tests beside the existing directory-symlink tests.

Attribution: following file symlinks is pre-existing. `b6832b747` introduced explicit no-follow handling and tests for directory symlinks only, leaving this directly adjacent gap in the reviewed hardening.

P3: no additional independently reproduced P3 defect.

# Focused verification

- `bin/orbit-session-index --check` passed before any repository archive was opened: `Session index is up to date.` Archive reads therefore remained permitted.
- Cumulative range inspected: first parent of merge `bee6c9960` through current `HEAD`, plus current source and the named commits `9edb2dbd4`, `7b69e1619`, `15602b850`, `0c4cd0002`, `52e5ea250`, and `b6832b747`.
- Focused non-E2E command passed: `bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/AgentSessionArchiveTest.php tests/Feature/E2ESupport/SessionArchiveTest.php tests/Feature/E2ESupport/SessionIndexTest.php` -> 82 tests, 82 passed, 1168 assertions.
- The green suite does not cover the five reproductions above. Existing cross-provider coverage checks exact numeric markers, while wrong-cwd/primary-ownership coverage is Codex-only. Archive tests cover directory symlinks and successful refresh, but not destination symlinks, refresh copy failure, malformed manifest validation, or file symlinks.
- No `composer test:e2e*` command was run or delegated.

# Archives and evidence assessment

- The relevant indexed archives were inspected only after the freshness gate passed. The final `2026-07-10-105744-capture-evidence-integrity-hardening` archive is `capture_status: partial` because worker 986 and reviewer 990 have explicit failure manifests; the final packet contains matching waivers. That partial status is intentional and is not itself a defect.
- The `051706` and `051955` disambiguation archives are near-duplicates but their `loop.md` files differ in post-merge proof, scratchpad revision, and archive link. This is duplicated storage/ceremony, not incorrect evidence under the present contract.
- The retained reviewer/analyzer evidence accurately proves the earlier capture-root, checked-write/copy, diagnostic, temp-directory, and directory-symlink fixes. It did not exercise archive-destination ownership, atomic archive refresh, cross-provider cwd ownership, manifest schema validation, or file-symlink copying.

# Intentional design and residual risks (not findings)

- Codex ownership is fail-closed: exact normalized cwd plus standalone primary identity selects full owners, full owners outrank partials, and ambiguous owned candidates remain ambiguous. The focused suite passed this behavior.
- Caller-attested incarnation floors are intentionally Codex-only. The implementation skill explicitly requires a fresh Solo process id or waiver for restarted Claude/Grok lanes. The finding above is about ordinary cwd/actor ownership, not a demand to add unsupported timestamp floors.
- Antigravity remains intentionally unsupported as a provider-session format; terminal manifests plus preserved reviewer reports and explicit waivers are the documented boundary.
- Capture-directory construction and replacement now use a direct-child temp, backup, swap, and rollback with checked writes/copies. That code is materially stronger; it does not cure the separate archive-refresh transaction.
- The current Solo `spawned_processes` fallback has heuristic id/pid/name matching without an explicit foreign key to `processes`, and provider source files can mutate between parsing and raw copy. These are credible residual identity/TOCTOU risks, but no additional independent failure was run after the user's stop boundary, so they are not verdict-driving findings here.
- `writeJsonFile()` in the archive helper does not check `file_put_contents()` and the filesystem protections remain path-check based rather than descriptor/no-follow based. These deserve follow-up during remediation, but the reproduced higher-severity failures already define the required changes.

# Overall assessment

The Codex-specific ownership and capture-staging work is substantially improved and the focused tests are green, but current `main` still permits wrong-provider-session success, false validated-staging success, external file capture, destructive destination-symlink traversal, and loss of an existing archive on refresh failure. These are actionable P1/P2 evidence-integrity defects. The passing test suite does not cover them.

VERDICT: changes-required
