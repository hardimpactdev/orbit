# Orbit Feature Slice

- Slice: 03-copy-defaults-on-development-instance-create
- Depends on: 02-app-development-defaults-crud

## Outcome

Every newly created Orbit app-development instance receives an ordered,
independent copy of its app's current development defaults; no other new or
existing instance changes.

## Scope

- Included: One copy action with explicit app-development eligibility; integration with `instance:add` and `instance:register` creation producers; transaction boundaries; no re-copy on registration update; copied command, timeout, and order values with new identities; authoritative lifecycle docs; focused gateway tests for positive, negative, independence, and rollback paths.
- Excluded: Copying into app-production or Laravel Cloud instances; retroactive propagation; implicit Fitta instance mutation; production deployment inheritance; zero-downtime work.

## Authority

- Decisions: Approved design `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-26-app-development-setup-defaults-design.md`; persisted role and `wasRecentlyCreated` define the transition boundary.
- Product docs: `apps/docs/content/domains/5_app/app-concepts.md`, `apps/docs/content/domains/5_app/27_instance-add/`, instance registration contract.

## Proof

- Focused: Controller and registrar tests prove copy-on-create, ordered value equivalence, independent IDs, no production/Cloud copy, no existing-instance mutation, no re-copy, and later default edits affect only future instances.
