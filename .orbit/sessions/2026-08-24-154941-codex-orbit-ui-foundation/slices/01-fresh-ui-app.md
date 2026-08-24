# Orbit Feature Slice

- Slice: 01-fresh-ui-app
- Depends on: none

## Outcome

A fresh, renamed Launch starter exists at `apps/ui`, serves its Inertia home page, enables Agentation in local development, and resolves the monorepo `GatewayConnector` from an explicit trusted gateway URL and optional CA path.

## Scope

- Included: copy only tracked starter source from Launch commit `9b76da62c67d5e5b7794572b66c0aa7c0703412e`; remove copied runtime/build artifacts; rename the application to Orbit UI; add the monorepo PHP SDK path dependency and Laravel binding; add focused Pest coverage for the page and SDK configuration; run focused PHP and frontend checks.
- Excluded: Orbit dashboard screens, request-source delegation, production hosting, local Orbit registration, native macOS files, and edits outside `apps/ui`.

## Authority

- Decisions: approved fresh start with no native dashboard migration; every future Orbit API call uses the PHP/Saloon SDK and configured gateway endpoint.
- Product docs: `PRODUCT_DECISIONS.md`, `apps/docs/content/architecture.md`, `apps/docs/content/tech-stack.md`.

## Proof

- Focused: demonstrate a failing SDK binding/page test before implementation, then pass the app Pest suite, PHP analysis/style checks, VitePlus checks, and client/SSR build.
