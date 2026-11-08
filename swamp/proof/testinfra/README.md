# pytest + Testinfra command proof spike

Owner: Nick. Research only. On this unmerged branch, a clearly marked override in `runner.py batch` routes only the canonical firewall:allow cases file to `ci_entry.py`. Other commands, plan and setup use the existing runner.

Each of the 50 expanded `firewall:allow` cases is a native pytest test. `--negative` runs the 13 declared effect-breaking checks. The test module imports no runner code and does not interpret the YAML cases. The offline parity tests compare every ordered setup, action, expectation, baseline, stage, placement and negative with the original spec. They are a port check, not a live command proof.

Testinfra 10.2.2 is Apache-2.0. pytest and xdist are MIT. Requirements are pinned. Testinfra supplies command results, file inspection, socket enumeration and raw iptables rules. There is no Incus backend or UFW module: `backend.py` adapts Incus, and `checks.py` retains the domain checks from the existing runner. CLI prompt driving and UFW fault injection use the existing shared guest tools under `tools/swamp-proof-guest`; both sides need those tools and their lines are excluded from the replacement count.

## Run

Run only inside a disposable task VM allocated through `task:start` or `proof:run`, with the product-provisioned nested fleet and its `base` snapshots. Use the same candidate checkout that provision applied before taking `base`. This spike creates no VM. It refuses to start unless the active CLI profile names the nested Gateway at `10.45.0.1`; mutating host commands name only its nested containers. Do not run it against the live fleet or Node 209.

```sh
python3 -m venv swamp/proof/testinfra/.venv
swamp/proof/testinfra/.venv/bin/pip install -r swamp/proof/testinfra/requirements.txt
swamp/proof/testinfra/.venv/bin/python -m pytest swamp/proof/testinfra/test_firewall_allow.py \
  -n 4 --dist=load --max-worker-restart=0 --durations=0 \
  --junitxml=swamp/proof/testinfra/results/junit.xml
# On a separate restore of the same base, require the declared assertion to detect a broken effect:
swamp/proof/testinfra/.venv/bin/python -m pytest swamp/proof/testinfra/test_firewall_allow.py \
  -n 4 --dist=load --max-worker-restart=0 --negative \
  --junitxml=swamp/proof/testinfra/results/negative.xml
```

pytest prints one final summary. Test IDs contain `firewall:allow` and the original case ID; JUnit XML carries the same names. An infrastructure failure is an error, never a skip or pass. Assertions within a case accumulate as before. Each case has its original timeout. `--max-worker-restart=0` prevents xdist from silently retrying after a worker crash. Evidence and exact case times stay under the printed session directory.

For offline port checks on any machine:

```sh
swamp/proof/testinfra/.venv/bin/python -m pytest swamp/proof/testinfra/test_port.py -n 4
```

## Isolation and limits

xdist provides processes and scheduling, not resource isolation. The fixtures retain main's case placement and locks: a case owns its Node and reads the fleet lock; a fleet-wide case owns that lock exclusively. This baseline predates the readers–writers change proposed in PR #3236. Read-only audits remain global, so running those cases beside writers would weaken failure detection.

The fixtures restore `base` before each batch and after the last case, wait for Doctor, and prepare IPv6 for the dual-stack batch. The restored checkout must already have been applied by product provision; the spike changes no production code. The final restore runs in fixture teardown, so its failure appears in pytest and JUnit.

The batch barrier is deliberately limited to this command at four workers: only three tests belong to the later batch, so they cannot occupy every worker while earlier tests remain. Serial execution also works. Other worker counts and four or more later-batch cases are refused. A general replacement needs a batch-aware xdist scheduler; that code is not hidden in the line count. This is a material limit, not production-ready integration.

Existing `uncovered` entries stay uncovered: in particular IPv6 and non-traffic mutations, stage-selective negative proofs, any-source traffic causality and several refusal/fault fixtures. The port does not claim to prove them.

## Matched measurements

```sh
python3 swamp/proof/testinfra/benchmark.py --output swamp/proof/testinfra/results/ab
python3 swamp/proof/testinfra/benchmark.py --output swamp/proof/testinfra/results/ab-negative --negative
```

