# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad:
- Worktree: /Users/nckrtl/orbit/.worktrees/deploy-step-failure-visibility
- Branch: deploy-step-failure-visibility

## Goal

`deploy:run` surfaces the node-side `internal:deploy:run-step` error envelope message on the failed step instead of replacing it with the generic `Deploy run step response is invalid.`, so a real cause such as a missing app working directory is visible in `deploy:log` and `deploy.step_failed`.

## Scope

- Owned: `apps/gateway/app/Services/Deploy/DeployManager.php`, `apps/gateway/tests/Unit/Services/Deploy/`, `apps/docs/content/domains/10_deploy/4_deploy-run/technical/1_deploy-run.md`
- Constraints: Keep the generic message as the last-resort fallback only. Do not change the transport, payload shape, or step execution order. Do not leak operation tokens or secrets into step stderr.
- Out of scope: Creating the missing `launch-production` runtime user or app path on main1 (owned by the `app.security.system_user` doctor work), the Cloudflare 403, and any deploy preflight probe for the working directory.

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact --filter=Deploy` 51 passed; `bin/orbit-cli-pest --compact` 2533 passed; `bin/orbit-docs-pest --compact` 178 passed
  - broader: passed - `composer quality-check` exit 0 at candidate HEAD with a clean worktree, artifact `.orbit/quality-gates/quality-check-2026-08-08T063726Z-2d742119c2e6.json`
  - runtime: passed - candidate=cf00e3e2a71c6a04886d75c8e18a7e5815c0e232; venue=retained-incus; environment=dev-fixture; command=orbit deploy:run cwdproof --detach; expected=the refused step records the node refusal reason and envelope code instead of the generic protocol message; observed=The provided cwd "/srv/cwdproof" does not exist. (deploy_run_step_failed); result=passed; evidence=`.orbit/evidence/deploy-step-refusal-retained-incus.md`
- Blast radius: complete - evidence=repository-wide `rg -n "response is invalid" apps/gateway/app --glob '*.php'` plus `orbit:command-catalog --check` and `orbit:monorepo-unit-map --check`; result=four sibling executors keep `stdout: $result->stdout` so the envelope survives there and they are a materially different, separate defect, and both generated artifacts are fresh
- Review: passed - human-judgment=not-required
- Reviewed feature tip: cf00e3e2a71c6a04886d75c8e18a7e5815c0e232
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: cf00e3e2a71c6a04886d75c8e18a7e5815c0e232
- Accepted main tip: 017a7b4b657dc6574644052c7fff1a8bb4e893e3

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
