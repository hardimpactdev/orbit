CHECKOUT_PROOF: /Users/nckrtl/orbit/.worktrees/capture-evidence-integrity-hardening | capture-evidence-integrity-hardening | HEAD b6832b747ab568942b234c84c6ca29e5e2926430; clean; Solo process 991, project 4

## Findings

No findings. No implementation, contract, verification, actor-boundary, evidence, packet, or guardrail correction is required before the feature owner performs the normal post-analyzer packet update and finalization lint.

## Verdict Rationale

Loop proper: yes.

The exact range `f1acfee5de5d74432656c8d46a11e9d1bb5bff54..b6832b747ab568942b234c84c6ca29e5e2926430` contains four ordered commits and only the owned capture helper, archive helper, focused tests, implementation-skill clarification, existing signal record, and its generated index row. The serial choice is justified by shared files and stage dependencies in `.orbit/loop.md:22-26`; the work followed retained red-first Stage 1/2/3 and correction evidence, bounded independent review, root correction, formal re-review, and exact-commit aggregate verification (`.orbit/loop.md:63-80`).

The final implementation satisfies the accepted contracts:

- Stage 1 rejects Claude/Grok incarnation floors before staging (`bin/orbit-agent-session-capture:85-121`) and the skill states the Codex-only/fresh-process-or-waiver rule (`.agents/skills/implementing-features/SKILL.md:569-579`).
- Stage 2 separates exact all-occurrence marker discovery from standalone primary identity (`bin/orbit-agent-session-capture:751-835`), classifies every Codex candidate by exact cwd and identity before cardinality, preserves full-over-partial precedence, and returns loud ambiguity or `no_owned_marker_transcript` with bounded actual diagnostics (`bin/orbit-agent-session-capture:561-633`).
- Stage 3 validates explicit providers/slugs before DB or staging, derives canonical provider roots, constructs captures in unique direct-child siblings, and installs them through backup/swap/rollback (`bin/orbit-agent-session-capture:69-83,137-182,270-296`; `bin/orbit-agent-session-capture-filesystem.php:5-31,90-220`).
- The accepted corrections are closed: checked construction rejects false writes/copies, missing raw sources, and escaping archive names; cleanup reasserts canonical containment and does not follow temp symlinks (`bin/orbit-agent-session-capture-filesystem.php:45-182`). Archive copy/discovery excludes only direct foreign `.tmp-*` capture siblings, preserves backups and unrelated evidence, and skips root/nested directory symlinks (`bin/orbit-session-archive:391-473,681-725`). The includable seam is one bin-local project-prefixed procedural file loaded with `require_once`, without a public test flag, class, dependency, or broader abstraction (`bin/orbit-agent-session-capture:6,19`; `bin/orbit-agent-session-capture-filesystem.php:5-220`).

The prior four formal findings and later root corrections are genuinely closed. Reviewer 988 identified provider containment, pre-swap construction integrity, actionable diagnostics, and global collisions; the retained correction/root/symlink reds prove the missing behavior before correction. Formal reviewer 990 then found no remaining P0-P2 issue and passed all eight contracts (`.orbit/evidence/capture-integrity-final-rereview-codex-990.md:1-34`). Direct source inspection supports that conclusion.

The slice makes capture evidence simpler and more reliable: one explicit ownership classifier, one small filesystem seam, coherent whole-directory replacement, fail-closed ambiguity, actionable bounded diagnostics, and archive non-follow rules. It does not weaken ownership or ambiguity evidence and does not introduce speculative product or framework abstraction.

## Evidence Reviewed

- Orchestrator/Solo context: scratchpad `solo://proj/4/scratchpad/orbit-feature-loop-r--276`, current revision 96, capture sections from slice start through the high-model review and the separate replay-evolution re-review.
- Worktree/range: exact path, branch, clean status, base `f1acfee5de5d74432656c8d46a11e9d1bb5bff54`, and HEAD `b6832b747ab568942b234c84c6ca29e5e2926430`.
- Repository guidance: `AGENTS.md`, `AGENT_FAST_PATH.md`, relevant analyzer/finalization sections of `HARNESS.md`, `HARNESS_SIGNALS.md`, `harness-signals/README.md`, `LOOP.md.example`, and `PRODUCT_DECISIONS.md`.
- Packet and reviews: `.orbit/loop.md`, reviewer 988, formal re-review 990, Stage 1/2/3 reds, correction red, root-gap red, archive-symlink red, rollback adjudication, and the worker/reviewer manifests.
- Final implementation/tests/signal/index: all eight files in the exact diff, with direct production-source and focused-test inventory inspection; `git diff --check` is clean.
- Exact-commit verification: docs lint records exit 0 on the exact branch/commit (`.orbit/quality-gates/docs-lint-2026-07-10T084154Z-7465d37930b5.json:1-14`); aggregate quality records exit 0 with all 43 subgates at 0 on the same exact commit (`.orbit/quality-gates/quality-check-2026-07-10T084401Z-129a77af73c6.json:1-58`). No E2E lane ran or was required.

## Signal Classifications

Every `.orbit/loop.md:84-96` Candidate Signals While Working item is classified below. `correct-noop` means the packet's disposition is correct and no additional durable loop change is warranted now.

