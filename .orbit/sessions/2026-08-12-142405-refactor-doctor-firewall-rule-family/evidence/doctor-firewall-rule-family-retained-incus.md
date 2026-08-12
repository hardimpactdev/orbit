# Doctor firewall-rule family retained Incus proof

- Candidate: `3b84795bb739bb2fdf1e82c0302d24555f5b7454`
- Base: `6d138cda28099ba1c1af710a27d6c197fa757a91`
- Venue: retained Incus development fixture
- Topology: `dev-7b7a3d` (`operator_gateway_app-dev`)
- Host: Beast over LAN address `192.168.6.20`
- Target: `app-dev-1` (`app-dev`, `database`)

## Candidate identity

The operator and gateway checkouts used the candidate source. Local and remote
hashes matched:

```text
a250a988a7311a2108e97d597cdf7e5f1024956e194bde068e54976bb3935140  apps/gateway/app/Services/Doctor/DoctorFirewallRuleFamilyProbe.php
b8a4150d30d4d12db6c0497a81fa7cf0b7122a973c65599f161990b71b3b40f2  apps/gateway/app/Services/Doctor/DoctorReportRunner.php
```

The Incus host check used the required LAN address:

```text
ssh -tt -o HostName=192.168.6.20 beast 'incus version'
Client version: 6.0.0
Server version: 6.0.0
```

## Concrete rule path

The operator created and applied one disposable rule:

```text
orbit firewall:allow doctor-firewall-proof --node=app-dev-1 --port=41234 --from=10.6.0.0/24 --protocol=tcp --reason=doctor-proof --json
```

Orbit returned `action=created` and `backend_enacted=true`. The operator then
ran:

```text
orbit doctor --node=app-dev-1 --family=firewall_rule --stream-json
```

The candidate emitted the normal queued, tree, running, and done events. It
inspected the concrete rule and returned the full documented issue contract:

- family and scope: `firewall_rule` on `app-dev-1`;
- key and code: `firewall_rule.rule_mismatch`;
- kind and disposition: `divergent`, `genuine_drift`;
- detail: expected and observed firewall shapes plus
  `rule=doctor-firewall-proof`;
- restore action: `restore_firewall_rule_rule_mismatch`.

The mismatch was existing firewall behavior: stored `address_family=both`
compared with the IPv4 UFW observation for the IPv4 subnet. A second standard
`from=any` rule showed the same pre-existing mismatch. This extraction did not
change the diff implementation. Both disposable rules were removed.

## Healthy empty-inventory path

After rule removal, the same Doctor command emitted the normal family events
and completed with exit code `0`. Its final report was healthy, named only the
`firewall_rule` family, and kept the normal scope, summary, issues, and actions
shape.

## Deterministic verification

- Focused Doctor checks: 227 tests and 1,961 assertions passed.
- Full `composer quality-check`: passed at the exact candidate in 192 seconds.
- Quality artifact:
  `.orbit/quality-gates/quality-check-2026-08-12T121608Z-5209fb765cec.json`
- Claude Opus reviewed the extraction boundary in Solo. Its requested
  behavior-only sequencing and test corrections were applied.
