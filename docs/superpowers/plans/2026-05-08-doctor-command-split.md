# Doctor Command Unified Modes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the unified `orbit doctor` command with integrated verify, interactive, restore, and adopt modes as documented in `docs/domains/11_operation/3_doctor/`.

**Architecture:** Extend `DoctorCommand` to support `--fix`, `--restore`, and `--adopt` options. Annotate issue payloads with `restorable` / `adoptable` flags so the command can filter findings without family knowledge. Split `DoctorReportRunner` into a `probe()` step (read-only) and an `apply()` step (mutating) so interactive mode can probe first and apply per-finding choices later. Build a framed-panel human renderer matching `6.1_doctor_output-render_human.md`. Update all runtime `next_command` producers to emit `doctor --fix --family=… --restore` or `doctor --fix --family=… --adopt`.

**Tech Stack:** Laravel 13 console commands, Saloon gateway requests, Pest tests, Laravel Prompts (`Prompts::fake()` for prompt assertions), existing Orbit doctor probe/fixer services.

## Status

**Completed:**
- ✅ Task 1: Add Fix Mode Options to DoctorCommand (all tests passing)
- ✅ Task 2: Add Mode Validation and App-Node Denial (folded into Task 1 work)

**Remaining:**
- ✅ Task 3: Annotate Issues with `restorable` / `adoptable` Flags
- ✅ Task 4: Split `DoctorReportRunner` into `probe()` and `apply()`
- ✅ Task 5: Implement Interactive Mode with Prompts
- ✅ Task 6: Implement Bulk Modes (Restore/Adopt)
- ✅ Task 7: Build Framed Panel Human Renderer
- ✅ Task 8: Update JSON Renderer Contract
- ✅ Task 9: Update Gateway API for Fix Modes
- ✅ Task 10: Update Doctor Command Documentation References
- ✅ Task 11: Update Runtime Handoff Strings
- ✅ Task 12: Update E2E Command Invocations
- ✅ Task 13: Final Quality Gate

---

## File Map

- Modify `app/Console/Commands/DoctorCommand.php`: implement interactive prompts, bulk mode filtering, framed-panel human render, gateway request selection by mode.
- Modify `app/Services/Doctor/DoctorReportRunner.php`: split into `probe()` (read-only diff collection) and `apply()` (fixer/adopter dispatch); deprecate `run()` or keep it as a back-compat wrapper.
- Modify family probes/diff entries to emit `restorable` / `adoptable` flags on issue payloads:
  - `app/Services/Nodes/NodesProbe.php`
  - `app/Services/Apps/AppsProbe.php`
  - `app/Services/Workspaces/WorkspacesProbe.php`
  - `app/Services/Processes/ProcessesProbe.php`
  - `app/Services/Proxy/ProxyRouteProbe.php`
  - `app/Services/Firewall/FirewallRuleProbe.php`
  - `app/Services/Tools/ToolsProbe.php`
  - `app/Services/Schedules/SchedulesProbe.php`
- Modify `app/Http/Controllers/Api/DoctorRunController.php`: verify-only endpoint; reject `mode` parameter values other than `verify`.
- Create `app/Http/Controllers/Api/DoctorFixController.php`: gateway API endpoint for `restore` and `adopt`; deny app-node callers; map mode validation to `restore` or `adopt`.
- Modify `app/Http/Gateway/Requests/Doctor/RunDoctorRequest.php`: drop `mode` argument; verify-only.
- Create `app/Http/Gateway/Requests/Doctor/FixDoctorRequest.php`: take `mode: 'restore'|'adopt'`; post to `/api/doctor/fix`.
- Modify `routes/api.php`: add `POST /api/doctor/fix` route.
- Modify runtime handoff producers (every `next_command` listed in Task 11 audit).
- Modify command docs that still reference the reverted `doctor:fix` command.
- Update/create tests:
  - `tests/Feature/Commands/Operations/DoctorCommandContractTest.php` (update for modes)
  - `tests/Feature/Commands/Operations/DoctorInteractiveInputTest.php` (new)
  - `tests/Feature/Commands/Operations/DoctorNonInteractiveInputTest.php` (new)
  - `tests/Feature/Commands/Operations/DoctorJsonRendererTest.php` (update)
  - `tests/Feature/Commands/Operations/DoctorHumanRendererTest.php` (update)
  - `tests/Feature/Http/Api/DoctorRunControllerTest.php` (update)
  - `tests/Feature/Http/Api/DoctorFixControllerTest.php` (new)
  - `tests/Unit/Http/Gateway/Requests/Doctor/FixDoctorRequestTest.php` (new)
  - Family probe tests covering `restorable` / `adoptable` flag emission for each kind.
- Update existing tests that assert old `next_command` strings.
- Update E2E commands using `php artisan doctor --fix` or `php artisan doctor --adopt` to use `--fix --restore` or `--fix --adopt`.

---

### Task 1: Add Fix Mode Options to DoctorCommand ✅

**Files:**
- ✅ Modified: `app/Console/Commands/DoctorCommand.php`
- ✅ Modified: `tests/Feature/Commands/Operations/DoctorCommandContractTest.php`

**Summary:** Added `--fix`, `--restore`, `--adopt` options to signature. Updated `mode()` method to return `verify`, `interactive`, `restore`, `adopt`. Updated `effect()` method for activity logging. Updated `DoctorReportRunner` to normalize action modes. All 32 tests passing.

- [x] **Step 1: Write failing signature tests**
- [x] **Step 2: Run tests and confirm failure**
- [x] **Step 3: Add options to signature**
- [x] **Step 4: Update mode() method to support all modes**
- [x] **Step 5: Run focused tests**

---

### Task 2: Add Mode Validation and App-Node Denial ✅

**Files:**
- ✅ Modified: `app/Console/Commands/DoctorCommand.php`
- ✅ Modified: `tests/Feature/Commands/Operations/DoctorCommandContractTest.php`

**Summary:** Validation and denial logic implemented as part of Task 1's work.
- `--restore` and `--adopt` mutual exclusion check before probes (`DoctorCommand::executeDoctor`).
- App-node caller denial when `mode !== 'verify'` returns `caller_role_not_allowed`.
- `effect()` returns `ActivityLogType::Write` for `interactive`, `restore`, and `adopt` modes; otherwise `Read`.
- Test cases at `it('rejects mutually exclusive restore and adopt flags before probes')` and the app-node denial assertion (line 174 expecting `caller_role_not_allowed`).