The script runs ABBA twice: two cold and two warm runs for each engine on the same task VM, commit and `base`. Cold creates a new venv and installs without the package cache; warm reuses it. Dependency-install time is separate from case wall time. Every run restores the same base; cold does not mean controlled host or guest page caches. Baseline runs use the unchanged runner, its placement, limit 4 and per-case receipts. Pytest uses its own fixtures. The summary refuses to count missing or duplicate receipts as a complete run. Logs, JUnit and per-case timings are retained. `benchmark.py` is measurement tooling, not replacement framework.

## CI without an outer Swamp worker

A CI job can execute the same pytest command directly in an already provisioned task VM. It needs Python, the pinned packages, PHP and checkout dependencies, the candidate Orbit CLI, Incus access to the three nested Nodes, and the nested operator's WireGuard identity, Gateway access grants and CA profile. Incus access effectively gives root in that disposable fleet. Fault injection needs that access. The fixtures do not need a standing key to the live fleet.

CI must still acquire, provision and release a task VM through Orbit, or independently supply an equivalent isolated nested fleet and snapshot lifecycle. Current product provision and command execution use Swamp: direct pytest removes the worker around test invocation, not Swamp from the product under test. The nested Gateway's Swamp and its bootstrap credentials remain necessary for real firewall Operations. JUnit and the session evidence must be uploaded before releasing the VM. A GitHub-hosted job would also need network reachability to the private task VM; running the job inside that VM avoids exposing nested management endpoints.

## Spike-only CI entry (#3250)

Nick authorized the smallest CI-path integration on this branch only. `proof:run COMMIT --all` from Beast (Node 9, ci-runner) uses existing CI admission and credentials. The product provisions and restores the commit-specific nested fleet and runs batch setup; `ci_entry.py` adds no lifecycle operations. It creates a venv in the proof evidence directory, installs the pinned requirements, and runs native pytest cases instead of the YAML engine. pytest 8.4.2 is pinned because xdist 3.8.0 loadgroup writes `_nodeid`, which pytest 9.2.0 no longer reads. A real subprocess test verifies grouping.

Within each batch, stock xdist `loadgroup` gives each target Node one queue, with at most four workers. Node cases complete before a second pytest invocation runs the fleet-exclusive group. This reproduces main's exclusive Node/fleet placement without worker starvation on file locks. It retains the same case order on each Node and the same exclusive-case order. Starting pytest twice is a measured framework cost; the fixtures keep their locks as a second isolation layer. CI owns restore/setup, so the direct-run barrier and final restore are disabled in CI mode. The broader direct benchmark limitations above still apply to `benchmark.py`, which is not used in this CI comparison.

The 4 KB step output contains every original case ID, verdict and JUnit duration, native pytest summaries, pinned dependency install seconds, case-phase wall seconds and return codes. Failure detail is bounded by the remaining output space. Missing, skipped, duplicate and setup/teardown error results fail closed. Local logs/JUnit/receipts also exist in the allocated VM until normal destruction; the durable artifact for this experiment is step output.

The current protocol alternates the spike commit and its merge base with main twice each. Each proof creates its own disposable command VM with the same product lifecycle and CI capacity. “Cold” means the first proof of each engine; later proofs are ordered warm samples, not reuse of the venv or a controlled OS-cache state. Every new pytest VM pays installation cost; its second batch reuses the venv. This protocol supersedes the original same-VM ABBA proposal at Nick's request.

CI diagnostics (#3250, spike only): `spike-install` is a separate native SSH
step, with pinned dependencies and a `spike-install-BATCH` timing verdict. These
two rows are overhead, not additional firewall cases. The case entry prints only
JSON verdict lines. Cause and bounded failure text travel inside those objects;
non-verdict pytest output is not exposed by the operation API. The final native
Report has a 4096-byte projection cap. Reserve room for all 50 case identities,
verdicts, durations, and both install timings before adding distinct diagnostics.
Many simultaneous failures cannot each carry full diagnostics through that cap;
the spike preserves complete local JUnit but does not claim a CI stdout reader.
No Swamp data on Node 209 is read by hand.
