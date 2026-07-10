# Session Index Facet Reviewer 947

- Solo process: `947`
- Checkout proof: `cwd=/Users/nckrtl/orbit/.worktrees/session-index-facet-normalization branch=session-index-facet-normalization status=two modified requested files`
- Reviewed diff: `git diff HEAD -- bin/orbit-session-index apps/gateway/tests/Feature/E2ESupport/SessionIndexTest.php`
- Verification: `bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/SessionIndexTest.php` -> 4 passed, 95 assertions

## Blockers

- `bin/orbit-session-index:448` — Backticked loop outcomes, `blocked - reason`, and replay-proven `skipped because/for ...` values remain noncanonical. Normalize only recognized closed heads while preserving both raw fields; add exact regression fixtures.
- `bin/orbit-session-index:187` — Same-line `Fresh analyzer: Verdict: ...` values are discarded as null/unknown. Return the same-line value for existing yes/no/freeform normalization and test it.
- `bin/orbit-session-index:398` — Indented nested labels can be mistaken for top-level facets, violating the absent-value null contract. Restrict same-line extraction to column-zero labels and add an indented-lookalike test.

## Verdict

`VERDICT: Blockers`

## Capture Status

`bin/orbit-agent-session-capture 947` failed with `ambiguous_duplicate_markers` because the exact Solo marker matched multiple Codex sessions. The existing lane-close capture signal explicitly warns that a parent transcript can contain a child marker. This report preserves the reviewer output; `.orbit/loop.md` records the explicit capture waiver and recurrence candidate.