- [x] **Step 1: Validation tests in place**
- [x] **Step 2: Validation logic in place**
- [x] **Step 3: `effect()` updated**
- [x] **Step 4: Tests pass (32/32)**

---

### Task 3: Annotate Issues with `restorable` / `adoptable` Flags

**Goal:** Add boolean `restorable` and `adoptable` fields to every issue payload emitted by `DoctorReportRunner`. Tasks 5 and 6 (interactive prompts, bulk filtering) and Task 8 (JSON renderer contract from `6.2_doctor_output-render_json.md`) depend on these flags.

**Files:**
- Modify: `app/Services/Doctor/DoctorReportRunner.php` (every issue-builder method)
- Modify: each family probe diff method or annotation seam (see File Map)
- Modify: `tests/Feature/Commands/Operations/DoctorCommandContractTest.php` (assert flags appear on JSON payloads)
- Create: per-family probe tests asserting flag values for each kind they emit

**Annotation rules** (derived from `6.2_doctor_output-render_json.md` and current fixer/adopter coverage):

| Family | Kind | `restorable` | `adoptable` | Reason |
| --- | --- | --- | --- | --- |
| `node` | `unverifiable` | false | false | No probe data → cannot fix or adopt blindly |
| `node` | `missing` / `divergent` | true | false | `NodesProbe`/related fixer can re-apply intent |
| `app` | `missing` / `divergent` | true | false | `AppsProbe` repair is intent-to-reality |
| `workspace` | `missing` / `divergent` | true | false | Workspace creation/restore is intent-to-reality |
| `process` | `missing` / `divergent` | true | false | Process re-supervise is intent-to-reality |
| `proxy` | `missing` / `divergent` | true | false | `ProxyRouteFixer` covers these |
| `proxy` | `extra` | true | true | `ProxyRouteFixer::removeExtra` (restore) and `ProxyRouteAdopter::adopt` (adopt) both supported |
| `firewall_rule` | `missing` / `divergent` | true | false | `FirewallRuleFixer` covers these |
| `firewall_rule` | `extra` | true | true | Fixer can remove; `FirewallRuleProbe::adopt` can ingest |
| `tool` | `missing` / `divergent` | true | false | `ToolsFixer` covers these |
| `tool` | `extra` | false | false | No remover or adopter today |
| `schedule` | `missing` / `divergent` | true | false | `SchedulesFixer` covers these |
| `schedule` | `extra` | false | false | No remover or adopter today |

If any cell above contradicts current fixer/adopter capabilities, treat the implementation source as authoritative and update this table during implementation.

- [ ] **Step 1: Add helper for flag computation**

In `DoctorReportRunner` (or a new `DoctorIssueAnnotator` if the runner grows large), add a private method:

```php
/**
 * @return array{restorable: bool, adoptable: bool}
 */
private function annotate(string $family, string $kind): array
{
    return match (true) {
        $family === 'proxy' && $kind === 'extra' => ['restorable' => true, 'adoptable' => true],
        $family === 'firewall_rule' && $kind === 'extra' => ['restorable' => true, 'adoptable' => true],
        $kind === 'unverifiable' => ['restorable' => false, 'adoptable' => false],
        in_array($family, ['tool', 'schedule'], true) && $kind === 'extra' => ['restorable' => false, 'adoptable' => false],
        in_array($kind, ['missing', 'divergent'], true) => ['restorable' => true, 'adoptable' => false],
        default => ['restorable' => false, 'adoptable' => false],
    };
}
```

Apply this in every `*IssuePayload` method so flags merge into the returned array. The `extra`-kind payloads (synthesized inline, e.g. proxy-extra around line 198 of the runner) must also receive flags.

- [ ] **Step 2: Test flag values per family/kind**

Add a focused test or extend an existing renderer test:

```php
it('annotates proxy-extra issues as both restorable and adoptable', function (): void {
    createDoctorLocalNode('gateway');
    // Seed a ProxyRoute pointing to a node where the route is missing on disk
    // Run runner in verify mode
    // Assert the issue payload has restorable=true, adoptable=true
});

it('annotates app-missing issues as restorable but not adoptable', function (): void {
    // Seed App with reality missing; expect restorable=true, adoptable=false
});
```

Cover at minimum: `proxy/extra`, `firewall_rule/extra`, `tool/missing`, `schedule/extra` (false/false), `node/unverifiable` (false/false).

- [ ] **Step 3: Update JSON renderer assertions**

The existing `DoctorJsonRendererTest` should now assert `restorable` and `adoptable` keys exist on every issue object.

- [ ] **Step 4: Run focused tests**

```bash
php artisan test --compact tests/Feature/Commands/Operations
```

Expected: PASS.

---

### Task 4: Split `DoctorReportRunner` into `probe()` and `apply()`

**Goal:** Separate read-only probing from mutating fix/adopt application. Interactive mode (Task 5) needs to probe once, prompt per finding, then apply only the chosen actions. Today `DoctorReportRunner::run()` does both atomically and treats every non-verify mode as "apply everything".

**Files:**
- Modify: `app/Services/Doctor/DoctorReportRunner.php`
- Modify: `app/Console/Commands/DoctorCommand.php` (call new methods instead of `run()`)
- Modify: `app/Http/Controllers/Api/DoctorRunController.php` (use `probe()` only)

**Target shape:**

```php
final readonly class DoctorReportRunner
{
    /**
     * @return array{healthy: bool, mode: 'verify', scope: array, summary: array, issues: list<array>, actions: list<array>}
     */
    public function probe(Node $node, array $families = []): array;

    /**
     * @param  list<array<string,mixed>>  $issues  Issues to act on; caller has already filtered by mode.
     * @return list<array<string,mixed>>  Action records.
     */
    public function apply(Node $node, string $mode, array $issues): array;

    /**
     * @param  array<string,mixed>  $probe
     * @param  list<array<string,mixed>>  $actions
     * @return array{healthy: bool, mode: string, scope: array, summary: array, issues: list<array>, actions: list<array>}
     */
    public function finalize(array $probe, string $mode, array $actions): array;

    /**
     * Convenience wrapper that probes then applies all eligible issues for the mode.
     * Used by bulk modes (restore/adopt) and the existing API verify path.
     *
     * @return array{healthy: bool, mode: string, scope: array, summary: array, issues: list<array>, actions: list<array>}
     */
    public function run(Node $node, string $mode = 'verify', array $families = []): array;
}
```

