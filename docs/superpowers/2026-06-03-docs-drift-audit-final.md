# Docs Drift Audit — Final Synthesis (2026-06-03)

Scratchpads: findings `docs-audit-findings-claude` (id 357), Codex review file
`2026-06-03-docs-drift-audit-review-codex.md`, synthesis `docs-audit-final` (id 359).
Authority order: mission > architecture > concepts > tech-stack > domain READMEs > technical contracts.

## Codex downgrades / additions applied
- **B1 → A5 (upgraded + widened):** exec drift spans the whole `workspace:exec` AND `app:exec` contracts (public + technical + renderers), not 2 README lines. Merged Codex #1 (app:exec) + #2 (workspace:exec).
- **B3 → C3:** "three concepts lists five" is copy/count drift.
- **Codex #3 (DNS tool-doctor adopt) A → B7:** doc deliberately documents/justifies the cross-family write → a boundary decision to confirm, not silent drift.
- **Codex #4 → A6** (doctor category/render tables stale); **#5 → B5**; **#6 → B6**; **#7 → C4**.
- A4 line refs extended (:103-105, :146-148). B2 extra stale spots noted.

## A — direct contradictions
- **A1** schedule-add.md:7 / schedule-remove.md:7 — node-side scheduler vs gateway-only (tech-stack.md:332-336, schedule-concepts.md:72-73).
- **A2** tool README:70,72 + catalog/README.md:73-75 — php-cli/composer "container capability" vs host toolchain (tech-stack.md:66, node-concepts.md:281-292, catalog files).
- **A3** firewall-doctor.md:30,51 — eligibility drops router+ingress vs firewall README:13-16 (7 roles).
- **A4** node-update 1_node-update.md:39,57,68-74,103-105,146-148 — `--tld` app-dev-only; no path for agent node tld. Product decision needed.
- **A5 (highest leverage)** app:exec + workspace:exec contracts container-first vs host authority (app-concepts.md:82-84, workspace-concepts.md:30-33, node-concepts.md:284). app-exec.md:55-58 (intro :5-12 says host), app-exec technical+renderers; workspace-exec.md:5-10 ("no longer host PHP … inside container"), workspace-exec technical+renderers; workspace/README.md:25-27,222-224. Code follow-up.
- **A6** doctor tables stale vs 10-role/9-family: doctor.md:13-20 omits ingress/websocket/s3; on-operator/on-gateway variants stale; 6.1_render_human:45-54 omits database_connection; 1_doctor.md:206-216 omits database-doctor.

## B — stale terminology / boundary to confirm
- **B2** node README:519 "app-dev role-assignment TLD" (node-level; node-concepts.md:161-180).
- **B5** proxy-doctor.md:9 "ingress routes only" under-scopes; covers ws/s3 routes at :83-87,102-106.
- **B6** schedule-list 6.1_render_human:28 "Node where the Orbit Scheduler executes runs" implies target-node execution.
- **B7** tool-doctor.md:157-166 + dns-bootstrap-contract.md:32-33,143-149 — tool-family adopt writes node-family DNS mappings; documented but blurs the "do not overlap" boundary. Confirm intent.

## C — refs / catalog / phantom / wording
- **C1** laravel-installer absent from tool README table + catalog role-baseline table.
- **C2** schedule-doctor.md:100 phantom `run_history_hook_*` code.
- **C3** node README:25 "three concepts" lists five.
- **C4** doctor.md:90-91 + 6.1_render_human:462-463 "`--fix` modes (interactive, restore, adopt)" conflation.

## Recommended execution order
1. A5 (resolve exec model; biggest blocker; code follow-up)
2. A2 + C1 (tool catalog host-toolchain + laravel-installer)
3. A6 (doctor tables to 10-role/9-family)
4. A1 + B6 (kill node-side-scheduler language)
5. A3 (firewall-doctor eligibility; code follow-up)
6. A4 + B2 (agent-tld decision + node:update/wording)
7. B5, B7-decision, C2, C3, C4

Coherence (both reviewers): 6/10. Biggest blocker: host-vs-container exec model (A5).