| Candidate | Disposition | Persona classification | Basis |
|---|---|---|---|
| Six capture/index defects escaped the earlier low-model review | tighten | correct-noop | The capture portion correctly tightened the existing lane-close signal; the already-merged index defect remains outside this range. No second signal is needed. |
| Claude provider-floor, ownership, and atomic-replacement handoff | already-covered | correct-noop | This is the controlling implementation contract, now enforced by code and adversarial tests rather than a separate signal. |
| Initial root-relative Pest path loaded zero tests | reject | correct-noop | One-off command friction, immediately corrected; existing app-relative runner guidance covers it. |
| Generic discovery parser was reused for primary identity | tighten | correct-noop | The existing capture signal and deterministic mention/standalone identity tests now encode the required separation. |
| Multiple-partial fixture was late | reject | correct-noop | Corrected review steering; the fixture passed against the cohesive classifier and there is no recurrence evidence. |
| Deterministic rollback required a private rename seam | already-covered | correct-noop | The accepted local procedural seam and four-state test matrix are the narrow verified target; no broader guidance is needed. |
| `main()` indentation and explicit traversal coverage were caught in root review | already-covered | correct-noop | Style enforcement and the Stage 3 traversal fixture close these in-slice review catches. |
| Safe ambiguity diagnostics named arbitrary scanned files | tighten | correct-noop | Bounded `matched_candidates`/`owned_candidates`, stderr, tests, and the existing signal now make failures actionable without weakening ambiguity. |
| Reviewer 988 provider/transaction/diagnostic/collision findings | tighten | correct-noop | All four were coupled defects in the existing capture guardrail and were correctly absorbed there with retained reds and formal re-review. |
| Claude correction design | already-covered | correct-noop | It is the bounded implementation decision for the preceding findings, not an independent durable signal. |
| Root correction review found raw, containment, temp-depth, backup, and symlink gaps | tighten | correct-noop | The same existing signal now names checked construction, canonical destructive containment, direct-depth exclusion, backup preservation, and no-follow behavior. |
| Reviewer 990 metadata remained at the primary checkout | defer | defer | Capture correctly failed closed with `foreign_cwd`; checkout proof and the byte-identical report preserve review validity. Treat this as a steering/rebaseline datum, not a reason to weaken capture ownership or add another capture signal. |
| Session-index replay-evolution findings | defer | defer | This is an accepted, bounded parser/tests/index follow-up that must land before program rebaseline. It is separate from capture integrity, needs no capture guardrail change, and is not a blocker for this slice. |

Guardrail decisions: no `missed`, `redundant`, or `wrong-target` decision. The accepted recurring capture signal was tightened at the smallest reachable targets—helper behavior, focused tests, skill clarification, signal record, and generated index—and all other candidates were correctly covered, rejected, or deferred. Additional durable loop change: none.

## PRODUCT_DECISIONS Impact

None. `PRODUCT_DECISIONS.md` is unchanged in the exact range, and the slice changes repository-development evidence capture and harness enforcement rather than Orbit product intent. The packet's classification at `.orbit/loop.md:5-6,45` is correct.

## Topology Assessment

Retained topology proof is correctly `not applicable`. The diff changes local repository-development capture/archive helpers, deterministic filesystem fixtures, tests, and a harness signal; it does not cross an operator-visible runtime, node, VM, macOS-native, provisioning, or deployed product seam. `.orbit/loop.md:149-153` gives the same scoped rationale. No E2E or live-node proof should be substituted.

## Verification Assessment

Verification is sufficient and proportionate. Stage-specific reds and greens, correction/root reds, focused full-owner/archive/finalization coverage, syntax/format/index/diff checks, independent formal re-review, exact-commit docs lint, and exact-commit 43/43 aggregate quality collectively cover the changed surface. The quality artifacts identify the exact branch and HEAD; no broad aggregate rerun was needed or performed by this analyzer.

The capture waivers are sufficient:

- Worker 986 has a coherent `ambiguous_duplicate_markers` manifest from multiple fully owned rollouts after internal delegation; unsafe selection was correctly refused, and the packet retains Solo output and names the waiver (`.orbit/loop.md:124,159`). The older manifest predates the newly added actionable diagnostic fields, which is evidence for the correction rather than a reason to recapture or select heuristically.
- Reviewer 990 has a coherent `no_owned_marker_transcript` manifest whose matched candidate records `foreign_cwd=/Users/nckrtl/orbit`; exact worktree proof, command execution, and the byte-identical report preserve the review result, and the packet explicitly names the waiver (`.orbit/loop.md:134,159`).

## Packet Gaps

None at the pre-analyzer boundary. The packet contains the objective/range, ordered contracts, red/green evidence, correction history, exact worker/reviewer pointers, explicit waivers, signal outcomes, topology rationale, product-decision classification, and exact-commit gates. The fresh-analyzer row and finalization lint are intentionally pending this report; the feature owner should record process 991 and `VERDICT: yes`, capture this analyzer lane or state an explicit waiver if capture fails, then run the narrow finalization lint. Those are normal post-report mechanics, not missing evidence for this verdict.

## Loop Improvements

None beyond the implemented tightening of `harness-signals/2026-07-07-lane-close-agent-session-capture.md`. The separate session-index replay-evolution slice remains pre-rebaseline and must not be folded into capture closure.

VERDICT: yes
