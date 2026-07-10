CHECKOUT PROOF

- `pwd`: `/Users/nckrtl/orbit/.worktrees/capture-evidence-integrity-hardening`
- Solo identity: MCP `whoami` confirmed process `988`, actor `mcp-7352f50ee1db438b`, process `capture-evidence-integrity-reviewer-high`, project `orbit` (`4`). The local `solo` binary has no `whoami` subcommand.
- Branch: `capture-evidence-integrity-hardening`
- HEAD: `52e5ea2503ab92cd04b283a2d5a286927fb07d20`
- Expected head: `52e5ea250` resolved to the same full SHA.
- Tracked status: clean; both `git status --short` and `git status --short --untracked-files=no` produced no output.
- Reviewed range: `f1acfee5de5d74432656c8d46a11e9d1bb5bff54..52e5ea2503ab92cd04b283a2d5a286927fb07d20`, including commits `15602b850`, `0c4cd0002`, and `52e5ea250`, plus `.orbit/loop.md`, `.orbit/evidence/`, and the worker-986 capture manifest.

FINDINGS

- P1 — `bin/orbit-agent-session-capture:106,128,419-478`: Provider-root containment is lexical and rooted in unvalidated `--provider` input. `../../...` provider values make the final/temp/backup paths pass `assertDirectChildPath()` relative to the same escaped string, and a symlinked provider root also passes while resolving outside `.orbit/agent-sessions`; successful replacement can then recursively delete the moved external directory at line 478. Consequence: a local capture invocation can overwrite or recursively remove a directory outside the capture tree. Smallest fix: require provider input to be a non-empty canonical single path component, create/canonicalize the `agent-sessions` root, reject symlinked provider roots, and assert the canonical provider root is exactly one child below that root before creating or renaming anything. Evidence: explicit slug traversal is tested, but no provider traversal/symlink test exists; the direct-child check compares `dirname($path)` only with `dirname($final)`, never with the canonical capture root.
- P1 — `bin/orbit-agent-session-capture:132-226,530-534`: The transaction covers only rename replacement, not construction of the temporary capture, and both JSON/message writes ignore `file_put_contents()` failure. A failed scan/write/copy before line 225 escapes the catch and leaves a hidden partial temp sibling; a false write can also proceed to replacement, allowing an incomplete capture to be installed and, when its manifest survived, counted as healthy by finalization consumers. Consequence: the new atomic-directory contract can still archive partial evidence or publish a success missing `usage.json`/`messages.jsonl`, while an older coherent final may be accompanied by leaked temp evidence. Smallest fix: make every write throw on `false`, wrap temp construction/copy in cleanup that removes the direct-child temp on pre-replacement failure, and add deterministic write/copy-failure tests while preserving the adjudicated rename-failure states. Evidence: Stage 3 tests cover first/second/double rename outcomes only; `bin/orbit-session-archive` recursively copies hidden temp siblings byte-for-byte and its manifest discovery also walks them.
- P2 — `bin/orbit-agent-session-capture:597-645`: Ambiguous Codex failures populate `checked` from the first 20 files scanned, not from marker matches or surviving full/partial owners. The real worker-986 manifest therefore lists unrelated March rollouts and omits the files that caused the July ambiguity, while stderr tells the operator to inspect that list. Consequence: the safe failure cannot be diagnosed or waived from its retained evidence and may send reviewers toward unrelated transcripts. Smallest fix: retain `checked` only if backward compatibility requires it, but add bounded `matched_candidates`/`owned_candidates` diagnostics with path and ownership class and use those for ambiguity stderr/manifest output. Evidence: `.orbit/agent-sessions/codex/capture-evidence-integrity-worker-986/manifest.json` contains 20 arbitrary scanned paths; the classifier already has `$candidates`, `$fullOwners`, and `$partialOwners` at the return point.
- P2 — `bin/orbit-agent-session-capture:17,1227-1228`: The require-time no-main guard suppresses execution but still declares a generic global `main()` and every helper globally. The focused test passes only because it requires the script in a fresh PHP subprocess. Consequence: requiring the private seam from any process that already defines `main()` (or requiring the file twice) fatals before the guard can help, making the seam runtime-fragile and collision-prone. Smallest fix: put the includable seam in a project-specific namespace (or prefix all exported helper names, beginning with the entry point) and use `require_once` semantics in the test. Evidence: both `php -r 'function main(...) ... require ...'` and a double `require` fail with `Cannot redeclare function main()` at line 17; direct CLI help and `php -l` still work.

