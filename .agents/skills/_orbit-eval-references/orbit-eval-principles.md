# Orbit Eval Principles

Use these rules across Orbit eval construction, execution, and review.

## Authority And Scope

- Start from Orbit authority docs: `apps/docs/content/**` for product behavior and `PRODUCT_DECISIONS.md` for dated direction changes.
- Treat code, tests, live-node incidents, failed commands, and prior sessions as evidence for failure modes, not as product authority.
- Keep eval artifacts in Solo scratchpads while iterating. Graduate validated fixtures to the repo only with user approval.
- Do not wire release gates from eval skills. The review skill may recommend a gate; Orbit's release and quality-gate process owns implementation.
- Do not run `composer test:e2e*` unless the user explicitly invokes that Composer E2E command.

## Suite Types

| Type | Use | Expected Signal |
| --- | --- | --- |
| `capability` | Explore whether an agent or Orbit can perform a hard task class. | May start low; useful when it separates approaches. |
| `regression` | Protect known behavior after a bug, incident, or accepted fix. | Should trend near 100%; failures should be actionable. |
| `diagnostic` | Debug a workflow, scorer, harness, or prompt. | Useful for learning; not a gate by default. |
| `release-gate-candidate` | Propose a stable eval for release consideration. | Recommendation only until wired through Orbit's existing gates. |

## Good Case Rules

- One case measures one failure mode.
- Cases are unambiguous enough that two competent reviewers can independently reach the same verdict.
- Every case has `reference_solution`, `known_good_examples`, or `known_bad_examples`.
- Positive behavior gets a matching negative or edge case when applicable.
- End-state checks are separate from transcript, stdout, or final assistant claims.
- Exact tool order is graded only when ordering is the contract.
- Hidden answer keys, rubrics, and previous trial traces stay out of the agent-under-test context.

## Scoring Rules

- Prefer deterministic checks for file contents, database rows, JSON schema, command side effects, docs links, topology state, and exact contracts.
- Use model judges for semantic or interaction-quality dimensions that deterministic checks cannot capture.
- Treat LLM judges as classifiers: calibrate them with known labels, measure disagreement, and keep an `Unknown` result for insufficient evidence.
- Grade separate dimensions separately instead of hiding multiple judgments inside one Likert score.
- Use human review for high-stakes, ambiguous, or newly created model-judge criteria.

## Agent Eval Rules

- Capture task, case, trial, transcript or trajectory, final outcome, grader results, harness, and suite separately.
- Prefer outcome checks over path checks unless the path is the product contract.
- Use repeated trials for nondeterministic agents.
- For interactive tasks, use a scripted or simulated user persona so the run is reproducible.

## Statistical Rules

- Use pass@k when any successful attempt among k attempts is acceptable.
- Use pass^k when reliability across k attempts is required.
- Use paired comparisons for model, prompt, or harness changes when cases are shared.
- Use confidence intervals or power analysis before claiming small improvements.
- Treat 0% and 100% pass rates as review triggers: the eval may be too hard, too easy, broken, saturated, or useful only as regression coverage.

## Maintenance Signals

Refresh or add cases when Orbit sees production issues, live-node drift, `orbit doctor` anomalies, docs drift, support reports, failed verification, reviewer corrections, or recurring failures in local or `ssh nick` Codex sessions.
