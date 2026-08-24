# Orbit UI correction review handoff

candidate=adbc12e29d31f13ac8821b92a02ae8c79529a747
base_candidate=5c53eb54eb1b96513f9a708466fc49b97fb46264

Re-review the exact corrected candidate and the delta from `base_candidate`.
This is the same review cycle. Do not start a second reviewer.

## Corrections

1. Empty and non-file gateway CA paths normalize to `null`, with regression
   coverage.
2. The architecture `Next` body remains under `### Next`.
3. The tech-stack platform body remains under `### Platform and roles`; the
   complete browser UI section follows it.
4. Root script guards still reject Pint/PHPStan outside `apps/ui`; the toolchain
   exception is explicit in root instructions and product docs.
5. Root Repository Shape now routes `apps/ui`.
6. The Pest version contract and authoritative testing docs cover all seven
   Composer projects.
7. Root instruction prose was compacted without changing its rules so the
   unchanged 35,600-byte combined instruction budget passes.

## Proof

- Clean exact candidate: `adbc12e29d31f13ac8821b92a02ae8c79529a747`.
- Focused correction tests passed.
- `composer quality-check` passed all 51 subgates. Artifact:
  `.orbit/quality-gates/quality-check-2026-08-24T132928Z-c3a40b02c2b8.json`.
- `composer quality-gate:final-check` performed evidence-only analysis and
  returned warning-only local timing variance.
- Browser runtime passed at `https://orbit.nmbp`; exact structured receipt is in
  `.orbit/loop.md` and `.orbit/evidence/ui-runtime/receipt.md`.
- `bin/orbit-feature-proof-receipt` returns `ok: true` for this candidate.

Return the required exact final lines:

`BLAST_RADIUS: complete|gaps`

`HUMAN_JUDGMENT: required|not-required`

`VERDICT: PASS|FIX|ESCALATE`