`probe()` returns `mode: 'verify'`, an empty `actions` array, and the full `issues` list with `restorable`/`adoptable` flags populated.

`apply()` takes a pre-filtered subset of issues and returns action records (with `mode` already set to `restore` or `adopt`). It does **not** re-run probes.

`run()` keeps the current behavior: in verify mode it just probes; in fix modes it probes, filters issues by the matching flag, then applies. Bulk callers (Task 6) use this. The API gateway (Task 9) uses `probe()` for verify and `apply()` for fix.

- [ ] **Step 1: Write tests pinning the new contract**

```php
it('probe() returns issues with no actions, mode=verify', function (): void {
    createDoctorLocalNode('gateway');
    // Seed at least one drift
    $result = app(DoctorReportRunner::class)->probe(localGatewayNode());
    expect($result['mode'])->toBe('verify')
        ->and($result['actions'])->toBe([])
        ->and($result['issues'])->not->toBeEmpty();
});

it('apply() runs only the issues handed to it and returns action records', function (): void {
    // Seed two drifts
    $probe = app(DoctorReportRunner::class)->probe(localGatewayNode());
    $picked = [$probe['issues'][0]];
    $actions = app(DoctorReportRunner::class)->apply(localGatewayNode(), 'restore', $picked);
    expect($actions)->toHaveCount(1)
        ->and($actions[0]['mode'])->toBe('restore');
});

it('run() in verify mode equals probe()', function (): void {
    // Both should produce identical issues + empty actions
});

it('run() in restore mode probes then applies all restorable issues', function (): void {
    // Seed a mix: one restorable, one adoptable-only
    $result = app(DoctorReportRunner::class)->run(localGatewayNode(), 'restore');
    // Expect actions only for the restorable one
});
```

- [ ] **Step 2: Implement `probe()` by extracting the diff-collection loop**

Move the issue-collection sections from `run()` into `probe()`. Skip every fix/adopt path; produce the same issue payloads (now with annotation flags from Task 3).

- [ ] **Step 3: Implement `apply()` by reusing the existing fixer/adopter dispatch**

Take the pre-filtered issue list. For each issue, dispatch to the matching family fixer or adopter (the same code paths that `run()` currently invokes). Return action records with `mode` set to the caller's mode value.

- [ ] **Step 4: Refactor `run()` to call `probe()` then optionally `apply()`**

```php
public function run(Node $node, string $mode = 'verify', array $families = []): array
{
    $probe = $this->probe($node, $families);

    if ($mode === 'verify') {
        return $probe;
    }

    $eligible = array_values(array_filter(
        $probe['issues'],
        fn (array $issue): bool => match ($mode) {
            'restore' => ($issue['restorable'] ?? false) === true,
            'adopt' => ($issue['adoptable'] ?? false) === true,
            default => false,
        },
    ));

    $actions = $this->apply($node, $mode, $eligible);

    // Re-derive healthy + summary from probe + actions
    return $this->finalize($probe, $mode, $actions);
}
```

- [ ] **Step 5: Update `DoctorCommand` and `DoctorRunController` call sites**

`DoctorCommand` keeps using `run()` for bulk modes. `DoctorRunController` switches to `probe()` (verify-only). Interactive mode (Task 5) will call `probe()` directly.

- [ ] **Step 6: Run tests**

```bash
php artisan test --compact tests/Feature/Commands/Operations tests/Feature/Http/Api
```

Expected: PASS.

---

### Task 5: Implement Interactive Mode with Prompts

**Files:**
- Modify: `app/Console/Commands/DoctorCommand.php`
- Create: `tests/Feature/Commands/Operations/DoctorInteractiveInputTest.php`

**Behavior** (from `5.1_doctor_input-mode_interactive.md`):

1. When `--fix` is set without `--restore` or `--adopt` and the process is attached to a TTY (and `--json` is not set), enter interactive mode.
2. Run `probe()` to collect issues.
3. Walk issues in family order, then by kind order (`unverifiable`, `missing`, `divergent`, `extra`).
4. For each issue with at least one supported direction (`restorable` or `adoptable`), render a `select` prompt with options derived from the flags. Issues with neither flag are skipped silently.
5. Apply chosen action via `apply()` for that single issue. Record the resulting action.
6. If the user picks `details`, show `issue.detail` and re-prompt the same finding.
7. If the user picks `skip`, append a skipped action record and move on.
8. Render the final result (Task 7 covers the framed panel).

**Interactive mode preconditions:**
- `--fix` is set without `--restore` or `--adopt`.
- The process is attached to a TTY (`$this->input->isInteractive()` returns true).
- `--json` is not set (JSON disables interactive mode).

**Validation for non-interactive `--fix`:** When `--fix` is set without `--restore`/`--adopt` AND (`$this->input->isInteractive()` is false OR `$this->wantsJson()`), fail before probes with `validation_failed` and message "Use --restore or --adopt for non-interactive fix runs." This ensures that bulk modes require explicit direction in shell pipelines and CI. Added in Step 2 of this task.

- [ ] **Step 1: Add the prompt seam**

```php
use function Laravel\Prompts\select;

protected function chooseAction(array $issue): string
{
    $options = $this->actionOptions($issue);
    return select(
        label: 'Resolve '.($issue['summary'] ?? $issue['key'] ?? 'finding'),
        options: $options,
        default: array_key_first($options),
    );
}

/**
 * @return array<string,string>
 */
protected function actionOptions(array $issue): array
{
    $options = [];
    if (($issue['restorable'] ?? false) === true) {
        $options['restore'] = 'Restore gateway intent on the node';
    }
    if (($issue['adoptable'] ?? false) === true) {
        $options['adopt'] = 'Adopt node reality into the gateway';
    }
    $options['skip'] = 'Skip';
    if (! empty($issue['detail'] ?? null)) {
        $options['details'] = 'Show details';
    }
    return $options;
}
```

