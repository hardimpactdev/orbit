# Progress Tree Spec & Pao Test Fidelity Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Lock down the canonical progress-tree visual spec, eliminate the
mismatch between the canonical UX doc and per-command renderer docs, surface
the spec from the command-designer skill, and close the test-fidelity gap
caused by `laravel/pao` stripping tree glyphs from agent-observed output.

**Background:** A previous step ported the `SpinnerTreeRenderer` /
`LifecycleSummaryRenderer` / `WithStepTree` / `WithSpinner` stack from
`../orbit-old-may` and rewrote `UpdateCommand` and `UpdateAllCommand` to use
it. That work surfaced three problems this plan addresses:

1. `docs/commands/ux/progress/progress-tree.md` describes the proper anatomy
   (pipes, double-spacing, dim/accent treatment, Working/Done footer) but
   per-command `6.1_*_output-render_human.md` files draw an abbreviated tree
   without pipes. They contradict each other and neither has been clearly
   declared the source of truth.
2. The `command-designer` skill describes the *mechanics* (icon table, ANSI
   codes, traits) but never the rendered anatomy. A future port can read the
   skill end to end and still build the wrong-shaped tree.
3. `laravel/pao`'s `OutputCleaner` strips `┌│└●` and several other glyphs,
   collapses `...`→`..`, and squashes runs of spaces in any output emitted
   when `AgentDetector::detect()->isAgent` is true. It is bypassed during
   `runningUnitTests()`, so test assertions still pass. The result: tests
   describe a tree that matches the spec, but agents observing the same
   command see a mangled tree. The visible-in-agent-shell rendering has zero
   automated coverage.

**Tech Stack:** Laravel 13, PHP 8.5, Pest 4, Symfony Console output, Pao
service provider, existing `WithStepTree` / `UpdateAllProgress` rendering
stack.

---

## Implementation Contract

The canonical progress tree anatomy lives in
`docs/commands/ux/progress/progress-tree.md`. Per-command renderer docs do
not redraw it; they reference it and add a command-specific step list.

The `command-designer` skill mirrors the canonical anatomy and points at
`progress-tree.md` for visual authority. The skill's
`references/terminal-output.md` is the implementation manual and must show
at least one annotated rendered example so the visual contract is
discoverable from the skill alone.

`laravel/pao` is fully disabled for the Orbit application, because Orbit's
human contract *is* the visual tree (with `--json` reserved for machine
consumers). Pao's glyph stripping is hostile to that contract.

Decorated-mode rendering is covered by automated tests for the two existing
progress-tree commands (`update`, `update:all`). The test asserts the tree
glyphs and at least one ANSI color sequence appears in the captured
decorated output; substring assertions on the non-decorated path are not
sufficient.

A single hand-rolled progress tree (e.g. raw `$this->line('┌ ...')` calls)
in a command is a regression. The architecture test layer enforces this.

## File Map

### Spec hardening

- Modify: `docs/commands/ux/progress/progress-tree.md` — add explicit
  "Visual Anatomy" section (outer indent, pipe separators, double-space gap,
  label padding, footer treatment), formalize the dot-state table with
  matching anatomy lines, add a "Dynamic Trees" subsection covering rows
  added mid-flight (`update:all` shape).
- Modify: `.agents/skills/command-designer/references/terminal-output.md` —
  add a "Rendered Anatomy" section showing the canonical tree, annotated;
  add a "Dynamic Trees" subsection citing `UpdateAllProgress` as the
  reference implementation; add a "Pao Disabled" note explaining why
  agent-observed output now matches test output.
- Modify: `.agents/skills/command-designer/SKILL.md` — verify the link list
  resolves, add `progress-tree.md` to the Reference Map alongside
  `terminal-output.md`.

### Per-command doc dedupe

Replace the redrawn tree in each `6.1_*_output-render_human.md` with:

1. A reference link: `See [progress tree anatomy](../../../ux/progress/progress-tree.md).`
2. A "Step List" subsection: title text, ordered step labels, lifecycle
   rule (single label vs per-stage), success and failure footer text.

