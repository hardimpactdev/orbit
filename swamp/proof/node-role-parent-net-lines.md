# Role parent source line accounting

Comparison base: `adcf542120a14cea21cad594a83c9e669053b722` (accepted main at this rebase). Includes the imported Nodes checkpoints. Counts describe this draft; it does not meet the requested net-negative target yet. Recompute after the parent/residue deletion is complete.

Gateway app +379, Gateway tests +357, Docs/other +20, Swamp +5559.

| File | Added | Deleted | Net | Reason |
| --- | ---: | ---: | ---: | --- |
| `apps/gateway/app/Actions/Nodes/AdmitNodeRoleAddAction.php` | +76 | −0 | +76 | Separate parent admission, capture actual assignment/source state and push names-only execution copies. |
| `apps/gateway/app/Actions/Nodes/AdmitNodeRoleOperationAction.php` | +19 | −50 | -31 | Separate parent admission, capture actual assignment/source state and push names-only execution copies. |
| `apps/gateway/app/Actions/Nodes/AdmitNodeRoleRelocateAction.php` | +120 | −0 | +120 | Separate parent admission, capture actual assignment/source state and push names-only execution copies. |
| `apps/gateway/app/Domain/Nodes/NativeNodeRoleConvergeInput.php` | +65 | −7 | +58 | Consume Nodes-owned typed contribution capture and collapsed shared phase factory. |
| `apps/gateway/app/Domain/Nodes/NodeRoleDirectProjection.php` | +13 | −0 | +13 | Align owning role capture, registration and projection with native parent inputs and output facts. |
| `apps/gateway/app/Domain/Nodes/NodeRoleParentInput.php` | +46 | −0 | +46 | Own add/relocate graph composition and terminal partial-success facts. |
| `apps/gateway/app/Domain/Nodes/NodeRoleParentProjection.php` | +97 | −0 | +97 | Own add/relocate graph composition and terminal partial-success facts. |
| `apps/gateway/app/Domain/Nodes/NodeRoleRelocateInput.php` | +3 | −28 | -25 | Replace old transfer binder with actual-source capture and native-parent ownership checks. |
| `apps/gateway/app/Domain/Nodes/direct-operations.php` | +16 | −5 | +11 | Align owning role capture, registration and projection with native parent inputs and output facts. |
| `apps/gateway/app/Infrastructure/MachineSoftware/NodeRecipeProgram.php` | +17 | −3 | +14 | Align owning role capture, registration and projection with native parent inputs and output facts. |
| `apps/gateway/tests/Feature/Api/NativeDatabaseRoleConvergeTest.php` | +167 | −0 | +167 | Cover admission identity, private absence, legacy parity and native success/failure behavior. |
| `apps/gateway/tests/Feature/Api/NodeRoleParentNativeTest.php` | +148 | −0 | +148 | Own add/relocate graph composition and terminal partial-success facts. |
| `apps/gateway/tests/Feature/Api/NodeRoleRelocationOperationTest.php` | +12 | −6 | +6 | Cover admission identity, private absence, legacy parity and native success/failure behavior. |
| `apps/gateway/tests/Feature/Infrastructure/Routes/NativeRouteResidueProjectionTest.php` | +36 | −0 | +36 | Cover admission identity, private absence, legacy parity and native success/failure behavior. |
| `docs/reference/database-role.md` | +2 | −0 | +2 | Document native contribution boundaries, source cleanup and remaining parent limits. |
| `docs/reference/node-provisioning.md` | +19 | −3 | +16 | Document native contribution boundaries, source cleanup and remaining parent limits. |
| `docs/reference/node-role-certificates.md` | +2 | −0 | +2 | Document the consumed role supplier and its unchanged public/private contract. |
| `swamp/config/gateway-workflow-grants.json` | +1 | −1 | +0 | Keep existing exact workflow and secret scopes aligned with native parent registration. |
| `swamp/config/swamp-shape-baseline.json` | +4 | −11 | -7 | Retain existing Nodes-owned DNS allowances at reduced counts; remove obsolete router-preparation allowance; add no rows. |
| `swamp/extensions/behaviors/operations/certificates/README.md` | +1 | −1 | +0 | Document the consumed role supplier and its unchanged public/private contract. |
| `swamp/extensions/behaviors/operations/certificates/residue_native_test.py` | +189 | −0 | +189 | Preserve the original residue-removal parity case on current native children; adapt only its disposable fixture. |
| `swamp/extensions/behaviors/operations/certificates/role_native_test.py` | +23 | −0 | +23 | Consume canonical WebSocket reverb pair paths and prove signing, reuse and removal parity. |
| `swamp/extensions/behaviors/operations/certificates/script_actions_test.py` | +55 | −27 | +28 | Cover admission identity, private absence, legacy parity and native success/failure behavior. |
| `swamp/extensions/behaviors/operations/certificates/scripts/role_cleanup.py` | +3 | −3 | +0 | Consume canonical WebSocket reverb pair paths and prove signing, reuse and removal parity. |
| `swamp/extensions/behaviors/operations/certificates/scripts/role_paths.py` | +2 | −1 | +1 | Consume canonical WebSocket reverb pair paths and prove signing, reuse and removal parity. |
| `swamp/extensions/behaviors/operations/certificates/scripts/role_publish.py` | +5 | −5 | +0 | Consume canonical WebSocket reverb pair paths and prove signing, reuse and removal parity. |
| `swamp/extensions/behaviors/operations/certificates/scripts/role_request.py` | +3 | −3 | +0 | Consume canonical WebSocket reverb pair paths and prove signing, reuse and removal parity. |
| `swamp/extensions/behaviors/operations/certificates/scripts/role_verify.py` | +3 | −3 | +0 | Consume canonical WebSocket reverb pair paths and prove signing, reuse and removal parity. |
| `swamp/extensions/behaviors/operations/fleet-recipe/README.md` | +15 | −0 | +15 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/assets.ts` | +14 | −3 | +11 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/manifest.yaml` | +28 | −1 | +27 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/observer_assets.ts` | +85 | −41 | +44 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/prerequisites_test.ts` | +42 | −0 | +42 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/role_phase_assets.ts` | +92 | −0 | +92 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/role_phase_assets_test.ts` | +59 | −0 | +59 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/role_phases/caddy_service.py` | +9 | −0 | +9 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/role_phases/cli.py` | +16 | −0 | +16 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/role_phases/common.py` | +53 | −0 | +53 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/role_phases/firewall.py` | +19 | −0 | +19 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/role_phases/observer.py` | +52 | −0 | +52 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/role_phases/php_runtime.py` | +20 | −0 | +20 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/role_phases/prerequisites.py` | +40 | −0 | +40 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/role_phases_test.py` | +286 | −0 | +286 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_assets.ts` | +156 | −0 | +156 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_scripts/phase_account.py` | +30 | −0 | +30 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_scripts/phase_activation.py` | +13 | −0 | +13 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_scripts/phase_checkout.py` | +24 | −0 | +24 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_scripts/phase_cleanup.py` | +9 | −0 | +9 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_scripts/phase_common.py` | +94 | −0 | +94 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_scripts/phase_dependencies.py` | +24 | −0 | +24 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_scripts/phase_environment.py` | +21 | −0 | +21 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_scripts/phase_restore.py` | +20 | −0 | +20 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_scripts/phase_unit.py` | +11 | −0 | +11 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_scripts/phase_verify.py` | +16 | −0 | +16 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_scripts/websocket_cleanup.py` | +60 | −0 | +60 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_scripts/websocket_directory.py` | +36 | −0 | +36 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_scripts/websocket_link.py` | +46 | −0 | +46 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_scripts/websocket_observe.py` | +53 | −0 | +53 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/scripts/caddy_ordering.py` | +130 | −0 | +130 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/scripts/file.py` | +8 | −0 | +8 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/scripts/file_restore.py` | +40 | −0 | +40 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/scripts/file_snapshot.py` | +45 | −0 | +45 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet-recipe/scripts/role_prerequisites.py` | +294 | −0 | +294 | Keep a sole shipped provider for extracted prerequisites, Caddy ordering and bounded role phase scripts. |
| `swamp/extensions/behaviors/operations/fleet/recipe_program_test.py` | +25 | −0 | +25 | Cover admission identity, private absence, legacy parity and native success/failure behavior. |
| `swamp/extensions/behaviors/operations/integrations/mod_test.ts` | +18 | −0 | +18 | Cover admission identity, private absence, legacy parity and native success/failure behavior. |
| `swamp/extensions/behaviors/operations/integrations/registration.json` | +1 | −4 | -3 | Align owning role capture, registration and projection with native parent inputs and output facts. |
| `swamp/extensions/behaviors/operations/integrations/websocket_runtime_system.ts` | +2 | −0 | +2 | Align owning role capture, registration and projection with native parent inputs and output facts. |
| `swamp/extensions/behaviors/operations/node-role-converge-command/README.md` | +57 | −5 | +52 | Consume Nodes-owned typed contribution capture and collapsed shared phase factory. |
| `swamp/extensions/behaviors/operations/node-role-converge-command/actions_test.ts` | +187 | −7 | +180 | Consume Nodes-owned typed contribution capture and collapsed shared phase factory. |
| `swamp/extensions/behaviors/operations/node-role-converge-command/composition.ts` | +101 | −0 | +101 | Consume Nodes-owned typed contribution capture and collapsed shared phase factory. |
| `swamp/extensions/behaviors/operations/node-role-converge-command/firewall_steps.ts` | +0 | −67 | -67 | Consume Nodes-owned typed contribution capture and collapsed shared phase factory. |
| `swamp/extensions/behaviors/operations/node-role-converge-command/manifest.yaml` | +1 | −1 | +0 | Consume Nodes-owned typed contribution capture and collapsed shared phase factory. |
| `swamp/extensions/behaviors/operations/node-role-converge-command/observer_steps.ts` | +0 | −248 | -248 | Consume Nodes-owned typed contribution capture and collapsed shared phase factory. |
| `swamp/extensions/behaviors/operations/node-role-converge-command/phase_steps.ts` | +188 | −0 | +188 | Consume Nodes-owned typed contribution capture and collapsed shared phase factory. |
| `swamp/extensions/behaviors/operations/node-role-converge-command/publication_steps.ts` | +162 | −0 | +162 | Consume Nodes-owned typed contribution capture and collapsed shared phase factory. |
| `swamp/extensions/behaviors/operations/node-role-converge-command/publication_workflow.ts` | +35 | −0 | +35 | Consume Nodes-owned typed contribution capture and collapsed shared phase factory. |
| `swamp/extensions/behaviors/operations/node-role-converge-command/result.ts` | +69 | −15 | +54 | Consume Nodes-owned typed contribution capture and collapsed shared phase factory. |
| `swamp/extensions/behaviors/operations/node-role-converge-command/result_inputs.ts` | +43 | −0 | +43 | Consume Nodes-owned typed contribution capture and collapsed shared phase factory. |
| `swamp/extensions/behaviors/operations/node-role-converge-command/root_steps.ts` | +465 | −0 | +465 | Consume Nodes-owned typed contribution capture and collapsed shared phase factory. |
| `swamp/extensions/behaviors/operations/node-role-converge-command/scripts_test.py` | +21 | −2 | +19 | Consume Nodes-owned typed contribution capture and collapsed shared phase factory. |
| `swamp/extensions/behaviors/operations/node-role-converge-command/steps.ts` | +0 | −71 | -71 | Consume Nodes-owned typed contribution capture and collapsed shared phase factory. |
| `swamp/extensions/behaviors/operations/node-role-converge-command/websocket_workflow.ts` | +229 | −0 | +229 | Consume Nodes-owned typed contribution capture and collapsed shared phase factory. |
| `swamp/extensions/behaviors/operations/node-role-converge-command/workflows.ts` | +18 | −219 | -201 | Consume Nodes-owned typed contribution capture and collapsed shared phase factory. |
| `swamp/extensions/behaviors/operations/node-role-parent/manifest.yaml` | +8 | −0 | +8 | Own add/relocate graph composition and terminal partial-success facts. |
| `swamp/extensions/behaviors/operations/node-role-parent/result.ts` | +94 | −0 | +94 | Own add/relocate graph composition and terminal partial-success facts. |
| `swamp/extensions/behaviors/operations/node-role-parent/result_test.ts` | +101 | −0 | +101 | Own add/relocate graph composition and terminal partial-success facts. |
| `swamp/extensions/behaviors/operations/node-role-parent/workflows.ts` | +308 | −0 | +308 | Own add/relocate graph composition and terminal partial-success facts. |
| `swamp/extensions/behaviors/operations/node/converge_footprint.ts` | +1 | −1 | +0 | Align owning role capture, registration and projection with native parent inputs and output facts. |
| `swamp/extensions/behaviors/operations/node/converge_local.ts` | +2 | −2 | +0 | Align owning role capture, registration and projection with native parent inputs and output facts. |
| `swamp/extensions/behaviors/operations/node/footprint_local_actions.ts` | +2 | −1 | +1 | Align owning role capture, registration and projection with native parent inputs and output facts. |
| `swamp/extensions/behaviors/operations/node/footprint_workflows.ts` | +261 | −291 | -30 | Align owning role capture, registration and projection with native parent inputs and output facts. |
| `swamp/extensions/behaviors/operations/node/footprint_workflows_test.ts` | +2 | −5 | -3 | Cover admission identity, private absence, legacy parity and native success/failure behavior. |
| `swamp/extensions/behaviors/operations/node/gateway_role_system.ts` | +5 | −85 | -80 | Align owning role capture, registration and projection with native parent inputs and output facts. |
| `swamp/extensions/behaviors/operations/node/gateway_role_system_test.ts` | +8 | −10 | -2 | Cover admission identity, private absence, legacy parity and native success/failure behavior. |
| `swamp/extensions/behaviors/operations/node/role_direct.ts` | +36 | −44 | -8 | Retire add/relocate-only engines; preserve shared live convergence and provisioning consumers. |
| `swamp/extensions/behaviors/operations/node/role_direct_local.ts` | +2 | −0 | +2 | Retire add/relocate-only engines; preserve shared live convergence and provisioning consumers. |
| `swamp/extensions/behaviors/operations/node/role_direct_local_test.ts` | +25 | −6 | +19 | Cover admission identity, private absence, legacy parity and native success/failure behavior. |
| `swamp/extensions/behaviors/operations/node/role_direct_test.ts` | +14 | −0 | +14 | Cover admission identity, private absence, legacy parity and native success/failure behavior. |
| `swamp/extensions/behaviors/operations/node/role_relocate_direct_local.ts` | +1 | −1 | +0 | Retire add/relocate-only engines; preserve shared live convergence and provisioning consumers. |
| `swamp/extensions/behaviors/operations/routes/residue-local_test.ts` | +30 | −0 | +30 | Cover admission identity, private absence, legacy parity and native success/failure behavior. |
| `swamp/extensions/behaviors/operations/routes/router-preparation.ts` | +35 | −129 | -94 | Align owning role capture, registration and projection with native parent inputs and output facts. |
| `swamp/extensions/behaviors/operations/routes/router-preparation_test.py` | +13 | −2 | +11 | Cover admission identity, private absence, legacy parity and native success/failure behavior. |
| `swamp/proof/node-role-parent-README.md` | +32 | −0 | +32 | Replace synthetic proof inputs with real F13 CLI variants and Node parity; no receipt claim. |
| `swamp/proof/node-role-parent-parity.py` | +109 | −0 | +109 | Replace synthetic proof inputs with real F13 CLI variants and Node parity; no receipt claim. |
| `swamp/proof/node-role-parent-timings.json` | +38 | −0 | +38 | Replace synthetic proof inputs with real F13 CLI variants and Node parity; no receipt claim. |
| `swamp/proof/node.role_add/app-prod.json` | +97 | −0 | +97 | Replace synthetic proof inputs with real F13 CLI variants and Node parity; no receipt claim. |
| `swamp/proof/node.role_add/database.json` | +97 | −0 | +97 | Replace synthetic proof inputs with real F13 CLI variants and Node parity; no receipt claim. |
| `swamp/proof/node.role_add/ingress.json` | +118 | −0 | +118 | Replace synthetic proof inputs with real F13 CLI variants and Node parity; no receipt claim. |
| `swamp/proof/node.role_add/websocket.json` | +107 | −0 | +107 | Replace synthetic proof inputs with real F13 CLI variants and Node parity; no receipt claim. |
| `swamp/proof/node.role_converge/README.md` | +13 | −0 | +13 | Consume frozen supplier F13 convergence variants and parity; use canonical certificate filenames; no receipt claim. |
| `swamp/proof/node.role_converge/app-prod.json` | +174 | −0 | +174 | Consume frozen supplier F13 convergence variants and parity; use canonical certificate filenames; no receipt claim. |
| `swamp/proof/node.role_converge/database.json` | +138 | −0 | +138 | Consume frozen supplier F13 convergence variants and parity; use canonical certificate filenames; no receipt claim. |
| `swamp/proof/node.role_converge/ingress.json` | +150 | −0 | +150 | Consume frozen supplier F13 convergence variants and parity; use canonical certificate filenames; no receipt claim. |
| `swamp/proof/node.role_converge/parity.py` | +206 | −0 | +206 | Consume frozen supplier F13 convergence variants and parity; use canonical certificate filenames; no receipt claim. |
| `swamp/proof/node.role_converge/parity_test.py` | +302 | −0 | +302 | Consume frozen supplier F13 convergence variants and parity; use canonical certificate filenames; no receipt claim. |
| `swamp/proof/node.role_converge/prepare.py` | +71 | −0 | +71 | Consume frozen supplier F13 convergence variants and parity; use canonical certificate filenames; no receipt claim. |
| `swamp/proof/node.role_converge/prepare_test.py` | +78 | −0 | +78 | Consume frozen supplier F13 convergence variants and parity; use canonical certificate filenames; no receipt claim. |
| `swamp/proof/node.role_converge/websocket.json` | +185 | −0 | +185 | Consume frozen supplier F13 convergence variants and parity; use canonical certificate filenames; no receipt claim. |
| `swamp/proof/node.role_relocate/websocket.json` | +150 | −0 | +150 | Replace synthetic proof inputs with real F13 CLI variants and Node parity; no receipt claim. |
| `swamp/tests/_support/websocket_role_ssh.py` | +79 | −0 | +79 | Cover admission identity, private absence, legacy parity and native success/failure behavior. |
| `swamp/tests/node_app_prod_role_native_test.py` | +76 | −0 | +76 | Consume canonical WebSocket reverb pair paths and prove signing, reuse and removal parity. |
| `swamp/tests/node_database_role_native_test.py` | +135 | −9 | +126 | Consume canonical WebSocket reverb pair paths and prove signing, reuse and removal parity. |
| `swamp/tests/node_role_parent_native_test.py` | +152 | −0 | +152 | Cover admission identity, private absence, legacy parity and native success/failure behavior. |
| `swamp/tests/node_role_prefix_native_test.py` | +45 | −0 | +45 | Cover admission identity, private absence, legacy parity and native success/failure behavior. |
| `swamp/tests/node_websocket_role_native_test.py` | +150 | −0 | +150 | Consume canonical WebSocket reverb pair paths and prove signing, reuse and removal parity. |
| `swamp/workflows/workflow-node-role-converge.yaml` | +29 | −45 | -16 | Generate native action graph from sole owning factory; maintain existing UUID and dependencies. |
| `swamp/workflows/workflow-node-role-publication.yaml` | +29 | −0 | +29 | Generate native action graph from sole owning factory; maintain existing UUID and dependencies. |
| `swamp/workflows/workflow-node-role-websocket.yaml` | +38 | −0 | +38 | Consume shared WebSocket phase collapse and preserve activation/recovery parity. |
| `swamp/workflows/workflow-operation-node-role-add-direct.yaml` | +50 | −339 | -289 | Generate native action graph from sole owning factory; maintain existing UUID and dependencies. |
| `swamp/workflows/workflow-operation-node-role-converge-209.yaml` | +6 | −5 | +1 | Generate native action graph from sole owning factory; maintain existing UUID and dependencies. |
| `swamp/workflows/workflow-operation-node-role-relocate-direct.yaml` | +55 | −455 | -400 | Generate native action graph from sole owning factory; maintain existing UUID and dependencies. |

This accounting file is documentation only and is excluded from its own table.