- [ ] **Step 2: Add the interactive walk in `executeDoctor()`**

When `mode === 'interactive'`:

```php
if (! $this->input->isInteractive() || $this->wantsJson()) {
    return $this->failCommand(
        code: 'validation_failed',
        message: 'Use --restore or --adopt for non-interactive fix runs.',
        meta: ['fields' => ['restore', 'adopt']],
    );
}

$probe = $this->isGatewayCaller()
    ? $runner->probe($this->localDoctorNode(), $families)
    : $this->sendGatewayDoctor('verify', $families);

if ($probe instanceof GatewayApiException) {
    return $this->failCommand(
        code: $probe->errorCode() ?? 'gateway_unavailable',
        message: $probe->getMessage(),
        meta: $probe->errorMeta(),
    );
}

$actions = [];
foreach ($this->orderedIssues($probe['issues']) as $issue) {
    if (($issue['restorable'] ?? false) === false && ($issue['adoptable'] ?? false) === false) {
        continue;
    }
    do {
        $choice = $this->chooseAction($issue);
        if ($choice === 'details') {
            $this->renderIssueDetails($issue);
        }
    } while ($choice === 'details');

    if ($choice === 'skip') {
        $actions[] = $this->skippedAction($issue, 'interactive');
        continue;
    }

    $applied = $this->isGatewayCaller()
        ? $runner->apply($this->localDoctorNode(), $choice, [$issue])
        : $this->sendGatewayDoctor($choice, $families, [$issue]);

    if ($applied instanceof GatewayApiException) {
        return $this->failCommand(
            code: $applied->errorCode() ?? 'gateway_unavailable',
            message: $applied->getMessage(),
            meta: $applied->errorMeta(),
        );
    }

    $actions = [
        ...$actions,
        ...$this->doctorList($applied, 'actions'),
    ];
}

$result = $this->finalizeInteractiveResult($probe, $actions);
```

`orderedIssues()` reuses the existing `groupDoctorIssues` order (family order then kind order).

- [ ] **Step 3: Concrete tests**

```php
use Laravel\Prompts\Prompt;

beforeEach(fn () => Prompt::fake());

it('prompts per finding with both directions when restorable and adoptable', function (): void {
    createDoctorLocalNode('gateway');
    // Seed a proxy/extra issue (restorable=true, adoptable=true)
    Prompt::fake(['restore']);

    Artisan::call('doctor', ['--fix' => true]);

    Prompt::assertOutputContains('Resolve');
    expect(Artisan::output())->toContain('restore');
});

it('skips unsupported findings without prompting', function (): void {
    // Seed an unverifiable node issue (restorable=false, adoptable=false)
    Prompt::fake([]);  // No prompts expected
    $exitCode = Artisan::call('doctor', ['--fix' => true]);
    expect($exitCode)->toBe(1);  // Drift remains
});

it('shows details and re-prompts when user picks details', function (): void {
    // Seed an issue with detail data
    Prompt::fake(['details', 'skip']);
    Artisan::call('doctor', ['--fix' => true]);
    Prompt::assertOutputContains('detail');
});

it('rejects --fix without direction in non-TTY contexts', function (): void {
    // --json forces non-interactive
    $exitCode = Artisan::call('doctor', ['--fix' => true, '--json' => true]);
    expect($exitCode)->toBe(1);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($payload['error']['code'])->toBe('validation_failed');
});
```

- [ ] **Step 4: Run focused tests**

```bash
php artisan test --compact tests/Feature/Commands/Operations/DoctorInteractiveInputTest.php
```

Expected: PASS.

---

### Task 6: Implement Bulk Modes (Restore/Adopt)

**Files:**
- Modify: `app/Console/Commands/DoctorCommand.php`
- Create: `tests/Feature/Commands/Operations/DoctorNonInteractiveInputTest.php`

**Behavior** (from `5.2_doctor_input-mode_non-interactive.md`):

- When `mode === 'restore'`: call `runner->run($node, 'restore', $families)` (which probes, filters by `restorable`, applies).
- When `mode === 'adopt'`: same with `'adopt'` and `adoptable`.
- Action records emitted by `run()` already have the right `mode`.
- If the resolved scope produces zero eligible issues for the chosen direction, render the result anyway (no actions, issues remain) and exit `1` with `drift_detected` so callers don't think the run succeeded.

- [ ] **Step 1: Write bulk mode tests**

```php
it('applies restore actions to all restorable findings', function (): void {
    createDoctorLocalNode('gateway');
    // Seed two restorable issues + one adoptable-only

    $exitCode = Artisan::call('doctor', [
        '--fix' => true,
        '--restore' => true,
        '--family' => ['proxy'],
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($payload['success']['data']['doctor']['mode'])->toBe('restore')
        ->and($payload['success']['data']['doctor']['actions'])->toHaveCount(2);
});

it('applies adopt actions to all adoptable findings', function (): void {
    // Mirror with --adopt
});

it('records skipped actions for unsupported findings in restore mode', function (): void {
    // Seed an issue restorable=false, then call --fix --restore
    // Expect action record with status=skipped, mode=restore
});

it('exits with drift_detected when no eligible findings exist for the direction', function (): void {
    // Seed only adoptable-false issues, call --fix --restore --json
    // Expect exit 1 + error.code=drift_detected
});
```

- [ ] **Step 2: Implement bulk dispatch**

In `executeDoctor()`, for `mode in ['restore', 'adopt']`:

```php
$result = $this->isGatewayCaller()
    ? $runner->run($node, $mode, $families)
    : $this->runGatewayDoctor($mode, $families);
```

`run()` already handles filter-by-flag and apply (Task 4 step 4). No further filtering needed at the command level.

- [ ] **Step 3: Run focused tests**

```bash
php artisan test --compact tests/Feature/Commands/Operations/DoctorNonInteractiveInputTest.php
```

Expected: PASS.

---

### Task 7: Build Framed Panel Human Renderer

**Goal:** Replace the current line-based human renderer with the framed panel format defined in `docs/domains/11_operation/3_doctor/technical/6.1_doctor_output-render_human.md`.

