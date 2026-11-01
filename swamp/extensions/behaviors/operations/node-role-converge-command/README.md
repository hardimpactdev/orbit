# Native role convergence

`operation-node-role-converge-209` executes existing Database-only and
Ingress-only, WebSocket-only and AppProd-only assignments from209 when no serving Metrics fleet needs
reconciliation. All four use the same `node-role-converge` factory: account,
observer binary, packages, peer DNS, observer files/service, CLI, and typed
terminal contribution. Ingress also installs pinned Caddy, enables its service,
publishes the complete admitted catalog through `operation-route-caddy`, then
applies canonical firewall expansion and pruning in one bounded script-file phase. Failure before the
firewall prevents its action. Required Caddy/firewall failures fail the role;
observer, peer DNS and CLI failures remain explicit follow-ups.

The Gateway admits full public intent, the shared209 identity and full target
pins. Only the existing observer digest uses vault-backed SSH exec stdin.
Only the terminal subscriber records state. Native admissions reject retained
callbacks. Other roles use the shared retained role-add graph with the original
dispatcher run ID; its duplicate converge graph is deleted. Enrollment
restoration, whole-hub transitions and Metrics/Tool kinds remain separate.

Official cfgmgmt firewall schemas cannot express the WireGuard interface and
destination tuple and do not accept full target pins. The canonical catalog
supplies exact UFW arguments, executed with official SSH and UFW's idempotence.
No retained UFW status parser or embedded firewall program is used by this root.
No transport, policy engine, credential or worker is added.

The exported `roleConvergeSteps(name, intentExpr, fullGlobals, dependency?, guard?, options?)` shares this graph with composing roots. `preparedToolsExpr` verifies same-Node complete OS coverage and suppresses only generic package installation. `observerDigestExpr` selects the existing private observer digest. `executionSecretsExpr` selects public vault references containing `observer` and, for WebSocket, `runtime_material`. Private values use whole vault reads on SSH stdin. No recipe/tool installer is imported.

`RoleConvergeReceipt` is schema1 with Node/assignment/role identity, role/runtime/certificate/publication proof, baseline, footprint, CLI and follow-up. The final step is `<name>_role_result` (or `role_result` with empty prefix). It reads only named public statuses and the successful CLI output. Optional role lists must bind completed admission-job context before effects. Forward composition does not provide complete roleless enrollment or deferred parent restoration/finalization. These remain unsupported; this slice does not unblock complete node.add.

For direct embedding, that step exposes the receipt fields flat:
`steps["<name>_role_result"].outputs.role_proven`, `outputs.node_id`, and the
other receipt fields. There is no intermediate `outputs.result` object.
A workflow child has its own output envelope; its wrapping is a separate
contract. Native parent run `5d3aa473-c6bf-4fd7-884f-90d27d273b49` verifies the
direct form.

Owning add and relocation parents can capture a supported contribution after recording the actual assignment. `NativeNodeRoleConvergeInput::supportsContribution(Node, RoleName)` has no worker or sole-role requirement. `capture(Node, NodeRole, snapshot)` preserves `snapshot.created` and verifies the stored assignment plus snapshot identity. `created` is required in native intent and survives successful and failed receipts. Other roles on the Node do not themselves refuse capture; missing required artifact or Metrics contributions still do. This is forward contribution support, not acceptance of a complete add, relocation or enrollment parent. The existing private renderer is `NodeRoleRuntimeSource::resolveRoleMaterial(snapshot, ownerNodeId)`; the execution-copy runtime key is `node-role-{operation_id}-runtime_material`.

WebSocket uses visible source, Composer, private environment, release/unit validation, service activation, listener observation, restoration and release cleanup actions. A source or validation failure before cutover leaves the running service untouched. Cleanup keeps the current and captured prior releases after success. A failed cutover retains its candidate for same-child resume; a failed pre-cutover candidate is removed. Its complete captured PHP/Caddy/DNS catalogs use the canonical Route children. The Gateway records certificate and serving state from terminal outputs. AppDev and Analytics remain on their existing paths.

The shared contribution resolves its canonical public supplier files once before optional fan-out and stages them once per contribution and run. Account, observer installation, prerequisites, observer configuration, PHP footprint and CLI run as bounded script-file phases. AppProd prerequisites retain Composer scope checks, pinned PHP, JavaScript installation and Caddy ordering in their existing order. A same-Node prepared-tool proof skips OS and JavaScript installation while retaining Composer scope checks, PHP and Caddy ordering. Peer DNS uses the canonical native network child, without the old network program.

