---
title: "CLI design standard"
description: "How Orbit CLI commands collect input, ask for consent, and present results, progress, and failures."
covers:
  - apps/cli/app/Support/Console/**
  - apps/cli/tests/Expected/**
  - apps/cli/design/**
  - bin/cli-contract
---

# CLI design standard

This standard defines how commands in `apps/cli` collect input, request consent, and render output. Command names follow the [CLI command vocabulary](/reference/cli-command-vocabulary), and console helper details live in `apps/cli/app/Support/Console/README.md`.

## Input and output modes

Input mode controls prompting; output mode controls rendering:

| Invocation | Input mode | Output |
| --- | --- | --- |
| Interactive terminal without machine-output flag | Interactive | Human |
| Noninteractive stdin or `--no-interaction` | Noninteractive | Human (unless machine flag passed) |
| `--json` in any context | Noninteractive | Command's JSON contract |
| Documented stream mode | Noninteractive | Command's frame contract |

Rules for every mode:

- Machine output contains no prompts, headings, spinners, or ANSI escapes.
- Public data commands support `--json`, and commands that return a final JSON document never emit progress frames.
- Arguments and options that identify records take numeric IDs (matching the API and MCP); interactive prompts present a list and submit the ID.
- Validate required inputs before mutating. Re-prompt on invalid interactive input; fail immediately with the documented error code in noninteractive or machine mode.

## Prompt selection

Use Laravel Prompts primitives (never raw Symfony Console question/table helpers):

| Input type | Primitive |
| --- | --- |
| Open single-line value | Text |
| Open multiline value | Textarea (`Enter` newline, `Ctrl+D` submit) |
| Secret | Password (echo disabled; stdin only in noninteractive mode, never argv) |
| Boolean | Confirmation |
| Closed scalar enum (single / multiple) | Select / Multiselect |
| Dynamic searchable set (single / multiple) | Search / Multisearch (read-only callbacks) |
| Open value with suggestions | Suggest |
| Existing registry entity (multi-column) | Interactive data list (with `Press / to search` hint) |

`Ctrl+C` always cancels; outside a textarea, `Ctrl+D` or EOF cancels rather than accepting a default. Never fall back to free-text ID entry for an existing registry entity, and never invent a default when the candidate set is empty.

## Consent

Resolve the target before asking for destructive consent, state both target and effect without truncating, and default destructive confirmations and ownership transfers to **No**.

- Preserve existing explicit consent flags; otherwise use `--yes` for noninteractive consent and a default-No prompt interactively.
- Noninteractive and machine modes require the explicit consent flag and never imply consent.
- Keep `--force` strictly for its documented override (for example, discarding local changes), never as generic confirmation.

## Results

Match output layout to the user's task:

- **Lists:** Laravel Prompts table (falling back to wrapped blocks on narrow terminals).
- **Single records:** Indented detail tree.
- **Long-running Operations:** Progress tree using `○` (pending), `◉` (running), and `●` (done/failed).
- **Failures:** Human output prints the message, error code (for Gateway error envelopes), details, and `request_id`; JSON mode returns the standard error envelope (`error.code`, `message`, `details`, `request_id`).
- **Streams & signals:** Structured data goes to stdout, diagnostics to stderr, and `SIGINT`/`SIGTERM` exit with `128 + signal`.

## Verification

Canonical human renderings live under `apps/cli/tests/Expected/` (for example, `nodes/node-list/default.human.txt`, `nodes/node-show/default.human.txt`, `nodes/node-add/created.human.txt`, and `nodes/node-add/tld-required.human.txt`) and are checked by `apps/cli/tests/Feature/CommandSurfaceTest.php`.

Run the CLI contract checker from the repository root:

```bash
bin/cli-contract --changed           # check fixtures for changed commands
bin/cli-contract --preview <fixture> # preview a rendered fixture
bin/cli-contract --coverage          # check fixture coverage
ORBIT_EXPECTED=update bin/cli-contract <fixture>
```
