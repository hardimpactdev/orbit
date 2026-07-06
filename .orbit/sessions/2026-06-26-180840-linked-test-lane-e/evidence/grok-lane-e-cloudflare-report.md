# Lane E — Cloudflare DNS/cache-rule linked-test remediation

**Worker:** Grok (Solo process 2064, `lane-e-cloudflare-linked-test-audit`)  
**Worktree:** `/Users/nckrtl/orbit/.worktrees/linked-test-catalog-drift`  
**Scope:** `cf-dns:*` and `cf-cache-rule:*` command docs only

## Summary

Replaced 12 stale `apps/gateway/tests/Feature/Commands/Cloudflare/CfDns*` and `CfCacheRule*` linked-test rows (7 `cf-dns` + 5 `cf-cache-rule` missing refs per baseline) with three existing CLI routine test files. Every cited path now exists on disk. Uncovered gateway contract, interactive input, and renderer edge cases are recorded as explicit coverage-gap prose following the Lane F `cf-ssl` pattern.

## Changed files (19)

- `apps/docs/content/domains/12_cf/2_cf-dns-list/technical/1_cf-dns-list.md`
- `apps/docs/content/domains/12_cf/2_cf-dns-list/technical/6.1_cf-dns-list_output-render_human.md`
- `apps/docs/content/domains/12_cf/2_cf-dns-list/technical/6.2_cf-dns-list_output-render_json.md`
- `apps/docs/content/domains/12_cf/3_cf-dns-add/technical/1_cf-dns-add.md`
- `apps/docs/content/domains/12_cf/3_cf-dns-add/technical/6.1_cf-dns-add_output-render_human.md`
- `apps/docs/content/domains/12_cf/3_cf-dns-add/technical/6.2_cf-dns-add_output-render_json.md`
- `apps/docs/content/domains/12_cf/4_cf-dns-remove/technical/1_cf-dns-remove.md`
- `apps/docs/content/domains/12_cf/4_cf-dns-remove/technical/5.1_cf-dns-remove_input-mode_interactive.md`
- `apps/docs/content/domains/12_cf/4_cf-dns-remove/technical/5.2_cf-dns-remove_input-mode_non-interactive.md`
- `apps/docs/content/domains/12_cf/4_cf-dns-remove/technical/6.1_cf-dns-remove_output-render_human.md`
- `apps/docs/content/domains/12_cf/4_cf-dns-remove/technical/6.2_cf-dns-remove_output-render_json.md`
- `apps/docs/content/domains/12_cf/6_cf-cache-rule-add/technical/1_cf-cache-rule-add.md`
- `apps/docs/content/domains/12_cf/6_cf-cache-rule-add/technical/6.1_cf-cache-rule-add_output-render_human.md`
- `apps/docs/content/domains/12_cf/6_cf-cache-rule-add/technical/6.2_cf-cache-rule-add_output-render_json.md`
- `apps/docs/content/domains/12_cf/7_cf-cache-rule-remove/technical/1_cf-cache-rule-remove.md`
- `apps/docs/content/domains/12_cf/7_cf-cache-rule-remove/technical/5.1_cf-cache-rule-remove_input-mode_interactive.md`
- `apps/docs/content/domains/12_cf/7_cf-cache-rule-remove/technical/5.2_cf-cache-rule-remove_input-mode_non-interactive.md`
- `apps/docs/content/domains/12_cf/7_cf-cache-rule-remove/technical/6.1_cf-cache-rule-remove_output-render_human.md`
- `apps/docs/content/domains/12_cf/7_cf-cache-rule-remove/technical/6.2_cf-cache-rule-remove_output-render_json.md`

## Test files inspected

