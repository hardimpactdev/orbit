---
title: "Fixture retirement"
description: "Criteria for retiring shape-gate fixture families into proof cases under ADR 0262."
---

# Fixture retirement

[ADR 0262](/decisions/0262-prove-changed-operations-on-the-pull-request#build-order) retires shape-gate fixture families one by one. A family retires only when all three conditions hold:

- A [proof cases file](/reference/verification-cases) covers the family's realistic behavior.
- Its proof batch finishes within the 5-minute PR wall-time budget ([Feature delivery](/reference/implementation-loop)).
- The proof has been enabled and gated at least one real PR green.

## Permanent exceptions

Two groups stay as quick checks or fixtures by design and never retire into proofs:

- **Shape gate and Swamp source checks:** Static lint, guard syntax, expression ends, absent secrets, and grant checks.
- **Proof copy and nested fleet:** The code that builds and runs the proof copy itself ([ADR 0262 exception](/decisions/0262-prove-changed-operations-on-the-pull-request#exception-the-code-that-makes-the-proof-copy)).

Pure-code tests (workflow engine helpers, maintenance/dependency logic) remain quick checks pending the pure-code decision in ADR 0262.

## Batch wall time

Every proof batch pays fixed overhead before its cases start: restoring `base` (bounded at 45 seconds) and preparing the nested fleet. Fast read-only batches fit easily under 5 minutes, while batches that run Node converges or Instance container lifecycles exceed the budget until the changes in [The five-minute math](/reference/fixture-retirement/instance-container#the-five-minute-math) land.
