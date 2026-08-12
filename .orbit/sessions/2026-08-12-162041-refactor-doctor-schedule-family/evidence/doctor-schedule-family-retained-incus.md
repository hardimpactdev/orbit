# Doctor Schedule Family Retained Incus Proof

- Candidate: `6aa586ef60e47b503cc0b8a65c9ca360bb075d0c`
- Venue: retained-incus
- Environment: dev-fixture
- Topology: `dev-2a201f`
- Beast connection: LAN SSH with `HostName=192.168.6.20`
- Operator instance: `orbit-e2e-dev-2a201f-operator`
- Command: `orbit doctor --node=gateway --family=schedule --json`
- Expected contract result: exit 1 because the prepared fixture has no
  scheduler runtime backend or runtime hibernator; emit those two schedule
  issues in stable order
- First exit: 1
- Second exit: 1
- Output comparison: exact byte match
- First SHA-256: `c2cf7f8ed004e1effd5476f09ca0246ff567993c82eb91e5d7d3bc56f1079f0e`
- Second SHA-256: `c2cf7f8ed004e1effd5476f09ca0246ff567993c82eb91e5d7d3bc56f1079f0e`
- Observed issue order:
  1. `schedule.runtime_backend_unavailable`
  2. `schedule.runtime_hibernator_missing`
- Result: the two runs matched the expected Doctor drift contract exactly

The proof used the retained Incus topology command lane. No human-only
`composer test:e2e*` lane was run.
