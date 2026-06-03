# Docs Drift Audit Review - Codex

Scope: independently verified the cited findings against `apps/docs/content/`, excluding `porting/`, and hunted nearby drift in the requested domains. Authority order used: mission > architecture > concepts > tech-stack > domain READMEs > technical contracts, plus the ground-truth facts supplied in the request.

## Per-Finding Verdicts

| Finding | Verdict | Reason / correction |
| --- | --- | --- |
| A1 | Agree | `apps/docs/content/domains/9_schedule/1_schedule-add/schedule-add.md:7` and `apps/docs/content/domains/9_schedule/4_schedule-remove/schedule-remove.md:7` both say a target-node scheduler syncs changes. Authority says the scheduler is gateway-only: `apps/docs/content/tech-stack.md:332-336` and `apps/docs/content/domains/9_schedule/schedule-concepts.md:72-73`. Severity A is correct. |
| A2 | Agree | `apps/docs/content/domains/3_tool/README.md:70,72` and `apps/docs/content/domains/3_tool/catalog/README.md:73-75` still describe PHP/Composer as runtime-container capabilities. Authority says app-dev/app-prod install host PHP/Composer/Laravel installer tooling: `apps/docs/content/tech-stack.md:66,424-435`, `apps/docs/content/domains/1_node/node-concepts.md:281-292`, and the individual catalog pages `php-cli.md` / `composer.md`. Severity A is correct. |
| A3 | Agree | `apps/docs/content/domains/4_firewall/firewall-doctor.md:30` omits `router` and `ingress`; line `51` repeats the narrowed role-incompatible model. Upstream firewall docs include both roles at `apps/docs/content/domains/4_firewall/README.md:13-16` and `firewall-concepts.md:36-39`. Severity A is correct. |
| A4 | Agree | `apps/docs/content/domains/1_node/7_node-update/technical/1_node-update.md:39,57,68-74,103-105,146-148` restrict `--tld` to app-dev/development TLD. Authority says `tld` is node-level and shared by `app-dev` and `agent`: `apps/docs/content/concepts.md:26`, `apps/docs/content/domains/1_node/node-concepts.md:108-113,161-180`, `apps/docs/content/tech-stack.md:383-389`. Severity A is correct. |
| B1 | Partially agree | Correct drift, but understated. `apps/docs/content/domains/6_workspace/workspace-concepts.md:26-29` is stale, while `:30-33` already says host toolchain. The real issue is A severity because the full `workspace:exec` public/technical/rendering contract is container-first: `workspace-exec.md:5-10,56-59,76-83,108-114`, `technical/1_workspace-exec.md:17-18,66-74,89-90,124-143,174-177,207-215`, and the human/JSON renderers. |
| B2 | Agree | `apps/docs/content/domains/1_node/README.md:517-519` is stale role-assignment wording. Additional stale lines exist in `node-doctor.md:27-29,100-107,194`, `node-concepts.md:438-451`, and `1_node-new/technical/1_node-new.md:39,184-187`. Severity B is fair because A4 captures the command-contract break. |
| B3 | Agree, downgrade | `apps/docs/content/domains/1_node/README.md:25-50` says "three concepts" but lists five. This is real, but it is copy/count drift rather than behavior-contract drift; I would mark it C, not B. |
| C1 | Agree | `laravel-installer` has a catalog file and is part of the node host toolchain baseline (`apps/docs/content/domains/3_tool/catalog/laravel-installer.md:11-15,38-40,49-52`; `node-concepts.md:221-223`), but it is absent from the summary tables in `apps/docs/content/domains/3_tool/README.md:64-84` and `catalog/README.md:80-83`. Severity C is correct, though it clusters with A2. |
| C2 | Agree | `apps/docs/content/domains/9_schedule/schedule-doctor.md:49-79` defines no `run_history_hook_*` issue or fix code, but `schedule-doctor.md:100` names `run_history_hook_*` in test coverage. Severity C is correct. |

