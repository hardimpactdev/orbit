# pytest + Testinfra command proof spike

Owner: Nick. Research only. This does not change the product proof runner.

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