The WebSocket child resolves its public script bundle once, then executes account, checkout, dependencies, environment, unit, activation and verify as one script-file action per legacy phase. Each action uses official pinned SSH exec under coreutils `timeout`; every step has `queueTimeout: 30`. The terminal supplier receives only named phase statuses. It never receives the whole native context.

After activation failure the child restores its prior unit and release. Resume the same failed child from `activation` to republish the admitted unit/link and activate the candidate. Retrying only `verify` cannot prove completion: readiness checks the exact admitted release link and unit as well as the service and socket. The original before snapshot remains cached upstream. Cleanup can resume separately without restarting the service. Phase limits are account30s, checkout120s, dependencies300s, environment60s, unit30s, activation45s, verify15s, restore60s and cleanup30s. F10 target-VM measurement remains required before accepting an F8 run budget.

## Retained caller boundary

WebSocket convergence cannot fall back to the retained add/relocate graph.
Unsupported mixed-role, absent-assignment or Metrics-fleet convergence refuses
before effects. The obsolete `websocket.role_converge` and
`websocket.role_remove` callback registrations and role preparation dispatch
are deleted. The shared WebSocket installer remains necessary for role
add/relocate. Synchronous Node enrollment still reaches `WebSocketRoleBaseline`
through `AddNodeRoleAction::executeDuringProvisioning`; its root must switch
before that baseline and installer can be deleted.

The retained converge Gateway DNS caller and standalone generic
`node.role_remove` caller still use `node/gateway_role_system.ts`. The Nodes
lane owns both callers. Lane21's add/relocate retirement does not retire them;
their three remaining shape findings stay explicit draft gates.

Database, Ingress, AppProd, AppDev and WebSocket share account, observer, package,
peer-DNS, CLI and terminal assembly in `root_steps.ts`. AppDev adds storage,
early private DNS, hibernation and tmpfiles through the same contribution.
Storage and hibernation use the extracted Fleet policy that retained callers
also consume; no copied filesystem writer is introduced. Metrics must consume its
owning lane's native runtime contribution. These future consumers are not
claimed as migrated here.

AppProd port work follows the legacy phases: resolve the managed account,
install the canonical Caddy package and role prerequisites, install the pinned
JavaScript runtime, order and publish Caddy, then apply the role firewall.
The PHP build uses the recipe installer rather than generic APT PHP aliases.
Composer refuses a foreign scope; JavaScript uses the existing Fleet recipe
policy, including NodeSource and Corepack retirement. Native parity runs must
measure the p95 before accepting the F8 run budget and queue timeout. The draft
source selects this contribution for supported AppProd plans; topology proof
and live activation are not claimed.

## F13 topology proof

The complete role variants live at `swamp/proof/node.role_converge/{database,ingress,app-prod,websocket}.json`. Run one with `bin/swamp-proof run node.role_converge --variant <role>`. Each uses the supported AppProd topology Node; a bounded preparation helper replaces its current role through the normal CLI when needed and records public assignment precondition evidence before the convergence request. Preparation requires lane21's native role-add parent. It never writes Gateway records directly.

The WebSocket leaf remains `reverb.orbit` and signs through the topology Gateway's own CA and normal primitive. The proof has no signer address input or disposable signer. Its read-only parity checks recorded assignment identity, native and projected phase proofs, exact public configuration and the target's service state. The fixtures establish warm convergence, not cold installation. A passing F10 receipt is still required before merge.

## AppDev contribution

AppDev preserves the legacy order: account and storage validation, private DNS on its admitted publishers, Caddy/package prerequisites and recipe PHP/JavaScript, hibernation directories, Caddy ordering/publication, then firewall. A native dependency stops each later phase when its predecessor fails. Follow-up footprints run after proven AppDev firewall completion. The PHP installer uses shared-FPM policy and the canonical recipe. Admission captures the desired active footprint without changing the database claim, so PHP runtime and tmpfiles cannot disappear while the assignment is provisioning.

The native observer verifies `/etc/orbit/agent/secret` against the copied existing identity hash. Its guard remains mandatory. A hashless topology Node still needs its normal identity prerequisite; the fixture never fabricates one. AppDev's `app-dev.json` uses topology `app-dev`, the actual CLI converge call and read-only storage/hibernation/tmpfiles parity. The unmeasured F8 run budget is 1800 seconds, with queueTimeout30; an actual F13 receipt and measured timings remain merge gates.
