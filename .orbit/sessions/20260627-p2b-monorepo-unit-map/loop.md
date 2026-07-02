# Loop: P2B Monorepo Unit Map

## Feature Context

- Scratchpad: `solo://proj/2/scratchpad/llm-usefulness-impro--389`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-monorepo-unit-map`
- Branch: `codex/monorepo-unit-map`
- Completed slices:
  - P2A/P4 context: prior command-catalog and compact lookup work exists on `main`; this slice builds on the root-start routing gap those evals exposed.
- Current slice: P2B generated monorepo unit map.

Current slice:
P2B from `solo://proj/2/scratchpad/llm-usefulness-impro--389`: add a generated, freshness-checked monorepo unit map that gives LLMs a compact root-start routing surface.

Done when:
- `apps/docs/content/generated/monorepo-unit-map.json` is generated from explicit repo facts, not hand-edited prose.
- The artifact names each Orbit unit, owning paths, entrypoints, preferred verification, relevant skills, and routing cautions without duplicating command-catalog details.
- A docs app command can regenerate and check freshness.
- Focused Pest coverage proves schema shape, known paths, no `.worktrees` leakage, and freshness.
- A fresh-agent eval compares root-start routing without vs with the artifact before claiming LLM efficiency value.

Evidence:
- Focused docs Pest for the unit-map generator and command.
- Mago format/lint/analyze as needed for touched docs app files.
- `composer docs-lint` or `composer quality-check` depending on touched surfaces.
- Fresh-agent eval measuring correct routing, cited source reads, turns/tool calls, elapsed time, and invalid verification suggestions.

Reviewer checks:
- Claude Opus review for implementation if the diff is non-trivial.
- Orbit eval execution review before trusting the eval result.

Stop if:
- The map cannot be generated deterministically from current repo facts.
- The generated artifact conflicts with `AGENTS.md`, `HARNESS.md`, or product docs authority.
- Fresh-agent eval design would expose answer keys or treatment artifacts to baseline agents.

Pivot if:
- The map starts becoming a prose guide; reduce to compact JSON and route to existing authority docs.
- Eval shows no measurable efficiency gain; reject or narrow the slice instead of persisting more surface area.

## Progress

- Tried:
  - Implemented generated JSON artifact at `apps/docs/content/generated/monorepo-unit-map.json`.
  - Implemented `apps/docs/app/Librarian/MonorepoUnitMapBuilder.php`.
  - Added docs app command `orbit:monorepo-unit-map` with generation, freshness check, and `--unit=` output.
  - Added root pointers in `AGENTS.md` and `HARNESS.md`.
  - Added Pest coverage in `apps/docs/tests/Feature/Librarian/MonorepoUnitMapTest.php`.
  - Ran comparative fresh-agent evals, repaired invalid eval design, and used eval failures to refine wrapper-relative verification guidance and Reverb routing cautions.
  Result:
  - Keep P2B as a narrow, generated root-start routing aid. Trustworthy eval evidence supports reduced source/file reads in the sampled routing tasks and fewer invalid verification suggestions, especially wrapper-path mistakes. Do not claim token savings, release-gate readiness, or broad statistical proof.
  Next:
  - Finish finalization, commit/merge when review and finalization gates allow, then persist the narrow P2B outcome back to the Solo roadmap once Solo is reachable.

## Candidate Signals While Working

- 2026-06-27/eval-confirmation: Fresh-agent treatment runs still made wrapper-relative verification mistakes until the map explicitly documented path argument rules. Status: fixed locally through generated map fields and Pest coverage.
- 2026-06-27/eval-confirmation: Reverb routing was ambiguous because `docker/orbit-reverb` ownership was not explicit enough. Status: fixed locally through generated map fields and Pest coverage.
- 2026-06-27/finalization: Solo MCP transport closed while attempting scratchpad read and Solo tool summary. Status: blocker for scratchpad persistence and Solo-managed post-feature analyzer; no repository guardrail candidate yet.

## Blockers

- None for the committed P2B code slice. Solo scratchpad persistence and Solo-managed analyzer are deferred orchestration follow-ups because Solo MCP returns `Transport closed` and no `solo` CLI is available on PATH.

## Evidence Links

