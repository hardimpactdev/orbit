# Targeted closure review: D1 and D2

verdict: PASS

HUMAN_JUDGMENT: not-required — both closures are mechanically decidable and I
verified each independently rather than reading intent: a full SQLite parity table
for D1, a byte-exact reproduction of the recorded tree digest for D2, and a computed
set difference between the proof's exclusion rules and `E2ECurrentCheckout`. The D2
contract question that made my previous review require judgment (whitelist vs whole
tree) has been decided and implemented. Everything left is POLISH.

Inspected only `4c5c15c15d0e3d1ff9af0e219beb2c1e4f307792..7affbfd740f7daad9a77bdf93950cd9a9894523b`
(5 files, +439/-109) plus the focused sync code needed to decide closure. Did not
rerun Pest, Mago, quality-check, or the retained proof.

## D1 — CLOSED

`constrainUbuntu()` now emits `platform = 'ubuntu' OR platform glob 'ubuntu_*'`.
SQLite `GLOB` is case-sensitive and treats `_` as a literal, so the prefix rule is
enforced exactly and any suffix text passes through. Verified the emitted predicate
against sqlite over the full dataset plus the cases that broke the previous
candidate; every row matches `isUbuntu()`:

| platform | `isUbuntu()` | SQL | | platform | `isUbuntu()` | SQL |
| --- | --- | --- | --- | --- | --- | --- |
| `ubuntu` | true | true | | `ubuntu-24-04` | false | false |
| `ubuntu_24-04` | true | true | | `ubuntufoo` | false | false |
| `ubuntu_24-04-LTS` | **true** | **true** | | `xubuntu_24` | false | false |
| `ubuntu_Noble` | **true** | **true** | | `debian_12` | false | false |
| `ubuntu_` | true | true | | `Ubuntu_24-04` | false | false |
| `ubuntu_a*b` | true | true | | `UBUNTU_24-04` | false | false |
| `''` | false | false | | `Ubuntu` / `UBUNTU` | false | false |
| `NULL` | false | false | | | | |

The `ubuntu_a*b` row matters: GLOB metacharacters are only special in the pattern,
which is a fixed literal, so a stored value containing `*` cannot widen the match.
`NULL glob …` yields NULL, so null platforms stay ineligible on both sides.

The dropped `where(...)->orWhereRaw(...)` grouping closure reintroduces a bare
`orWhere` at the top of the builder, but both production callers still wrap the call
in their own closure — `FirewallRuleIntent.php:226` and `FirewallRuleQuery.php:119` —
so the disjunction stays contained and cannot leak past the status/role predicates.

`FirewallTargetPlatformTest` gains `ubuntu_24-04-LTS` and `ubuntu_Noble`, the exact
lowercase-prefix/uppercase-suffix shape the previous dataset could not reach, and it
asserts PHP and SQL together per case. The docblock now states the rule and the
dialect it depends on.

## D2 — CLOSED

Both halves of the defect are gone, and the replacement is stronger than the
requirement rather than equal to it.

**Dead nullable HEAD leg removed.** `observe_remote_head()`, the `remote_head` input,
and the `is_string(...) && !== ''` skip are deleted; no reference survives outside the
test that now asserts `$bound` carries no `remote_head` key. The test whose name
contradicted its behavior is renamed to what it proves.

**Whitelist replaced by a whole-tree comparison.** `firewall_proof_checkout_paths()`
is gone. The rig now enumerates `git ls-files --cached` at the candidate root, applies
its exclusion rules, and hashes every surviving file. I reproduced this locally at the
candidate with a clean tree: **5,111 tracked files**, tree digest
`034a408b1c0cdd6518c9ed142c307f9753be819555813c5110dcba028607ffcb` — byte-identical to
the `checkout_digest` in both the evidence JSON and the receipt string. The three
surfaces I named as uncovered in D2 are all in the set now
(`FirewallAllowCommand.php`, `StoreFirewallRuleRequest.php`,
`FirewallRuleStoreController.php`), as is `NodeRoleAssignments.php`. No `vendor/`,
`.env`, `.orbit/`, `node_modules/`, or `database.sqlite` leaks in.