CONTRACT MATRIX

| # | Result | Evidence |
|---|---|---|
| 1 | PASS | Claude and Grok incarnation floors return exact `incarnation_floor_unsupported_provider` after provider resolution and before any provider-root/staging creation; focused red/green artifacts prove preserved sentinels. |
| 2 | PASS | All providers use integer-list matching with `(?!\d)` and Codex filters every marker candidate through exact normalized cwd plus primary identity before cardinality. Numeric-prefix, wrong-cwd, and foreign-primary tests are behaviorally specific. |
| 3 | PASS | Discovery and standalone-line primary identity are separate functions; all marker occurrences are parsed and multiple standalone identities become partial rather than first-wins. Mention-only adversarial coverage proves the precision split. |
| 4 | PASS | One full owner outranks partials, zero owned candidates fails conservatively, a sole legacy partial is visible, multiple full or partial-only candidates fail loudly, and timestamps only annotate/validate after ownership selection. |
| 5 | PASS | Explicit slugs must equal their canonical form and fail before DB/staging. The root-review traversal case is sound for slug input. |
| 6 | FAIL | Same-slug success/failure and deterministic rename states are well covered, but provider-root containment and pre-replacement write/copy cleanup are not transactional or safely contained. The private seam also has global collision behavior hidden by the test subprocess. |
| 7 | FAIL | The skill change and generated index scope are minimal, but the signal's coherent/guarded replacement claim is stronger than the implementation under write/copy exceptions, and retained ambiguity diagnostics are not actionable. |

TEST/VERIFICATION GAPS

- Add provider traversal and symlinked-provider-root tests that prove no path outside canonical `.orbit/agent-sessions` is renamed or deleted.
- Add deterministic manifest/usage/messages/raw write-copy failure tests proving the old final remains coherent and no temp sibling survives pre-replacement exceptions.
- Assert ambiguity diagnostics name the actual matched/full/partial candidates, not arbitrary scan order.
- Add a require-time collision test or namespace the seam; the current clean-subprocess test proves no-main behavior only.
- Existing red/green artifacts are credible and specific: Stage 1 `2/10`, Stage 2 `11/66`, Stage 3 `13/116`, full owning file `47/545`, related SessionArchive `56/607`, and FeatureFinalizationGate `47/111` are recorded. I did not rerun them because the findings are source/evidence-contract defects rather than disputed test outcomes.
- `composer quality-check` has not run and `.orbit/quality-gates/` contains no completed aggregate artifact. `bin/orbit-feature-finalization-check --lint .orbit/loop.md` currently blocks because the loop outcome is `in progress`; this is accurate packet state, not an additional code finding. No E2E run was needed or permitted.

LOOP CANDIDATE CLASSIFICATION

- `tighten` — ambiguity diagnostics: safe ambiguity is already covered, but the retained candidate list must expose actual marker/owner survivors. Tighten the existing lane-close capture signal, helper manifest, and a focused assertion; do not create a second signal.
- `tighten` — canonical staging containment and pre-swap exception cleanup: the existing signal already claims direct-child/transactional safety, so correct that guardrail target and add the missing adversarial tests there.
- `reject` for durable promotion — generic global helper collision is a local seam-design defect; fix and test it, but there is no evidence yet that it warrants broader harness guidance.
- `already-covered` — root-review corrections for `main()` indentation and explicit traversal slug syntax are sound and are already captured by code style plus the Stage 3 test. They do not need another durable signal.
- `reject` — the worker's initial zero-test root-relative Pest command was corrected immediately and existing app-relative runner guidance already covers it.

PRODUCT_DECISIONS IMPACT

None. This branch changes repository-development evidence capture and harness enforcement, not Orbit product direction; no `PRODUCT_DECISIONS.md` entry is warranted.

VERDICT: changes-required

<oai-mem-citation>
<citation_entries>
MEMORY.md:1283-1289|note=[prior staged capture and finalization contract orientation]
MEMORY.md:1205-1211|note=[prior lane close capture rollout pointers and keywords]
</citation_entries>
<rollout_ids>
019f3b7b-6435-75f2-b781-3ebaaaf7a746
019f3b96-13c2-7ad2-a409-15c251958cde
019f3b9f-8203-7fc1-92d9-73a190642072
</rollout_ids>
</oai-mem-citation>
