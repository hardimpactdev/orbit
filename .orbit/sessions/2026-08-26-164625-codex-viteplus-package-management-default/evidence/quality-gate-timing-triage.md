# Quality-gate timing triage

- Gate: `composer quality-check`
- Candidate: `87c57fa9e0199e9f7e62eddd1e51eb975de74430`
- Evidence: `.orbit/quality-gates/quality-check-2026-08-26T143808Z-30636ca62d46.json`
- Classification: performance warning
- Confidence: medium
- Reason: the exact clean candidate passed all 51 subgates. The analyzer only
  compared the 160-second run with a 26-second local baseline and marked the
  aggregate and slow Pest lanes warning-only. It did not identify a failed
  contract or a comparable test-count/workload provenance for that baseline.
- Smallest next action: no rerun and no baseline refresh. Keep the successful
  artifact and warning classification.
- Remaining unknowns: whether the 26-second baseline used the same host load,
  cache state, test counts, and concurrency as this run.