**Fail-closed on missing, extra, and mismatched.** The remote helper reads the
expected path list over stdin, emits `H\t<path>\t<sha256>` only for paths that are
actually files, then walks the remote tree under the same exclusion rules and emits
`E\t<path>` for anything unexpected. `firewall_proof_assert_synced_trees()` throws on
a non-empty `array_diff_key` in either direction and on any digest inequality — so a
file that did not transfer is missing, a leftover from a prior candidate is extra, and
a divergent byte is a mismatch. `firewall_proof_include_unexplained_extras()` drops
only extras that exist locally, which is correct: those are the untracked-but-shipped
files the E2E archive carries with `--others --exclude-standard`.

**Path choice is sound.** The rig verifies `/home/orbit/orbit`, while the scenario
executes from `/home/orbit/orbit-run`. Those are not the same path, but they are the
same bytes at bind time: `orbit-run` is an overlay whose lowerdir is
`E2ECurrentCheckout::SourceMountedGuestPath` = `/home/orbit/orbit`, and every `--sync`
routes through `refreshRuntimeCheckouts()` → `mountFreshRuntimeCheckout()` →
`sourceMountedRuntimeOverlayCommand()`, whose prepare step is `rm -rf "$upper" "$work"`
(the upperdir-preserving `…RestoreCommand()` variant is only used on the unmount-failure
rollback path). The forced sync therefore empties the overlay before binding, so
nothing can shadow the verified tree. This is the one place where the closure depends
on sync internals rather than on the rig, and it holds.

**Exclusion drift challenged and quantified.** I computed the set difference between
`firewall_proof_synced_exclude_patterns()` and
`E2ECurrentCheckout::archiveExcludePatterns()`. The proof's list is a strict superset:
zero E2E-only patterns, six proof-only ones
(`vendor`, `apps/e2e/vendor`, `packages/core/vendor`, `packages/sdk/vendor`,
`.orbit-e2e-source-sync.lock`, `tmp-e2e-tree-hash-*`). Applying the E2E rules to the
proof's 5,111 digested paths yields **0** files the archive would not ship, so no
tracked file is currently digested that cannot arrive. The proof-only vendor patterns
are load-bearing on the remote side: guest vendor trees are installed from archives,
not shipped, and without those patterns the extras walk would flag them.

Drift direction is also safe by construction. A pattern added to `archiveExcludePatterns()`
but not to the proof makes a digested file arrive missing → fail closed (a spurious FIX,
never a false PASS). A pattern added to the proof but not to E2E makes the file a remote
extra that exists locally → explained and ignored. Neither direction can manufacture a pass.

**Empty-list false pass challenged.** There is no explicit floor: with an empty local
list, `assert_synced_trees([], [])` would pass and `firewall_proof_tree_digest([])`
returns `e3b0c442…`, which satisfies `require_digest()`. It is not reachable in the
real flow — `local_firewall_proof_candidate()` runs first and aborts unless
`git rev-parse HEAD` returns the candidate and `git status --porcelain` is empty in the
same root, so `git ls-files --cached` in that root cannot come back empty — and this run
empirically carried 5,111 paths. Recorded as P2 rather than a defect because the gap is
guarded upstream, not in the comparison itself.

**Binding recorded and enforced.** `binding: "synced-tracked-tree"` appears in the state
file, the evidence JSON, and the receipt string alongside `checkout_digest=`.
`firewall_proof_validate_state()` now rejects any state missing that exact binding, so a
retained state file written by the 14-file era fails closed instead of being reused.

## New POLISH

### P1. `firewall-doctor.md` still describes the old binding

