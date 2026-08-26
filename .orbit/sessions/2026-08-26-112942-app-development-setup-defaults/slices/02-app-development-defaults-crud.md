# Orbit Feature Slice

- Slice: 02-app-development-defaults-crud
- Depends on: none

## Outcome

An authorized operator can create, list, update, reorder, and remove ordered
development setup defaults on an app without changing any instance-owned setup
steps.

## Scope

- Included: `app_development_setup_steps` persistence owned by `App`; model, relationship, factory, request validation, actions, gateway CRUD API, canonical payloads, app read/write authorization, public `app-development-setup-step:add|list|update|remove` commands with JSON and human renderers, command visibility and permissions, authoritative app docs, focused gateway and CLI tests.
- Excluded: Copying defaults into instances; running defaults; changing legacy `AppSetupStep`/`instance-setup-step:*`; Fitta data migration.

## Authority

- Decisions: Approved design `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-26-app-development-setup-defaults-design.md`; CRUD must preserve stable order and independent row identity.
- Product docs: `apps/docs/content/domains/5_app/`, `apps/docs/content/PRODUCT_DECISIONS.md`.

## Proof

- Focused: Gateway migration/model/action/controller/authorization tests; CLI command forwarding, validation, renderer, and command-list tests; docs lint.