- Modify: `docs/commands/11_operation/1_update/technical/6.1_update_output-render_human.md`
- Modify: `docs/commands/11_operation/2_update-all/technical/6.1_update-all_output-render_human.md`
- Modify: `docs/commands/2_gateway/1_gateway-add/technical/6.1_gateway-add_output-render_human.md`
- Modify: `docs/commands/2_gateway/2_gateway-trust/technical/6.1_gateway-trust_output-render_human.md`
- Modify: `docs/commands/1_node/1_node-new/technical/6.1_node-new_output-render_human.md`
- Modify: `docs/commands/1_node/5_node-grant/technical/6.1_node-grant_output-render_human.md`
- Modify: `docs/commands/1_node/6_node-revoke/technical/6.1_node-revoke_output-render_human.md`
- Modify: `docs/commands/1_node/7_node-update/technical/6.1_node-update_output-render_human.md`
- Modify: `docs/commands/1_node/8_node-remove/technical/6.1_node-remove_output-render_human.md`
- Modify: `docs/commands/1_node/9_node-default/technical/6.1_node-default_output-render_human.md`
- Modify: `docs/commands/1_node/10_node-agent-ide/technical/6.1_node-agent-ide_output-render_human.md`
- Modify: `docs/commands/3_tool/3_tool-install/technical/6.1_tool-install_output-render_human.md`
- Modify: `docs/commands/3_tool/4_tool-remove/technical/6.1_tool-remove_output-render_human.md`
- Modify: `docs/commands/3_tool/5_tool-start/technical/6.1_tool-start_output-render_human.md`
- Modify: `docs/commands/3_tool/6_tool-stop/technical/6.1_tool-stop_output-render_human.md`
- Modify: `docs/commands/3_tool/7_tool-restart/technical/6.1_tool-restart_output-render_human.md`
- Modify: `docs/commands/3_tool/9_tool-update/technical/6.1_tool-update_output-render_human.md`
- Modify: `docs/commands/3_tool/11_tool-reload/technical/6.1_tool-reload_output-render_human.md`
- Modify: `docs/commands/3_tool/12_tool-reconfigure/technical/6.1_tool-reconfigure_output-render_human.md`
- Modify: `docs/commands/4_firewall/2_firewall-allow/technical/6.1_firewall-allow_output-render_human.md`
- Modify: `docs/commands/4_firewall/3_firewall-deny/technical/6.1_firewall-deny_output-render_human.md`
- Modify: `docs/commands/4_firewall/4_firewall-remove/technical/6.1_firewall-remove_output-render_human.md`
- Modify: `docs/commands/5_app/1_app-new/technical/6.1_app-new_output-render_human.md`
- Modify: `docs/commands/5_app/2_app-register/technical/6.1_app-register_output-render_human.md`
- Modify: `docs/commands/5_app/6_app-remove/technical/6.1_app-remove_output-render_human.md`
- Modify: `docs/commands/5_app/7_app-prune/technical/6.1_app-prune_output-render_human.md`
- Modify: `docs/commands/5_app/9_app-agent-ide/technical/6.1_app-agent-ide_output-render_human.md`
- Modify: `docs/commands/6_workspace/1_workspace-new/technical/6.1_workspace-new_output-render_human.md`
- Modify: `docs/commands/6_workspace/2_workspace-setup/technical/6.1_workspace-setup_output-render_human.md`
- Modify: `docs/commands/6_workspace/5_workspace-remove/technical/6.1_workspace-remove_output-render_human.md`
- Modify: `docs/commands/7_process/1_process-add/technical/6.1_process-add_output-render_human.md`
- Modify: `docs/commands/7_process/2_process-edit/technical/6.1_process-edit_output-render_human.md`
- Modify: `docs/commands/7_process/3_process-remove/technical/6.1_process-remove_output-render_human.md`
- Modify: `docs/commands/7_process/5_process-start/technical/6.1_process-start_output-render_human.md`
- Modify: `docs/commands/7_process/6_process-stop/technical/6.1_process-stop_output-render_human.md`
- Modify: `docs/commands/7_process/7_process-restart/technical/6.1_process-restart_output-render_human.md`
- Modify: `docs/commands/8_proxy/2_proxy-add/technical/6.1_proxy-add_output-render_human.md`
- Modify: `docs/commands/8_proxy/3_proxy-remove/technical/6.1_proxy-remove_output-render_human.md`
- Modify: `docs/commands/9_schedule/1_schedule-add/technical/6.1_schedule-add_output-render_human.md`
- Modify: `docs/commands/9_schedule/4_schedule-remove/technical/6.1_schedule-remove_output-render_human.md`
- Modify: `docs/commands/9_schedule/5_schedule-run/technical/6.1_schedule-run_output-render_human.md`
- Modify: `docs/commands/10_deploy/4_deploy-run/technical/6.1_deploy-run_output-render_human.md`
- Modify: `docs/commands/11_operation/3_doctor/technical/6.1_doctor_output-render_human.md`
- Modify: `docs/commands/12_cf/3_cf-dns-add/technical/6.1_cf-dns-add_output-render_human.md`
- Modify: `docs/commands/12_cf/4_cf-dns-remove/technical/6.1_cf-dns-remove_output-render_human.md`
- Modify: `docs/commands/12_cf/5_cf-cache-flush/technical/6.1_cf-cache-flush_output-render_human.md`
- Modify: `docs/commands/12_cf/6_cf-cache-rule-add/technical/6.1_cf-cache-rule-add_output-render_human.md`
- Modify: `docs/commands/12_cf/7_cf-cache-rule-remove/technical/6.1_cf-cache-rule-remove_output-render_human.md`
- Modify: `docs/commands/12_cf/8_cf-ssl-enable/technical/6.1_cf-ssl-enable_output-render_human.md`
- Modify: `docs/commands/12_cf/9_cf-ssl-disable/technical/6.1_cf-ssl-disable_output-render_human.md`
- Modify: `docs/commands/13_vpn/2_vpn-client-new/technical/6.1_vpn-client-new_output-render_human.md`
- Modify: `docs/commands/13_vpn/3_vpn-client-enable/technical/6.1_vpn-client-enable_output-render_human.md`
- Modify: `docs/commands/13_vpn/4_vpn-client-disable/technical/6.1_vpn-client-disable_output-render_human.md`
- Modify: `docs/commands/13_vpn/5_vpn-client-remove/technical/6.1_vpn-client-remove_output-render_human.md`
- Modify: `docs/commands/13_vpn/6_vpn-web-ui-change-password/technical/6.1_vpn-web-ui-change-password_output-render_human.md`
- Modify: `docs/commands/14_php/2_php-use/technical/6.1_php-use_output-render_human.md`
- Modify: `docs/commands/15_agent-ide/1_agent-ide-message/technical/6.1_agent-ide-message_output-render_human.md`
- Modify: `docs/commands/16_dns/1_dns-resolve-tld/technical/6.1_dns-resolve-tld_output-render_human.md`

