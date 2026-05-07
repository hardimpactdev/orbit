# Inputs

Interactive prompt primitives for collecting missing required input before
side effects begin. All input commands use Laravel Prompts. Symfony Console
`$this->ask`, `$this->confirm`, `$this->choice`, and `$this->secret` are
banned.

In `--json` or non-interactive input mode no prompt is rendered; missing
required input fails with `validation_failed`.

## Selection Rule

| Field shape | Use |
| --- | --- |
| Free text (names, IPs, paths, hostnames) | [`text`](text-prompt.md) |
| Secrets that must not echo (tokens, TLS material, passphrases) | [`password`](password-prompt.md) |
| Yes / no decision (destructive consent) | [`confirm`](confirm-prompt.md) |
| Pick one from a small fixed set (≤ ~7 entries) | [`select`](select-prompt.md) |
| Pick many from a small fixed set | [`multi-select`](multi-select-prompt.md) |
| Pick one from a large or dynamic set with search | [`search`](search-prompt.md) |
| Pick many from a large or dynamic set with search | [`multi-search`](multi-search-prompt.md) |
| Free text with non-binding suggestions (e.g. recent values) | [`suggest`](suggest-prompt.md) |

For row selection where each row carries multiple display columns, use
[`data-table-prompt`](../lists/data-table-prompt.md) instead of `search`.

## Cancellation

Prompt aborts (Ctrl-C, EOF) exit with the standard command failure status
and no side effects. Commands wrap prompt calls in
`App\Concerns\HandlesPromptCancellation` so abort handling is consistent.
The concern wraps `text` and `confirm` today; extend it the same PR a
command introduces another primitive that needs cancellation handling.

## Pages

- [`text`](text-prompt.md)
- [`password`](password-prompt.md)
- [`confirm`](confirm-prompt.md)
- [`select`](select-prompt.md)
- [`multi-select`](multi-select-prompt.md)
- [`search`](search-prompt.md)
- [`multi-search`](multi-search-prompt.md)
- [`suggest`](suggest-prompt.md)
