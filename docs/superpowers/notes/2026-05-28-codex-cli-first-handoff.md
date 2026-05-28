# Codex CLI-First Handoff — 2026-05-28

You are the new primary owner of: **orchestrate Solo agents until every Solo todo in project `orbit` (project_id 2) that implements `docs/superpowers/plans/2026-05-27-cli-first-command-surface.md` is properly implemented, verified, reviewed, and closed.**

You have full Solo MCP access. You may drive `Grok - subagent for Codex` (Solo `process_id 1697`) and `Cursor - subagent for Codex` (Solo `process_id 1698`) as your allowed subagents. Do NOT spawn additional agent tools (Antigravity, Gemini, OpenCode, Amp, Copilot, Kimi) without explicit operator approval — Grok and Cursor are the only sanctioned subagents.

## Current state (handed off from Claude session 1683)

Closed Solo todos so far (in order): **482, 483, 484, 520, 521, 485, 487**. Each has an evidence comment (1987–1992, 1994, 2000). Read those comments to see the established close-out pattern.

Todo **486** ORBIT-CLI-08B is implementation-complete but `in_progress` because G4 (live-node + 72h observation) is operator-only — see its comment 1999 for the full state. Do NOT close 486.

Todo **488** ORBIT-CLI-08D is `in_progress` with NO implementation yet — your first task.

Todos **489, 490, 492, 498** each carry an inline prompt-branch test-gap spec from `docs/superpowers/plans/2026-05-28-cli-prompt-branch-test-gaps.md` (comments 1994–1998). When you port the matching command, the new test MUST include the `expectsQuestion` / `expectsChoice` / `expectsConfirmation` assertion with the label string quoted verbatim from the source. Item 4 (`Target IP address` for `dns:resolve-tld`) was already closed by todo 487.

The working tree is intentionally dirty with in-flight migration work from prior sessions. Never reset, revert, or stash anything you didn't author. No commits have been made during this objective; commit strategy is the operator's call.

## Coding conventions (must hold)

- D18 / ORBIT-CLI-ARCH-01 is enforced by `apps/cli/tests/Feature/Architecture/ThinCliCommandsTest.php`. Every public/internal CLI command must extend exactly one of: `GatewayCommand`, `LocalOnlyCommand`, `BootstrapGatewayCommand`, `InternalExecutorCommand`. Breaking that fails the arch test.
- Public commands use the canonical envelope helpers (`renderSuccess`, `renderFailure`, `renderGatewayFailure`) from `EmitsCanonicalEnvelopes`. Failure codes must match the docs technical contract verbatim — read `apps/docs/content/domains/.../technical/1_<command>.md` before writing the command.
- New ports must (a) remove their entry from `apps/cli/CompatibilityBridge.php` ALLOW_LIST, (b) register the class in `apps/cli/config/commands.php` `add` (keep `paths => []`), (c) add to both the visible-list assertion AND the per-command dataset in `apps/cli/tests/Feature/CommandListVisibilityTest.php`, and (d) update `apps/cli/tests/Feature/CompatibilityBridgeTest.php` with a "does not bridge ported X" dataset plus matching "continues to bridge X writes/log outside this slice" dataset.
- Reference implementations to mirror:
  - Read commands: `apps/cli/app/Commands/Firewall/FirewallListCommand.php`, `apps/cli/app/Commands/Deploy/*.php`.
  - Bootstrap: `apps/cli/app/Commands/Gateway/GatewayAddCommand.php`.
  - Local-only: `apps/cli/app/Commands/Gateway/GatewayTrustCommand.php`, `apps/cli/app/Commands/Node/NodeDefaultCommand.php`.
  - Local-only with interactive prompt branch + `expectsQuestion` test: `apps/cli/app/Commands/Dns/DnsResolveTldCommand.php` and `apps/cli/tests/Feature/Commands/Dns/DnsResolveTldCommandTest.php`.

## Verification gates (per todo, in order)

