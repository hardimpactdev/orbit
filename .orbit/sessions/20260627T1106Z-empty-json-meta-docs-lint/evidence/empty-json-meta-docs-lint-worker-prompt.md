You are implementing one scoped Orbit feature slice in a dedicated feature worktree. This is a Solo-tracked one-shot worker lane; the Solo MCP send-input transport is unavailable, so this prompt is delivered through `solo processes spawn` args.

Worktree:
/Users/nckrtl/orbit/.worktrees/codex-empty-json-meta-docs-lint

Branch:
codex/empty-json-meta-docs-lint

Read and follow before broad discovery:
- AGENTS.md
- HARNESS.md
- LOOP.md.example
- .orbit/loop.md
- HARNESS_SIGNALS.md
- harness-signals/README.md
- .agents/skills/implementing-features/SKILL.md
- .agents/skills/librarian/SKILL.md
- .agents/skills/pest-testing/SKILL.md
- .agents/skills/spatie-laravel-php/SKILL.md

Feature roadmap:
solo://proj/2/scratchpad/llm-usefulness-impro--389

Current slice:
Add a focused Librarian/docs-lint guard against stale empty JSON metadata examples and align current product docs with the runtime/test contract.

Why:
- The P6 eval rejected a broad golden fixture catalog but found JSON envelope metadata drift.
- The previous landed correction documented shared `JsonEnvelope` empty metadata as `[]`.
- A follow-up scan still finds many fenced JSON examples using empty `"meta": {}`.
- Current runtime/tests emit empty metadata as JSON array `[]` for shared envelopes and stream JSON terminal frames:
  - `packages/core/src/Http/JsonEnvelope.php`
  - `packages/core/tests/JsonEnvelopeTest.php`
  - `apps/cli/app/Commands/Concerns/StreamsGatewayProgress.php`
  - `apps/cli/tests/Feature/Commands/StreamsGatewayProgressTest.php`
  - `apps/cli/tests/Feature/Commands/Operation/DoctorCommandTest.php`

Owned files/scope:
- New rule under `apps/docs/app/Librarian/Rules/`, suggested name `JsonMetadataShapeRule.php`.
- Register rule in `apps/docs/config/librarian.php`.
- Add focused fixture coverage in `apps/docs/tests/Feature/Librarian/OrbitCommandDocsRulesTest.php`.
- Mechanically update exact empty metadata examples in `apps/docs/content/**/*.md` from empty object to empty array where the JSON property is `meta`.

Do not change:
- Runtime `JsonEnvelope` or CLI stream behavior.
- Broad golden fixture machinery.
- Broad Mago baseline/strictness.
- E2E tests or topology flows.
- Unrelated docs prose.
- Git commits, merges, branch cleanup, or project deletion.

Implementation requirements:
1. Add Pest coverage first or in the first narrow diff:
   - Bad fenced JSON with empty object metadata fails docs-lint with a new rule id, suggested `command_docs.json_metadata_shape`.
   - Corrected empty metadata `[]` passes.
   - Non-empty metadata object passes, because metadata can be object-shaped when it has stable fields.
   - At least one fixture proves the rule scans general docs under the docs root, not only `6.2_*_output-render_json.md`.
2. Implement the rule using `JsonExampleParser`, but do not rely on `$example->decoded` to distinguish `{}` from `[]`; PHP associative decoding turns both into `[]`.
3. Re-decode each valid JSON example in object mode with `json_decode($example->raw, false, flags: JSON_THROW_ON_ERROR)` and recursively inspect object properties.
4. Emit a finding when any property named `meta` is an empty JSON object (`stdClass` with no properties). Empty arrays and non-empty objects are valid.
5. Include the path to the offending property in the finding message, such as `success.meta`, `error.meta`, or `event.error.meta`.
6. Skip invalid JSON examples; existing JSON validity rules own parse failures.
7. Replace exact empty metadata examples in docs, but do not alter non-empty metadata objects.
8. Update `.orbit/loop.md` progress/evidence with what you changed and verification attempted.

Suggested verification:
- `cd apps/docs && vendor/bin/pest --compact tests/Feature/Librarian/OrbitCommandDocsRulesTest.php --filter='metadata|json metadata|json'`
- `composer docs-lint`
- `cd apps/docs && vendor/bin/mago format --check app/Librarian/Rules/JsonMetadataShapeRule.php tests/Feature/Librarian/OrbitCommandDocsRulesTest.php config/librarian.php`
- `cd apps/docs && vendor/bin/mago lint app/Librarian/Rules/JsonMetadataShapeRule.php tests/Feature/Librarian/OrbitCommandDocsRulesTest.php config/librarian.php`
- `git diff --check`

Report:
- changed files
- focused tests/verification and results
- any docs examples you intentionally left unchanged, with reason
- risks/blockers
