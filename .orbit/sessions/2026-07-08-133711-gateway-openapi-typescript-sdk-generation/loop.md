# Orbit Current Slice State

## Feature Context

- Scratchpad: solo://proj/4/scratchpad/gateway-openapi-sche--247
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-gateway-openapi-schema
- Branch: codex/gateway-openapi-schema
- Completed slices:
  - Scramble inventory spike: complete - gateway-local `dedoc/scramble` dev dependency installed; OpenAPI inventory exported and compared with PHP SDK; archived at `/Users/nckrtl/orbit/.orbit/sessions/2026-07-08-104240-gateway-openapi-schema-spike`.
  - Contract hardening: complete - gateway-local Scramble config/customizer, export ergonomics, focused schema contract test, and refreshed schema artifact; archived at `/Users/nckrtl/orbit/.orbit/sessions/2026-07-08-111349-gateway-openapi-contract-hardening`.
  - SDK drift alignment: complete - PHP Saloon SDK request drift aligned for the core dashboard surface and refreshed comparison evidence reports no remaining actionable drift; archived at `/Users/nckrtl/orbit/.orbit/sessions/2026-07-08-115956-gateway-openapi-sdk-drift-alignment`.
  - Public SDK coverage triage: complete - gateway-owned `openapi-sdk-surface.json` classifies schema-only operations into public/internal/deferred groups; archived at `/Users/nckrtl/orbit/.orbit/sessions/2026-07-08-122220-gateway-openapi-sdk-surface-triage`.
- Current slice: Generate a reusable TypeScript gateway client from the classified public OpenAPI surface for the Tauri/TanStack macOS app.

## Done Contract

- Active slice start gate:
  - Multi-slice feature roadmap scratchpad exists before worker dispatch: yes - solo://proj/4/scratchpad/gateway-openapi-sche--247 revision 7
  - `.orbit/loop.md` links the feature roadmap and names the current slice: yes
  - If the source scratchpad lives in another Solo project, execution-project scratchpad links back to it and mirrors the roadmap substance: not applicable - same Solo project.
- Parallelization scan:
  - Candidate parallel lanes: generator/package selection; public OpenAPI filtering; TypeScript package/client wrapper; docs/test verification.
  - Serialized lanes, with required dependency/shared-state/provider-capacity/merge-order reason: serial self-owned - the filtered public OpenAPI input and generator choice must settle before package code, tests, and docs can be trusted.
  - Deferred lanes (lane -> concrete reason -> owner): macOS UI integration -> later slice because the SDK package must exist first; adding all missing PHP SDK request classes -> separate follow-up from Slice 4; switching to a fuller generated SDK framework -> pivot only if the default generator stack fails acceptance.
  - Parallel dispatch started (lane -> Solo process or owner): not started; existing Solo Codex orchestrator process 862 will continue the serial slice.
- Generator recommendation:
  - Default path: generate from the OpenAPI spec using `openapi-typescript` for types plus `openapi-fetch` for a small typed fetch client/wrapper.
  - Rationale: current docs show `openapi-typescript` generates strict OpenAPI 3 types and `openapi-fetch` creates a typed fetch client from those `paths`; this is enough for Tauri/browser use and keeps runtime small.
  - Alternative/pivot: evaluate `@hey-api/openapi-ts` with fetch client and SDK plugins only if named SDK-method ergonomics are required or the `openapi-fetch` wrapper cannot meet the macOS/TanStack needs.
- Done when:
  - A repeatable command builds a public TypeScript SDK/client from the current gateway OpenAPI export and `apps/gateway/openapi-sdk-surface.json`.
  - The generated public OpenAPI input includes existing PHP SDK-covered operations plus schema-only operations classified as `public_sdk`, and excludes `internal_only` plus `deferred_optional` operations.
  - A reusable TypeScript package exists, expected path `packages/sdk-typescript` unless repo inspection finds a stronger local package boundary.
  - The package exports generated OpenAPI types, a small Orbit gateway client wrapper for `baseUrl`, optional header/auth injection, custom `fetch`, and typed request/response ergonomics.
  - TypeScript checks or tests prove representative dashboard calls are typed, including nodes/apps/processes/tools and at least one Slice 4 public follow-up group.
  - Product docs or package README document generation source, public-surface filtering, and macOS/TanStack consumption expectations.
  - Focused generation/typecheck/tests and relevant PHP/docs verification pass with exact commands recorded in this packet.
- Evidence:
  - Filtered public OpenAPI artifact under `.orbit/evidence/` or package-local generated input.
  - Generated TypeScript SDK package files and tests/typecheck output.
  - Exact generator command and package manager lockfile changes.
  - Updated `.orbit/loop.md` final distillation.
- Reviewer checks:
  - Feature orchestrator self-review of generated public surface to confirm internal/deferred routes are absent.
  - JavaScript/TypeScript style review against Spatie JS rules for hand-written wrapper/tests.
  - Fresh analyzer only if generator selection creates a product-direction dispute, dependency concern, or review escalation.
