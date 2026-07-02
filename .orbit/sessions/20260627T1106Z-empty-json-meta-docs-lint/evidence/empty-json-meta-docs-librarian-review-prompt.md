You are a read-only Orbit docs-librarian reviewer for one completed feature slice.

Worktree:
/Users/nckrtl/orbit/.worktrees/codex-empty-json-meta-docs-lint

Branch:
codex/empty-json-meta-docs-lint

Read and follow:
- AGENTS.md
- HARNESS.md
- HARNESS_SIGNALS.md
- harness-signals/README.md
- .agents/review-personas/docs-librarian.md
- .agents/skills/updating-documentation/SKILL.md
- .orbit/loop.md

Review scope:
- New docs-lint rule for empty JSON metadata examples:
  - `apps/docs/app/Librarian/Rules/JsonMetadataShapeRule.php`
  - `apps/docs/app/Librarian/JsonMetadataExampleDecoder.php`
  - `apps/docs/app/Librarian/JsonMetadataStructureInspector.php`
  - `apps/docs/config/librarian.php`
  - `apps/docs/tests/Feature/Librarian/OrbitCommandDocsRulesTest.php`
- Mechanical docs replacements changing empty `"meta": {}` examples to `[]` under `apps/docs/content/**`.
- Do not run a broad docs drift audit. Do not edit files. Do not commit.

Context:
- The previous P6 eval rejected a broad golden fixture catalog but exposed docs/code drift around empty JSON metadata.
- Runtime/tests currently emit empty metadata as JSON array `[]` for shared `JsonEnvelope` and stream JSON terminal frames.
- This slice should help LLM agents by making docs examples match runtime and making future stale empty-object metadata examples fail `composer docs-lint`.

Verification already run by the orchestrator:
- `cd apps/docs && vendor/bin/pest --compact tests/Feature/Librarian/OrbitCommandDocsRulesTest.php --filter='metadata|json examples with empty|scans general product|stream json frame'` passed: 6 tests, 46 assertions.
- `cd apps/docs && vendor/bin/mago format --check app/Librarian/JsonMetadataExampleDecoder.php app/Librarian/JsonMetadataStructureInspector.php app/Librarian/Rules/JsonMetadataShapeRule.php tests/Feature/Librarian/OrbitCommandDocsRulesTest.php config/librarian.php` passed.
- `cd apps/docs && vendor/bin/mago lint app/Librarian/JsonMetadataExampleDecoder.php app/Librarian/JsonMetadataStructureInspector.php app/Librarian/Rules/JsonMetadataShapeRule.php tests/Feature/Librarian/OrbitCommandDocsRulesTest.php config/librarian.php` passed.
- `cd apps/docs && vendor/bin/mago analyze app/Librarian/JsonMetadataExampleDecoder.php app/Librarian/JsonMetadataStructureInspector.php app/Librarian/Rules/JsonMetadataShapeRule.php` passed.
- `composer docs-lint` passed.
- `rg -n '"meta"\s*:\s*\{\}' apps/docs/content -g '*.md'` found no matches.
- `git diff --check` passed.
- `composer quality-check` passed.

What I need from you:
- Findings first, ordered by severity.
- Be critical about whether the rule actually guards the stream JSON examples, whether the EOF Pest tests are acceptable, whether the docs replacements overreach, and whether any product decision entry is required.
- Include concrete file/line references.
- End with open questions, evidence reviewed, and whether this is safe to merge.
