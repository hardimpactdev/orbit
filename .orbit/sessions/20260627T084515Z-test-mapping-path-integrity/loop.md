# Orbit Current Slice State

## Feature Context

- Scratchpad: solo://proj/2/scratchpad/llm-usefulness-impro--389
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-test-mapping-path-integrity
- Branch: codex/test-mapping-path-integrity
- Completed slices:
  - P1/P4 command catalog and CLI-to-gateway mappings: merged.
  - Linked-test catalog cleanup and verification hints: merged.
  - P2B generated monorepo unit map: merged.
  - P2/P2A one-screen/per-scope docs: deferred by natural-discovery eval until a root-start eval shows agents miss current generated artifacts.
  - P2.5 operational snapshot: deferred/revise by eval.
  - P3 generated LLM artifact freshness in `composer docs-lint`: merged.
- Current slice: P3 test mapping path integrity for LLM-facing docs.

## Done Contract

- Active slice start gate:
  - Multi-slice feature roadmap scratchpad exists before worker dispatch: yes - solo://proj/2/scratchpad/llm-usefulness-impro--389
  - `.orbit/loop.md` links the feature roadmap and names the current slice: yes
  - If the source scratchpad lives in another Solo project, execution-project scratchpad links back to it and mirrors the roadmap substance: not applicable
- Parallelization scan:
  - Candidate parallel lanes: rule/test implementation, docs cleanup, review/verification.
  - Serialized lanes, with required dependency/shared-state/provider-capacity/merge-order reason: tests define the rule before implementation; docs cleanup must satisfy the rule before final docs-lint.
  - Deferred lanes (lane -> concrete reason -> owner): broad prose-group spine cleanup deferred because current prose pass is warning-heavy and not yet proven as a strict LLM-efficiency improvement.
  - Parallel dispatch started (lane -> Solo process or owner): none yet; use fresh review only after focused implementation is visible.
- Done when:
  - Docs-lint/Librarian mechanically fails when Test Mapping sections cite test paths that do not exist in the monorepo.
  - Stale `apps/gateway/tests/E2E/...` product-doc roots are removed or corrected to current authority.
  - The normal `composer docs-lint` path passes after cleanup.
  - No E2E commands are run.
- Evidence:
  - P3 inventory outcomes in `/tmp/orbit-p3-footgun-inventory-20260627/outcomes/`.
  - Current scan found 29 missing documented test paths, mostly stale `apps/gateway/tests/E2E/...` paths.
  - `apps/docs/content/testing/README.md` says new E2E coverage lives under `apps/e2e`, never `apps/gateway/tests/E2E`.
  - `apps/docs/content/domains/README.md` still documents `apps/gateway/tests/E2E/`.
- Reviewer checks:
  - Fresh analyzer should verify the rule is not a nonsense assertion and that doc cleanup does not pretend missing E2E tests exist.
- Stop if:
  - Existing docs intentionally name future test paths and need product decisions before cleanup.
  - A strict rule would require running or creating E2E.
- Pivot if:
  - Existing rules already catch missing test paths after all, or docs path scan is a false positive outside Test Mapping sections.

## Progress

- Tried: Verified `composer docs-lint` does not currently include a strict prose-group pass over authority spine files.
  Result: prose pass exists but emits many warnings; defer broad prose strictness.
- Tried: Scanned domain docs for backticked test paths and checked filesystem presence.
  Result: 29 missing test path strings, concentrated in stale gateway E2E paths and a few migrated/renamed support tests.
- Tried: Added `TestMappingPathExistsRule`, registered it in docs Librarian config, added focused Pest coverage, and cleaned stale mapped test paths.
  Result: `composer docs-lint` now fails on nonexistent Test Mapping table paths and passes after the docs cleanup.
- Tried: Counterfactual stale path check by temporarily changing an existing mapped path to `MissingToolsProbeTest.php`.
  Result: `composer docs-lint` failed with `command_docs.test_mapping_path_exists`; restored the valid path.
