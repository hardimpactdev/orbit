# Orbit Repo Feedback Loop

Orbit's repo feedback loop turns implementation signals into durable guardrails
that guide future fixes and block repeat failure modes. It is manual in this
slice: no scheduler, reviewer automation, or eval runner is introduced here.

## Scope

This loop governs Orbit repository development only. It covers signals from
implementation sessions, tests, docs drift, reviews, and live verification that
show agents are missing context. Product behavior contracts remain under
`apps/docs/content/`.

## Principles

- Treat repeated feedback as missing context, not as an agent personality issue.
- Distill only signals that are repeatable, high-cost, or safety-critical.
- Prefer the latest useful intervention point: prompt correction before a
  skill update, skill update before a static rule, static rule before broader
  automation.
- Keep the guardrail target close to the failure: tests for executable
  contracts, skills for workflow, product docs for behavior, root harness docs
  for repo-wide routing.
- Do not turn `AGENTS.md` into a rulebook. Add pointers there only when agents
  need a new discovery route.

## Loop Steps

1. **Capture**: record the exact signal: failing command, review comment,
   human correction, drift finding, or live-node symptom.
2. **Search**: check `harness-signals/` for similar prior signals before
   treating the issue as new. If it reappeared, question whether the current
   guardrail target is sufficient.
3. **Triage**: decide whether it is one-off local work, a missing guardrail, or
   a product contract conflict.
4. **Record**: create or update a curated signal record in `harness-signals/`
   when the signal is repeatable, high-cost, safety-critical, or evidence that
   an existing guardrail failed. Do not record ordinary local noise.
5. **Select Guardrail Target**: choose the smallest durable home for the
   lesson. Use `HARNESS_SIGNALS.md` as the signal-to-guardrail-target map.
6. **Distill**: update the chosen guardrail target in the same worktree when it
   is part of the current change. Otherwise create a scoped follow-up.
7. **Verify**: prove the guardrail now guides or blocks the issue with the
   narrowest useful command or review check.
8. **Report**: include the signal record, chosen guardrail target,
   verification, and any follow-up in the implementation report.

## Guardrail Targets

| Guardrail Target | Use When |
|------|----------|
| `AGENTS.md` | Agents need a new root discovery route or repo-wide warning. |
| `HARNESS.md` | The durable repo harness map changes. |
| `LOOP.md` | The feedback-loop procedure changes. |
| `HARNESS_SIGNALS.md` | A signal source or guardrail target decision changes. |
| `harness-signals/` | A curated signal should remain searchable across worktrees. |
| `.agents/skills/**` | A workflow, command family, verification lane, or role-specific procedure changes. |
| `apps/docs/content/**` | Product behavior, operator-facing contracts, or authority docs change. |
| Tests/static checks | The lesson can be enforced mechanically. |
| `apps/docs/content/product-decisions.md` | A dated product direction change or reversal lands. |

## Report Template

Use this compact block when a session distills a signal:

```markdown
Harness signal:
- Record: <harness-signals/path.md, or none>
- Source:
- Missing context:
- Guardrail target:
- Verification:
- Follow-up:
```

## Out Of Scope

- Nightly or scheduled signal mining
- Automated reviewer-persona matrix
- Eval runner or `evalc/`
- Customer workspace or fleet-operation harness
- Logging every implementation event in this file or in `harness-signals/`