- Stop if:
  - The generator requires exposing internal/deferred routes to produce a usable client.
  - Adding a root JavaScript workspace or package manager strategy requires a broader repository decision.
  - Generated output is too large or unstable to review without a separate generated-code policy decision.
  - The package cannot be verified locally without releasing artifacts to nodes.
- Pivot if:
  - `openapi-typescript` plus `openapi-fetch` cannot provide acceptable client ergonomics; compare `@hey-api/openapi-ts` against the same filtered public spec and record the tradeoff before changing generator direction.
  - Existing repo/package layout suggests a better boundary than `packages/sdk-typescript`; update the packet before moving generated code.

## Progress

- Tried: Confirmed Slice 4 was archived before rewriting the active packet.
  Result: passed - archive exists at `/Users/nckrtl/orbit/.orbit/sessions/2026-07-08-122220-gateway-openapi-sdk-surface-triage`.
  Next: Lint this Slice 5 packet and send the continuation prompt to Solo process 862.
- Tried: Updated roadmap scratchpad for Slice 5 activation.
  Result: passed - solo://proj/4/scratchpad/gateway-openapi-sche--247 revision 7 marks TypeScript SDK generation active and records the generator recommendation.
  Next: Hand the active packet to Solo process 862.
- Tried: Proved Slice 5 first checkpoint state.
  Result: passed - process 862 is `codex-gateway-openapi-schema` in Solo project 4; worktree is `/Users/nckrtl/orbit/.worktrees/codex-gateway-openapi-schema`; branch is `codex/gateway-openapi-schema`; packet lint passed.
  Next: Implement package-local TypeScript SDK generation without introducing a root JavaScript workspace.
- Tried: Created `packages/sdk-typescript` with `openapi-typescript` plus `openapi-fetch`.
  Result: passed - package-local `npm install` succeeded after pinning TypeScript to the latest compatible 5.x line required by `openapi-typescript@7.13.0`.
  Next: Generate the filtered public OpenAPI input and TypeScript schema.
- Tried: Generated public TypeScript SDK input from gateway OpenAPI plus `apps/gateway/openapi-sdk-surface.json`.
  Result: passed - `npm run generate` refreshes `.orbit/evidence/gateway-openapi.json`, writes `packages/sdk-typescript/openapi/public-gateway-openapi.json`, writes `.orbit/evidence/typescript-sdk-public-openapi-summary.json`, and regenerates `packages/sdk-typescript/src/generated/schema.ts`.
  Next: Prove typed representative calls and route exclusions.
- Tried: Typechecked the wrapper and representative dashboard calls.
  Result: passed after patching gateway OpenAPI metadata for raw-request `/processes` query filters; `npm test`/`npm run typecheck` accepts nodes/apps/processes/tools/app-instance/app-websocket calls and rejects internal/deferred routes.
  Next: Run focused gateway/docs/package verification and update this packet.

## Candidate Signals While Working

- Slice 1/Slice 2 archive live Codex session extraction exceeded the child script's default PHP memory limit once; current response remains explicit empty process fixture plus preserved Solo process/evidence pointers. No recurrence during Slice 3 or Slice 4 archives.
- `openapi-typescript@7.13.0` peers on TypeScript `^5.x`, so the new package uses TypeScript 5.9.3 instead of the host-current TypeScript 6 line.
- Scramble missed `/processes` query parameters because the controller reads raw `Request`; Slice 5 patched the gateway OpenAPI customizer to document `node`, `app`, and `workspace` at the source contract.
- The first focused Pest invocation used root-relative test paths with `bin/orbit-gateway-pest` and failed before running tests; the gateway-local path rerun passed.

## Blockers

- none

## Evidence Links

- Feature roadmap: solo://proj/4/scratchpad/gateway-openapi-sche--247 revision 7
- Completed Slice 1 archive: `/Users/nckrtl/orbit/.orbit/sessions/2026-07-08-104240-gateway-openapi-schema-spike`
- Completed Slice 2 archive: `/Users/nckrtl/orbit/.orbit/sessions/2026-07-08-111349-gateway-openapi-contract-hardening`
- Completed Slice 3 archive: `/Users/nckrtl/orbit/.orbit/sessions/2026-07-08-115956-gateway-openapi-sdk-drift-alignment`
- Completed Slice 4 archive: `/Users/nckrtl/orbit/.orbit/sessions/2026-07-08-122220-gateway-openapi-sdk-surface-triage`
- Public SDK surface contract: `apps/gateway/openapi-sdk-surface.json`
- Public SDK coverage evidence: `.orbit/evidence/sdk-public-surface-coverage.json`
- Gateway OpenAPI artifact: `.orbit/evidence/gateway-openapi.json`
- TypeScript SDK public OpenAPI artifact: `packages/sdk-typescript/openapi/public-gateway-openapi.json`
- TypeScript SDK public-surface evidence: `.orbit/evidence/typescript-sdk-public-openapi-summary.json`
- TypeScript SDK package: `packages/sdk-typescript`
- Existing Solo Codex orchestrator: process=862; name=codex-gateway-openapi-schema; project=4.
- Session archive: .orbit/sessions/2026-07-08-133711-gateway-openapi-typescript-sdk-generation