- Tried: Fresh Solo Claude Opus reviewer (`test-mapping-path-review`, process 2134) reviewed the diff read-only.
  Result: no blockers; safe to merge; residual non-blocking risks were that `apps/docs/content/testing/e2e/docker.md` paths are outside the narrow Test Mapping rule and `repoRoot()` assumes `apps/docs` remains two levels under the repo root. Reviewer process deleted after capture.

## Candidate Signals While Working

- No durable harness signal needed. The implemented Librarian rule is the durable guard for the recurring missing-Test-Mapping-path issue, and this slice did not expose a separate harness workflow failure.

## Blockers

- None.

## Evidence Links

- P3 inventory outcomes: `/tmp/orbit-p3-footgun-inventory-20260627/outcomes/*.json`
- Missing path scan: local shell check over `apps/docs/content/domains`.
- Testing authority: `apps/docs/content/testing/README.md`.
- Conflicting domain template: `apps/docs/content/domains/README.md`.

## Harness Signals

- No live topology or E2E behavior touched.
- This slice only tightens docs/test references used by agents.

## Final Distillation

- Loop outcome:
  - complete + loop improvement
  - Summary: implemented and validated P3 test mapping path integrity. LLM-facing command docs now avoid citing stale/missing test files in Test Mapping tables, and `composer docs-lint` enforces that contract.
- Required verification:
  - passed - `bin/orbit-docs-pest --compact --filter='test mapping'`: 4 tests / 28 assertions.
  - passed - `composer docs-lint`: 0 issues.
  - passed - Independent content path scan over `apps/docs/content/domains` and `apps/docs/content/testing`: `missing=0`.
  - passed - `bin/orbit-docs-pest --compact`: 119 tests / 935 assertions.
  - passed - `cd apps/docs && vendor/bin/mago analyze app config database --reporting-format=medium`: exit 0; only pre-existing help-level redundant-cast notes outside this slice.
  - passed - `cd apps/docs && vendor/bin/mago lint app tests config database --reporting-format=medium`: exit 0; no issues after touched-file cleanup.
  - passed - `cd apps/docs && vendor/bin/mago format app tests config database --check`.
  - passed - `git diff --check`.
  - `composer quality-check`: passed - latest run duration 29.0s.
  - passed - `composer quality-gate:final-check`: no warnings detected; no E2E lanes rerun.
  - Retained topology proof: not applicable - docs/Librarian-only change, no CLI/runtime/topology behavior touched.
- Finalization gate fit:
  - Fits merge after commit and main-checkout revalidation. Quality-check artifacts exist in the feature worktree and final-check reports no warnings.
- Distillation packet:
  - Location: `.orbit/loop.md`
  - Includes objective/final diff: yes; new docs Librarian path-existence rule, rule registration, focused Pest coverage, and docs cleanup for stale mapped test paths.
  - Includes worker/reviewer/terminal/evidence pointers: yes; Solo reviewer process 2134, deleted after capture; retained terminal 1994 left running and unrelated.
  - Includes orchestrator steering notes: yes; broad prose strictness deferred because not proven as LLM-efficiency improvement, and docker.md path drift noted as a possible future rule expansion only if it recurs.
- Fresh analyzer:
  - Persona: Solo Claude Opus read-only reviewer.
  - Solo process or analyzer: 2134 `test-mapping-path-review`.
  - Verdict: no blockers; safe to merge; no durable harness signal needed.
- Candidate signals:
  - None beyond the implemented Librarian rule.
- Accepted durable updates:
  - `TestMappingPathExistsRule` registered in docs Librarian config.
- Rejected or already-covered signals:
  - Separate harness signal rejected; this was a docs-lint coverage gap, now directly enforced by the rule.
- Deferred follow-ups:
  - Possible future docs-reference path integrity for fenced/example docs such as `apps/docs/content/testing/e2e/docker.md` if drift recurs. Not part of this slice because current acceptance was Test Mapping integrity and the reviewer found current docker.md paths correct.
- No-new-signal rationale:
  - The late catches were stale documentation references, not a repeated harness workflow mistake. The smallest durable prevention is the docs-lint rule itself.