Lines 124–126 say the rig "verifies a fail-closed checkout digest of the
firewall/Doctor/public-SSH/proof surface on the remote runtime overlay." Both halves are
now wrong: the surface is the whole tracked tree, and the verified path is the sync
transport source `/home/orbit/orbit`, not `orbit-run`. The new `synced-tracked-tree`
binding token that the receipt emits appears nowhere in the docs. No docs file changed in
this delta. Flagging it explicitly because `CLAUDE.md` makes `apps/docs/content/`
describe correct behavior a merge condition, not a nicety — the brief classifies docs
clarity as POLISH, so it does not block this closure, but it should land before merge.

### P2. No floor on the synced-path list

Add a minimum-count or known-path assertion in `firewall_proof_local_synced_files()` so
the tree comparison cannot silently degrade to a no-op if `git ls-files` ever returns
nothing. `firewall_proof_list_synced_paths()` swallows git failure via `2>/dev/null` and
`(string) shell_exec(...)`, so the degradation would be silent rather than loud. The
helper test asserts three known paths are present, but only at test time; the rig has no
runtime equivalent.

### P3. Explained extras are matched by existence, not content

An extra whose path exists locally is accepted without hashing, so an untracked file
that is stale on the guest passes unnoticed. Consistent with a binding named
`synced-tracked-tree` — untracked files are not part of commit identity — and the safe
choice given untracked local content is not candidate-bound. Worth one sentence in the
docblock so the tolerance is deliberate rather than incidental.

### P4. Remote root is not trimmed

The remote helper computes relative paths as `substr($pathname, strlen($root) + 1)` and
only checks `is_dir($root)`. A trailing slash on `ORBIT_PROOF_ROOT` would shift every
relative path by one character and fail everything closed. `FIREWALL_PROOF_TRANSPORT_CHECKOUT`
has no trailing slash, so it is latent; an `rtrim($root, '/')` removes the footgun.

### P5. Unguarded cleanup in the extras fixture

`7affbfd74` replaced `@unlink`/`@rmdir` with bare calls to satisfy Mago lint. The
`finally` block now runs unguarded, so a failure before the fixture is fully created
raises warnings from the cleanup and can mask the original failure. Creating the fixture
before the `try` (or checking existence in `finally`) keeps lint happy without that.

Earlier POLISH items P1–P8 from the previous review are untouched by this delta and
still stand — notably the `(v6)` blindness in the UFW parser, which still leaves the
seeded v6 deny on the shared topology after `--cleanup`.

## Evidence

- `.orbit/quality-gates/quality-check-2026-08-23T103658Z-ca1a143c5437.json` — exit 0,
  all 46 subgates 0, `commit=7affbfd74…`, `dirty=false`. Subgate key set is identical to
  the previous candidate's receipt, so no lane was dropped.
- `.orbit/evidence/firewall-retained-proof/7affbfd74….json` — `result=passed`,
  `binding=synced-tracked-tree`, `checkout_digest=034a408b1c0c…` (independently
  reproduced), `host=beast`, `target=dev-501dc2`, owned instance triple.
- `.orbit/workers/handoff/impl-firewall-7affbfd74….md`.
- Worktree HEAD is `7affbfd74…` with a clean `git status --porcelain --untracked-files=all`.

## Blast radius

D1's production blast radius is now closed in the correct direction: Doctor probe,
family resolution, adopt policy, and both SQL target-resolution queries agree on one
rule for every input I tested, so the split where Doctor could adopt firewall rules onto
a node the mutation surface refuses is gone. The one new dependency is dialect: the rule
is now SQLite-specific (`GLOB`), where the previous `LIKE … ESCAPE` was portable. The
gateway is SQLite by contract and `DB_CONNECTION` defaults to sqlite, so this is a
narrowing of an already-narrow surface, not a live risk — but a non-SQLite gateway would
now raise a SQL error instead of matching differently, which is at least the loud failure.

D2's changes are entirely proof-rig-local and move identity strictly outward: 14 files to
5,111, with fail-closed missing/extra/mismatch handling and a binding token recorded in
the evidence. No production code path is affected. The remaining risk is a spurious
failure if `E2ECurrentCheckout` exclusions drift ahead of the proof's copy, which costs a
rerun, not a false pass.

candidate=7affbfd740f7daad9a77bdf93950cd9a9894523b
