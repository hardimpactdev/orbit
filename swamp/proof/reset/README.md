# Task-local nested fleet reset

Run this repository program through `tasks.script`, or use the same program as
an existing Project check hook on the allocated task VM:

```sh
bin/proof-fleet reset 3187
bin/proof-fleet verify 3187 swamp/proof/reset/cases/smoke.sh
bin/proof-fleet release 3187
```

Do not call `bin/swamp-proof up` from a task VM. This entrypoint never provisions
an outer VM, restages the candidate checkout, starts a production worker, or
changes the outer observer. Provision a missing task runtime through the existing
`tasks.provision` / `task-vm-provision` path instead. Prepare the attached seed
once with `sudo bin/e2e-topology-host-setup`.

## Network and operator

The nested fleet has a real WireGuard network, `10.45.0.0/24`. The nested Gateway is the hub (`10.45.0.1`), `app-dev` is `10.45.0.2` and `app-prod` is `10.45.0.3`. The outer VM joins as the roleless member `operator` (`10.45.0.10`) on its own interface, `wg-nested`. That interface never touches the real fleet's `orbit` interface or `10.44.0.0/24`. The outer VM runs the candidate CLI with one profile, `nested`, that names the Gateway by address. The Gateway recognises the outer VM as a WireGuard member, so a command arrives as it does from an operator machine. Keys live on the outer VM, so every reset agrees with them.

## Boot behaviour

A reset boots three VMs. [`tools/swamp-proof-guest/boot.py`](../../../tools/swamp-proof-guest/boot.py) writes the boot fixes during the build, so a fresh generation boots as a ready copy does:

- `orbit-agent` starts after `wg-quick@orbit`, and an `ExecStartPre` waits until `/api/v1/agent/realtime` returns a URL;
- `cloud-init.disabled`;
- an `orbit-hosts.path` unit restores the Orbit names in `/etc/hosts` after the Incus agent rewrites it;
- Swamp serve and the worker are enabled and ordered at boot;
- the Gateway services that use the checkout wait for the mount and use `WorkingDirectory=-`;
- the Gateway VM has 2 vCPUs;
- PHP uses `opcache.validate_timestamps=1`, and no Laravel config, route or event cache exists.

`tests/test_boot.py` pins each file. Measured on ready copies built with these fixes, the whole fleet is Doctor-clean about 50 s after `incus start`; before them it took 95-99 s.

## Source and the live checkout

The guests mount the generation's private source copy, not the outer VM's live checkout. The Gateway bootstrap writes into the source it runs from: it replaces `apps/gateway/.env`, runs `key:generate` and `package:discover`, and writes `storage` and `bootstrap/cache`. Against the live checkout that would overwrite the task's own `.env`, change its caches and break the preservation assertions below. A live edit would also change code under a pinned candidate identity. The copy uses reflinks and the candidate's installed dependencies, so it is cheap.

## Ownership and results

The entrypoint runs its whole transaction inside the stable `proof-owner`
`command/shell` behavior in the owned group controller. Swamp's native per-behavior
method lock covers workflow definition installation, validation, the entire native
run, failure cleanup, cases and receipt collection. Reset, verify and release use
the same behavior. There is no Python lock or second workflow mutex. Contenders
use Swamp's default lock wait and report native lock timeout without touching the
active lease; they do not bypass the owner. The owner command has a one-hour
budget covering the existing reset, case and cleanup budgets. Each independent verification run calls `proof-fleet-reset` once. Cases
share that generation and must clean their own fixtures. A clean-state case or
failed case cleanup requires a new independent run, not an implicit reset.

`proof-fleet-reset` owns the sequencing: inventory, scoped release, candidate
snapshot, nested resource preparation, fixture configuration, readiness, and
result publication. Its failure path collects diagnostics and releases only
the generation it started. The shell/PHP programs perform individual bounded
phases. A timed-out diagnostic (including inventory collection) is recorded and cannot skip the owned release. Null Incus configuration on unmanaged interfaces carries no ownership. `proof-command-verification` composes that workflow with the selected
repository case program and records its actual exit status. Initialization
failure skips cases and is never a passing command proof.

The persistent orchestration repository and run history live under
`~/.local/state/orbit-proof-GROUP/controller`. The disposable source and control
plane live under `generations/GENERATION`; diagnostics and receipts live under
`CHECKOUT/.orbit-artifacts/GROUP/reset/GENERATION`. The latter two paths are
outside every guest and every release scope. The entrypoint uses only the
proof-only `~/.config/swamp/club-api-key` via `SWAMP_API_KEY_FILE` (private file,
not its value in argv). Online authentication with that file is sufficient;
a separate sign-in token is not a prerequisite. Do not discover or copy other
credentials.