**Files:**
- Modify: `app/Console/Commands/DoctorCommand.php` (rewrite `renderHuman` and supporting methods)
- Create or modify: `tests/Feature/Commands/Operations/DoctorHumanRendererTest.php`

**Required render features:**

1. **Per-mode title** at the top border:
   - Verify: `D O C T O R  R E S U L T`
   - Interactive: `D O C T O R  I N T E R A C T I V E`
   - Restore: `D O C T O R  R E S T O R E`
   - Adopt: `D O C T O R  A D O P T`
   - In-progress (any mode): `D O C T O R I N G`
2. **Target line** in the second divider:
   - In progress: `Performing check-up on <target>`
   - Final success: `Successfully performed check-up on <target>`
   - Probe failure: `Check-up on <target> could not complete`
   - `<target>` is the resolved node name when scope targets one node, otherwise `selected scope`.
3. **Category rows** in family order with status text per the table in 6.1:
   - `Queued`, `Checking`, `Gathering results`, `OK`, `<n> issue detected`/`<n> issues found`, `Skipped, no <category data> configured`, `Unavailable, <reason>`, `Fixing`/`Adopting`, `<n> fixed`/`<n> adopted`/`<n> skipped`/`<n> failed`
4. **Family-specific issue tables** rendered inline below the owning category row, with columns from the table in 6.1:
   - `node`: `NODE`, `ISSUE`, `SUMMARY`
   - `app`: `APP`, `ISSUE`, `SUMMARY`
   - `workspace`: `WORKSPACE`, `ISSUE`
   - `process`: `APP`, `PROCESS`, `ISSUE`, `SUMMARY`
   - `proxy`: `DOMAIN`, `ISSUE`, `SUMMARY`
   - `firewall_rule`: `NODE`, `RULE`, `ISSUE`, `SUMMARY`
   - `tool`: `NODE`, `TOOL`, `ISSUE`, `SUMMARY`
   - `schedule`: `NODE`, `SCHEDULE`, `ISSUE`, `SUMMARY`
   - Add `NEXT` column when any row has a manual next step.
5. **Action tables** rendered inline below owning category row only in fix modes (interactive/restore/adopt), never in verify mode.
6. **Empty-scope rows** (`Skipped, no <category data> configured`) for families that produced zero rows.
7. **Centered summary** at the bottom:
   - Healthy: `No issues detected`
   - Verify unhealthy: `<n> issues detected across <m> categories` plus the next-action line `Run doctor --fix to restore, adopt, or skip findings`
   - Fix mode healthy: `No issues remaining; <n> actions completed`
   - Fix mode unhealthy: `<n> issues remaining across <m> categories`
   - Failed actions: `<n> actions failed; review remaining issues`

**Out of scope:** True per-row in-place updates while probes stream. The renderer may render the in-progress panel once before probes start and the final panel after `run()` returns. Do not implement live re-render unless probe streaming exists.

- [ ] **Step 1: Write renderer tests**

`DoctorHumanRendererTest.php` should assert:

```php
it('renders verify-mode title and target line', function (): void {
    // Healthy run, single-node target
    Artisan::call('doctor', ['--node' => 'beast']);
    $out = Artisan::output();
    expect($out)->toContain('D O C T O R  R E S U L T')
        ->and($out)->toContain('Successfully performed check-up on beast');
});

it('renders interactive-mode title', function (): void {
    Prompt::fake(['skip']);
    Artisan::call('doctor', ['--fix' => true]);
    expect(Artisan::output())->toContain('D O C T O R  I N T E R A C T I V E');
});

it('renders restore-mode title', function (): void {
    Artisan::call('doctor', ['--fix' => true, '--restore' => true]);
    expect(Artisan::output())->toContain('D O C T O R  R E S T O R E');
});

it('renders adopt-mode title', function (): void {
    Artisan::call('doctor', ['--fix' => true, '--adopt' => true]);
    expect(Artisan::output())->toContain('D O C T O R  A D O P T');
});

it('renders family-specific issue tables', function (): void {
    // Seed a workspace issue
    Artisan::call('doctor');
    expect(Artisan::output())->toContain('WORKSPACE')
        ->and(Artisan::output())->not->toContain('NODE / KEY / SUMMARY');
});

it('renders empty-scope rows for selected families with no rows', function (): void {
    Artisan::call('doctor');
    expect(Artisan::output())->toContain('Skipped, no');
});

it('shows verify next-action prose when drift remains', function (): void {
    // Seed drift
    Artisan::call('doctor');
    expect(Artisan::output())->toContain('Run doctor --fix');
});

it('does not render action tables in verify mode', function (): void {
    Artisan::call('doctor');
    expect(Artisan::output())->not->toContain('Actions');
});

it('renders action results inline in fix modes', function (): void {
    // Seed restorable drift, run --fix --restore
    Artisan::call('doctor', ['--fix' => true, '--restore' => true]);
    expect(Artisan::output())->toMatch('/\d+ fixed|\d+ adopted|\d+ skipped|\d+ failed/');
});
```

- [ ] **Step 2: Implement frame primitives**

Build small helpers:
- `frameTopBorder(string $title, int $width)`
- `frameTargetDivider(string $targetLine, int $width)`
- `frameSummaryDivider(int $width)`
- `frameBottom(int $width)`
- `frameCategoryRow(string $label, string $status, int $width)`
- `frameInlineTable(array $headers, array $rows, int $width)`

`$width` is fixed (e.g. 80) or computed from terminal width with a sensible minimum.

- [ ] **Step 3: Implement category-row state machine**

Map probe results / action results to status text per the table in section 7.0.3 above.

- [ ] **Step 4: Implement family-specific issue tables**

