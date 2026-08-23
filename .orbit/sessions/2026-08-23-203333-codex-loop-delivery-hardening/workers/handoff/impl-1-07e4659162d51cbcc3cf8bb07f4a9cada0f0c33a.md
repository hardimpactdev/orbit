candidate=07e4659162d51cbcc3cf8bb07f4a9cada0f0c33a

# impl-1 contract restoration handoff

Rebuilt `HARNESS.md` and `.agents/skills/implementing-features/SKILL.md` from `b4d1d37d5452e5f25ec92d249b7e5310b1f1ec6d`, then reapplied only the intended edits. Reverted `McpConfigurationTest.php` size caps and extra toContain assertions to base; kept spawn-sentence expectation updates.

## Intended edits kept

- Spawn parentheticals removed
- Skill FRAME 5: `Before dispatch, split planned paths by non-automated venue; then run bin/orbit-feature-acceptance route`
- Focused Mago on all changed PHP including tests before each candidate commit
- `Keep nonterminal work active through status questions and partial blockers; stop only at LAND, required human judgment, or a whole-goal blocker.`
- Compact-route phrase omitted for the 6720-byte cap

## Preserved from base

exact checkout identity; preparation-failure blocker; primitive=/transitions=; owning skills; small vertical slices; moved-HEAD re-review; historical strict receipts.

`git diff b4d1d37d5..HEAD -- HARNESS.md .agents/skills/implementing-features/SKILL.md` shows no deletions outside parentheticals, the compact-route phrase, the replaced FRAME venue line, and the replaced production-PHP Mago sentence.

## Checks

- Skill 6687 <= 6720; combined 35582 <= 35600
- Focused contract Pest including full `McpConfigurationTest` 32 passed
- `composer quality-check` exit 0 at `07e4659162d51cbcc3cf8bb07f4a9cada0f0c33a`

## Proof receipt

```json
{
    "ok": true,
    "problem": null,
    "candidate": "07e4659162d51cbcc3cf8bb07f4a9cada0f0c33a",
    "dirty": false,
    "docs_only": false,
    "gate": "quality-check",
    "artifact": "/home/nckrtl/orbit/.worktrees/codex-loop-delivery-hardening/.orbit/quality-gates/quality-check-2026-08-23T181545Z-81b66c693651.json",
    "venue": "automated",
    "runtime": "not applicable"
}
```
