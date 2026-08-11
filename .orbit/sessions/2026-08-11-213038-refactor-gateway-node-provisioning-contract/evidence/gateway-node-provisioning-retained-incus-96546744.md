# Gateway node provisioning retained Incus proof

- Candidate: `96546744ca156ad12e2caef61260cfc2ab383ea7`
- Date: `2026-08-11`
- Environment: retained Incus development fixture
- Topology: `dev-24de1d` (`operator_gateway_app-dev`)
- Runner: Beast through LAN SSH as `nckrtl@192.168.6.20`
- Solo terminal: project `26`, process `2279` (`retained-incus-proof`)
- Instances: `orbit-e2e-dev-24de1d-operator`, `orbit-e2e-dev-24de1d-gateway`, `orbit-e2e-dev-24de1d-dev`

## Checkout identity

The Solo terminal entered the retained operator VM and changed to
`/home/orbit/orbit-run`.

```text
cwd=/home/orbit/orbit-run
user=orbit
launcher=/home/orbit/.local/bin/orbit
```

The launcher contained this exact target:

```text
exec '/home/orbit/orbit-run/apps/cli/orbit' "$@"
```

The local and retained-VM SHA-256 values for
`NodeBootstrapReservation.php` matched:

```text
c35b99b24c0499e7718154a096eed1792b1b4efd9b9d22f417bccaad2de9a67c
```

## Behavior

The Solo terminal ran the real source-mounted CLI against the retained gateway:

```text
orbit node:new proof-invalid-app --roles=app-dev --host=10.6.0.4 --user=orbit --tld=proof-invalid --self-grant=custom --json --no-interaction
```

The first call and the retry returned the same result and exit code:

```json
{"error":{"code":"validation_failed","message":"Use --preset or --permissions to specify grant permissions.","meta":{"fields":["preset","permissions"]}}}
```

```text
exit=1
retry_exit=1
matching_nodes=0
```

A read-only query of the retained gateway database, also through
`nckrtl@192.168.6.20`, confirmed that the rejected request created no durable
state:

```text
nodes=0
bootstraps=0
peers=0
```

Result: passed. Invalid app grant input fails before node, bootstrap, or
WireGuard peer reservation, and a retry is deterministic.