1. `bin/orbit-cli-pest --compact --filter=<Family>` and (if gateway-side tests still exist) `bin/orbit-gateway-pest --compact tests/Feature/Commands/<Family>`.
2. `bin/orbit-cli-pest --compact --filter='CompatibilityBridge|CommandListVisibility|<Family>'`.
3. `bin/orbit-cli-pest --compact`.
4. `bin/orbit-gateway-pest --exclude-group=e2e --exclude-group=slow --parallel --compact`.
5. `cd apps/cli && vendor/bin/pint --dirty --format agent`.
6. `bin/orbit-gateway-vendor-bin pint --dirty --format agent`.
7. `composer docs-lint` from repo root.
8. `git diff --check` on every touched file — must exit 0.
9. For the prompt-branch test gaps (items in comments 1994–1998): `grep -rE "expects(Question|Choice|Confirmation|OutputToContain).*['\"]<Label>" apps/cli/tests apps/gateway/tests` — non-zero count after the port.

E2E lanes (`composer test:e2e:docker`, `composer test:e2e:provision`) require live infrastructure and operator approval — do NOT run them unprompted. They gate todos 465 and 486 only.

## Review protocol

For every implementation, request a read-only review from Grok (process 1697) before closing the todo. Format the review request the way Claude did it (see Solo comment 1989 on todo 484 for an example): scope, files to inspect, what to verify, the verification matrix already run, ask for `READY` or `BLOCKERS` with `file:line` references. Use `send_input(process_id=1697, input="...")` and `timer_fire_when_idle_any(processes=[1697], max_wait_ms=180000, body="...")` to wait without polling.

Cursor (process 1698) is your secondary tool. Use it for:
- Read-only second-opinion reviews when Grok is busy or quota-exhausted.
- Mechanical refactors that touch many files predictably (multi-file constructor signature updates, sentinel swaps, schema-version bumps).
Do NOT use Cursor for the primary thin-adapter port — that's your work.

If Grok returns `BLOCKERS`, patch the implementation in place and re-request review until `READY`. Trust BLOCKERS findings unless you can verify against code that they're wrong.

## Solo todo workflow

1. `todo_update(status="in_progress")`.
2. Read the todo body + every comment via `todo_get(include_comments=true)`.
3. Read the relevant `apps/docs/content/domains/.../...md` contract first. Authority is docs > plan > existing gateway-side command source.
4. Implement, run the verification matrix, request Grok review.
5. On Grok READY: write a detailed evidence comment (`todo_comment_create`) covering scope, file list, architecture preserved, exact verification command outputs (passed counts + assertion counts), downstream items remaining. Then `todo_complete(completed=true)`.
6. If a blocker is sequencing-only (not behavior), `todo_remove_blocker` is allowed — Claude removed 486 from 487 for this reason. If a blocker is semantic, do NOT remove it.

## Implementation-sequence order (Phase 8 next)

488 → 489 → 490 → 491 → 492 → 493 → 494 → 495 → 496 → 497 → 498 → 499 → 500 → 501 → 502 → (Phase 9: 503 → 504 → 505 → 506) → (Phase 10: 507 → 508 → 509 → 510) → (Phase 11: 511 → 512 → 513) → (Phase 12: 514 → 515 → 516). Some are blocked by sequencing only — check with `todo_get` before you start each one. Two are operator-gated and cannot be closed: 465 (G3) and 486's G4 evidence.

## Start now

1. `whoami()` to confirm session identity.
2. `todo_get(todo_id=488, include_comments=true)` to inspect ORBIT-CLI-08D.
3. Port the `update` self-update command per its docs contract at `apps/docs/content/domains/11_operation/1_update/update.md` and `.../technical/1_update.md`. Extract a service into `apps/cli/app/Services/Updates/` (mirroring the dns and gateway pattern). Do NOT port `update:all` here — that's a Phase 9 streamed todo.
4. After 488 closes, continue 489 next.

The Stop hook will keep firing on this objective until every implementation-sequence todo is closed.