## New Findings Missed

### 1. A - `app:exec` still has a container-exec command contract

Authority says app CLI execution uses the app node host PHP toolchain, while FrankenPHP remains the web runtime: `apps/docs/content/tech-stack.md:66,109-110,231-248,424-435` and `apps/docs/content/domains/5_app/app-concepts.md:82-84`.

Downstream drift:

- `apps/docs/content/domains/5_app/10_app-exec/app-exec.md:55-58` says `app:exec` "looks up its FrankenPHP runtime container" and runs the command "inside that container", despite the same page saying host PHP at `:5-12`.
- `apps/docs/content/domains/5_app/10_app-exec/technical/1_app-exec.md:15-16,60-68,83-84,117-133,169-172` requires a running app container, exposes an API action to run inside the runtime container, and maps Docker/container failure codes.
- `apps/docs/content/domains/5_app/10_app-exec/technical/6.1_app-exec_output-render_human.md:17,66` and `6.2_app-exec_output-render_json.md:73-74,143-181,255` still render `docker exec`, `container`, `app.exec_container_not_running`, and Docker failure semantics.

This is the app-side version of B1 and can drive the wrong implementation surface.

### 2. A - Full `workspace:exec` contract is still container-first

Authority says workspace PHP/Composer/Artisan execution runs on the node host PHP toolchain: `apps/docs/content/domains/6_workspace/workspace-concepts.md:30-33`, backed by the same tech-stack host-toolchain model.

Downstream drift:

- `apps/docs/content/domains/6_workspace/14_workspace-exec/workspace-exec.md:5-10` says commands run inside the workspace's FrankenPHP runtime container and "no longer" use host PHP/Composer.
- `apps/docs/content/domains/6_workspace/14_workspace-exec/workspace-exec.md:56-59,76-83,108-114` repeats container execution and points to container repair.
- `apps/docs/content/domains/6_workspace/14_workspace-exec/technical/1_workspace-exec.md:17-18,66-74,89-90,124-143,174-177,207-215` hardcodes runtime-container identity, `docker exec`, and container failure codes.
- `technical/6.1_workspace-exec_output-render_human.md:16-17` and `technical/6.2_workspace-exec_output-render_json.md:73-77,162-217,276` keep Docker/container output and failure semantics.

This is a scope expansion of B1, not a separate conceptual category.

### 3. A - DNS tool doctor adoption crosses into node-family DNS mappings

Authority explicitly splits DNS responsibilities and says they "do not overlap": development/agent DNS mappings are owned by the node family and verified by `doctor --family=node`; VPN-facing DNS runtime is verified by `doctor --family=tool`; "the tool family does not own DNS records" (`apps/docs/content/architecture.md:168-181`).

Downstream drift:

- `apps/docs/content/domains/3_tool/tool-doctor.md:157-164` says `tool.dns_config_drift` adoption parses `dnsmasq.conf` into node-family mapping triples and records them into node-family state.
- `apps/docs/content/domains/3_tool/dns-bootstrap-contract.md:32-33,143-149` repeats that DNS drift is adoptable by the tool probe and records observed mappings into node-family state.

Restore/re-render from node state is aligned. The adopt path is not: it makes `doctor --family=tool` mutate the node-owned DNS mapping source of truth.

### 4. A - Doctor category/render contracts are stale against current roles and families

Authority has 10 roles (`apps/docs/content/architecture.md:100`) and nine state families including `database_connection` (`apps/docs/content/architecture.md:372-384`; `apps/docs/content/domains/README.md:167-169`).

Downstream drift:

- `apps/docs/content/domains/11_operation/3_doctor/doctor.md:13-20` and `technical/1_doctor.md:49-56` omit role-category rows for `router`, `ingress`, `websocket`, and `s3`.
- `technical/2_doctor_on-operator-node.md:41-46` and `technical/3_doctor_on-gateway-node.md:38-43` are more stale: gateway targets render only `Node` rather than `Node`, `Scheduling`; app targets omit `Databases`; `agent`, `router`, `ingress`, `websocket`, and `s3` are absent.
- `technical/6.1_doctor_output-render_human.md:45-55` maps only eight family labels and omits `database_connection` / `Databases`; `:251-260` also omits database issue-table columns.
- `technical/1_doctor.md:206-216` omits `database-doctor.md` from the converted family doctor contract list even though the public page links it at `doctor.md:119`.

This can cause `--family` intersection, role-aware rendering, and human diagnostics to diverge from the actual product family set.

### 5. B - Proxy doctor intro under-scopes the proxy family to ingress routes

Authority says the proxy family owns every HTTP/HTTPS route Orbit serves: `apps/docs/content/architecture.md:380`; `apps/docs/content/domains/8_proxy/README.md:12-23` lists app, workspace, gateway, websocket, S3, tool, and custom route owners.

Downstream drift:

- `apps/docs/content/domains/8_proxy/proxy-doctor.md:9` says it covers "Orbit-owned ingress routes only".
- The same doc later covers non-ingress service route drift (`proxy.websocket.*` and `proxy.s3.*`) at `proxy-doctor.md:83-87,102-106`.

I would keep this B, not A, because the operative probe and issue tables mostly include the broader route set; the intro is the stale part.

### 6. B - Schedule list renderer still implies target-node scheduler execution

Authority says the Orbit Scheduler runs only on the gateway and dispatches target-node work via `RemoteShell`: `apps/docs/content/tech-stack.md:332-336`, `apps/docs/content/domains/9_schedule/schedule-concepts.md:72-73`.

Downstream drift:

- `apps/docs/content/domains/9_schedule/2_schedule-list/technical/6.1_schedule-list_output-render_human.md:28` defines the `Node` column as "Node where the Orbit Scheduler executes runs for this schedule."

The column can be the target node, but the scheduler does not execute there. This is the same family of drift as A1, at lower severity.

### 7. C - Doctor human docs conflate all resolution modes as "`--fix` modes"

Authority distinguishes verify, interactive `--fix`, `--restore`, and `--adopt`, with permissions `doctor:verify`, `doctor:restore`, and `doctor:adopt`: `apps/docs/content/architecture.md:401-410`; `apps/docs/content/domains/11_operation/3_doctor/technical/1_doctor.md:95-106`.

Downstream drift:

- `apps/docs/content/domains/11_operation/3_doctor/doctor.md:90-91` says "In `--fix` modes (interactive, restore, adopt)".
- `apps/docs/content/domains/11_operation/3_doctor/technical/6.1_doctor_output-render_human.md:462-463` says action failures occur in "`--fix` modes".

This is wording drift, not a permissions drift; I did not find a phantom `doctor:fix` permission.

## Areas Checked Without Additional Drift

- `apps/docs/content/domains/10_deploy/*` is aligned: deploy steps run on the host PHP toolchain and the app's FrankenPHP container serves the deployed source (`README.md:33-36`, `deploy-concepts.md:29-33`, `4_deploy-run/technical/1_deploy-run.md:54-80`).
- `apps/docs/content/abstractions/17_security.md` is aligned: security is not a state family and `doctor --family=security` is invalid.
- `apps/docs/content/domains/5_app/app-doctor.md` and `apps/docs/content/domains/7_process/process-doctor.md` did not show additional authority drift in their family-doctor contracts.
- `apps/docs/content/domains/2_gateway/*` did not show additional drift against the requested ground-truth items.

## Overall

Coherence score: 6/10.

Single biggest remaining blocker: the host-vs-container PHP execution model is still split across the docs. `tech-stack`, app concepts, and parts of app/workspace concepts say host PHP; `app:exec`, `workspace:exec`, and tool summary docs still encode container execution and Docker failure contracts. That is the highest-risk drift because it directly changes command implementation, API payloads, error codes, and user recovery guidance.
