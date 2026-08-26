# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-app-development-setup-defaults
- Worktree: /Users/nckrtl/orbit/.worktrees/app-development-setup-defaults
- Branch: app-development-setup-defaults

## Goal

Orbit stores reusable development setup defaults on an app, copies them into
independent instance-owned setup steps only when a new app-development instance
is created, and manages Bun as a required tool on app-development and
app-production nodes. Fitta is the first app migrated to the new defaults.

## Scope

- Owned: Bun tool lifecycle and app role baselines; app-development setup-step CRUD across docs, gateway API, and CLI; copy-on-create producers `InstanceController::store` and `AppRegistrar::registerAppRecord`; consumers app setup-step CRUD, `instance:add`, `instance:register`, `instance:setup`, and role convergence; dangerous invariants are independent row identities, stable order, app-development-only copy, no re-copy, and no existing-instance mutation; Fitta default-recipe migration and proof. primitive=app development setup defaults; transitions=success:new app-development instance owns copied steps and Bun role converges|failure:creation and role convergence fail closed|retry:creation and convergence retry without duplicate defaults|stop-restart:persisted defaults and copied rows resume safely|stale:existing instances remain unchanged
- Constraints: Existing instances never change; copied rows receive independent identities and ordering; later app-default edits affect only future app-development instances; app-production and Laravel Cloud instances do not inherit development defaults; setup runs through existing Orbit-managed instance setup routing; schema rows use foreign-key cascades; lifecycle predicates use persisted node roles and new-instance identity.
- Out of scope: Production setup/deployment inheritance; release recipes; zero-downtime deployment; automatic migration of apps other than Fitta; changes to existing instance setup steps.

## Slices

| Slice | State | Checkpoint |
| --- | --- | --- |
| `.orbit/slices/01-bun-managed-prerequisite.md` | complete | f53c35ea13418683745396f715f11efe65219cd4 |
| `.orbit/slices/02-app-development-defaults-crud.md` | complete | 02c4b873a14f03045a2cec2a0c661829c1638a91 |
| `.orbit/slices/03-copy-defaults-on-development-instance-create.md` | complete | 02c4b873a14f03045a2cec2a0c661829c1638a91 |

## Proof

- Verification:
  - focused: passed - gateway feature/unit bundle passed 133 tests with 864 assertions; CLI relevant command and visibility bundle passed 215 tests with 474 assertions; scoped Mago, Rector, docs lint, OpenAPI/SDK classification, and secret scan passed
  - broader: passed - `composer quality-check` exited 0 on exact clean candidate 02c4b873a14f03045a2cec2a0c661829c1638a91 with every subgate zero; artifact=`.orbit/quality-gates/quality-check-2026-08-26T091946Z-75f72383a2de.json`
  - runtime: passed - candidate=02c4b873a14f03045a2cec2a0c661829c1638a91; venue=retained-incus; environment=dev-fixture; target=topology dev-11337b kind operator_gateway_app-dev_app-prod on beast; expected=Bun managed on app-dev and app-prod, new Fitta app-dev instance owns eight independent copied defaults, app-prod and existing rows stay unchanged, copied recipe completes and route serves; observed=candidate hashes matched the synced operator and gateway bytes, Bun 1.4.0 remained under the orbit user on both roles, app ids 74..81 and instance ids 9..16 remained independent and ordered, app-prod count remained zero, review-correction CRUD and validation metadata passed, and https://fitta.test returned 200; result=passed; evidence=`.orbit/evidence/app-development-setup-defaults-retained-incus.md`
- Required verification:
  - Retained topology proof: passed - topology id=dev-11337b; kind=operator_gateway_app-dev_app-prod; host=beast; inspected roles=operator,gateway,dev,prod; exact candidate file hashes, Bun convergence, copied identity/order, production exclusion, Fitta setup state, and 200 route response recorded in tmux `feat-app-development-setup-defaults:proof-1`; evidence=`.orbit/evidence/app-development-setup-defaults-retained-incus.md`
  - `composer quality-check`: passed - exact clean candidate 02c4b873a14f03045a2cec2a0c661829c1638a91, dirty=false, exit 0, all subgates zero; receipt=`.orbit/quality-gates/quality-check-2026-08-26T091946Z-75f72383a2de.json`
- Blast radius: complete - evidence=`.orbit/evidence/app-development-setup-defaults-blast-radius.md`; result=70-file diff classified across app-default CRUD, both creation producers, setup consumers, Bun role convergence, authorization, generated contracts, docs, and tests with no production deployment inheritance path added
- Review: passed - reviewer=Claude app-defaults-review; human-judgment=not-required; BLAST_RADIUS=complete; evidence=`.orbit/evidence/app-development-setup-defaults-review.md`
- Reviewed feature tip: 02c4b873a14f03045a2cec2a0c661829c1638a91
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 02c4b873a14f03045a2cec2a0c661829c1638a91
- Accepted main tip: e8f126db45649e2ce5e5244ab29c29e03969b63a

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
