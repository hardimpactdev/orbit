# UX Primitives Plan — Laravel Prompts Standardization

**Status:** Implemented 2026-05-07.
**Scope:** Node domain commands and the cross-domain UX reference docs.
**Out of scope:** Conversion of non-node domain commands and the
`app:new` repository-search redesign. Those become follow-up PRs once the node
domain is the working reference.

## Problem

1. Renderer docs across `docs/commands/**/technical/6.1_*_output-render_human.md`
   describe table output as ASCII without naming a renderer primitive.
2. List commands such as `node:list` print Symfony's default `+---+` table via
   `$this->table(...)` instead of a Laravel Prompts primitive.
3. Interactive prompt commands such as `app:new` use Symfony Console
   `ask/confirm/choice` instead of Laravel Prompts `text/confirm/select`.
4. The skill at `.agents/skills/command-designer/references/terminal-output.md`
   bans `$this->table()` and points at a `RendersTable::renderBorderedTable()`
   trait that does not exist anywhere in `app/`.
5. There is no shared product-authority reference for renderer/input
   primitives, so each renderer/input-mode doc redefines the surface from
   scratch and drift goes uncaught.

## Outcome

- A new `docs/commands/ux/` tree owns the product-authority list of admitted
  renderer and prompt primitives, when each is appropriate, and the rules for
  picking between them.
- Renderer docs and input-mode docs name the primitive they invoke and link
  back to the matching `docs/commands/ux/` page.
- The docs linter enforces (a) renderer/input-mode docs name a primitive that
  exists under `docs/commands/ux/`, and (b) banned primitives such as
  `$this->table` or Symfony `ask/confirm/choice` are not referenced.
- The node domain is fully migrated: implementation, docs, and tests all
  reference the same primitives.
- Other domains keep working but are flagged by the linter, queueing them for
  follow-up PRs that follow the same pattern.

## Design Decisions

These were locked in during the brainstorm and are not up for re-litigation in
this plan:

- New tree lives at `docs/commands/ux/` with hyphenated, full-name files
  (`data-table-prompt.md`, `text-prompt.md`).
- List default is read-only `Laravel\Prompts\table`. Use `datatable` only when
  selecting a row triggers a follow-up action (e.g. `orbit profile` selecting
  the app to profile).
- Custom Orbit progress tree (dot-style `┌`/`○`/`◉`/`●`/`└`) stays as Orbit's
  signature. It is documented under `docs/commands/ux/progress/` rather than
  replaced by Laravel Prompts.
- Documented progress patterns: `progress-tree`, `spinner`. No
  `progress-bar.md` until a command needs one.
- Documented input primitives:
  `text`, `password`, `confirm`, `select`, `multi-select`, `search`,
  `multi-search`, `suggest`. No `textarea`, `pause`, `note`, `autocomplete`.
- Skill file `.agents/skills/command-designer/references/terminal-output.md`
  becomes implementation-only (ANSI, traits, animation patterns) and defers to
  `docs/commands/ux/` for primitive selection.
- The docs linter is updated as part of this plan. If it flags non-node
  families, that surfaces follow-up scope. It does not block the node work.
- `docs/commands/README.md` is updated to point at `docs/commands/ux/` as the
  primitive authority, replacing the indirect SKILL.md reference at line
  91-92.

## File Layout

```
docs/commands/ux/
├── README.md                              TOC + cross-cutting selection rules
├── lists/
│   ├── README.md                          TOC + table vs datatable rule
│   ├── table.md                           Laravel\Prompts\table  (read-only)
│   └── data-table-prompt.md               Laravel\Prompts\datatable  (interactive)
├── inputs/
│   ├── README.md                          TOC + primitive picker
│   ├── text-prompt.md                     Laravel\Prompts\text
│   ├── password-prompt.md                 Laravel\Prompts\password
│   ├── confirm-prompt.md                  Laravel\Prompts\confirm
│   ├── select-prompt.md                   Laravel\Prompts\select
│   ├── multi-select-prompt.md             Laravel\Prompts\multiselect
│   ├── search-prompt.md                   Laravel\Prompts\search
│   ├── multi-search-prompt.md             Laravel\Prompts\multisearch
│   └── suggest-prompt.md                  Laravel\Prompts\suggest
└── progress/
    ├── README.md                          TOC + custom tree vs spinner rule
    ├── progress-tree.md                   Custom Orbit dot tree
    └── spinner.md                         Single-line spinner (sub-second waits)
```