Final list to be confirmed by `grep -rn "## Progress Tree"
docs/commands/**/technical/6.1_*_human.md` so we touch every doc that
currently redraws the tree and skip those that legitimately have no
progress UI.

### Pao removal

- Modify: `bootstrap/providers.php` (or `config/app.php` providers list,
  whichever Orbit uses) — remove `Laravel\Pao\Laravel\ServiceProvider` from
  auto-discovery if present, OR
- Modify: `app/Providers/AppServiceProvider.php` — set
  `$_SERVER['PAO_DISABLE'] = '1'` in `register()` before Pao boots, with a
  short comment citing this plan.

The exact mechanism depends on whether Pao is required directly in
`composer.json` or pulled in transitively. If transitive, the env-var
escape hatch is the safer approach because it survives composer updates.

- Modify: `composer.json` — only if Pao is a direct dependency that we want
  to drop entirely. Verify nothing else in the codebase relies on it
  (`grep -rn "Laravel\\\\Pao" app/ bootstrap/ config/`).

### Decorated-mode test coverage

- Create: `tests/Feature/Commands/Operations/UpdateDecoratedRenderingTest.php`
  — runs `update` with `BufferedOutput(decorated: true)`, asserts the
  rendered buffer contains `┌`, `│`, `└`, the cyan `\e[36m` color code on
  the active row, and the green `\e[32m` color code on completed rows. Also
  asserts the title in active tense (`Updating Orbit`).
- Create: `tests/Feature/Commands/Operations/UpdateAllDecoratedRenderingTest.php`
  — same shape for `update:all`, plus assertions that stage labels
  transition (`Pulling source - local` → `Installing dependencies - local`
  → `Done - local`) and that dynamic row extension works for the
  control-caller stream path.

### Architecture enforcement

- Create: `tests/Feature/Architecture/ProgressTreeUsageTest.php` — Pest
  `arch()` test forbidding hand-rolled tree rendering. Specifically:
  commands using `┌`, `│`, `└`, `○`, `●`, or `◉` characters in literal
  string output must use the `WithStepTree` trait or the `UpdateAllProgress`
  helper.

## Implementation Steps

### Phase 1 — Spec hardening

- [ ] Audit `docs/commands/ux/progress/progress-tree.md` against the actual
      `SpinnerTreeRenderer` / `LifecycleSummaryRenderer` output.
      Reconcile any drift (label padding, footer wording, dot color
      assignments).
- [ ] Add the "Visual Anatomy" section with explicit indent / pipe / gap
      / padding rules.
- [ ] Add the "Dynamic Trees" subsection. Reference `UpdateAllProgress`
      as the reference implementation.
- [ ] Update `.agents/skills/command-designer/references/terminal-output.md`
      with a "Rendered Anatomy" section that shows the canonical tree.
