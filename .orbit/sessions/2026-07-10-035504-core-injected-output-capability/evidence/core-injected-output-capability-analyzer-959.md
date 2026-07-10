# Core Injected Output Post-Feature Analyzer

CHECKOUT_PROOF: `pwd=/Users/nckrtl/orbit/.worktrees/core-injected-output-capability` | `branch=core-injected-output-capability` | status="2 modified files: `packages/core/src/Progress/LiveRepaintOutput.php`, `packages/core/tests/Progress/LiveRepaintOutputTest.php`"

## Verdict

Loop proper: `blocked-by-missing-evidence`

Guardrail decisions: staged stop `correct-noop`; worker command regrouping/`|| true` `defer`; capture-marker retry `correct-noop`; reviewer aggregate violation `correct-noop`; core defect `correct-noop`; topology-row mismatch `correct-noop` because existing enforcement already covers it.

## Evidence Reviewed

- Orchestrator trail: Solo roadmap scratchpad 276, revision 35; orchestrator process 942.
- Worktree and diff: exactly two modified files. The implementation only removes global `STDOUT` fallback; the test adds the undecorated `BufferedOutput` regression.
- Red proof: worker capture records `resource(2)` was not null, 1 failed test / 1 assertion.
- Worker verification: focused 1/2, core 112/519, CLI 2,175/9,111; core formatting passed and Mago reported only existing diagnostics. Capture manifest is `status: ok`.
- PTY: `.orbit/evidence/core-injected-output-capability/composer-test-pty/summary.txt` records autonomous `composer test` exit 0 in 83.850s, with no timeout or idle timeout. Transcript/chunks contain all five passing test lanes.
- Before evidence: `/Users/nckrtl/orbit/.orbit/sessions/2026-07-10-030353-cli-pest-noninteractive-baseline/evidence/cli-pest-noninteractive-baseline.md` records autonomous exit 1 on 21 renderer failures and identifies global `STDOUT` inheritance.
- Independent review: `.orbit/evidence/core-injected-output-capability-reviewer-958.md` says “No blockers” and `VERDICT: pass`.
- Aggregate gate: `.orbit/quality-gates/quality-check-2026-07-10T012120Z-d55615aefb4b.json` records exit 0 in 94 seconds and every subgate at 0.
- Product authority: `git diff HEAD -- PRODUCT_DECISIONS.md apps/docs/content` is empty; docs-lint passed. Existing docs distinguish interactive TTY repainting from redirected/non-TTY rendering, so the routine fix remains aligned without a decision-ledger entry.

## Findings

- Severity: high
  Type: verification-gap
  Evidence: `.orbit/loop.md` marks retained topology “not applicable.” Current `HARNESS.md` defines non-test PHP outside `apps/docs/` as topology-relevant and requires `passed` retained-topology proof. The executable gate implements that exact classification, so `packages/core/src/Progress/LiveRepaintOutput.php` is included.
  Issue: The local PTY evidence strongly proves the defect and fix, but it cannot be inferred to satisfy the current retained-topology contract. The packet therefore cannot truthfully remain `complete` under current finalization rules.
  Recommendation: Treat the loop as blocked until the required retained-topology evidence exists; do not cross commit/merge completion boundaries on the current `not applicable` row.

No code, review, PTY, quality, or documentation finding remains beyond this verification gap.

## Guardrail Decisions

- Candidate: staged stop plus `CONTINUE` after the first diff
  Classification: correct-noop
  Existing coverage: `implementing-features` already forbids staged-stop-only handoffs.
  Counterfactual: Applying that existing instruction would have removed the unnecessary round trip. Additional prose would be redundant.
- Candidate: worker command regrouping and `|| true` around CLI Pest
  Classification: defer
  Existing coverage: required verification must be run and independently checked; subsequent PTY and quality artifacts provide trustworthy exit-zero corroboration.
  Counterfactual: Separate required commands preserving their exit codes would have avoided manual output inference. One occurrence does not yet establish recurrence or justify another guardrail.
- Candidate: exact capture-marker retry
  Classification: correct-noop
  Existing coverage: the lane-close capture signal and implementation skill require the exact Solo marker. The marker produced an exact-match, healthy capture.
  Counterfactual: The current guardrail is what made capture succeed; there is no new failure mode.
- Candidate: reviewer started orchestrator-owned `composer quality-check`
  Classification: correct-noop
  Existing coverage: reviewer ownership and the explicit prompt were already bounded. The orchestrator cancelled the run and retained the bounded verdict.
  Counterfactual: Following existing scope would have avoided cancellation; more duplicate guidance would not materially improve prevention.
- Candidate: global-STDOUT capability defect
  Classification: correct-noop
  Existing coverage: the new deterministic regression and minimal production correction are the appropriate durable guard.
  Counterfactual: The regression now fails immediately if an injected non-stream output again inherits host TTY capability; harness prose adds no value.
- Candidate: retained-topology row marked not applicable
  Classification: correct-noop
  Existing coverage: HARNESS and the executable merge-boundary gate already reject this shape after the production PHP diff is committed.
  Counterfactual: Applying the existing diff-derived verification rule before recording `complete` would have exposed the missing proof. No new guardrail is warranted.

## Loop Improvements

- None. The blocking correction is required verification under an existing guardrail, not a new durable process rule.

## Packet Gaps

- Required retained-topology proof is absent.
- The fresh-analyzer process/verdict fields still await this report, which is expected and not independently blocking.
- `bin/orbit-feature-finalization-check --lint .orbit/loop.md` passes packet shape, but that lint intentionally does not inspect the branch diff and therefore does not resolve the topology-evidence gap.

`VERDICT: blocked-by-missing-evidence`