### Page Skeleton

Every primitive page uses the same structure:

```markdown
# <Primitive Display Name>

Short user-facing summary.

## Use When

- Concrete situation 1.
- Concrete situation 2.

## Avoid When

- When a different primitive is the better fit (link to it).

## Contract

- Signature in the renderer/input-mode docs (e.g. `Primitive | text`).
- Required vs optional fields and validation timing.
- JSON-mode behavior (suppressed in non-interactive mode, or downgraded to
  failed-validation when required and missing).

## Renderer / Implementation

- Laravel Prompts function or Orbit trait that produces it.
- Cross-link to `.agents/skills/command-designer/references/terminal-output.md`
  for ANSI-level mechanics.

## Examples

One short PHP snippet plus the rendered output.

## Cross References

- Upstream Laravel Prompts docs URL.
- Reference command in this repo (e.g. `orbit profile` for `datatable`).
```

The README files at each level are short tables of contents plus the selection
rule. They do not duplicate primitive content.

## Primitive Linkage Convention

The linker between command docs and `docs/commands/ux/` is a typed reference,
not free prose. Without an enforceable convention the new linter rule has
nothing to assert against. Two conventions:

### Renderer Docs

Every `*_output-render_human.md` and `*_output-render_json.md` file gains a
`## Primitive` section near the top, immediately after the back-link to the
canonical contract and the `**Renderer:**` line. The section names the
primitive and links to its `docs/commands/ux/` page.

```markdown
## Primitive

- Output table: [`Laravel\Prompts\table`](../../../../ux/lists/table.md)
- Progress: [Progress tree](../../../../ux/progress/progress-tree.md)
```

When a renderer legitimately uses no primitive (raw `$this->line(...)` of a
single value, JSON envelope only, etc.), the section explicitly states so:

```markdown
## Primitive

- None. Renderer prints a single line via `$this->line(...)`.
```

### Input-Mode Docs

`*_input-mode_interactive.md` files already include a `## Prompt Mapping`
section with a `Primitive` column. The linter parses that column and requires
a same-file link to `docs/commands/ux/inputs/<primitive>-prompt.md` for each
distinct primitive named. The link may be inline next to the prompt mapping
or in a sibling `## Primitive References` list at the bottom of the file.

This means the convention has zero ambiguity for the linter:

- Renderer doc: presence of `## Primitive` section + at least one link to
  `docs/commands/ux/{lists,progress}/`.
- Input-mode doc: every primitive named in a `Prompt Mapping` table has a
  matching `docs/commands/ux/inputs/` link in the same file.

## Tasks

### Phase 1 — Reference Docs

1. Create `docs/commands/ux/README.md` with the cross-cutting rules:
   - Lists default to `table`. Switch to `datatable` only when row selection
     triggers a follow-up action.
   - Inputs prefer Laravel Prompts primitives. Symfony Console
     `ask/confirm/choice/secret/table` are banned.
   - Progress trees stay custom; spinners are for single sub-second waits.
2. Create `docs/commands/ux/lists/{README.md,table.md,data-table-prompt.md}`.
3. Create `docs/commands/ux/inputs/README.md` plus the eight primitive pages
   above.
4. Create `docs/commands/ux/progress/{README.md,progress-tree.md,spinner.md}`.
   Move the dot-tree spec out of `terminal-output.md` and into
   `progress-tree.md`. Keep ANSI/trait mechanics in the skill.
5. Update `docs/commands/README.md`:
   - Replace the indirect SKILL.md reference at line 91-92 with a direct link
     to `docs/commands/ux/`.
   - Add a one-line "UX Primitives" entry to the Documentation Structure
     section.
