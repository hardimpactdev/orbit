# bin/swamp-proof fixture format, v0

Owner: sub-orchestrator B. Builder: lane 25. Frozen 2026-11-01 11:40Z. Changes go through B.
Lane 25 commits this as `swamp/proof/README.md` in the `bin/swamp-proof` PR. Until then, this file is canonical.

## Where

One file per kind: `swamp/proof/<kind>.json`, for example `swamp/proof/task_vm.sweep_host.json`.
Optional helper files sit next to it in `swamp/proof/<kind>/`.

## How a proof runs

1. One disposable Task VM on Shark through the normal Task group / provision / cancel API. Its host OS runs control; one Incus system container is the default target. Task VM east-west traffic stays denied.
2. Control runs the pinned official Swamp and extensions (`node-worker/config.ts` + `.swamp.yaml`), with a throwaway repo, datastore, vault, serve and one worker. It loads the PR branch's `swamp/`.
3. Control generates an SSH key. Target trusts it. Control pins target's host key. Both stay VM-local.
4. The runner fills the fixture's bindings, puts the fixture's throwaway secrets in control's vault, runs `target_setup`, then starts the workflow on control with `envelope`.
5. It checks `expect`, runs `parity`, reads native step history for `phases`, writes the receipt and cancels the Task group in a trap, also on failure, and verifies provider destruction of the VM and its nested target.

## Shape

```json
{
  "kind": "task_vm.sweep_host",
  "workflow": "operation-task-vm-sweep",
  "budget_seconds": 300,
  "queue_timeout_seconds": 10,
  "envelope": {
    "operation_id": "${proof.operation_id}",
    "node_id": "${proof.target.node_id}",
    "kind": "task_vm.sweep_host",
    "intent": {
      "start_tag": "${proof.start_tag}",
      "deadline_at": "${proof.now+300}",
      "ssh_execution": {
        "control_node_id": "${proof.control.node_id}",
        "identity_file": "${proof.control.identity_file}",
        "public_key": "${proof.control.public_key}",
        "target": {
          "name": "${proof.target.name}",
          "host": "${proof.target.host}",
          "user": "${proof.target.user}",
          "port": "${proof.target.port}",
          "host_keys": "${proof.target.host_keys}"
        }
      }
    },
    "execution_secrets": {}
  },
  "secrets": [
    {"vault": "orbit-control-inputs", "key": "example-key", "value": "generate:hex32"}
  ],
  "target_setup": "task_vm.sweep_host/target-setup.sh",
  "expect": {"status": "succeeded", "outputs": {"job": "execute", "step": "action", "match": {}}},
  "phases": [
    {"name": "sweep", "job": "execute", "step": "action", "budget_seconds": 225}
  ],
  "parity": {
    "command": ["python3", "-I", "swamp/extensions/behaviors/operations/task-vms/sweep_parity_test.py"],
    "cases": ["orphan-vm-removed", "protected-build-kept"]
  }
}
```

## Authoritative mechanics (v0.3, 11:40Z; replaces "How a proof runs" where they differ)

- **One VM per proof.** Task VMs cannot reach each other (Incus ACL drops RFC1918, and the hub filters the task VM range); opening that is Nick's call. The VM host OS runs control: pinned official Swamp and extensions (`node-worker/config.ts` + `.swamp.yaml`), a throwaway repo, datastore, vault and serve, plus one worker.
- **Targets are Incus system containers inside the VM,** on a VM-internal bridge. Each has its own sshd, a non-root user with passwordless sudo, and a VM-local key and pinned host key. v0 has one target, `default` (`proof.target` = `proof.targets.default`).
- **Worker selector:** the control worker enrolls as `orbit-node-209` against its loopback serve. Workflows run unmodified. Nothing touches the real 209.
- **Bootstrap:** `incus exec` over the existing operator SSH to shark, only on the Gateway-owned proof VM names. No new keys, pins, edges or host changes.
- **Placement:** a dedicated proof Project excludes beast (Node 9); placement honors exclusions since #2303.
- **target_setup:** SSH as the target user, then `sudo -n`; `SUDO_USER` is the target user. Before it runs, the runner copies `swamp/proof/<kind>/` to `/tmp/swamp-proof/<kind>/` on the target (`PROOF_KIND_DIR`, same layout).
- **Shared throwaway secrets:** a `secrets` entry with `"stage_to_target": true` is also written to `$PROOF_KIND_DIR/secrets/<key>` (0600, target user). No fixed tokens, not even test-only ones.
- **Time bindings:** `${proof.now+N}` renders an ISO-8601 UTC string. `${proof.epoch+N}` renders integer Unix seconds.

