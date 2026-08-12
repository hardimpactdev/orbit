# Doctor node family retained Incus proof

- Candidate: `a738645b63c3ceb5f1d13bf795ec0111f11d24ce`
- Venue: `retained-incus`
- Environment: `dev-fixture`
- Topology: `dev-187668`
- Incus host: Beast over LAN at `192.168.6.20`
- Operator instance: `orbit-e2e-dev-187668-operator`
- Command: `orbit doctor --node=gateway --family=node --json`
- Expected: Both runs return the same ordered Doctor report. The fixture can exit with drift.
- Observed: Both runs exited `1` with the same byte-for-byte output. Each report contained the same six issues in the same order: platform record, four security checks, then DNS mapping.
- Result: passed

The non-zero exit is expected fixture drift. It is not a command or transport failure.
