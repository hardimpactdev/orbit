# LLM-Affordance File Capture

Use this pattern when running comparative fresh-agent evals for LLM-facing Orbit affordances: command catalogs, linked-test maps, skills, onboarding docs, prompts, or compact lookups. It keeps final outcomes inspectable when terminal tails truncate or final assistant claims disagree with cited evidence.

## When To Use

Prefer file capture when:

- the eval asks the agent to cite docs, tests, or other repo evidence
- the output contract is structured enough for deterministic checks
- transcript tails or final chat responses may lose detail
- baseline and treatment conditions share the same task but differ in visible affordances

Skip file capture only when the case is purely conversational, the outcome is a single command side effect, or writing a temp file would change the measured behavior. Say why in the trial record.

## Outcome File Contract

Each trial writes exactly one outcome JSON file when practical:

- path: `/tmp/orbit-<suite>-<run>/<trial_id>.json`
- one file per trial; do not split one trial across multiple outcome files
- the transcript or trajectory stays a separate reference; do not paste the outcome into the scratchpad as a blob
- final assistant claims, terminal tails, and stdout are supporting evidence only; they are not the outcome

Tell the agent under test to write only the assigned outcome file. Repository worktrees stay read-only unless the case explicitly measures edits.

### Minimal JSON Shape

Keep fields flexible across suites. Require only what the case needs, but these keys should repeat across LLM-affordance evals:

```json
{
  "trial_id": "example-trial",
  "case_id": "example-case",
  "condition": "baseline",
  "docs": [
    {
      "path": "apps/docs/content/domains/example.md",
      "claim": "What behavior this doc establishes"
    }
  ],
  "evidence": [
    {
      "path": "apps/cli/tests/Feature/Commands/ExampleTest.php",
      "claim": "What behavior this test proves"
    }
  ],
  "coverage_gaps": [
    "Behavior not yet covered by cited docs or evidence"
  ],
  "risks": [
    "Uncertainty, stale source, or weak support"
  ],
  "confidence": "high",
  "metrics": {
    "doc_count": 0,
    "evidence_count": 0,
    "coverage_gap_count": 0
  }
}
```

Use `evidence` or `tests` consistently within a suite. Optional outcome-file
`metrics` help friction comparisons but are not a substitute for deterministic
checks; record formal `eval-trial.tracked_metrics` separately in the trial
artifact.

Record in the `eval-trial` artifact:

- `outcome_ref`: absolute path to the one outcome file
- `transcript_ref`: separate Solo process, terminal, or scratchpad reference
- deterministic grader commands and results
- `reset_or_teardown`: proof the worktree stayed clean and temp output stayed isolated

## Deterministic Checks

Run these before semantic scoring or aggregate claims. Record pass, fail, or not-applicable per check.

| Check | What it proves |
| --- | --- |
| JSON validity | The outcome file parses as JSON |
| Required keys | `trial_id`, `case_id`, `condition`, and the suite-required evidence fields are present |
| Doc path existence | Every cited doc path exists in the assigned worktree |
| Evidence/test path existence | Every cited evidence or test path exists exactly as written |
| Duplicate paths | No repeated doc or evidence path inflates counts |
| Near-miss paths | No cited path is a plausible but wrong sibling, such as `apps/cli/tests/Feature/GatewayCommandTest.php` when the real file is `apps/cli/tests/Feature/Commands/GatewayCommandTest.php` |
| Stale artifact leakage | Cited docs or evidence do not point at retired paths, banned terms, or artifacts the case marked stale |
| Read-only cleanliness | `git status --short` in the assigned worktree is empty, or only expected temp output changed outside the repo |

Treat the near-miss path check as deterministic only when the cited path is a
non-existent look-alike. When the path exists but proves the wrong behavior,
classify it during semantic proof checks.

Use shell checks when practical:

```bash
python3 -m json.tool /tmp/orbit-<suite>-<run>/<trial_id>.json >/dev/null
test -f <cited-path>
git -C <assigned-worktree> status --short
```

A failed exact-path check is usually an agent citation failure when the harness and worktree were valid. A missing outcome file, invalid JSON, wrong worktree, read-only violation, truncated capture, or answer-key leakage is a harness or infrastructure issue first.

## Semantic Proof Checks

Deterministic path checks are necessary but not sufficient. After path checks pass, review whether each cited doc or evidence item actually supports its claim.

For each cited item:

1. Open the cited file in the assigned worktree.
2. Read the section, assertion, command coverage, or test body the agent relied on.
3. Decide whether the claim is supported, unsupported, or ambiguous.

Classify results separately:

- `nonexistent_path`: the path does not exist; this is a deterministic failure
- `unsupported_citation`: the path exists, but the cited content does not prove the claim
- `supported_citation`: the path exists and the claim matches the cited content

Do not treat unsupported citations as mere path misses. A near-miss path is deterministic; a real file cited for the wrong behavior is semantic.

Spot-check at least one surprising pass and one failure per run before trusting aggregates. For promotion beyond diagnostic runs, define a reference checklist or golden answer for semantic proof.

## Failure Classification Before Agent Scoring

Classify these problems before counting agent capability, pass@k, or paired deltas:

| Problem | Default classification | Count as agent failure? |
| --- | --- | --- |
| Missing outcome file | harness | no |
| Invalid JSON | harness | no |
| Output truncation with no outcome file | harness | no |
| Wrong worktree or branch | harness | no |
| Read-only violation in assigned worktree | harness | no |
| Answer key, reference solution, or prior trial trace visible to agent under test | harness or invalid trial | no |
| Exact cited path does not exist | agent citation failure | yes, when harness was valid |
| Cited path exists but does not support claim | agent semantic failure | yes, when harness was valid |

Mark the trial `invalid`, `harness`, or `infrastructure` when capture quality blocks fair scoring. Record why before aggregation.

## Fresh-Agent Execution Steps

1. Prepare one temp directory per run: `/tmp/orbit-<suite>-<run>/`.
2. Assign each trial a unique `trial_id` and outcome path before spawning the agent.
3. Start a fresh Solo process or fresh thread per trial.
4. Give the agent the public task, output contract, assigned outcome path, and read-only rules.
5. Keep answer keys, reference solutions, grader rubrics, and prior trial traces hidden.
6. After the trial ends, verify the outcome file exists and run deterministic checks.
7. Record `outcome_ref`, `transcript_ref`, grader results, and reset proof separately in the scratchpad.
8. Run semantic proof checks before comparing baseline and treatment aggregates.

## Review Expectations

Reviewers should reject or downgrade runs that:

- rely only on terminal tails or final assistant claims when file capture was practical
- omit deterministic path checks
- skip semantic proof checks and still claim coverage or citation quality improved
- aggregate truncated or missing outcome files as agent capability evidence

A valid comparative conclusion needs paired trials, separate transcript and outcome refs, deterministic check results, and explicit semantic-proof notes for any score that depends on cited evidence quality.

## Deferred Work

A reusable validator script or CI gate is intentionally deferred. Reuse this reference across a few more LLM-affordance evals first, then promote only the outcome fields and checks that repeat across suites.
