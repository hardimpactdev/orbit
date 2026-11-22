---
title: "Fixture retirement: instance containers"
description: "Why fixtures for instance containers stay active and what proof infrastructure is needed to retire them."
---

# Fixture retirement: instance containers

The three instance-container fixture files (`swamp/tests/ansible_instance_container_test.ts`, `ansible_instance_container_destroy_test.ts`, and `ansible_instance_container_power_test.ts`) stay as fixtures until container lifecycle proofs fit within the 5-minute PR wall-time budget ([Fixture retirement](/reference/fixture-retirement/index)).

## Classification criteria

When reviewing fixture cases for retirement into [verification cases](/reference/verification-cases), each case falls into one of six buckets:

| Class | Meaning |
| --- | --- |
| **New** | Replaced by a real proof case on a command's `.cases.yaml` file. |
| **Covered** | Already exercised by another proof case in the same family. |
| **Copy path** | Nested-fleet or proof-copy behavior; stays as a fixture under ADR 0262. |
| **Designed away** | Made impossible by current architecture (for example, one-time legacy migrations). |
| **Dropped** | Rare mid-call fault or crash that a proof copy cannot synthesize on demand. |
| **Pure-code** | No playbook, workflow, or Node behind it; stays as a quick check pending ADR 0262's pure-code decision. |

## Required proof infrastructure

Retiring the instance-container family into live proofs requires four pieces that are not yet built in `swamp/proof/runner/`:

1. **Nested Instance host:** Include a prepared `app-dev` Instance host in the proof `base` snapshot.
2. **Fixture Projects:** Support disposable fixture Projects without requiring a GitHub App token (open decision: local Git fixture source).
3. **Starting states:** Provide three reusable batch starting states: `instance-host`, `instance-default`, and `instance-clone`.
4. **Runner steps:** Add container-aware proof steps (fixture creation, container state/exec, HTTP probe, and sleep/wake power checks).

## The five-minute math

Proof batch wall time follows:

```text
wall time = restore + preparation + setup + (selected cases * case time) / (places * hosts per copy)
```

Fitting this family inside the 5-minute budget requires four changes together:

- **Pre-baked observers:** Keep current observers in `base` so preparation stays short.
- **Pre-seeded default:** Store a seeded default Instance inside `base` so clone cases skip initial build time.
- **Two Instance hosts per copy:** Double per-copy concurrency for container lifecycle writers.
- **Phase-based selection:** Map changed Ansible phases to only the affected container proof cases rather than running the full suite.
