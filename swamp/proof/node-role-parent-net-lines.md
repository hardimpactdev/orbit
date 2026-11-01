# Role parent source line accounting

Comparison base: `adcf542120a14cea21cad594a83c9e669053b722`. Includes the imported Nodes supplier checkpoints and the guarded retained paths required by C for unported roles. The requested net-negative target is not met; no full worker-retirement claim follows from this draft.

| Scope | Added | Deleted | Net |
| --- | ---: | ---: | ---: |
| Docs/other | 26 | 3 | +23 |
| Gateway production | 609 | 65 | +544 |
| Gateway tests | 428 | 9 | +419 |
| Swamp docs/proof | 2148 | 6 | +2142 |
| Swamp production | 5066 | 1767 | +3299 |
| Swamp tests | 2353 | 327 | +2026 |

| File | Added | Deleted | Net | Reason |
| --- | ---: | ---: | ---: | --- |
| `apps/gateway/app/Actions/Nodes/AdmitNodeRoleAddAction.php` | 122 | 0 | +122 | 0 |
| `apps/gateway/app/Actions/Nodes/AdmitNodeRoleOperationAction.php` | 19 | 50 | -31 | 50 |
| `apps/gateway/app/Actions/Nodes/AdmitNodeRoleRelocateAction.php` | 161 | 0 | +161 | 0 |
| `apps/gateway/app/Domain/Nodes/NativeNodeRoleConvergeInput.php` | 65 | 7 | +58 | 7 |
| `apps/gateway/app/Domain/Nodes/NodeRoleDirectInput.php` | 2 | 0 | +2 | 0 |
| `apps/gateway/app/Domain/Nodes/NodeRoleDirectProjection.php` | 13 | 0 | +13 | 0 |
| `apps/gateway/app/Domain/Nodes/NodeRoleParentInput.php` | 71 | 0 | +71 | 0 |
| `apps/gateway/app/Domain/Nodes/NodeRoleParentProjection.php` | 121 | 0 | +121 | 0 |
| `apps/gateway/app/Domain/Nodes/NodeRoleRelocateInput.php` | 2 | 2 | +0 | 2 |
| `apps/gateway/app/Domain/Nodes/direct-operations.php` | 16 | 3 | +13 | 3 |
| `apps/gateway/app/Infrastructure/MachineSoftware/NodeRecipeProgram.php` | 17 | 3 | +14 | 3 |
| `apps/gateway/tests/Feature/Api/AdmitNativeNodeRoleTest.php` | 8 | 2 | +6 | 2 |
| `apps/gateway/tests/Feature/Api/NativeDatabaseRoleConvergeTest.php` | 167 | 0 | +167 | 0 |
| `apps/gateway/tests/Feature/Api/NativeNodeRoleHttpTest.php` | 7 | 1 | +6 | 1 |
| `apps/gateway/tests/Feature/Api/NodeRoleParentNativeTest.php` | 198 | 0 | +198 | 0 |
| `apps/gateway/tests/Feature/Api/NodeRoleRelocationOperationTest.php` | 12 | 6 | +6 | 6 |
| `apps/gateway/tests/Feature/Infrastructure/Routes/NativeRouteResidueProjectionTest.php` | 36 | 0 | +36 | 0 |
| `docs/reference/database-role.md` | 2 | 0 | +2 | 0 |
| `docs/reference/node-provisioning.md` | 22 | 3 | +19 | 3 |
| `docs/reference/node-role-certificates.md` | 2 | 0 | +2 | 0 |
| `swamp/config/gateway-workflow-grants.json` | 1 | 1 | +0 | 1 |
| `swamp/config/swamp-shape-baseline.json` | 4 | 17 | -13 | 17 |
| `swamp/extensions/behaviors/operations/certificates/README.md` | 1 | 1 | +0 | 1 |
| `swamp/extensions/behaviors/operations/certificates/actions.ts` | 0 | 28 | -28 | 28 |
| `swamp/extensions/behaviors/operations/certificates/actions_test.ts` | 0 | 40 | -40 | 40 |
| `swamp/extensions/behaviors/operations/certificates/manifest.yaml` | 1 | 1 | +0 | 1 |
| `swamp/extensions/behaviors/operations/certificates/residue_native_test.py` | 189 | 0 | +189 | 0 |
| `swamp/extensions/behaviors/operations/certificates/role_native_test.py` | 23 | 0 | +23 | 0 |
| `swamp/extensions/behaviors/operations/certificates/script_actions_test.py` | 55 | 27 | +28 | 27 |
| `swamp/extensions/behaviors/operations/certificates/scripts/local.py` | 29 | 5 | +24 | 5 |
| `swamp/extensions/behaviors/operations/certificates/scripts/role_cleanup.py` | 3 | 3 | +0 | 3 |
| `swamp/extensions/behaviors/operations/certificates/scripts/role_paths.py` | 2 | 1 | +1 | 1 |
| `swamp/extensions/behaviors/operations/certificates/scripts/role_publish.py` | 5 | 5 | +0 | 5 |
| `swamp/extensions/behaviors/operations/certificates/scripts/role_request.py` | 3 | 3 | +0 | 3 |
| `swamp/extensions/behaviors/operations/certificates/scripts/role_verify.py` | 3 | 3 | +0 | 3 |
| `swamp/extensions/behaviors/operations/certificates/system.ts` | 0 | 54 | -54 | 54 |
| `swamp/extensions/behaviors/operations/certificates/system_test.py` | 85 | 51 | +34 | 51 |
| `swamp/extensions/behaviors/operations/fleet-recipe/README.md` | 15 | 0 | +15 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/assets.ts` | 14 | 3 | +11 | 3 |
| `swamp/extensions/behaviors/operations/fleet-recipe/manifest.yaml` | 28 | 1 | +27 | 1 |
| `swamp/extensions/behaviors/operations/fleet-recipe/observer_assets.ts` | 85 | 41 | +44 | 41 |
| `swamp/extensions/behaviors/operations/fleet-recipe/prerequisites_test.ts` | 42 | 0 | +42 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/role_phase_assets.ts` | 92 | 0 | +92 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/role_phase_assets_test.ts` | 59 | 0 | +59 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/role_phases/caddy_service.py` | 9 | 0 | +9 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/role_phases/cli.py` | 16 | 0 | +16 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/role_phases/common.py` | 53 | 0 | +53 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/role_phases/firewall.py` | 19 | 0 | +19 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/role_phases/observer.py` | 52 | 0 | +52 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/role_phases/php_runtime.py` | 20 | 0 | +20 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/role_phases/prerequisites.py` | 40 | 0 | +40 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/role_phases_test.py` | 286 | 0 | +286 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_assets.ts` | 156 | 0 | +156 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_scripts/phase_account.py` | 30 | 0 | +30 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_scripts/phase_activation.py` | 13 | 0 | +13 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_scripts/phase_checkout.py` | 24 | 0 | +24 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_scripts/phase_cleanup.py` | 9 | 0 | +9 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_scripts/phase_common.py` | 94 | 0 | +94 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_scripts/phase_dependencies.py` | 24 | 0 | +24 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_scripts/phase_environment.py` | 21 | 0 | +21 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_scripts/phase_restore.py` | 20 | 0 | +20 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_scripts/phase_unit.py` | 11 | 0 | +11 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_scripts/phase_verify.py` | 16 | 0 | +16 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_scripts/websocket_cleanup.py` | 60 | 0 | +60 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_scripts/websocket_directory.py` | 36 | 0 | +36 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_scripts/websocket_link.py` | 46 | 0 | +46 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/runtime_scripts/websocket_observe.py` | 53 | 0 | +53 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/scripts/caddy_ordering.py` | 130 | 0 | +130 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/scripts/file.py` | 8 | 0 | +8 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/scripts/file_restore.py` | 40 | 0 | +40 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/scripts/file_snapshot.py` | 45 | 0 | +45 | 0 |
| `swamp/extensions/behaviors/operations/fleet-recipe/scripts/role_prerequisites.py` | 294 | 0 | +294 | 0 |
| `swamp/extensions/behaviors/operations/fleet/recipe_program_test.py` | 25 | 0 | +25 | 0 |
| `swamp/extensions/behaviors/operations/integrations/mod_test.ts` | 18 | 0 | +18 | 0 |
| `swamp/extensions/behaviors/operations/integrations/registration.json` | 1 | 4 | -3 | 4 |
| `swamp/extensions/behaviors/operations/integrations/websocket_runtime_system.ts` | 2 | 0 | +2 | 0 |
| `swamp/extensions/behaviors/operations/node-role-converge-command/README.md` | 57 | 5 | +52 | 5 |
| `swamp/extensions/behaviors/operations/node-role-converge-command/actions_test.ts` | 225 | 7 | +218 | 7 |
| `swamp/extensions/behaviors/operations/node-role-converge-command/composition.ts` | 101 | 0 | +101 | 0 |
| `swamp/extensions/behaviors/operations/node-role-converge-command/firewall_steps.ts` | 0 | 67 | -67 | 67 |
| `swamp/extensions/behaviors/operations/node-role-converge-command/manifest.yaml` | 1 | 1 | +0 | 1 |
| `swamp/extensions/behaviors/operations/node-role-converge-command/observer_steps.ts` | 0 | 248 | -248 | 248 |
| `swamp/extensions/behaviors/operations/node-role-converge-command/phase_steps.ts` | 188 | 0 | +188 | 0 |
| `swamp/extensions/behaviors/operations/node-role-converge-command/publication_steps.ts` | 172 | 0 | +172 | 0 |
| `swamp/extensions/behaviors/operations/node-role-converge-command/publication_workflow.ts` | 35 | 0 | +35 | 0 |
| `swamp/extensions/behaviors/operations/node-role-converge-command/result.ts` | 69 | 15 | +54 | 15 |
| `swamp/extensions/behaviors/operations/node-role-converge-command/result_inputs.ts` | 43 | 0 | +43 | 0 |
| `swamp/extensions/behaviors/operations/node-role-converge-command/root_steps.ts` | 465 | 0 | +465 | 0 |
| `swamp/extensions/behaviors/operations/node-role-converge-command/scripts_test.py` | 21 | 2 | +19 | 2 |
| `swamp/extensions/behaviors/operations/node-role-converge-command/steps.ts` | 0 | 71 | -71 | 71 |
| `swamp/extensions/behaviors/operations/node-role-converge-command/websocket_workflow.ts` | 229 | 0 | +229 | 0 |
| `swamp/extensions/behaviors/operations/node-role-converge-command/workflows.ts` | 18 | 219 | -201 | 219 |
| `swamp/extensions/behaviors/operations/node-role-parent/manifest.yaml` | 8 | 0 | +8 | 0 |
| `swamp/extensions/behaviors/operations/node-role-parent/result.ts` | 124 | 0 | +124 | 0 |
| `swamp/extensions/behaviors/operations/node-role-parent/result_test.ts` | 136 | 0 | +136 | 0 |
| `swamp/extensions/behaviors/operations/node-role-parent/workflows.ts` | 471 | 0 | +471 | 0 |
| `swamp/extensions/behaviors/operations/node/converge_footprint.ts` | 4 | 18 | -14 | 18 |
| `swamp/extensions/behaviors/operations/node/converge_local.ts` | 1 | 45 | -44 | 45 |
| `swamp/extensions/behaviors/operations/node/footprint_local_actions.ts` | 2 | 1 | +1 | 1 |
| `swamp/extensions/behaviors/operations/node/footprint_workflows.ts` | 358 | 289 | +69 | 289 |
| `swamp/extensions/behaviors/operations/node/footprint_workflows_test.ts` | 9 | 9 | +0 | 9 |
| `swamp/extensions/behaviors/operations/node/gateway_role_system.ts` | 5 | 85 | -80 | 85 |
| `swamp/extensions/behaviors/operations/node/gateway_role_system_test.ts` | 8 | 10 | -2 | 10 |
| `swamp/extensions/behaviors/operations/node/role_direct.ts` | 34 | 3 | +31 | 3 |
| `swamp/extensions/behaviors/operations/node/role_direct_contract.ts` | 76 | 75 | +1 | 75 |
| `swamp/extensions/behaviors/operations/node/role_direct_local.ts` | 2 | 0 | +2 | 0 |
| `swamp/extensions/behaviors/operations/node/role_direct_local_test.ts` | 25 | 6 | +19 | 6 |
| `swamp/extensions/behaviors/operations/node/role_direct_native_fixture.ts` | 0 | 1 | -1 | 1 |
| `swamp/extensions/behaviors/operations/node/role_direct_test.ts` | 14 | 0 | +14 | 0 |
| `swamp/extensions/behaviors/operations/node/role_relocate_direct_local.ts` | 1 | 1 | +0 | 1 |
| `swamp/extensions/behaviors/operations/routes/mutation-command/assets.ts` | 14 | 1 | +13 | 1 |
| `swamp/extensions/behaviors/operations/routes/residue-command/README.md` | 9 | 0 | +9 | 0 |
| `swamp/extensions/behaviors/operations/routes/residue-command/assets.ts` | 195 | 0 | +195 | 0 |
| `swamp/extensions/behaviors/operations/routes/residue-command/assets_test.ts` | 64 | 0 | +64 | 0 |
| `swamp/extensions/behaviors/operations/routes/residue-command/manifest.yaml` | 10 | 0 | +10 | 0 |
| `swamp/extensions/behaviors/operations/routes/residue-command/scripts/residue.py` | 87 | 0 | +87 | 0 |
| `swamp/extensions/behaviors/operations/routes/residue-command/scripts/residue_test.py` | 135 | 0 | +135 | 0 |
| `swamp/extensions/behaviors/operations/routes/residue-local.ts` | 0 | 115 | -115 | 115 |
| `swamp/extensions/behaviors/operations/routes/residue-local_test.ts` | 0 | 163 | -163 | 163 |
| `swamp/extensions/behaviors/operations/routes/router-preparation.ts` | 35 | 129 | -94 | 129 |
| `swamp/extensions/behaviors/operations/routes/router-preparation_test.py` | 13 | 2 | +11 | 2 |
| `swamp/proof/node-role-parent-README.md` | 31 | 0 | +31 | 0 |
| `swamp/proof/node-role-parent-parity.py` | 109 | 0 | +109 | 0 |
| `swamp/proof/node-role-parent-timings.json` | 40 | 0 | +40 | 0 |
| `swamp/proof/node.role_add/app-prod.json` | 97 | 0 | +97 | 0 |
| `swamp/proof/node.role_add/database.json` | 97 | 0 | +97 | 0 |
| `swamp/proof/node.role_add/ingress.json` | 118 | 0 | +118 | 0 |
| `swamp/proof/node.role_add/websocket.json` | 107 | 0 | +107 | 0 |
| `swamp/proof/node.role_converge/README.md` | 13 | 0 | +13 | 0 |
| `swamp/proof/node.role_converge/app-prod.json` | 174 | 0 | +174 | 0 |
| `swamp/proof/node.role_converge/database.json` | 138 | 0 | +138 | 0 |
| `swamp/proof/node.role_converge/ingress.json` | 150 | 0 | +150 | 0 |
| `swamp/proof/node.role_converge/parity.py` | 206 | 0 | +206 | 0 |
| `swamp/proof/node.role_converge/parity_test.py` | 302 | 0 | +302 | 0 |
| `swamp/proof/node.role_converge/prepare.py` | 71 | 0 | +71 | 0 |
| `swamp/proof/node.role_converge/prepare_test.py` | 78 | 0 | +78 | 0 |
| `swamp/proof/node.role_converge/websocket.json` | 185 | 0 | +185 | 0 |
| `swamp/proof/node.role_relocate/websocket.json` | 150 | 0 | +150 | 0 |
| `swamp/tests/_support/websocket_role_ssh.py` | 79 | 0 | +79 | 0 |
| `swamp/tests/node_app_prod_role_native_test.py` | 76 | 0 | +76 | 0 |
| `swamp/tests/node_database_role_native_test.py` | 135 | 9 | +126 | 9 |
| `swamp/tests/node_role_parent_native_test.py` | 436 | 0 | +436 | 0 |
| `swamp/tests/node_role_prefix_native_test.py` | 45 | 0 | +45 | 0 |
| `swamp/tests/node_websocket_role_native_test.py` | 150 | 0 | +150 | 0 |
| `swamp/workflows/workflow-node-role-converge.yaml` | 29 | 45 | -16 | 45 |
| `swamp/workflows/workflow-node-role-publication.yaml` | 29 | 0 | +29 | Regenerate Nodes-owned publication child from its named-status factory; declare every child queue timeout. |
| `swamp/workflows/workflow-node-role-websocket.yaml` | 38 | 0 | +38 | 0 |
| `swamp/workflows/workflow-operation-node-role-add-direct.yaml` | 269 | 72 | +197 | 72 |
| `swamp/workflows/workflow-operation-node-role-converge-209.yaml` | 6 | 5 | +1 | 5 |
| `swamp/workflows/workflow-operation-node-role-relocate-direct.yaml` | 298 | 92 | +206 | 92 |
| `swamp/workflows/workflow-route-residue.yaml` | 37 | 0 | +37 | 0 |

This accounting file is documentation only and is excluded from its own table.