Map family key → column set; resolve column values from the issue payload (node/app/workspace name lookups already exist in the runner's payload methods).

- [ ] **Step 5: Implement summary line selection**

Driven by `mode` and `summary` counts. Compose the next-action prose for verify mode only.

- [ ] **Step 6: Run focused tests**

```bash
php artisan test --compact tests/Feature/Commands/Operations/DoctorHumanRendererTest.php
```

Expected: PASS.

---

### Task 8: Update JSON Renderer Contract

**Files:**
- Modify: `app/Console/Commands/DoctorCommand.php` (JSON envelope already in `jsonSuccess`/`failCommand`; ensure the doctor payload is forwarded unchanged)
- Modify: `tests/Feature/Commands/Operations/DoctorJsonRendererTest.php`

**Required JSON contract** (from `6.2_doctor_output-render_json.md`):

- `mode` ∈ `verify`, `interactive`, `restore`, `adopt`.
- `scope` includes `families`, `node`, `self`, `app`, `workspace`.
- `summary` includes `issues`, `fixed`, `adopted`, `skipped`, `conflicts`, `failed`.
- `issues[]` includes `family`, `node`, `kind`, `code`, `key`, `summary`, `restorable`, `adoptable`, optional `details`.
- `actions[]` array is always present. Empty in verify mode. Populated in fix modes with `family`, `node`, `key`, `mode`, `status`, `summary`, optional `details`.

- [ ] **Step 1: Tests**

```php
it('emits restorable and adoptable on every issue', function (): void {
    // Seed a mix
    Artisan::call('doctor', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    foreach ($payload['error']['data']['doctor']['issues'] as $issue) {
        expect($issue)->toHaveKeys(['restorable', 'adoptable']);
    }
});

it('emits empty actions array in verify mode', function (): void {
    Artisan::call('doctor', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $doctor = $payload['success']['data']['doctor'] ?? $payload['error']['data']['doctor'];
    expect($doctor['actions'])->toBe([]);
});

it('emits action records in fix modes', function (): void {
    Artisan::call('doctor', ['--fix' => true, '--restore' => true, '--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $doctor = $payload['success']['data']['doctor'] ?? $payload['error']['data']['doctor'];
    expect($doctor['mode'])->toBe('restore')
        ->and($doctor['actions'][0])->toHaveKeys(['family', 'node', 'key', 'mode', 'status', 'summary']);
});
```

- [ ] **Step 2: Adjust runner emission if needed**

Verify that `DoctorReportRunner::probe()` always returns `actions: []` and that `DoctorReportRunner::apply()` emits action records in the documented shape. No additional command-level transformation should be required.

- [ ] **Step 3: Run focused tests**

```bash
php artisan test --compact tests/Feature/Commands/Operations/DoctorJsonRendererTest.php
```

Expected: PASS.

---

### Task 9: Update Gateway API for Fix Modes

**Files:**
- Modify: `app/Http/Controllers/Api/DoctorRunController.php`
- Create: `app/Http/Controllers/Api/DoctorFixController.php`
- Modify: `app/Http/Gateway/Requests/Doctor/RunDoctorRequest.php`
- Create: `app/Http/Gateway/Requests/Doctor/FixDoctorRequest.php`
- Modify: `routes/api.php`
- Modify: `app/Console/Commands/DoctorCommand.php` (`runGatewayDoctor`)
- Modify: `tests/Feature/Http/Api/DoctorRunControllerTest.php`
- Create: `tests/Feature/Http/Api/DoctorFixControllerTest.php`
- Create: `tests/Unit/Http/Gateway/Requests/Doctor/FixDoctorRequestTest.php`

**Mode mapping:** `DoctorRunController` is verify-only and always emits `mode=verify`. `DoctorFixController` accepts and emits `restore` or `adopt`. The gateway API never receives `mode=interactive`; interactive mode is a CLI orchestration mode where the CLI prompts locally, then sends concrete restore/adopt apply requests to the gateway when the caller is a control node.

**Caller-role transport rules:**
- Gateway caller, verify: call `$runner->probe($localGatewayNode, $families)` locally.
- Gateway caller, interactive: call `$runner->probe($localGatewayNode, $families)`, prompt locally, then call `$runner->apply($localGatewayNode, $choice, [$issue])` locally per selected issue.
- Gateway caller, restore/adopt: call `$runner->run($localGatewayNode, $mode, $families)` locally.
- Control caller, verify: send `RunDoctorRequest` to `/api/doctor/run`.
- Control caller, interactive: send `RunDoctorRequest` to get findings, prompt locally, then send `FixDoctorRequest` to `/api/doctor/fix` for each selected restore/adopt issue.
- Control caller, restore/adopt: send `FixDoctorRequest` to `/api/doctor/fix` with no issue subset so the gateway applies all eligible findings in scope.
- App caller, verify: send `RunDoctorRequest` or use the authorized gateway path defined by caller-role transport.
- App caller, any fix mode: fail before side effects unless a family contract explicitly allows a narrow exception.

`FixDoctorRequest` therefore needs an optional `issues` payload. When `issues` is omitted, the gateway runs bulk mode for all eligible findings in scope. When `issues` contains one or more issue payloads, the gateway applies only those selected issues.

- [ ] **Step 1: Tighten DoctorRunController to verify-only**

```php
$mode = 'verify';
// remove the request->input('mode') logic entirely
$doctor = $runner->probe($caller, $families);
```

Drop the app-node denial branch (verify is allowed for app callers per the contract).

- [ ] **Step 2: Create DoctorFixController**

```php
final class DoctorFixController implements Loggable
{
    public function __invoke(Request $request, DoctorReportRunner $runner, DoctorScopeValidator $validator): JsonResponse
    {
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return response()->json([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'Peer identity unknown.',
                    'meta' => [],
                ],
            ], 403);
        }

        $mode = $request->input('mode');

        if (! is_string($mode) || ! in_array($mode, ['restore', 'adopt'], true)) {
            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Doctor fix mode must be restore or adopt.',
                    'meta' => ['fields' => ['mode']],
                ],
            ], 422);
        }

        if ($caller->role === 'app') {
            return response()->json([
                'error' => [
                    'code' => 'caller_role_not_allowed',
                    'message' => 'App-node callers may not run doctor --fix for this scope.',
                    'meta' => ['caller_role' => 'app', 'mode' => $mode],
                ],
            ], 403);
        }

        $families = $this->families($request);
        $failure = $validator->validate($families, $runner);

        if ($failure instanceof DoctorValidationFailure) {
            return response()->json([
                'error' => [
                    'code' => $failure->code,
                    'message' => $failure->message,
                    'meta' => $failure->meta,
                ],
            ], 422);
        }

        $issues = $request->input('issues');
        $doctor = is_array($issues)
            ? $runner->finalize(
                probe: $runner->probe($caller, $families),
                mode: $mode,
                actions: $runner->apply($caller, $mode, array_values(array_filter($issues, is_array(...)))),
            )
            : $runner->run($caller, $mode, $families);

        return response()->json(['success' => ['data' => ['doctor' => $doctor]]]);
    }
    public function effect(): ActivityLogType { return ActivityLogType::Write; }
    public function type(): string { return 'api:POST /doctor/fix'; }
    public function subject(): ?Model { return null; }
    public function properties(): array { return []; }
    public function description(): ?string { return null; }
}
```

- [ ] **Step 3: Update gateway requests**

`RunDoctorRequest`: remove the `mode` constructor argument and stop sending it.

`FixDoctorRequest`: take `mode: 'restore'|'adopt'`, families, node, self, app, workspace, and optional `issues`. POST to `/api/doctor/fix`. Reuse `DoctorRunResponse` as the DTO type — both endpoints return the same envelope.

- [ ] **Step 4: Add the route**

In `routes/api.php`:

```php
Route::post('/doctor/fix', DoctorFixController::class);
```

- [ ] **Step 5: Update CLI gateway transport helpers**

```php
private function runGatewayDoctor(string $mode, array $families): array|GatewayApiException
{
    return $this->sendGatewayDoctor($mode, $families);
}

/**
 * @param  list<string>  $families
 * @param  list<array<string,mixed>>|null  $issues
 * @return array<string,mixed>|GatewayApiException
 */
private function sendGatewayDoctor(string $mode, array $families, ?array $issues = null): array|GatewayApiException
{
    try {
        $scope = [
            'node' => $this->stringOption('node'),
            'self' => (bool) $this->option('self'),
            'app' => $this->stringOption('app'),
            'workspace' => $this->stringOption('workspace'),
        ];

        $request = $mode === 'verify'
            ? new RunDoctorRequest(families: $families, ...$scope)
            : new FixDoctorRequest(mode: $mode, families: $families, issues: $issues, ...$scope);

        $dto = app(GatewayConnector::class)->send($request)->dto();
    } catch (GatewayApiException $e) {
        return $e;
    } catch (Throwable) {
        return new GatewayApiException(
            message: 'Gateway connection is required to run doctor.',
            errorCode: 'gateway_unavailable',
            errorMeta: [],
        );
    }

    return $dto->doctor;
}
```

Interactive control-node mode uses `sendGatewayDoctor('verify', $families)` for
the probe and `sendGatewayDoctor($choice, $families, [$issue])` for each
selected restore/adopt action.

- [ ] **Step 6: Concrete tests**

`DoctorFixControllerTest.php`:
- 200 happy path with `mode: restore`
- 200 happy path with `mode: adopt`
- 422 when mode is missing or `verify` (must use the run endpoint instead)
- 403 when caller is an app node
- Activity log effect is `write`

`DoctorRunControllerTest.php`:
- Drop assertions about `mode: fix`/`mode: adopt` going through this endpoint.
- Add assertion that `mode` in the body is ignored.
- Effect remains `read` for app-node callers (now allowed).

`FixDoctorRequestTest.php`:
- Body shape includes `mode`, `families`, optional scope, and optional selected `issues`.
- Endpoint path is `/doctor/fix`.

- [ ] **Step 7: Run focused tests**

```bash
php artisan test --compact tests/Feature/Http/Api tests/Unit/Http/Gateway/Requests/Doctor
```

Expected: PASS.

---

### Task 10: Update Doctor Command Documentation References

**Goal:** Replace stale documentation references to the reverted `doctor:fix` command with the unified `doctor --fix` forms, while preserving the distinction between verify, restore, and adopt behavior.

**Files:**
- Modify: `docs/domains/README.md`
- Modify: every family doctor doc and command doc found by the audit below.
- Modify: `config/librarian-command-docs/shared_options.php` and `config/librarian-command-docs/warning_codes.php` if they still register `doctor:fix` command references.

**Migration patterns:**

| Old | New |
| --- | --- |
| `doctor:fix --family=<F> --restore` | `doctor --fix --family=<F> --restore` |
| `doctor:fix --family=<F> --adopt` | `doctor --fix --family=<F> --adopt` |
| `doctor:fix --restore` | `doctor --fix --restore` |
| `doctor:fix --adopt` | `doctor --fix --adopt` |
| ``doctor:fix --restore` behavior` | ``doctor --fix --restore` behavior` |
| ``doctor:fix --adopt` behavior` | ``doctor --fix --adopt` behavior` |

- [ ] **Step 1: Run the stale-doc audit**

```bash
rg -n 'doctor:fix|4_doctor-fix' docs/domains config/librarian-command-docs -g '*.md' -g '*.php'
```

Expected before implementation: matches exist in family doctor docs and registry files.

- [ ] **Step 2: Rewrite every stale reference**

Use the migration table above. Preserve existing family, node, app, and workspace filters.

- [ ] **Step 3: Run command-doc lint**

```bash
composer docs-lint -- --path=docs/domains
```

Expected: PASS.

- [ ] **Step 4: Verify stale references are gone**

```bash
rg -n 'doctor:fix|4_doctor-fix' docs/domains config/librarian-command-docs -g '*.md' -g '*.php'
```

Expected: no output.

---

### Task 11: Update Runtime Handoff Strings

**Goal:** Replace every legacy `next_command` payload that uses the old `doctor --family=… --fix` / `doctor --family=… --adopt` shape with the unified shape.

**Migration patterns** (preserve all existing scope flags; only the verb shape changes):

| Old | New |
| --- | --- |
| `doctor --family=<F> --fix` | `doctor --fix --family=<F> --restore` |
| `doctor --family=<F> --fix --node=<N>` | `doctor --fix --family=<F> --node=<N> --restore` |
| `doctor --family=<F> --adopt` | `doctor --fix --family=<F> --adopt` |
| `doctor --family=<F> --adopt --node=<N>` | `doctor --fix --family=<F> --node=<N> --adopt` |
| `doctor --fix` (no family) | `doctor --fix --restore` |
| `doctor --adopt` (no family) | `doctor --fix --adopt` |

**Audit command** (matches only the legacy shapes — the new shape always has `--fix` followed by other flags, never `--family … --fix` or bare `--adopt`):

```bash
rg -n --type=php 'doctor (--[^ ]+ )*--fix(?! --(restore|adopt|family|node|self|app|workspace|json))' app tests
rg -n --type=php 'doctor (--[^ ]+ )*--adopt(?<!--fix --adopt)' app tests
rg -n '--family=[^ ]+ --fix\b|--family=[^ ]+ --adopt\b' app tests docs
```

Easier brittle-free alternative: scan for `--fix` strings that are not preceded by `--fix ` (i.e. `--fix` is the first verb after `doctor`):

```bash
rg -n '"doctor [^"]*--fix"' app tests          # bare --fix in string literal
rg -n '"doctor [^"]*--adopt"' app tests        # bare --adopt in string literal
```

Inspect each match and rewrite by hand using the migration table.

**Known producer files** (audit confirmed at plan-write time):
- `app/Console/Commands/AppRemoveCommand.php`
- `app/Console/Commands/WorkspaceRemoveCommand.php`
- `app/Services/Firewall/FirewallRuleIntent.php`
- `app/Services/Proxy/ProxyRouteIntent.php`
- `app/Services/Workspaces/EnsureWorkspaceProxyRoute.php`
- `app/Actions/Apps/EnactAppRuntime.php`
- `app/Actions/Apps/RemoveApp.php`
- `app/Actions/Apps/EnsureAppProxyRoute.php`
- `app/Actions/Workspaces/CreateWorkspace.php`
- `app/Actions/Workspaces/SetupWorkspace.php`

**Known test files asserting old strings** (audit during implementation — every test that assertion-matches a `next_command` value is a candidate; do not assume only the three originally listed):

```bash
rg -n --type=php "next_command.*doctor.*--fix|next_command.*doctor.*--adopt" tests
```

- [ ] **Step 1: Run the audit commands and capture the full list**
- [ ] **Step 2: Rewrite each producer using the migration table**
- [ ] **Step 3: Update each affected test assertion to expect the new string**
- [ ] **Step 4: Verify no legacy producers remain**

```bash
rg -n '"doctor [^"]*--fix"|"doctor [^"]*--adopt"' app
rg -n '\-\-family=[^ ]+ \-\-fix\b' app docs
```

Expected: no output for legacy patterns. Strings of the form `doctor --fix --family=…` are valid and may match different audits.

- [ ] **Step 5: Run affected tests**

```bash
php artisan test --compact tests/Feature/Commands tests/Feature/Http/Api
```

Expected: PASS.

---

### Task 12: Update E2E Command Invocations

**Audit:**

```bash
rg -n --type=php "php artisan doctor [^\"']*--fix|php artisan doctor [^\"']*--adopt" tests/E2E
```

**Verified existing E2E files** (run the audit during implementation; do not assume specific filenames):
- `tests/E2E/Ephemeral/ToolsDoctorFixTest.php`
- Any other `*DoctorFixTest.php` / `*DoctorAdoptTest.php` discovered by the audit.

**Migration patterns:** Same as Task 10.

- [ ] **Step 1: Run audit and capture file list**
- [ ] **Step 2: Rewrite each invocation**

Examples:
- `php artisan doctor --fix --family=node --restore` → `php artisan doctor --fix --family=node --restore`
- `php artisan doctor --fix --family=app --adopt` → `php artisan doctor --fix --family=app --adopt`
- `php artisan doctor --fix` → `php artisan doctor --fix --restore`
- `php artisan doctor --adopt` → `php artisan doctor --fix --adopt`

- [ ] **Step 3: Run targeted E2E tests**

```bash
php artisan test --compact tests/E2E/Ephemeral
```

- [ ] **Step 4: Run the ephemeral E2E aggregate**

```bash
composer test:e2e
```

Expected: PASS (or document any unrelated pre-existing failures).

---

### Task 13: Final Quality Gate

**Files:** All touched files.

- [ ] **Step 1: Format code**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 2: Run command + API tests**

```bash
php artisan test --compact tests/Feature/Commands/Operations tests/Feature/Http/Api
```

- [ ] **Step 3: Lint docs**

```bash
composer docs-lint -- --path=docs/domains/11_operation/3_doctor
```

- [ ] **Step 4: Run full quality check**

```bash
composer quality-check
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app tests routes docs/domains/11_operation docs/superpowers/plans/2026-05-08-doctor-command-split.md
git commit -m "feat: implement unified doctor command with fix modes"
```

---

## Parallelization Notes

- Tasks 1 and 2 are complete.
- Tasks 3 and 4 must run sequentially (4 depends on the flags introduced in 3).
- Tasks 5 and 6 can run in parallel after Task 4. They share `DoctorCommand` so coordinate edits.
- Tasks 7 and 8 (renderers) can run in parallel after Task 6.
- Task 9 can run in parallel with Tasks 7–8 (different files).
- Task 10 can run after the unified command shape is accepted by docs.
- Tasks 11 and 12 can run in parallel after Tasks 5–6 (need the new mode semantics in place to know what to rewrite to).
- Task 13 is the final integration gate and must be last.

## Self-Review

- **Foundation gaps closed:** new Tasks 3 and 4 introduce the `restorable`/`adoptable` flags and the `probe()`/`apply()` split that Tasks 5–9 depend on.
- **Renderer scope corrected:** Task 7 captures the framed-panel rewrite end-to-end, including per-mode titles, target-line variants, family-specific column sets, empty-scope rows, action rows, and centered summary prose.
- **API contract clear:** Task 9 spells out the verify/fix endpoint split, caller-role transport, selected-issue apply requests, and how the CLI selects between `RunDoctorRequest` and `FixDoctorRequest`.
- **Docs cleanup explicit:** Task 10 removes stale `doctor:fix` references from command docs and linter registries after the command direction change.
- **Audit commands hardened:** Tasks 11 and 12 use audit greps that discriminate between legacy and new shapes instead of the broken `(--fix|--adopt)` alternation that matches the new shape too. They reference the actually-present E2E files.
- **Test gaps closed:** every task lists concrete test fixtures and assertions instead of placeholder comments.