- Focused P2B generator/command Pest: `bin/orbit-docs-pest --compact tests/Feature/Librarian/MonorepoUnitMapTest.php` passed with 18 tests and 101 assertions after the audit fix for broad Mago format recommendations.
- Docs app Pest: `bin/orbit-docs-pest --compact` passed with 116 tests and 922 assertions.
- Docs lint: `composer docs-lint` passed with 0 issues/errors/warnings across docs lint, testing docs, and references docs; post-commit artifact `.orbit/quality-gates/docs-lint-2026-06-27T073053Z-c5544b39707d.json`.
- Freshness: `bin/orbit-docs-artisan orbit:monorepo-unit-map --check` reported the generated map is up to date.
- Docs app Mago: format/lint checks including `routes` exited 0. Analyze printed two unrelated help notices in `app/Librarian/CommandCatalogGatewayPermissionIndex.php`.
- Full quality gate: `.orbit/quality-gates/quality-check-2026-06-27T073029Z-61e7551b59f0.json` records `composer quality-check` exit 0 with all subgates exit 0 at commit `de2b9338ced09e5ec61e7550378102de1b81e854`.
- Initial proto eval: `/tmp/orbit-monorepo-unit-map-eval-20260627-Hblfsf/artifacts/eval-run-review.md` verdict invalid; historical only.
- Repaired eval: `/tmp/orbit-monorepo-unit-map-eval-repair-20260627-0Uqs8g/artifacts/eval-run-review.md` verdict needs revision before use; useful for gaps but not trusted as final proof.
- Confirmation eval hidden reference: `/Users/nckrtl/.cache/orbit-eval-hidden/p2b-confirm-20260627-0Uqs8g/reference.json`.
- Confirmation eval final validator: `/tmp/orbit-monorepo-unit-map-eval-confirm-20260627-NYl7ba/artifacts/validator-result-final.json`.
- Confirmation eval final review: `/tmp/orbit-monorepo-unit-map-eval-confirm-20260627-NYl7ba/artifacts/final-eval-review.md` verdict trustworthy to inform the narrow decision to keep P2B.

## Harness Signals

- Searched: no harness-signal search performed yet; candidate findings were either implementation fixes already covered by generated map tests or Solo transport availability.
- Created or updated: none.
- Deferred follow-up: if Solo transport failures recur across three goal turns, classify as a process/tooling blocker outside this slice.

## Final Distillation

- Loop outcome:
  - complete
- Required verification:
  - Retained topology proof: not applicable - this slice changes docs-app generated metadata and root agent routing guidance, not live VM/node behavior.
  - `composer quality-check`: passed - `composer quality-check` exited 0 and wrote `.orbit/quality-gates/quality-check-2026-06-27T073029Z-61e7551b59f0.json` at commit `de2b9338ced09e5ec61e7550378102de1b81e854`.
- Finalization gate fit:
  - The branch diff touches root agent/harness guidance plus docs-app PHP, route registration, generated JSON, and Pest tests. `composer quality-check` is the required broad gate and passed. Retained topology proof is not applicable because no CLI runtime or node behavior changed.
- Distillation packet:
  - Location: `.orbit/loop.md`
  - Includes objective/final diff: yes - P2B generated monorepo unit map, docs command, tests, and root pointers.
  - Includes worker/reviewer/terminal/evidence pointers: partial - eval review artifacts and quality-gate artifacts are named; Solo-managed post-feature analyzer is blocked by Solo MCP transport.
  - Includes orchestrator steering notes: yes - eval invalidation, repaired confirmation, and narrow accepted claims are recorded.
- Fresh analyzer:
  - Persona: `.agents/review-personas/post-feature-analyzer.md`
  - Solo process or analyzer: not run - Solo MCP returns `Transport closed`.
  - Verdict: deferred, not substituted.
- Candidate signals:
  - wrapper-relative verification guidance gap -> already-covered -> fixed in generated map path rules and covered by `documents wrapper-relative verification path semantics for LLM agents`.
  - Reverb packaging ownership ambiguity -> already-covered -> fixed in generated map Reverb fields and covered by `warns agents not to invent gateway Pest paths for Reverb runtime packaging`.
  - Solo MCP transport unavailable -> defer -> needs repeated-goal-turn evidence before process/tooling action.
- Accepted durable updates:
  - none
- Rejected or already-covered signals:
  - wrapper-relative verification and Reverb packaging routing gaps are ordinary slice findings fixed in the implemented artifact and tests, not separate harness signals.
- Deferred follow-ups:
  - Retry Solo scratchpad update and Solo-managed post-feature analyzer when the MCP transport is reachable.
- No-new-signal rationale:
  - The concrete LLM-efficiency failures found during eval were product-surface gaps and are covered by the generated artifact plus tests. The remaining issue is transient Solo transport availability, not yet a durable Orbit guardrail signal.