| Path | Commands exercised |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Cloudflare/CloudflareReadCommandsTest.php` | `cf-dns:list` JSON/human table, empty state, zone validation, authorization passthrough, wireguard unreachable |
| `apps/cli/tests/Feature/Commands/Cloudflare/CloudflareWriteCommandsTest.php` | `cf-dns:add` POST/validation; `cf-dns:remove` consent/DELETE; `cf-cache-rule:add` POST; `cf-cache-rule:remove` consent/DELETE |
| `apps/cli/tests/Feature/Commands/Cloudflare/CloudflareRenderCommandsTest.php` | `cf-dns:add` human tree/created/already-present/failure prose; `cf-dns:remove` human tree/success/missing-consent; `cf-cache-rule:add` human tree; `cf-cache-rule:remove` human tree |
| `apps/gateway/tests/Feature/Http/Api/CloudflareControllerTest.php` | Zone list and SSL-disable permission only — not linked (no DNS/cache-rule API coverage) |

## Coverage rows added/narrowed

- **Narrowed from phantom gateway command tests → CLI tests:** all 12 previously missing gateway `CfDns*` / `CfCacheRule*` paths removed.
- **`cf-dns:list`:** linked `CloudflareReadCommandsTest.php` for GET forwarding, JSON envelope, table/empty human output, zone validation, `authorization_failed`, `gateway_unreachable_wireguard`.
- **`cf-dns:add`:** linked `CloudflareWriteCommandsTest.php` for POST forwarding and name/content validation; `CloudflareRenderCommandsTest.php` for human tree, created/already-present lines, failure prose.
- **`cf-dns:remove`:** linked `CloudflareWriteCommandsTest.php` for JSON consent/DELETE/removed envelope; `CloudflareRenderCommandsTest.php` for human tree, success, missing-consent guard.
- **`cf-cache-rule:add`:** linked `CloudflareWriteCommandsTest.php` for POST forwarding and JSON status passthrough; `CloudflareRenderCommandsTest.php` for human tree/success.
- **`cf-cache-rule:remove`:** linked `CloudflareWriteCommandsTest.php` for JSON consent/DELETE; `CloudflareRenderCommandsTest.php` for human tree/success.

## Coverage gaps left (explicit prose in docs)

Shared across all five commands:

- No gateway-side command or API tests for DNS/cache-rule authorization, zone/app resolution, provider mutations, or Orbit state guards.
- No interactive confirmation prompt tests for `cf-dns:remove` or `cf-cache-rule:remove`.
- No exhaustive per-renderer `error.code` matrix tests (`gateway_unavailable`, `cloudflare_unavailable`, etc.).

Command-specific:

- **`cf-dns:list`:** progress tree human output; JSON empty-list shape; `gateway_unavailable` / `cloudflare_unavailable`.
- **`cf-dns:add`:** zone inference, A/AAAA validation, idempotent/conflict JSON shapes, conflict human output.
- **`cf-dns:remove`:** non-address-record refusal; additional human failure messages.
- **`cf-cache-rule:add`:** already-present metadata, app-zone failure, human/JSON failure paths.
- **`cf-cache-rule:remove`:** missing-rule failure; human missing-consent output; non-JSON non-TTY non-interactive path.

## Uncertainty

- `cf-dns:list` documents `gateway_unavailable` while the CLI test surfaces `gateway_unreachable_wireguard`; left `gateway_unavailable` as a gap rather than claiming the wireguard-specific code covers the documented code.
- `CloudflareWriteCommandsTest` cache-rule JSON assertions check `status=done` from faked envelopes, not the fuller documented `ready`/`removed` field shapes; rows were narrowed to “envelope passthrough” only.

## Verification commands run

```bash
cd /Users/nckrtl/orbit/.worktrees/linked-test-catalog-drift
rg 'apps/gateway/tests/Feature/Commands/Cloudflare/Cf(Dns|CacheRule)' \
  apps/docs/content/domains/12_cf/{2_cf-dns-list,3_cf-dns-add,4_cf-dns-remove,6_cf-cache-rule-add,7_cf-cache-rule-remove}
# → no matches (stale refs cleared)

test -f apps/cli/tests/Feature/Commands/Cloudflare/CloudflareReadCommandsTest.php
test -f apps/cli/tests/Feature/Commands/Cloudflare/CloudflareWriteCommandsTest.php
test -f apps/cli/tests/Feature/Commands/Cloudflare/CloudflareRenderCommandsTest.php
# → all OK
```

## Blockers / risks

- **None for this lane slice.** Catalog regeneration, `CommandCatalogTest`, and broad docs-lint remain for the feature owner after all Lane E workers reconcile.
- **Risk:** downstream catalog regen must pick up these doc edits; until then baseline JSON still reports the old missing refs.