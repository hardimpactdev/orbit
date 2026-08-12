# Doctor workspace family retained Incus proof

- Candidate: `cfc4ad1d5`
- Venue: `retained-incus`
- Environment: `dev-fixture`
- Topology: `operator_gateway_app-dev`, run `dev-59ec11`
- Candidate sync: gateway and operator checkouts resynced after the final test-only amendment
- Beast connection: LAN SSH with `HostName=192.168.6.20`
- Operator instance: `orbit-e2e-dev-59ec11-operator`
- Target node: `app-dev-1`
- Command: `orbit doctor --node=app-dev-1 --family=workspace --json`
- Expected: The extracted workspace family completes successfully and returns a stable Doctor result on repeated runs.
- Observed: Both runs exited 0. Both returned a healthy workspace-family Doctor result with zero issues. The complete JSON outputs matched byte-for-byte and had SHA-256 `d9bd105c2a913ae145e59c41c3cb2f55e3a17df956d0b42e29d77f39ce47eb33`.
- Result: passed

The first topology acquisition attempt exposed a concurrent `/run/xtables.lock` race on Beast. A read-only LAN check showed the lock was free after the competing network setup ended. A clean retry succeeded. No human-only `composer test:e2e*` lane ran.