6. Trim `.agents/skills/command-designer/references/terminal-output.md`:
   - Delete the `RendersTable` references (lines 215, 340, and any related
     prose).
   - Replace the "Info And List Commands" section with a one-line link to
     `docs/commands/ux/lists/`.
   - Replace the dot-tree spec with a one-line link to
     `docs/commands/ux/progress/progress-tree.md`, keeping only the
     implementation patterns (`runStepTree`, ANSI constants, SSE shape).
   - Update the anti-patterns list to point at the new ux docs for the
     positive replacement.
7. Update `.agents/skills/command-designer/SKILL.md` reference table to add a
   row for `docs/commands/ux/` and tighten the existing terminal-output entry
   to "implementation patterns only."

### Phase 2 — Linter

8. Add `tool/docs-linter/src/Rules/RendererPrimitiveReferenceRule.php`:
   - For every `*_output-render_human.md` and `*_output-render_json.md`,
     require a `## Primitive` section that either lists at least one link to
     `docs/commands/ux/lists/` or `docs/commands/ux/progress/`, or
     explicitly declares `None.` followed by a one-line reason.
   - For every `*_input-mode_interactive.md`, parse the `Primitive` column of
     `## Prompt Mapping` tables and require a same-file link to
     `docs/commands/ux/inputs/<primitive>-prompt.md` for each distinct value.
   - Flag any reference to `$this->table`, `$this->ask`, `$this->confirm`,
     `$this->choice`, or `$this->secret` in renderer/input-mode docs as a
     banned-method finding.
9. Register the rule in `tool/docs-linter/src/CommandDocsLinter.php`. Run the
   linter once with the new rule active and regenerate the baseline so the
   rule does not block this PR for non-node families. Use the existing
   baseline-rebuild path on `tool/docs-linter/docs-linter.php` (verify the
   exact CLI flag while implementing — likely `--update-baseline` or
   equivalent; do not hand-edit `baseline.json` entries). Commit the
   regenerated `baseline.json` as the follow-up backlog snapshot.
10. Document the new rule in `tool/docs-linter/RULES.md` under the
    References section, matching the row format used for other rules
    (`Rule | Severity | Checks | Fix`).
11. Add `tests/Feature/DocsLinter/RendererPrimitiveReferenceRuleTest.php`
    covering: renderer doc with valid `## Primitive` link, renderer doc with
    explicit `None.` declaration, renderer doc missing the section, input-mode
    doc with all primitives linked, input-mode doc with an unlinked primitive,
    and a banned-method finding for `$this->table` / `$this->ask` references.
12. After tasks 8-11 land, verify pre-converted commands stay clean against
    the new rule. Touch up the renderer/input-mode docs of `profile`,
    `node:show`, `node:new`, `node:default`, `node:update`, and
    `node:agent-ide` to add the `## Primitive` section if it is missing.
    These commands already use Prompts in implementation; this is a docs-only
    pass and is not a baseline entry.

### Phase 3 — Node Family Docs

13. Update each node command's renderer and input-mode docs to name
    primitives explicitly and link to the matching `docs/commands/ux/` page:

    | Command | Files touched |
    | --- | --- |
    | `node:list` | `6.1_node-list_output-render_human.md` (table primitive) |
    | `node:show` | `6.1_node-show_output-render_human.md` (key-value tree) |
    | `node:new` | `5.1_node-new_input-mode_interactive.md` (text/select/confirm primitives) |
    | `node:default` | `5.1_node-default_input-mode_interactive.md` |
    | `node:update` | `5.1_node-update_input-mode_interactive.md` |
    | `node:agent-ide` | `5.1_node-agent-ide_input-mode_interactive.md` |
    | `node:remove` | `5.1_node-remove_input-mode_interactive.md` (confirm) |
    | `node:revoke` | `5.1_node-revoke_input-mode_interactive.md` (confirm) |
    | `node:grant` | `5.1_node-grant_input-mode_interactive.md` |

14. Verify each canonical technical contract (`1_node-*.md`) links the
    renderer/input-mode docs and that those docs link back.

### Phase 4 — Node Family Implementation

