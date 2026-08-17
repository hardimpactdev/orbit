# Solo 756 retained-Incus runtime receipt

candidate=`a06d8d465e8bb1c38cc5ead7e2db73b5356879e5`
git_tree_clean=true
venue=retained-incus
environment=dev-fixture
topology_id=`dev-f603c1` (operator_gateway_app-dev; acquired from the 756 worktree, source-mounted to the candidate)
source_binding=container `/srv/orbit/apps/gateway/app/Services/Processes/ProcessOwnerContext.php` sha256 `99e5c2752db07a7e88a0eca1748a2c2c63a251305ed55f497dc430a17141be42` identical to the worktree candidate
quality_receipt=`.orbit/quality-gates/quality-check-2026-08-17T093803Z-24aa2bebec85.json` (commit a06d8d46, dirty=false, exit 0, all 45 subgates zero)
driver_identity=Solo orchestrator agent process 2470 driving `ssh beast incus exec` into the retained VMs; live gateway construction guard exercised via `php artisan tinker` (read-only, no mutation)

## Owner-kind factory matrix (live gateway)

Each `ProcessOwnerContext` factory is exercised end-to-end through the live process-ownership
resolver (`ProcessOwnerContextResolver` → factory) and directly on the gateway.

| Factory | Live surface | Observed | Result |
|---|---|---|---|
| forNode | `orbit process:list --node=app-dev-1 --json` | node-owned valkey process returned (context node=app-dev-1, app/instance/workspace null) | PASS |
| forNode | `orbit process:list --node=gateway --json` | valid empty context (node=gateway, others null) | PASS |
| forNode | gateway tinker `ProcessOwnerContext::forNode($node)` | owner=App\Models\Node, app=NULL | PASS |
| forInstance | `orbit process:list --instance=proofapp.development --json` | `frankenphp-proofapp` process (context app=proofapp, instance=development, workspace=null) | PASS |
| forWorkspace | `orbit process:list --workspace=proofws --instance=proofapp.development --json` | `frankenphp-proofapp-proofws` process (context app=proofapp, instance=development, workspace=proofws) | PASS |
| forWorkspace (valid) | gateway tinker `forWorkspace($node, proofws, proofws->instance)` | constructs OK | PASS |
| reject mixed ownership at construction | gateway tinker `forWorkspace($node, proofws, proofapp2.instance)` | throws InvalidArgumentException "A workspace-owned process context requires the workspace instance." | PASS |
| reject mismatch (resolver) | `orbit process:list --workspace=proofws --instance=proofapp2.development --json` | validation_failed (workspace not resolvable under the mismatched instance's app) | PASS |

## Summary

The private-constructor + `forNode`/`forInstance`/`forWorkspace` factory refactor resolves and
projects process ownership correctly for all three valid owner kinds on the live gateway, and the
factory rejects mixed/mismatched ownership at construction time (proven directly on the live gateway),
matching the audit intent (contradictions rejected at construction, not deferred to execution). The
comprehensive per-guard rejection matrix (missing instance, mismatched app, private constructor
inaccessibility) is unit-tested in `ProcessOwnerContextFactoriesTest` (green in quality-check).

result=passed
