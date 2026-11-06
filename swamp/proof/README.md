# Swamp proof

The flow is stages 2 and 3 of the [command development pipeline](../../docs/reference/command-development-pipeline.mdx); the stage charts own the steps. This page documents the code that runs them today.

## What runs today

Not built: the reset. The code that built the nested fleet in a task VM, took the memory snapshot `base` and restored it was removed. Its replacement is rebuilt as Swamp steps, and until it lands a `proof:run` has no reset behind it. The case runner and the cases below stay. To prove a command, follow [verifying-commands](../../.agents/skills/verifying-commands/SKILL.md).

The target nested fleet inside a task VM is a Gateway that also runs Swamp, `app-dev` and `app-prod`, on a real WireGuard network `10.45.0.0/24` with the hub on the nested Gateway. [`selection.txt`](selection.txt) maps changed paths to the commands whose cases a commit needs; [`orbit proof:run`](../../docs/cli/proof/run.mdx) reads it.

Cases are written in `swamp/proof/<family>/<command>.cases.yaml` and run by the shared runner in [`runner/`](runner/runner.py) ([format](../../docs/reference/verification-cases.mdx)). A case calls the real candidate CLI, checks the real effect and prints a verdict. The first file is [`firewall/allow.cases.yaml`](firewall/allow.cases.yaml): a real `firewall:allow` opens real traffic. `orbit proof:run` selects a command with [`selection.txt`](selection.txt) and runs its cases file; a command without one is uncovered.

## Ready copies: the contract

One step makes ready copies, in stage 6. Anything else that stores or restores a nested fleet (the topology seed, B3) uses these names and keys.

- **Names:** `fleet-ready-gateway`, `fleet-ready-app-dev`, `fleet-ready-app-prod`. Stopped Incus VM copies in the same project and pool as the fleet.
- **Keys** on each copy: `user.ready.format` (the format of the copies, `4` today; a provision starts a fleet only from copies of the current format), `user.ready.set` (one identity for the three: the UTC time of the save), `user.ready.role`, `user.ready.made` (UTC, the copy), `user.ready.hwaddr`, `user.ready.ipv4` and `user.ready.subnet` (the network's `ipv4.address`; a task VM creates the network from it). `user.ready.complete` equals `user.ready.set` and is written last, on all three, by the workflow `operation-task-vm-ready-copies`; a set without it is half made and not used.
- **Not on a copy:** no `eth0` device and no snapshots. A task VM adds the NIC with the stored MAC and address when it starts the copies. A copy holds no checkout: no file under `/home/orbit/orbit` but the Laravel log a booted guest writes there (the directories it makes are no checkout either), and each task VM mounts its own over NFS. The state (`.env`, `storage`, `bootstrap/cache`) is on the guest's own disk under `/var/lib/orbit-checkout-state`, and the database stays in the Gateway's own state directory.
- **Stateful, no snapshots.** The guests have `migration.stateful=true` and a root `size.state` equal to `limits.memory`, set while they are stopped (`ready` on the originals, a restore on each restored guest). A copy and a restore use `incus copy --instance-only`, so a copy never carries the `base` snapshot of an earlier fleet.
- **No secret and no operator key.** The proof Swamp Club key is never in a copy: the workflow deletes it from the running Gateway, scans every file of all three guests for it, trims the disks, and scans each stored copy again after it boots. The Gateway copy has no WireGuard key of the operator, in its Node record or on its hub; the task VM that starts the copies makes its own.