The inventory records HEAD, HEAD tree, the effective dirty tree using a temporary
Git index, real-index digest, outer boot identity, observer identity and Pi service PID/start identity. Source
is exported into a separate Git checkout with private copies of installed
Composer dependencies. Guest convergence may edit that copy, never the candidate.
The snapshot includes nonignored candidate edits, not `.env`, runtime credentials,
or ignored artifacts. No commit, real-index staging, fetch or publication occurs.

The harness prepares VM copies from the existing seed with its normal attempt
lease, ownership metadata, host creation lock and capacity checks. This is not
normal `acquire`: the retired Gateway bootstrap action is unavailable. Preparation
explicitly does **not** claim a ready product topology. The combined nested Gateway/Swamp runtime needs 2560 MiB, not the ordinary Gateway catalog budget; preparation verifies its complete attempt ownership before stopping, resizing and booting that guest through the existing harness agent-readiness gate. Do not hotplug memory on its live virtiofs source mount. Other guests and the outer VM retain their limits. Use an allocated task VM with at least 8 GiB. Separate native steps use the shared `tools/swamp-proof-guest/fixture.py` bootstrap with explicit source, state and topology paths inside the nested Gateway. Swamp starts before Gateway seeding. The fixture installs its own RSA4096 CA, worker identity at the admitted role-worker path, matching agent secrets and observers, and publishes known-hosts. The same shared helper starts pinned Reverb, the agent-view subscriber and the real operations queue worker against the isolated SQLite database. The fixture uses the product firewall catalog to install the managed baseline, adds an unmanaged proof-network transport rule, and enables UFW before readiness. It never changes the outer host's services. Readiness has separate native steps: authenticated operator HTTP and a queued SSH probe using the admitted identity on each managed Node; one fresh host/ufw/units observation; then scoped Doctor and firewall baseline checks. The fact check requires generation-fresh real records and enabled UFW on every managed Node. It never polls or retries Gateway state: missing, stale or disabled facts fail closed, retain `facts-not-ready.json`, skip cases, and enter native owned cleanup. Native queueTimeout bounds worker admission. These are observations, not service-name-only checks. Test fixture seeding is setup, not command proof. Command
cases must invoke the actual candidate CLI and independently inspect effects. After cases, the workflow compares real UFW output and firewall database records with the pre-case baseline. Any residue fails verification; it never silently repairs a failing case.

Before deleting anything, scoped release validates the recorded network and all
guest identities. It does not sweep by name prefix. Unrelated fixtures, template,
outer system services, checkout and evidence are outside its scope. A refusal preserves the lease for diagnosis. If release refuses ownership, inspect the generation's `owner.json`, source `.e2e/attempt.json`, and failure inventory; correct the ownership discrepancy with the task orchestrator before retrying. Never remove the lease or sweep by prefix to bypass refusal. A failed preparation uses the harness's owned
rollback; later fixture/readiness failure releases the prepared generation.

## Native prerequisites and checks

The pinned Swamp `command/shell` builtin supplies bounded repository phase
execution and structured exit results. Native workflow dependencies supply
ordering/failure handling; a child workflow supplies reset reuse. The pinned
[behavior reference](https://swamp-club.com/manual/reference/behavior-definitions#per-behavior-method-locking)
and `swamp help behavior run` document the native owner lock and its default wait.
The workflow schema has no automatic step retry or observer-state wait primitive;
therefore the observation is one-shot and fails closed, rather than hiding an
Orbit-state polling loop in a helper. Discovery found
no official Incus extension; the community `@whitemars/incus` offers generic VM
lifecycle but not Orbit's attempt leases, seed topology, capacity locks or ownership
preflight. Replacing the existing harness would duplicate those rules. The
repository phase bridge therefore calls the existing harness, not a new Incus
lifecycle engine. No custom Swamp extension, transport or dispatcher is added.

Run the focused harness Pest tests and `python3 -m unittest discover -s
swamp/proof/reset/tests`. Native validation and real task-VM receipts are required
in addition to those regression tests. FakeIncus generations are not live resets.
See `reset.mmd` for the native composition and the generated workflow definitions
beside this page for the authoritative phase ordering.
