# Inputs

Interactive prompt primitives for collecting missing required input before
side effects begin. All input commands use Laravel Prompts. Symfony Console
`$this->ask`, `$this->confirm`, `$this->choice`, and `$this->secret` are
banned.

In `--json` or non-interactive input mode no prompt is rendered; missing
required input fails with `validation_failed`.

## Selection Rule

Use this table to choose the right primitive for a given field shape.

| Field shape | Use |
| --- | --- |
| Free text (new names, IPs, paths, hostnames, commands) | [`text`](text-prompt.md) |
| Secrets that must not echo (tokens, TLS material, passphrases) | [`password`](password-prompt.md) |
| Yes / no decision (destructive consent) | [`confirm`](confirm-prompt.md) |
| Pick one from a small fixed set (≤ ~7 entries) | [`select`](select-prompt.md) |
| Pick many from a small fixed set | [`multi-select`](multi-select-prompt.md) |
| Pick one from a large or dynamic set with search | [`search`](search-prompt.md) |
| Pick many from a large or dynamic set with search | [`multi-search`](multi-search-prompt.md) |
| Free text with non-binding suggestions (e.g. recent values) | [`suggest`](suggest-prompt.md) |

For row selection where each row carries multiple display columns, use
[`data-table-prompt`](../lists/data-table-prompt.md) instead of `search`.
Existing Orbit entity instances such as apps, nodes, workspaces, processes,
schedules, and tools must use a finite selector primitive (`datatable` for
registry rows, or `select` only for a small scalar enum). They must not fall
back to open `text` or `number` prompts.

## Cancellation

Prompt aborts (Ctrl-C, EOF) exit with the standard command failure status
and no side effects. Commands wrap prompt calls in
`App\Concerns\HandlesPromptCancellation` so abort handling is consistent.
The concern wraps shared prompt helpers for `text`, `search`, `select`,
`datatable`, and `confirm`; extend it in the same PR when a command introduces
another primitive that needs cancellation handling.

## Pages

Each page documents one prompt primitive with use cases, contract, and a reference implementation.

- [`text`](text-prompt.md)
- [`password`](password-prompt.md)
- [`confirm`](confirm-prompt.md)
- [`select`](select-prompt.md)
- [`multi-select`](multi-select-prompt.md)
- [`search`](search-prompt.md)
- [`multi-search`](multi-search-prompt.md)
- [`suggest`](suggest-prompt.md)
