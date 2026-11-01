# Instance deploy F9 rebuild

Draft port, with no F13 receipt. Do not merge before both variants pass.

- `dev.json`: `run instance.deploy --variant dev`, topology `app-dev`.
- `prod.json`: `run instance.deploy --variant prod`, topology `["app-dev", "app-prod+ingress"]`.
- `../instance.rollback.json`: the subsequent rollback parent port, with the production topology.

Each variant is a complete frozen-format fixture. Its phase job/step names must match the rebuilt workflow before a pass counts. Budgets are provisional until the topology runs measure them. The read-only parity helper checks the checkout or selected release, command account/order and dedicated production PHP service/socket. It does not repair state.

Development follows legacy `6291bb036`: fetch the configured branch; verify an exact pin is contained; refuse tracked changes or a divergent local branch; clean nested files left in removed directories; check out in place; run ordered commands with individual timeouts and required/best-effort results. A failed command leaves the new checkout and the old successful seed. Deployment commands expand Gateway-owned setup macros through the existing supplier before their digest and vault copy are made.

The source phase checks Swamp core and official `@swamp/git` first. A generic checkout does not provide the legacy in-place dirty/diverged refusals, branch-pin check and removed-directory cleanup as one action group. This script uses stock git defaults for that group. Placement and transport use official `@swamp/ssh`; timeouts use coreutils, with no family transport, supervisor or lock.

The command phase receives one command bundle through the shared vault descriptor. No command bytes appear in workflow intent or argv. The existing pure Project dotenv parser is prefixed unchanged to the command script; metadata does not rewrite script source or inject inputs into it. Metadata resolves shipped scripts and decodes public phase results only.

Production work remains in progress. Its legacy order is source, environment, before commands, activation plus captured runtime definitions, PHP refresh, Process restart, after commands, cleanup. The port must remove `prepareProduction`, `finishProduction` and their HTTP failure projector in the same PR. Rollback follows the proven production lower; registering the retired workflow alone does not restore it.