## Topologies (PLAN F12, Nick, standing)

- Versioned library in `swamp/proof/topologies/<name>/`, using the task topology names: `app-dev`, `app-prod`, `app-prod-2`, `+ingress`, plus `router` and `database`.
- Each holds a declarative `topology.json` (`name`, `version`, named targets and their role setup, and its prebuilt task VM image). Lane 5 builds the images via `task_vm.image_build` (C's domain). Until an image exists, the topology's setup builds the containers at proof time, and the receipt records that.
- `bin/swamp-proof --topology <name> <kind> [<kind>...]` proves several kinds in one VM. A fixture may set `"topology"` as its default.
- A proof that fails because of the topology fixes the topology in the same PR and bumps its version. Every receipt records `topology` and `topology_version`.
- The first topology is `app-dev` v1, for instance.create.

## v1 (about 16:30Z)

- Named targets: `${proof.targets.<name>.node_id|name|host|user|port|host_keys}`, and per-target `target_setup`.
- Disposable signer on control: a throwaway RSA-4096 CA and an endpoint with the Gateway sign contract (CSR + operation id → signed public bundle, RSA-2048 leaf, 397 days), taken from the Gateway sign primitive's tests. It accepts only the run's own operation id. Bound as `${proof.signer.url}` (and `${proof.signer.ca_bundle}`). Kinds that hard-code the Gateway sign address must take it as input first; the owning lane makes that change.

## Fields

| Field | Rule |
| --- | --- |
| `kind`, `workflow` | Gateway kind and native workflow name. |
| `budget_seconds` | F8 run budget. The receipt fails the proof when the run exceeds it. |
| `queue_timeout_seconds` | Must equal the workflow’s `queueTimeout`. The accepted firewall harness pilot uses null when the workflow omits it and records the inherited serve default of 600 seconds. |
| `envelope` | The exact workflow inputs the Gateway would send: `{operation_id, node_id, kind, intent, execution_secrets}`. Take it from a real Gateway-generated admission (the family's admission test or a captured Operation input) and replace only environment-specific values with bindings. |
| bindings | `${proof.operation_id}`, `${proof.start_tag}` (fresh UUID v4 per native run), `${proof.now+N}` (ISO time N seconds ahead), `${proof.epoch+N}` (integer Unix seconds N seconds ahead), `${proof.control.node_id\|identity_file\|public_key}`, `${proof.target.node_id\|name\|host\|user\|port\|host_keys}`. A binding that fills a whole JSON value keeps its type (`host_keys` is an array, `port` an integer). No other substitution. |
| `secrets` | Throwaway values for every vault key the run reads. `value` is `generate:hex32`, `generate:ed25519`, or `file:<path in the kind folder>`. Never a real credential. |
| `target_setup` | Optional script, run as root on target over the same SSH binding before the kind. It builds the state the kind acts on (for the sweep: an isolated Incus project and pool, one orphan instance, one topology volume). It must not reach any production host. |
| `expect` | Terminal native status and `outputs: {job, step, match}` selecting the public terminal step. `match` is a partial match object; parity reads that exact step’s public outputs. |
| `phases` | Legacy F9 phases mapped to native steps, each with its own budget. The receipt reports seconds per phase from native history timestamps (the pinned native view also exposes duration in milliseconds). |
| `parity` | The ported legacy parity tests. They run on control after the kind, with `PROOF_RUN_ID`, `PROOF_OUTPUTS` (path to the selected terminal step’s public outputs object, without a job or step wrapper; selected by `expect.outputs.job` and `expect.outputs.step`) and `PROOF_TARGET_SSH` (an ssh command prefix for target) in the environment. Exit 0 means every case passed. |

## Receipt

`~/swamp-execution/evidence/proof/<kind>/<UTC timestamp>.json`, quoted in the PR body:
`kind, workflow, swamp_commit, swamp_version, native_run_id, status, budget_seconds, total_seconds, phases[{name, seconds, budget_seconds, pass}], parity{passed, failed, cases}, control_vm, target_vm, destroyed: true`.

## Known gaps (lane 25 reports by 11:45Z)

- Placement requires the Gateway release containing #2303. Project exclusions select Shark without a host selector or a capacity change.
- Nested virtualization on target, for kinds that need Incus inside the VM (`task_vm.*`): unproven. Containers may be the only option.

## Run and cleanup

Use Python 3.12+ with PyYAML, the caller’s existing `~/.orbit/config.json`, and the existing strict operator SSH trust for Shark. Commit the branch before running it: the tool archives committed `swamp/` and the guest driver. It installs the exact runtime hash in `node-worker/config.ts`, verifies the `.swamp.yaml` version, and pulls each official extension at the version and checksums in `upstream_extensions.json`. It imports no production Swamp account, datastore, vault or token. An official authentication refusal blocks the proof.

```sh
bin/swamp-proof --project 62 --topology app-dev firewall.inspect
bin/swamp-proof --project 62 --topology app-dev kind.one kind.two
```

The canonical Project slug is `swamp-proof` (current id 62). Each normal external group is titled `proof <kind…> <topology>@<version>`. The trap calls `tasks:cancel` after success, failure or interruption. F11 cancels proof groups older than 45 minutes using only the normal Task group `created_at`; this is the sole fleet cleanup ceiling. The runner stores no per-VM deadline, heartbeat or budget metadata. Local lifecycle observation deadlines do not change that ceiling.

Each kind gets a receipt path on stdout. Exit 0 requires its expected terminal output, every named parity case, phase and total budgets, and confirmed provider destruction. Exit 1 means proof or cleanup failed; exit 2 means preflight refused before admission. `proof_pass` is the overall verdict; native `status` alone does not prove parity or cleanup. Receipts also record `topology`, `topology_version` and `image_missing`. Shared batches run sequentially in one VM and destroy it after all kinds.

## Initial pilot limits

App-dev v1 has no prebuilt image yet. The accepted firewall harness pilot constructs a plain Ubuntu system container with sshd, sudo and UFW. It does not claim full app-dev or instance.create parity. The canonical Task topology `node-template` is a stopped VM and is not used as a container image. Lane 6037 supplies the container recipe from the canonical app-dev recipe source; no second role installer is maintained here. Other kinds refuse until the topology supplies their prerequisites.

The firewall fixture follows `FirewallInspectionInput::capture` and its admission test. Six successful pinned native fixture runs measured 139, 139, 145, 147, 155 and 169 ms (p95 169 ms). Its initial pilot budget rounds to 1 second; these fixture runs replace only the SSH executable boundary and are not a real target proof. The isolated pilot reports cold worker overhead and any budget failure without claiming kind readiness.

## Topology image seam

`topology.json` declares `image: {alias: "orbit-proof-<topology>-v<version>", type: "container", fingerprint: "<sha256>" | null}`. The image lives in Shark’s `orbit-tasks` Incus image store. Bootstrap exports the exact fingerprint over the existing operator SSH, pushes the export into the owned proof VM with `incus file push`, imports it into that VM’s Incus, and verifies the fingerprint and container type before launch. Temporary host export files are removed. With a null fingerprint, the accepted pilot builds its plain Ubuntu container at proof time; the receipt records `image_missing: true` and a null `image_fingerprint`. Lane 6037 builds the default container from the canonical app-dev recipe; the stopped Task topology VM template is not used.
