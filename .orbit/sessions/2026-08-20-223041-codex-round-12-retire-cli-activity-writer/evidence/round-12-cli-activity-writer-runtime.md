# Round 12 CLI activity writer retained-runtime proof

- Candidate: `3733246705151d0a08bcc13b9275a790bfd5d744`
- Venue: `retained-incus`
- Environment: `dev-fixture`
- Topology: `dev-6d3dd9` (`operator_gateway` on `beast`)
- Target: `orbit-e2e-dev-6d3dd9-operator`
- Solo terminal: `solo://proj/116/process/2578`
- Runtime checkout: `/home/orbit/orbit-run`

The terminal verified that `/usr/local/bin/orbit` resolved to
`/home/orbit/orbit-run/apps/cli/orbit`. It invoked that launcher and then
verified that both retired production files were absent from the runtime
checkout. It ran the removal guard and stored-CLI compatibility formatter test
inside the retained VM.

Observed output:

```text
/home/orbit/orbit-run
/home/orbit/orbit-run/apps/cli/orbit
Version       0.1.192 (new version available: 0.1.195)
......
Tests:    6 passed (24 assertions)
Duration: 2.29s
```

Result: passed. The exact candidate cannot expose the retired writer files,
and it still formats stored `cli` activity rows correctly in the source-mounted
runtime checkout.
