---
title: "Test impact analysis misses declaration data"
description: "The fixture and history evidence behind full PR suites and fast TIA inside the task loop."
---

# Test impact analysis misses declaration data

Nick decided on 2026-10-19 to run full suites before merge and keep start-commit TIA inside the task loop. No declaration selector is built. [Feature delivery](/reference/implementation-loop#full-suites-gate-merges-tia-serves-the-task-loop) owns this rule.

## Fixture evidence

The audit ran on Beast with PHP 8.5.11, PCOV 1.0.12 and Pest fork `1a7b957430bf47f46f075a516f30d02218704b57`. Each fixture had six consumers and one unrelated test. Two consumers checked only the type; four checked the value. All passed before changing a declaration value from `before` to `after`.

| Changed declaration | Sequential TIA cases | TIA cases on two workers | Full-suite failing cases |
| --- | --- | --- | --- |
| Enum case, class constant, static or instance property default, attribute argument | 1 | 2 | 4 |
| Reflected parameter default | 0 | 0 | 4 |
| Normal method body or local static initializer (controls) | 6 | 6 | 4 |

The declaration runs returned zero from TIA while the full suite failed. Raw coverage showed declaration-file lines on the first lazy load and none for later reads; preloaded declarations had none. Reflected attribute and parameter-default expressions can evaluate on every read without covering the source line. This is a declaration-data coverage gap, not only once-per-process evaluation. Coverage edges alone cannot identify all consumers.

## History method and cost

The fixed window was 2026-09-19 18:43:07 UTC through 2026-10-19 18:43:07 UTC, anchored at Orbit `592e88fdffe1041a2cafc19814e9b3c2ae8e1661`. The audit traversed main’s first-parent history and compared each entry with its first parent. This counts PR landings once, plus direct main commits.

Of 927 entries, 580 touched `apps/gateway/app`. A semantic enum-file rule would widen 128 of those 580 (22.1%). A general declaration-data rule would widen 496 (85.5%, about 86%). It compared normalized declaration fragments, including enum cases, constants, properties, attributes, callable signatures/defaults, type headers and trait bindings, excluding bodies, comments and formatting. It parsed 13,135 distinct PHP blobs with no parse errors. These are trigger frequencies, not additional full runs; existing inputs may already force a full run.

Across Gateway as a whole, 599 of 682 changing entries contained declaration changes (87.8%). The earlier 359 enum-touch count compared all 1,869 app-changing DAG commits to their first parents; it counts branch work and landings and is not a PR denominator.

The full suite before merge catches these false greens before main. A semantic conflict introduced by main moving after PR CI remains subject to the existing main CI and red-main recovery rules.
