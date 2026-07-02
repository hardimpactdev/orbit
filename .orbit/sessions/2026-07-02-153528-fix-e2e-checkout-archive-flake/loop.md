# Orbit Current Slice State

## Feature Context

- Scratchpad: solo://proj/4/scratchpad/orbit-loop-review-20--223 (flake tracked in both Status sections; hit 2 of 3 full quality-check runs on 2026-07-02)
- Worktree: /Users/nckrtl/orbit/.worktrees/fix-e2e-checkout-archive-flake
- Branch: fix-e2e-checkout-archive-flake
- Completed slices:
  - loop-plumbing-hardening (f36df928), loop-hardening-followups (623fd844), release-candidate-helper (e4c890e1): loop tooling hardened, verified on main.
- Current slice: Root-cause and fix the E2ECurrentCheckoutTest "reuses the shared checkout archive after flushing in-process checkout state" parallel-run flake ("Failed asserting that 2 is identical to 1").

## Done Contract

- Single-slice: yes - one flaky test, one root cause to find and fix.
- Parallelization: serial - single investigation/fix lane; reproduction and stress verification share the same test state.
- Done when:
  - The race is reproduced and the root cause is identified with evidence (not guessed).
  - The fix lands at the correct layer: test hermeticity if the cache key reads non-hermetic global state during tests, or a real concurrency fix in E2ECurrentCheckout if the product support code races with itself.
  - Stress proof: the previously-flaky conditions pass repeatedly (at least 4 consecutive full parallel gateway Pest runs green, given the pre-fix failure rate of ~2 in 3).
  - composer quality-check green; merged to main and pushed.
- Evidence:
  - Reproduction evidence, root-cause notes, and stress-run results under .orbit/evidence/flake-*.txt.
- Reviewer checks:
  - Adversarial review of the diff: fix addresses the identified cause (not just retries/sleeps), no weakening of the test's contract (archive reuse must still be genuinely asserted).
- Stop if:
  - The root cause turns out to be in production topology behavior that needs retained topology proof — report before widening scope.
- Pivot if:
  - Reproduction shows a different failing mechanism than hypothesized — follow the evidence, not the hypothesis.

## Progress

- Tried: worktree prepared via bin/orbit-prepare-worktree (--skip-tests).
  Result: WORKTREE_PREPARED base_ref=main.
- Tried: investigation lane — read the full cache-key path, built a standalone tree-hash probe, a watch probe during a real parallel suite run, and a touch/rm mutator reproducer.
  Result: root cause proven — treeHash()/archiveManifest() shell_exec git ls-files + hash_file over the LIVE shared tree escapes Process::fake; the in-suite tree-hash sensitivity test writes tmp-e2e-tree-hash-*/alpha.txt at repo root and flips the key mid-test (two HASH FLIP events captured during one real suite run); pre-fix mutator reproducer failed 4/10 with the exact signature.
- Tried: fix — pin the hash via E2ECurrentCheckout::useTreeHashResolverForTests in the flaky test (5-line diff, mirrors sibling test at :443); reuse assertions unchanged.
  Result: mutator loop 10/10; full test file 31/31; full parallel suite green.
- Tried: stress lane — reproducer 10x, full parallel gateway suite 4x consecutive, focused 5x.
  Result: 0 failures across 16,184 suite test executions + 15 targeted runs (pre-fix baseline ~2 of 3 suite runs failed). Adversarial reviewer: zero blockers, two suggestions (sibling-coverage note; pre-existing cross-filesystem rename caveat).
  Next: quality-check, distillation, gate, commit, merge, archive, push.

## Candidate Signals While Working

- 2026-07-02 investigation: tests that write fixtures at the repo root (tmp-e2e-tree-hash-*) mutate global state other tests key on — the sensitivity test itself was the mutator. Residual risk is low now (the only reuse-dependent test pins its hash), but repo-root fixture writes are a latent interference class.
- 2026-07-02 reviewer: buildArchive() publishes via rename() from sys temp into the cache dir — non-atomic across filesystems if ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR is on another mount. Pre-existing, out of scope.

## Blockers

- none

## Evidence Links

- Failure artifacts: quality-check-2026-07-02T120354Z (loop-hardening-followups archive) and the first run of the release-candidate-helper slice — both "Failed asserting that 2 is identical to 1" at E2ECurrentCheckoutTest.php:1245.
- Session archive: .orbit/sessions/2026-07-02-153528-fix-e2e-checkout-archive-flake

## Harness Signals

- Searched: harness-signals/index.json — no existing record for parallel-Pest shared-state flakes; scratchpad 223 tracks this as a known follow-up.
- Created or updated: pending final distillation.
- Deferred follow-up: pending final distillation.

## Final Distillation

- Loop outcome:
  - complete
- Required verification:
  - Retained topology proof: not applicable - one gateway test file changed (test hermeticity fix); no topology, VM, CLI, or production support-code behavior touched.
  - `composer quality-check`: passed - exit 0 from this worktree post-fix (artifact in .orbit/quality-gates/); additionally 4 consecutive standalone full parallel gateway Pest runs green as stress proof.
- Finalization gate fit:
  - Non-docs diff (one PHP test file) requires quality-check evidence — present and passing.
- Distillation packet:
  - Location: `.orbit/loop.md`
- Fresh analyzer:
  - deferred - single-file test fix with a proven root cause, a pre/post reproducer (4/10 fail -> 10/10 pass), 16k-execution stress proof, and a zero-blocker adversarial review; analyzer would add no marginal risk coverage.
- Candidate signals:
  - repo-root fixture writes as cross-test interference class -> defer (single occurrence class now mitigated; promote only if another tree-state-dependent test flakes).
  - non-hermetic shell_exec escaping Process::fake -> already-covered in-slice (the existing useTreeHashResolverForTests seam exists precisely for this; the flaky test simply had not adopted it).
- Accepted durable updates:
  - The flaky test now pins its tree hash like its sibling; stress + reproducer evidence archived under .orbit/evidence/flake-*.
- Rejected or already-covered signals:
  - No new guardrail rule: the hermeticity seam already existed and sibling tests model correct usage.
- Deferred follow-ups:
  - Move repo-root test fixtures (tmp-e2e-tree-hash-*) under an ignored temp path if another interference case appears — owner: next E2E-support touchpoint.
  - Cross-filesystem rename() caveat in buildArchive() — owner: next E2ECurrentCheckout change; add a code note.
- No-new-signal rationale:
  - The defect was one test not using an existing hermeticity seam; the fix itself plus archived evidence is the durable record, and the interference class is watched via the deferred follow-up.