## Harness Signals

- Searched: package-local JavaScript layout under `apps/gateway`, `apps/docs`, and `packages`; current `openapi-typescript` and `openapi-fetch` docs through Context7; gateway Scramble customizer and focused OpenAPI tests.
- Created or updated: `packages/sdk-typescript/**`; `apps/gateway/app/Support/OpenApi/GatewayOpenApi.php`; `apps/gateway/tests/Feature/OpenApiSchemaContractTest.php`; `apps/docs/content/tech-stack.md`; `.orbit/evidence/typescript-sdk-public-openapi-summary.json`; `.orbit/loop.md`.
- Deferred follow-up: macOS UI integration remains after the TypeScript SDK package and generation command are verified.

## Final Distillation

- Loop outcome:
  - complete
- Required verification:
  - Retained topology proof: not applicable - TypeScript SDK package generation does not touch live node or topology behavior.
  - `npm install` from `packages/sdk-typescript`: passed - installed package-local dependencies with no vulnerabilities.
  - `npm run generate` from `packages/sdk-typescript`: passed - exported gateway OpenAPI, filtered public SDK input, and regenerated TypeScript schema.
  - `npm run typecheck` from `packages/sdk-typescript`: passed.
  - `npm run build` from `packages/sdk-typescript`: passed.
  - `npm test` from `packages/sdk-typescript`: passed.
  - `bin/orbit-gateway-pest tests/Feature/OpenApiSchemaContractTest.php tests/Feature/OpenApiSdkSurfaceContractTest.php --compact`: passed - 2 tests, 71 assertions.
  - `composer docs-lint`: passed - existing warnings only, 0 errors.
  - `bin/orbit-gateway-vendor-bin mago format --check app/Support/OpenApi/GatewayOpenApi.php tests/Feature/OpenApiSchemaContractTest.php`: passed.
  - `composer quality-check`: not applicable for this focused Slice 5 handoff - package-local TypeScript checks plus gateway OpenAPI/docs/PHP-format checks covered the changed surfaces; the new TypeScript package is not part of the root Composer quality fan-out, and no live topology or broad PHP runtime behavior changed.
- Finalization gate fit:
  - Slice 5 is ready for review/archive; no commit, merge, cleanup, or E2E lane was run.
- Distillation packet:
  - Location: `.orbit/loop.md`
  - Includes objective/final diff: reusable package-local TypeScript SDK generation from the classified public OpenAPI surface; thin `openapi-fetch` wrapper; source OpenAPI process-query metadata patch; docs update for generated client boundary.
  - Includes worker/reviewer/terminal/evidence pointers: roadmap scratchpad, Slice 1-4 archives, public surface contract, gateway OpenAPI evidence, TypeScript public OpenAPI evidence, package files, and Solo process 862.
  - Includes orchestrator steering notes: serial lane selected because package code, type tests, and docs depend on the filtered public spec and generator choice.
- Agent session capture waivers: none for this slice.
- Fresh analyzer:
  - Persona: not used - generator direction stayed within the approved default stack and no dependency/product dispute arose.
  - Solo process or analyzer: not used.
  - Verdict: not used.
- Candidate signals:
  - archive live extraction memory -> defer -> observed once before this slice; no recurrence during Slice 3 or Slice 4 archive.
  - TypeScript 6 peer conflict -> resolved locally -> use TypeScript 5.9.3 until `openapi-typescript` peers on TypeScript 6.
  - raw Request OpenAPI gap -> fixed in gateway customizer -> `/processes` query filters now appear in generated clients and are tested.
- Accepted durable updates:
  - `packages/sdk-typescript` package with package-local npm lockfile, filter script, public OpenAPI artifact, generated schema, thin client wrapper, typecheck fixture, and README.
  - Gateway OpenAPI customizer now documents `/processes` raw-request query filters: `node`, `app`, and `workspace`.
  - Gateway OpenAPI contract test asserts `/processes` query parameters.
  - Product tech-stack docs now name `packages/sdk-typescript` as the generated public TypeScript client surface for macOS/Tauri and TanStack callers.
  - `.orbit/evidence/typescript-sdk-public-openapi-summary.json` records 159 input operations, 118 public output operations, 91 output paths, 22 included public schema-only operations, and 41 removed internal/deferred operations.
- Rejected or already-covered signals:
  - `@hey-api/openapi-ts` pivot was not needed; path-based `openapi-fetch` methods met the current client ergonomics and typecheck proof.
  - Root JavaScript workspace creation was not introduced; existing repo layout uses package-local JavaScript dependencies.
- Deferred follow-ups:
  - macOS UI integration remains after the generated TypeScript SDK package is verified.
  - Dedicated PHP SDK request classes for Slice 4 public follow-up rows remain a separate PHP SDK follow-up.
  - Consider adding the new TypeScript package to a future root quality fan-out once the repository decides on a broader JavaScript workspace/package policy.
