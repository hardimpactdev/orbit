# App development setup defaults general review (corrected tip)

CHECKOUT_PROOF: cwd=/Users/nckrtl/orbit/.worktrees/app-development-setup-defaults; branch=app-development-setup-defaults; head=02c4b873a14f03045a2cec2a0c661829c1638a91; main=e8f126db45649e2ce5e5244ab29c29e03969b63a; status=clean

Same review identity as the prior FIX round (`app-defaults-review`). This is a
delta review of `f6be6ef4e..02c4b873a` against the original brief and the five
prior DEFECT findings, per the persona's corrected-tip rule. The delta touches
10 files, all inside owned scope (app-development setup-step CRUD across docs,
gateway API, and CLI, plus the shared CLI command-visibility dataset), so a
fresh full review is not required.

## Fresh proof

- Proof receipt: `ok=true`, candidate `02c4b873a14f03045a2cec2a0c661829c1638a91`, dirty=false, venue `retained-incus`.
- `composer quality-check`: artifact `.orbit/quality-gates/quality-check-2026-08-26T091946Z-75f72383a2de.json`, `exit_code=0`, 135s, all 50 subgates zero (gateway/cli/core/docs/sdk/ui Pest, Mago, Rector, docs lint, docs references, Cargo).
- Focused: gateway 133 passed / 864 assertions; CLI 215 passed / 474 assertions.
- Retained topology re-synced and passed. Candidate binding independently verified: the four `shasum -a 256` values recorded in `.orbit/evidence/app-development-setup-defaults-retained-incus.md:15-18` match the candidate files byte-for-byte, and the set now includes both corrected files (`AppDevelopmentSetupStepController.php`, `AppDevelopmentSetupStepRemoveCommand.php`).
- The runtime receipt exercises the correction paths directly, not just the original ones: invalid `--after=999999` returned `validation_failed` with `meta.field=after`; temporary default `82` was added, moved before `81`, updated, and removed with force; the remove payload retained `app=fitta`; final app and instance counts and orders unchanged; `https://fitta.test` returned 200.

## Prior findings — disposition

**1. DEFECT (closed) — declining removal confirmation still deleted.**
`apps/cli/app/Commands/App/AppDevelopmentSetupStepRemoveCommand.php:28-44` now inverts the guard: `$source` starts at `'force'`, and when `--force` is absent the command fails closed on JSON, non-interactive, or a declined `confirm()`, only setting `'prompt'` after an affirmative answer. `||` short-circuits so no prompt is attempted in non-interactive mode. All four paths verified correct. Covered by the new test `fails closed when interactive removal confirmation is declined` (`apps/cli/tests/Feature/Commands/App/AppDevelopmentSetupStepCommandTest.php:121-136`), which asserts `expectsConfirmation(..., 'no')`, `assertFailed()`, and `Http::assertNothingSent()`.

**2. DEFECT (closed) — documented not-found code emitted nowhere.**
`1_app-development-setup-step-update.md:13` and `1_app-development-setup-step-remove.md:13` now read `app.development_setup_step_not_found`, matching `AppDevelopmentSetupStepController.php:106,173`. `app.setup_step_not_found` no longer appears anywhere in the repository.

**3. DEFECT (closed) — undocumented `app.development_setup_step_invalid_anchor`.**
The code is gone repo-wide. `AppDevelopmentSetupStepController.php:70-79,83-86,121-132,142-145` now returns `validation_failed` with `error.meta.field`, matching `domains/README.md:496` and the sibling `AppSetupStepController`. Precedence at line 124 (`$before === false || $before !== null ? 'before' : 'after'`) resolves correctly for invalid-before, invalid-after, and both-supplied. Both catch blocks are only reachable with exactly one anchor set, so their field selection is sound. Covered by per-case `assertJsonPath('error.meta.field', ...)` at `AppDevelopmentSetupStepControllerTest.php:249-288`.

**4. DEFECT (closed) — `list` empty-state message.**
`AppDevelopmentSetupStepListCommand.php:36-40` prints `No development setup defaults found.` and returns `SUCCESS` before the table, satisfying `6.1_app-development-setup-step-list_output-render_human.md:4`.

**5. DEFECT (closed) — `add` human output missing order and timeout.**
`AppDevelopmentSetupStepAddCommand.php:56-57` now prints `Order:` and `Timeout:` alongside ID and command, satisfying `6.1_app-development-setup-step-add_output-render_human.md:3`.

Prior POLISH items all addressed: `payload()` takes `$appName` and `target()` no longer eager-loads steps, so the per-step `$s->app` lazy load is gone (`AppDevelopmentSetupStepController.php:229-238`); the duplicate `$step->save()` is removed (`UpdateAppDevelopmentSetupStep.php:74-77`); `firstField()` is deleted and both CLI and fixture use the contract key `order`; the four commands are registered in `CommandListVisibilityTest.php:317-320`; `error.meta.field` is set on every validation path.

## Regression check on the delta

No regression found. Verified: remove-command branch table for all four input combinations; `update()` field-selection precedence; both `InvalidArgumentException` catch blocks reachable only with one anchor set; `payload($model, $target->name)` is equivalent to the former `$s->app?->name` because every step is resolved through `where('app_id', $target->id)`; `UpdateAppDevelopmentSetupStep` still persists command and timeout before the reorder branch on both paths. The copy-on-create producers, ordering actions, Bun tool lifecycle, role baselines, and convergence are untouched by this delta and remain as proven in the prior round.

## Remaining non-blocking observations

- `AppDevelopmentSetupStepController.php:163-170`: the missing-consent backstop returns `meta.reason=destructive_consent_required` without `meta.field=force`; `domains/README.md:346-348` names both. The CLI fails closed before reaching this path, so it is a backstop-only residue.
- No test asserts the new empty-list message or the new `Order:`/`Timeout:` lines; both are single-branch renderer changes covered by the retained CRUD proof.
- `AppDevelopmentSetupStepListCommand.php:35` prints the `Development setup defaults for {app}:` header before the empty-state line, where the sibling `AppSetupStepListCommand` prints the empty message alone. Cosmetic; the contract is satisfied.

---

```text
BLAST_RADIUS: complete
HUMAN_JUDGMENT: not-required
VERDICT: PASS
```
