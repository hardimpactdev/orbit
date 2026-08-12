# Doctor instance and process family retained Incus proof

- Candidate: `018ad26fbe39d503db615a8eccab93ba9388306d`
- Base: `017dc72c7ac659cb6e6f8a2b7e1309840e7a2011`
- Venue: retained Incus development fixture
- Topology: `dev-726dce` (`operator_gateway_app-dev`)
- Host: Beast over LAN address `192.168.6.20`
- Target node: `app-dev-1` (`app-dev`, `database`)

## Candidate identity

The operator and gateway checkouts used the candidate commit. The candidate
files had the same hashes locally and in the retained topology:

```text
068a9be94eb0b3b6edf5743aa859b594fa05bcc2be5e33dbc1f06de53df2849e  apps/gateway/app/Services/Doctor/DoctorAppFamilyProbe.php
bc70b5c0f16b7c329aff934bf2ba6191f7b8411920ca9c7fb85fff964cea043c  apps/gateway/app/Services/Doctor/DoctorProcessFamilyProbe.php
bca26868a138be79202023905a7d2ac591bd1bbb4a1dd821e2ffe3484fa2d282  apps/gateway/app/Services/Doctor/DoctorReportRunner.php
```

The Incus host check used the LAN address:

```text
ssh -tt -o HostName=192.168.6.20 beast 'incus version'
Client version: 6.0.0
Server version: 6.0.0
```

## Runtime command

The operator ran the public Doctor families against the serving node:

```text
orbit doctor --node=app-dev-1 --family=instance --family=process --stream-json
```

Observed result:

- Doctor ran `instance` before `process`, which preserves the canonical family
  order.
- Each family emitted its running and done events.
- The final report used public family names `instance` and `process`.
- The final report kept the normal Doctor scope, summary, issues, actions, and
  exit-code envelope.
- The report was healthy with zero issues and exit code `0`.

The fixture had no registered app instances. This run therefore proved the
empty inventory path, the node runtime configuration scan, process inventory,
family routing, progress, and final report contract. A temporary `app:new`
attempt was not used as proof because the disposable fixture lacked GitHub
authentication and returned `app.source_creation_failed`. Focused Pest tests
cover concrete instance inspection and the one-snapshot regression.

## Deterministic verification

- Focused Doctor checks: 236 tests and 2,019 assertions passed.
- Full `composer quality-check`: passed at the exact candidate.
- Quality artifact:
  `.orbit/quality-gates/quality-check-2026-08-12T115121Z-c06a49ceb432.json`
- Claude Opus reviewed the exact implementation in Solo and returned PASS with
  no required fixes.
