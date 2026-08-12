# Doctor proxy family retained Incus proof

- Candidate: `a23b6ab2b69c8465e1160fec09afc1abe7eebf6c`
- Venue: `retained-incus`
- Environment: `dev-fixture`
- Topology: `operator_gateway_app-dev`, run `dev-c8f3dd`
- Beast connection: LAN SSH with `HostName=192.168.6.20`
- Operator instance: `orbit-e2e-dev-c8f3dd-operator`
- Target node: `app-dev-1`
- Command: `orbit doctor --node=app-dev-1 --family=proxy --json`
- Expected: The extracted proxy family completes successfully and returns a stable Doctor result on repeated runs.
- Observed: Both runs exited 0. Both returned a healthy proxy-family Doctor result with zero issues. The complete JSON outputs matched byte-for-byte and had SHA-256 `d9d53396f365843a2d5c31a1274fc9fdf6844fea911b5d41d38773502d866d74`.
- Result: passed

The topology was created from the candidate worktree with gateway and operator source overlays. No human-only `composer test:e2e*` lane ran.
