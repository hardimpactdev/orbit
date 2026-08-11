# Doctor fleet node projection retained Incus proof

- Candidate: `ef8959d474e6b5fe740c7455e71c9ed5dbd3abf9`
- Venue: `retained-incus`
- Environment: `dev-fixture`
- Topology: `dev-f7e6b7` (`operator_gateway`) on Beast
- SSH route: `nckrtl@192.168.6.20`
- Solo terminal: `solo://proj/24/process/2275`
- Runtime checkout: `/home/orbit/orbit-run`

## Candidate identity

The local and retained gateway copies had the same SHA-256 values:

- `DoctorFleetNodeProjection.php`: `416df7a0361c5f636ed35b5b1907e6416e0fbc6530af2fb051cfa53f6ba99030`
- `DoctorReportRunner.php`: `15377692af8b93b8d025d71738fa08c28badae149621a987ae2a831d800a964d`

The retained CLI launcher resolved to
`/home/orbit/orbit-run/apps/cli/orbit`.

## Behavior proof

The Solo terminal ran this read-only command twice:

```text
./apps/cli/orbit doctor --all --family=node --json
```

Both runs completed with exit code `1` because the retained fixture had known
Doctor issues. Both returned a complete Doctor report. A PHP assertion over the
two reports returned:

```text
FLEET_PROJECTION_PASS nodes=gateway roles=gateway,router,vpn issues=6
```

The assertion verified:

- stable node order across both runs;
- stable issue-key order across both runs;
- exact node-summary fields: `node`, `role`, `roles`, `healthy`, `families`,
  and `summary`;
- complete ordered roles: `gateway`, `router`, `vpn`;
- selected family: `node`;
- every projected issue was an array.

## Fixture observation

The first command immediately after acquisition reached the operator VM before
gateway TLS accepted a request. Direct HTTP and HTTPS readiness probes then
returned `200`. The two behavior-proof runs above completed after that check
and produced the same projection. This timing observation is separate from the
candidate behavior.

After acceptance, `composer e2e:incus -- --stop --id=dev-f7e6b7` exited `0`
and reaped both retained instances.
