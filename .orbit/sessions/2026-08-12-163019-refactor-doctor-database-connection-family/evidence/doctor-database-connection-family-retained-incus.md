# Doctor database connection family retained Incus proof

- Candidate: `e9f414d401126965ffa7f3da7eab132356587429`
- Venue: `retained-incus`
- Environment: `dev-fixture`
- Topology: `dev-78a891` (`operator_gateway_app-dev`)
- Incus host access: `ssh -tt -o HostName=192.168.6.20 beast`
- Command: `orbit doctor --node=beast --family=database_connection --json`
- Expected: The focused family command runs through the extracted service and repeated runs preserve the same report shape, issue order, and exit status.
- Observed: Two consecutive runs both exited `1` with `drift_detected`. Each report contained exactly two issues in this order: `database_connection.unverifiable`, then `database_connection.target_missing`. Scope, summaries, dispositions, details, and actions were identical.
- Result: `passed`

The non-zero exit is the expected Doctor contract for the prepared fixture's existing drift. The extraction did not change or repair that drift.
