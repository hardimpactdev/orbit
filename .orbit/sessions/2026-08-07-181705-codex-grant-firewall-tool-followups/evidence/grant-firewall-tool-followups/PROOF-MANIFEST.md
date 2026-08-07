# Retained-incus runtime proof — grant, firewall, and tool:install follow-ups

## Topology

| Field | Value |
| --- | --- |
| id | `dev-6cb7e5` |
| kind | `operator_gateway_app-dev` |
| provider | `incus` |
| host | `beast` |
| instances | `orbit-e2e-dev-6cb7e5-operator`, `orbit-e2e-dev-6cb7e5-gateway`, `orbit-e2e-dev-6cb7e5-dev` |
| checkout overlay | operator, gateway, app-dev |
| release | `composer e2e:incus -- --stop --id=dev-6cb7e5` |

## Overlay identity

The candidate branch is the code under test, verified inside the VM before any
command ran: `ToolInstallCommand.php` contains zero `status` references, and
`NodeGrantController.php:292` contains `'code' => 'node.grant_permissions_ignored'`.
Commands were run as the `orbit` user from `/home/orbit/orbit` on the gateway
instance, which is the only account carrying gateway CLI configuration.

## Observed

### tool:install --status removed
`./apps/cli/orbit tool:install composer --node=app-dev-1 --status=running --json`
→ `The "--status" option does not exist.`

### node:grant names only genuinely uncovered permissions, additively
1. `node:grant app-dev-1 gateway --permissions=node:read` → `already_granted:false`, `permissions:["node:read"]`, no warnings.
2. `node:grant app-dev-1 gateway --permissions=node:read,tool:read` → `already_granted:true`, stored set unchanged `["node:read"]`, and one warning:
   `node.grant_permissions_ignored`, family `node`, message naming **only** `tool:read`, `next_command: node:permissions app-dev-1 gateway --add='tool:read'`, `permissions:["tool:read"]`.
   `node:read` is correctly **not** reported, because the stored grant already confers it.
3. Running the suggested command verbatim → `mode:"add"`, `permissions:["node:read","tool:read"]`. It **widened**; it did not replace.
4. Wildcard case: stored `["*"]`, then `node:grant --permissions=node:read,tool:read` → `already_granted:true`, `permissions:["*"]`, **no warnings**.
   This is the regression that mattered: the pre-fix code reported every entry as missing and suggested a replacing command, which would have revoked the wildcard grant.

### firewall reason survives convergence, through to the node backend
1. `firewall:allow receipt-note --node=app-dev-1 --port=5199 --from=10.6.0.0/24 --reason="keep this note"` → `action:created`, `backend_enacted:true`, `reason:"keep this note"`.
2. Re-run **without** `--reason` → `action:converged`, `reason:"keep this note"` retained.
3. On the app-dev node: `sudo ufw show added` contains
   `ufw allow from 10.6.0.0/24 to any port 5199 proto tcp comment 'keep this note'`.
   The operator note survives to the actual backend rule, which is the reported data loss.

### protected role-owned rules reject user convergence
1. Seeded `receipt-protected` with `owner=metrics`; model derived `protected=true`.
2. `firewall:allow receipt-protected --node=app-dev-1 --port=5198 --from=10.6.0.0/24 --reason="user attempt"`
   → `error.code=firewall_rule.protected`, message "Protected firewall rules cannot be changed through firewall commands.", meta names the rule, node, and `owner=metrics`.
3. Post-rejection state: `metrics|protected|role owned note` — owner, protected, and reason all unchanged.

## Boundary (do not overclaim)

- `ufw` reports `Status: inactive` on this prepared topology, so rule **enforcement** was not exercised; `ufw show added` proves the rule and its comment are registered, which is the hop the reason fix affects.
- The protected-rule seed was created directly through the model to simulate a role-owned rule, rather than by converging a real `metrics` role baseline.
- The CLI test-isolation change is not runtime-observable and is proven by the sharded Pest lane, not here.
