# Instance deploy F9 rebuild

Draft port, with no F13 receipt. Do not merge before both variants pass.

The #2332 policy re-proof runs first in the multi-Node group `["app-dev", "app-prod+ingress"]`, before the production deploy variant. Its subject is development pending admission plus production's unique output handles after repeated durable writes. The exact zero-attempt/null-result vault refusal and later admission need public product paths. Impossible attempted or result-bearing failure tuples remain Deno cases, per D's 15:03Z decision.

A direct deploy request does not prove a policy tick. D's accepted `request.await_schedule` waits for the next natural tick and selects its admitted Operation; lane 25 implements it after the first live `up`. Do not seed Gateway rows or Swamp data. Preparation is recorded in `~/swamp-execution/evidence/lane2-policy2332-fixture-prep/` on beast; no receipt is claimed.

- `dev.json`: `run instance.deploy --variant dev`, topology `app-dev`.
- `dev-vp-bin-775.json`: `run instance.deploy --variant dev-vp-bin-775`, topology `app-dev`. Requires a real topology-managed VP installation. The hook changes only its existing `bin/` to 775; the deploy runs `vp --version`, and parity verifies success and that `bin/` remains 775. It neither creates a store nor installs a substitute binary.
- `prod.json`: `run instance.deploy --variant prod`, topology `["app-dev", "app-prod+ingress"]`.
- `../instance.rollback.json`: the subsequent rollback parent port, with the production topology.

Each variant is a complete frozen-format fixture. Its phase job/step names must match the rebuilt workflow before a pass counts. Budgets are provisional until the topology runs measure them. The read-only parity helper checks the checkout or selected release, command account/order and dedicated production PHP service/socket. It does not repair state.

Development follows legacy `6291bb036`: fetch the configured branch; verify an exact pin is contained; refuse tracked changes or a divergent local branch; clean nested files left in removed directories; check out in place; run ordered commands with individual timeouts and required/best-effort results. A failed command leaves the new checkout and the old successful seed. Deployment commands expand Gateway-owned setup macros through the existing supplier before their digest and vault copy are made.

VP scope selection retains #2391's legacy account ownership and executable rule: `/opt/orbit/vite-plus`, then the account's `.vite-plus`, then `.local/share/vite-plus`. Neither scope mode nor `bin/` mode is an extra refusal. The command phase puts the selected store on `PATH`, including projects without runtime pins. The 775 variant uses the Gateway's first-success `instance.deploy` budget of 1,800 seconds on a fresh topology (`OperationRunBudgets`, no explicit kind row). A topology with prior successful deploys must use its history-derived budget at receipt time. The other variant budgets remain provisional. The 775 variant is static preparation until D's allocated topology slot; it is not a receipt.

The source phase checks Swamp core and official `@swamp/git` first. A generic checkout does not provide the legacy in-place dirty/diverged refusals, branch-pin check and removed-directory cleanup as one action group. This script uses stock git defaults for that group. Placement and transport use official `@swamp/ssh`; timeouts use coreutils, with no family transport, supervisor or lock.

The command phase receives one command bundle through the shared vault descriptor. No command bytes appear in workflow intent or argv. The existing pure Project dotenv parser is prefixed unchanged to the command script; metadata does not rewrite script source or inject inputs into it. Metadata resolves shipped scripts and decodes public phase results only.

Production work remains in progress. Its legacy order is source, environment, before commands, activation plus captured runtime definitions, PHP refresh, Process restart, after commands, cleanup. The port must remove `prepareProduction`, `finishProduction` and their HTTP failure projector in the same PR. Rollback follows the proven production lower; registering the retired workflow alone does not restore it.
