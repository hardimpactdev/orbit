# Swamp proof fixtures (F13)

`bin/swamp-proof up --topology app-dev` creates a disposable Task group in Project 62 (`swamp-proof`) with no Task topology declaration, provisions it through the Gateway, stages the exact committed worktree, and composes the existing Incus topology. Every nested Node is a VM copy of the seed's stopped `node-template`. The topology Gateway runs the PR's Gateway and pinned official Swamp with its own database, vaults and loopback worker named `orbit-node-209`. Proofs never use production serve.

Use `bin/swamp-proof run <kind>` for each kind, `bin/swamp-proof receipt` to print the latest receipt path, and `bin/swamp-proof down` to cancel the Task group through the normal API. Failed `up` also cancels its group. A failed run leaves the held topology for inspection; always finish with `down`. Cleanup failures retain the exact group identity for retry.

The outer production Project uses `hardimpactdev/swamp-proof-fixture` only to provision its Task VM. This Project excludes Beast (Node 9). Nested fixture and seeded Projects use topology-local payload repositories; they never point at GitHub. Provision must select Shark (Node 13), Incus project `orbit-tasks`, network `orbittask0`. No Node, access edge, key or host pin is added by the runner. Normal provision supplies them.

## Topology and lifetime

A fixture's `topology` is a preset name or an array in the existing Task topology grammar, such as `["app-dev", "app-prod+ingress"]`. `up --topology` accepts either. Presets live in `topologies/<name>/topology.json` with `name`, `version`, `nodes`, and `seed`. `seed` is null before allocation or the full expected Task VM base-image fingerprint. The receipt records the actual seed, resolved Node array and preset version. Gateway and operator are implicit in every topology. The Gateway VM supplies the `gateway` and `swamp` roles, with VPN on the Gateway. These roles are not workload entries or proof of native enrollment. There are no container images or separate role installers.

The production Gateway config lists Project 62 in `ORBIT_TASK_VMS_MEDIUM_PROJECTS`. Its groups receive the normal medium allocation (4 vCPU, 8 GiB, 40 GiB and a topology seed clone), which holds one test Node plus Gateway and operator. Task groups reject topology declarations; the harness resolves the Node list inside the VM. The runner verifies the admitted medium resources before nested acquisition. Multi-Node `up` refuses until its large-VM allocation is agreed.

Persistent groups are titled `topology <topology>@<version> <lane>`. F11 cancels them after 60 minutes without a run, or eight hours after `Task.created_at`. Run start touches the root-owned `/var/lib/orbit/swamp-proof/last-run` on the outer Task VM. Missing mtime means `created_at`. One-shot groups use `proof <kinds> <topology>@<version>` and a 45-minute ceiling from `created_at`. No Gateway heartbeat or deadline field is stored.

## Request format

Each `swamp/proof/<kind>.json` declares `kind`, `workflow`, `topology`, `budget_seconds`, `queue_timeout_seconds`, `prepare`, `before_request`, `request`, `envelope`, `expect`, `phases`, and `parity`. The two hooks are optional. Queue timeout must be a positive integer equal to the source workflow's explicit `queueTimeout`. Missing, null and default dispatch budgets are refused. The first distributed pilot uses F8's 1800-second unproven budget and an explicit 10-second dispatch budget; its first successful native worker run supplies the measured replacement (longest success times 1.5). Local inline native timings do not include worker dispatch or cold startup.

```json
{
  "prepare": [{"argv": ["orbit", "project:create", "proof-app", "node-package", "${proof.repo.url}", ".", "--json"], "capture": {"project_id": "$.id"}}],
  "before_request": [{"argv": ["python3", "-I", "swamp/proof/example.kind/observer.py", "${proof.run}"]}],
  "request": {"argv": ["orbit", "instance:create", "${capture.project_id}", "${topology.app-dev.node_id}", "proof-${proof.run}", "--json"], "operation": "$.id"},
  "envelope": {"node_id": "${topology.app-dev.node_id}", "start_tag": "${match.uuid}"}
}
```

Prepare and request call the real `orbit` CLI on the topology operator. Captures select exact JSON paths such as `$.id` or `$.result.instance_id`. They create every target identity; fixtures do not hard-code IDs. Before-request commands run on the outer Task VM after prepare, with the staged worktree root as their working directory. Each `{argv:[...]}` object may set `timeout_seconds` (default 60, maximum 300). They may fault-inject workload state or use existing topology `spawn` and `logs` for observers. They never write the Gateway database or Swamp datastore.

Bindings are `${topology.<node>.node_id|name|wireguard_ip}`, `${capture.<name>}`, `${proof.run}`, `${proof.repo.url}`, `${proof.start_tag}` (fresh UUID v4), `${proof.now+N}` (UTC ISO string), and `${proof.epoch+N}` (integer Unix seconds). A whole-value binding preserves its JSON type. Secret values never appear in argv; the Gateway supplies them through its normal admission path.

Scheduler and Artisan-only kinds use `request: {"artisan":["schedule:test","--name=EVENT"],"select":{"kind":"KIND","target_type":"TYPE","target_id":"${capture.id}","count":1}}`. The command runs from the topology Gateway runtime. Selection uses the real operator `operation:list --kind` result, requires one new matching target after request start, and never repeats the command. Optional target filters are applied to returned records; the runner adds no public CLI.

