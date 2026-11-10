# Private DNS phase proof

ADR 0248 and `docs/cli/dns/publish.mmd` own the behavior. These delegated
Molecule scenarios exercise the real Ansible phase on a disposable Ubuntu
machine. They do not create or destroy that machine.

| Scenario | Coverage |
| --- | --- |
| `unit` | Render grammar, records, upstream selection, probes, recovery scripts, delegate capability policy and rejection of uncommitted or failed member receipts |
| `default` | Real VPN listener cutover, unchanged publication, reload/restart, invalid render, failed checks, controller interruption and recovery |
| `cluster` | Dedicated Router resolver, member delegate, resolved restart and WireGuard-hook persistence, TCP/AAAA, foreign namespace, public DNS independence, no automatic fallback, zero-change repair, delegate replacement, failed member commit, withdrawal and owned retirement |
| `precutover` | Router/member preparation with the VPN listener and files untouched; later VPN cutover preserves LAN selection; an offline member blocks retirement while successful VPN evidence remains available |

Run from this directory inside the disposable machine:

```sh
PATH=/tmp/m/bin:$PATH molecule test -s unit
PATH=/tmp/m/bin:$PATH molecule test -s default
PATH=/tmp/m/bin:$PATH molecule test -s cluster
PATH=/tmp/m/bin:$PATH molecule test -s precutover
```

The real-host scenarios mutate resolver policy and services. Never run them on
an enrolled production Node. The public DNS fixture owns `192.0.2.53` inside
the disposable machine; it removes dependence on external DNS availability.

## Recorded result

On 2026-11-08, `default`, `cluster` and `precutover` passed on real Ubuntu in
scratch Incus container `dns-rehearsal-1`, with systemd 259.9. UDP/TCP and
resolved selection use distinct addresses and logical target identities on
one machine. The fixture uses local Ansible transport and a dummy `orbit`
interface. It proves no multi-host network isolation, real WireGuard tunnel,
reboot, Gateway admission-to-served-Swamp dispatch, or production load.
The persistence case restarts resolved and replays the actual stored
WireGuard DNS hook without rerunning member repair.

The review regressions use private copies of the production phase assets.
One checks the uncached withdrawn name and persisted VPN hook immediately
after a replacement delegate reload, before the final VPN policy is applied.
The other makes only the member policy commit unwritable, runs the real
rollback, and checks that no successful receipt or resolver retirement follows.
It also queries the restored delegate, which requires its original readable
file mode to survive the private snapshot.
Its private copy returns from the final member failure after rollback so the
single-machine proof reaches the aggregate barrier, as a surviving second
member would in the fleet. Production inputs have no fault-injection option.

The local Gateway suite covers render ownership, retained lifecycle intent,
Node-removal refusal, candidate retention after interrupted publication,
revision fences, policy evidence and Doctor findings. The read-only health
timer fails during the deliberate Router outage and recovers without changing
selection.

Measured phase times are emitted by `cluster/verify.yml` as
`Report measured phase times`. Existing phase timeout remains 300 seconds;
the shared publication waits only the remaining part of its 31-second grace.
These timings exclude served Swamp dispatch, which is not available in this
scratch environment.

| Phase | Elapsed seconds |
| --- | ---: |
| VPN publication | 11.92 |
| Router publication, including shared grace | 52.10 |
| Router unchanged | 9.28 |
| Member selection | 13.69 |
| Member unchanged | 8.85 |
| Retirement publication, including shared grace | 31.53 |
