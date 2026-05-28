# CLI Prompt-Branch Test Gaps

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. The seven items below are a flat checklist of independent test additions. Each test follows the existing `*InteractiveInputModeTest.php` convention; no new infrastructure is introduced.

**Goal:** Close the seven concrete prompt-UX coverage gaps identified by Auto Research's `cli-ux-contract` topic. Six are missing assertions for conditional prompt branches; one is a confirm-site whose label could not be auto-extracted and needs a human eye.

**Tech Stack:** PHP 8.5, Laravel 13 gateway app, Pest 4, Laravel Prompts, `apps/gateway/tests/Feature/Commands/**/*InteractiveInputModeTest.php` convention.

---

## Overview

The Auto Research topic `cli-ux-contract` measured CLI UX testability across all 155 orbit command files. The summary:

- 43 commands use `Laravel\Prompts`.
- 0 commands use `PromptsForMissingInput` — orbit consistently rolls explicit prompt logic.
- 24 prompt call sites are conditional branches whose option set or invocation depends on runtime state.
- The `*InteractiveInputModeTest.php` convention (using Laravel's built-in `expectsQuestion`/`expectsChoice`/`expectsConfirmation`/`expectsOutputToContain`) already asserts **17 of those 24 branches**.
- 6 conditional branches have an extractable prompt label that does NOT appear in any orbit test assertion. 1 branch has a label the heuristic could not extract.

This plan adds the seven missing tests. No helper trait or new infrastructure is needed.

## Source of truth

Authoritative gap matrix: Auto Research finding `projects/orbit/topics/cli-ux-contract/findings/009-branch-assertion-gap.md` in the Auto Research repo (`~/projects/auto-research`). All file paths, line numbers, condition summaries, and quoted source lines below are taken verbatim from that finding and from `006-conditional-prompt-branches.md`.

## Complexity

Files: 7 test files (one per command, in the existing convention)
Modules: `apps/gateway/tests/Feature/Commands/`
Risk: Low. Each test follows an established pattern in the same monorepo.

## Non-Negotiable Boundaries

- Use the existing `*InteractiveInputModeTest.php` convention. Place each new file next to its sibling test files for the same command family.
- Use Laravel's built-in assertions (`expectsQuestion`, `expectsChoice`, `expectsConfirmation`, `expectsOutputToContain`). Do not introduce a wrapper trait.
- Do not modify the command bodies. The research established that the prompt-call sites already work; the gap is in test coverage only.
- The label string passed to `expectsQuestion`/`expectsChoice`/`expectsConfirmation` MUST match the prompt's `label:` argument in the command source verbatim, so future grep-based audits stay accurate.
- For conditional branches, the test must set up the runtime state that triggers the conditional path (missing argument, missing flag, specific registry state, etc.) and then assert the prompt is shown with the correct label and options.

## Work Items

Each item cites the command file, line, prompt label, and condition that must be set up to exercise the branch. The cited line numbers are against the worktree state at the time of the research (2026-05-28); confirm them against `origin/main` before writing tests.

### Item 1 — `AbstractWorkspaceStepRemoveCommand` Step ID prompt
- [ ] Add a test that exercises the `Step ID` text prompt branch at `apps/gateway/app/Console/Commands/AbstractWorkspaceStepRemoveCommand.php:164`.
- Branch trigger: step ID option missing AND input is interactive.
- Source quote: `$value = text(label: 'Step ID', required: true);`
- Asserts: `expectsQuestion('Step ID', '<provided id>')` plus exit code.
- Notes: This is an abstract command; place the test in whichever concrete subclass exposes this behavior (e.g. `WorkspaceSetupStepRemoveCommandTest` or `WorkspaceTeardownStepRemoveCommandTest`), or in an abstract-class test file if the existing convention does so.

### Item 2 — `AppAgentIdeCommand` confirm prompt (label unextractable)
- [ ] Read `apps/gateway/app/Console/Commands/AppAgentIdeCommand.php:289` (the `confirm(...)` call) and extract the actual label argument.
- Branch trigger: not `--force` AND input is interactive.
- Source quote (line 289): `$confirmed = confirm(`
- Add a test that asserts the confirm prompt fires with the correct label and respects `--force`.
- Notes: The Auto Research label-extraction heuristic failed for this site because the label appears on a subsequent line. Human review needed to read the label and write the assertion.

### Item 3 — `CfCacheFlushCommand` Cloudflare zone prompt
- [ ] Add a test that exercises the `Cloudflare zone` text prompt branch at `apps/gateway/app/Console/Commands/CfCacheFlushCommand.php:26`.
- Branch trigger: zone option missing AND input is interactive.
- Source quote: `$zone = text(label: 'Cloudflare zone', required: true);`
- Asserts: `expectsQuestion('Cloudflare zone', '<zone-id>')` plus exit code.

### Item 4 — `DnsResolveTldCommand` Target IP address prompt
- [ ] Add a test that exercises the `Target IP address` text prompt branch at `apps/gateway/app/Console/Commands/DnsResolveTldCommand.php:425`.
- Branch trigger: target argument missing AND input is interactive.
- Source quote: `$target = text(label: 'Target IP address', required: true);`
- Asserts: `expectsQuestion('Target IP address', '<ip>')` plus exit code.
- Notes: The companion `Development TLD` prompt on line 398 IS asserted (in `NodeUpdateInteractiveInputModeTest.php` and `NodeNewInteractiveInputModeTest.php`); only the target IP path needs a new test.

### Item 5 — `NodeGrantCommand` Select permissions multiselect
- [ ] Add a test that exercises the `Select permissions` multiselect branch at `apps/gateway/app/Console/Commands/NodeGrantCommand.php:470`.
- Branch trigger: registry-driven option set (multiselect options are `array_combine`'d from the runtime permissions registry).
- Source quote: `$selected = \Laravel\Prompts\multiselect(`
- Asserts: `expectsChoice('Select permissions', ['<perm1>', '<perm2>'], <registry-derived list>)` plus exit code.
- Notes: Requires a fixture that seeds the permissions registry so the option list is deterministic for assertion.

### Item 6 — `NodeNewCommand` Ingress node select
- [ ] Add a test that exercises the `Ingress node` select branch at `apps/gateway/app/Console/Commands/NodeNewCommand.php:3681`.
- Branch trigger: `$ingressNodes` populated by `activeIngressNodes()`.
- Source quote (line 3681): `$selectedNodeName = select(`
- Asserts: `expectsChoice('Ingress node', '<chosen>', <active-ingress-list>)` plus exit code.
- Notes: Requires a fixture that registers at least two ingress-capable nodes so the select renders a multi-option list. This is the "exclude X when one already exists" style branch the original research goal called out as the motivating example.

### Item 7 — `NodePermissionsCommand` Select permissions multiselect
- [ ] Add a test that exercises the `Select permissions` multiselect branch at `apps/gateway/app/Console/Commands/NodePermissionsCommand.php:126`.
- Branch trigger: registry-driven option set (same `array_combine`-over-registry shape as item 5, different command).
- Source quote: `multiselect(label: 'Select permissions', ...)`
- Asserts: `expectsChoice('Select permissions', ['<perm1>'], <registry-derived list>)` plus exit code.
- Notes: This command's `Consuming node` (line 78) and `Serving node` (line 87) selects are already asserted in `NodeRevokeInteractiveInputModeTest.php` and `ProxyAddInteractiveInputModeTest.php`; only the permissions multiselect needs new coverage.

## Acceptance Criteria

- Seven new tests land (one per item, with item 2 possibly merged into an existing `AppAgentIde` test file).
- `composer test` is green after each item lands.
- Every prompt label asserted matches the source verbatim. Run this audit after the work to confirm no drift:
  ```
  for L in 'Step ID' 'Cloudflare zone' 'Target IP address' 'Select permissions' 'Ingress node'; do
    echo "$L:"
    grep -rE "expects(Question|Choice|Confirmation|OutputToContain).*['\"]${L}" apps/gateway/tests | wc -l
  done
  ```
  Each label should report a non-zero count after the items land.

## Test Plan

- For each item: run only the new/updated test file with `bin/orbit-gateway-pest <path>`, then run the full feature suite with `composer test`.
- No E2E lane required: the gap is in-memory prompt assertion. The existing `*InteractiveInputModeTest.php` convention runs in the feature lane.
- After all seven items: re-run `composer test` and `composer quality-check` once.

## Out of Scope

- The `InteractsWithCommandPrompts` helper trait proposed by Auto Research finding `007-synthesis.md` is explicitly NOT recommended. Subsequent findings (`008-test-assertion-coverage.md`, `009-branch-assertion-gap.md`) showed orbit's existing convention already covers 17 of 24 conditional branches without a wrapper, and the wrapper would not have changed the work for the 7 gaps closed by this plan.
- The 12 LP-using commands with zero mapped tests (from `008-test-assertion-coverage.md`) are not part of this plan. Many are abstract/concern classes or commands covered via differently-named test files that the research's filename heuristic missed. A separate audit can pick them up later if needed.
- The E2E smoke set proposed in `007-synthesis.md` is not part of this plan. orbit's `composer test:e2e:docker` lane already exercises representative interactive commands end-to-end.