- [ ] Add a "Pao Disabled" note in `terminal-output.md` so future readers
      understand why agent-observed output matches the spec.
- [ ] Verify `command-designer/SKILL.md` Reference Map covers
      `progress-tree.md` and `terminal-output.md` as paired authorities.

### Phase 2 — Per-command doc dedupe

- [ ] Run `grep -rn "## Progress Tree" docs/commands/**/technical/` to
      generate the authoritative list of files redrawing the tree.
      Reconcile against the File Map above; remove any docs that have no
      progress UI.
- [ ] For each file: replace the redrawn tree with a reference link plus
      a "Step List" subsection containing only command-specific labels and
      footer text.
- [ ] Confirm Librarian still passes after the dedupe.
      If the lint output changes, update the relevant rule or Orbit registry
      before changing the command docs further.

### Phase 3 — Pao removal

- [ ] Determine whether `laravel/pao` is a direct or transitive composer
      dependency: `composer why laravel/pao`.
- [ ] If direct and not used elsewhere in the codebase
      (`grep -rn "Laravel\\\\Pao" app/ bootstrap/ config/`), remove from
      `composer.json` and run `composer update --lock laravel/pao`.
- [ ] If transitive or worth keeping installed, opt out via
      `$_SERVER['PAO_DISABLE'] = '1'` in
      `AppServiceProvider::register()` with a comment citing this plan.
- [ ] Verify by running `php artisan update --json` and `php artisan
      update` through tinker (agent context) and confirming the tree
      renders with `┌`, `│`, `└` intact.
- [ ] Run the existing test suite to confirm no test regressions caused
      by the disable.

### Phase 4 — Decorated rendering tests

- [ ] Create `UpdateDecoratedRenderingTest.php`. Use Symfony
      `BufferedOutput` with `decorated: true`, capture raw buffer, assert
      box-drawing glyphs and at least one cyan and one green ANSI code.
- [ ] Create `UpdateAllDecoratedRenderingTest.php`. Same shape, plus
      assertions on stage label transitions and dynamic row extension.
- [ ] Run the new tests in isolation (`php artisan test --compact
      --filter=DecoratedRendering`) and the full suite.

### Phase 5 — Architecture enforcement

- [ ] Create `ProgressTreeUsageTest.php` with a Pest `arch()` rule that
      forbids commands from emitting raw tree glyphs in string literals
      unless they use `WithStepTree` or `UpdateAllProgress`.
- [ ] Verify the rule catches a deliberate regression
      (`$this->line('┌ Test')` in a scratch command) and passes for the
      current codebase.
- [ ] Run `composer quality-check`.

## Suggested Commit Sequence

1. `Document canonical progress tree anatomy` — Phase 1 changes.
2. `Reference canonical progress tree anatomy from per-command docs` —
   Phase 2 mechanical dedupe. Keep this as a single commit so it can be
   reviewed as a single contract change.
3. `Disable Pao output cleaning for Orbit CLI` — Phase 3.
4. `Add decorated-mode progress tree rendering tests` — Phase 4.
5. `Enforce progress tree primitive usage via architecture test` —
   Phase 5 (optional; defer if Phase 1-4 prove sufficient).

## Open Questions

- **Lifecycle tense convention.** `progress-tree.md` describes the
  imperative-pending / present-participle-active / past-completed
  lifecycle. Most existing commands use a single label per step regardless
  of state, relying on dot color to convey state. Decision needed: enforce
  per-stage labels everywhere, or formally allow single-label trees as the
  default and reserve per-stage labels for cases where the work itself
  changes shape mid-step (`update:all`'s stage transitions). The
  recommendation is permissive — single label is fine when dot color is
  enough.
- **Pao disable scope.** Full disable for the whole Orbit CLI, or only for
  commands that use `WithStepTree` / `UpdateAllProgress`? Full disable is
  simpler and matches Orbit's contract that machine consumers use `--json`.
  The recommendation is full disable.

## Verification

- `composer quality-check` passes.
- `php artisan update` (run through any agent shell, e.g. tinker) renders
  the tree with `┌`, `│`, `└`, `●`, `◉`, `○` glyphs intact.
- `php artisan update:all` renders the per-target tree with proper stage
  label transitions and dynamic row extension when the control caller
  streams from a remote gateway.
- `tests/Feature/Commands/Operations/UpdateDecoratedRenderingTest.php`
  and `UpdateAllDecoratedRenderingTest.php` pass.
- `grep -rn "## Progress Tree" docs/commands/` returns no matches that
  redraw the tree; every match is replaced by a reference link to the
  canonical anatomy.