For each command in the order below, change implementation, then update or
add the test that asserts the new shape, then run
`php artisan test --compact --filter=Nodes`. Tests use the existing
`Prompt::fake([...])` pattern already in
`tests/Feature/Commands/Operations/ProfileCommandTest.php`.

15. `NodeListCommand` — replace `$this->table(...)` with
    `Laravel\Prompts\table(...)`. Verify `--json` path is untouched.
16. `NodeShowCommand` — confirm key-value tree renderer matches the doc; no
    Symfony `table` use today, but verify renderer doc names the primitive.
17. `NodeRemoveCommand` — replace `$this->confirm(...)` with
    `Laravel\Prompts\confirm(...)` via `HandlesPromptCancellation`. Preserve
    `--force` skip path.
18. `NodeRevokeCommand` — same as `node:remove`.
19. `NodeNewCommand`, `NodeDefaultCommand`, `NodeUpdateCommand`,
    `NodeAgentIdeCommand`, `NodeGrantCommand` — verify they use Prompts
    already. Only touch where a Symfony method slipped in, and tighten test
    assertions to name primitives.
20. Audit `app/Concerns/HandlesPromptCancellation.php` against the final
    node-family primitive set. Today it wraps `text` and `confirm` only.

    **Audit outcome:** Five node commands (`NodeAgentIde`, `NodeDefault`,
    `NodeNew`, `NodeShow`, `NodeUpdate`) call bare `Laravel\Prompts\text` and
    `Laravel\Prompts\select` without the cancellation wrapper. This predates
    the plan and is intentional: those commands do not need to convert
    `PromptAborted` into a JSON failure envelope. Ctrl-C on those prompts
    exits the process cleanly via Laravel Prompts' default signal handling.
    `NodeRemove` and `NodeRevoke` are the only commands that wrap prompts
    via the concern, because they translate aborts into the JSON failure
    envelope that automation expects on the destructive path. No extension
    of the concern is required by Phase 4.

### Phase 5 — Verification

21. Run `composer docs-lint -- --path=docs/commands/1_node` and confirm node
    is clean.
22. Run `composer docs-lint` over the whole tree and confirm only baseline
    entries remain. Print the baseline as the follow-up backlog.
23. Run `php artisan test --compact --filter=Nodes`.
24. Run `vendor/bin/pint --dirty --format agent`.
25. Run `composer quality-check` before handing the change off.

## Test Mapping

- `tests/Feature/Commands/Nodes/NodeListCommandTest.php` updated to assert
  the human renderer invokes `Laravel\Prompts\table` (or asserts the rendered
  output shape produced by that primitive).
- `tests/Feature/Commands/Nodes/NodeRemoveCommandTest.php` updated to assert
  the interactive path uses `Laravel\Prompts\confirm` and that `--force`
  skips it.
- `tests/Feature/Commands/Nodes/NodeRevokeCommandTest.php` updated similarly.
- New `tests/Feature/DocsLinter/RendererPrimitiveReferenceRuleTest.php`
  covering the new rule's success and failure paths (matching the location
  of `CommandContractComplexityRuleTest.php` and `CommandDocsLintCliTest.php`).

## Out Of Scope (Follow-Up PRs)

- Conversion of `app:*`, `firewall:*`, `process:*`, `proxy:*`, `schedule:*`,
  `tool:*`, and `workspace:*` commands. The linter baseline tracks these as
  known debt.
- `app:new` repository input redesign using `Laravel\Prompts\search` plus the
  GitHub CLI. This requires a separate spec because `~/orbit-old-may` only
  used a free-text prompt for the repo field; the live-search behavior is
  new product surface.
- A potential `WithPromptsList` or similar trait wrapping `Laravel\Prompts\table`
  with Orbit-specific column-width defaults. Hold until two or more list
  commands have shipped and we know the shared shape.

## Open Questions

- Exact CLI flag for regenerating `tool/docs-linter/baseline.json`. Verify
  during Phase 2 task 9 by reading `tool/docs-linter/docs-linter.php` and
  `tool/docs-linter/src/CommandDocsLintCli.php` rather than guessing.