A kind with variants uses `<kind>/<variant>.json`. `run <kind> --variant <variant>` selects one; without that flag the runner uses all variants in file-name order. Each is a complete fixture and declares its own required topology. Single-file kinds use `<kind>.json`.

`envelope` is a recursive subset of the actual public `Operation.input`, not workflow inputs. Every supplied key must match; omitted keys are unchecked. Arrays compare whole: the same length and order, with recursive subset matching and typed matchers in each element. Generated values support whole-value `${match.uuid}`, `${match.positive_int}`, `${match.sha256}`, `${match.nonempty_string}`, and `${match.present}`. There are no post-admission captures. A receipt stores the full actual input and every mismatch's JSON path.

## Local repository payloads

A kind carries public test code under `<kind>/repository/`. A variant may provide `<kind>/<variant>/repository/`; otherwise it uses the kind's shared payload. Before prepare, the runner turns that payload into one deterministic `main` commit and a bare repository on the topology Gateway. It binds `${proof.repo.url}` to `https://repositories.orbit/<run>/<kind>[-<variant>].git`. Every run has a new repository identity. The seed Project uses its own payload at `seed/repository/` and URL `https://repositories.orbit/seed.git`.

Lane 11's topology setup owns the fixture-only stock Caddy unit `swamp-proof-repositories.service` (`git http-backend` through `fcgiwrap`), private DNS and topology-CA trust. It binds the Gateway's observed Incus address, preserves product Caddy configuration and refuses a port conflict. Receipts record `fixture_repositories: separate-caddy`. The helper enables read-only Git access and disables receive-pack. It installs no service, SSH account or key. Smart HTTP supports production's shallow HTTPS clone path. No production source protocol or authentication policy changes.

The seeding call is `sudo -n python3 -I /home/orbit/orbit/tools/swamp_proof_repository.py seed <issue> repositories.orbit`, before `tools/swamp-proof-seed.php`. Kind calls replace `seed` with the kind and use the run id, with an optional final variant argument. The helper prints `{url, commit, branch}`. Bare repositories live under `/srv/swamp-proof/repositories`. The seed validator accepts only its local HTTPS URL. Its normal `github_app` source-access value requests no credential for this non-GitHub origin.

## Outputs and parity

Order: prepare, before-request, request, follow the Operation, inspect its exact native history, then parity. Native history search selects the unique declared workflow with the admitted `operation_id`. The runner checks the full run identity again before evaluating timestamps and outputs.

Before-request and parity both run on the outer Task VM from the staged worktree root and receives exactly these proof variables:

- `PROOF_EXEC`: the shell-quoted `bin/e2e-topology exec <issue>` prefix.
- `PROOF_OPERATION_ID`: the admitted Operation ID.
- `PROOF_OUTPUTS`: a JSON file containing the public outputs object of the step selected by `expect.outputs.job` and `.step`, without job or step wrappers. A `stdout` JSON string remains a string.
- `PROOF_RUN`: this proof's run ID.
- `PROOF_KIND_DIR`: the fixture's directory in the staged worktree.

Before-request receives the same available context, without `PROOF_OPERATION_ID` or `PROOF_OUTPUTS` before admission. There is no `PROOF_TARGET_SSH`. Use `PROOF_EXEC` with `operator --argv='["orbit","operation:show","ID","--json"]'` to read public Operation results, or the named workload Node to inspect it. Legacy `target_setup` and direct vault seeding are retired.

`expect` names the terminal native status and `{job, step, match}` for public outputs. Each `phases` entry has `{name, job, step, budget_seconds}`. `parity` declares a command argv and legacy case names. A proof passes only when Gateway and native terminal statuses agree, input and output expectations match, parity exits zero, and both total and phase budgets pass. Missing history or timings fail the proof. All native per-step timings and errors are printed.

## Installation and custody

The topology Gateway migrates a fresh database from mounted PR source, invokes `tools/swamp-proof-seed.php` with public observed topology facts, creates an independent runtime checkout and starts sandbox Swamp before `gateway:release:adopt`. Adoption needs Swamp for its native Caddy handoff. It then runs `gateway:release:configure` with topology-local values and starts Gateway services. The runtime lives at `/home/orbit/runtime/current/apps/gateway`. The mounted worktree remains at `/home/orbit/orbit`. `orbit:bootstrap` is not used. Receipts record `enrollment: fixture-set` and `topology_ca: fixture-set`. Seeded Node and role statuses are fixture intent; the existing verifier proves services, WireGuard, CA, access, pinned SSH and worker readiness.

The dedicated club token is held by the production Gateway. Only Project 62 / slug `swamp-proof` provision receives its execution copy. Native provision writes `/home/orbit/.config/swamp/club-api-key` mode 0600 for the proof user. Its copy step has a 10-second dispatch budget and a 30-second action budget. The harness passes it on guest stdin through `ORBIT_E2E_SWAMP_CLUB_TOKEN_FILE`. Sandbox CLI calls use `SWAMP_API_KEY_FILE` with the nested Gateway's private file. The wrapper never fetches or holds the credential locally. It dies with the Task VM.

Receipts are private JSON under `~/swamp-execution/evidence/proof/<kind>/<UTC timestamp>.json`. Quote their paths in kind PRs. They record `gateway_install: adopt` after the harness confirms that installation. They include the Operation ID and public input/result, native run ID, served commit, runtime pin, topology and seed, per-step and phase timings, budgets, parity, and the owned VM identity. `down` updates their destruction confirmation. An infrastructure refusal or missing readiness prerequisite is not a successful proof.
