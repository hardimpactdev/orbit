# Orbit Feature Slice

- Slice: 01-managed-viteplus-runtime
- Depends on: none

## Outcome

Converging an `app-dev` or `app-prod` role installs and verifies Vite+, a
Vite+-managed LTS Node.js/npm toolchain, and the existing independently managed
Bun runtime on Linux and macOS.

## Scope

- Included: VitePlus install/update/remove/safe-adopt behavior; stable host
  `vp`, `node`, `npm`, and `npx` entry points; app role baseline membership;
  focused gateway tests; tool catalog, node-role, and product-decision docs.
- Excluded: repository package scripts and project `packageManager` declarations;
  external app repository migration; direct package publication commands.

## Authority

- Decisions: newest VitePlus/Node/npm/Bun entry in `PRODUCT_DECISIONS.md` and
  the 2026-08-26 app development setup defaults decision.
- Product docs: `apps/docs/content/domains/3_tool/catalog/viteplus.md`,
  `apps/docs/content/domains/3_tool/catalog/bun.md`, and applicable node-role
  baseline docs.

## Proof

- Focused: gateway Pest coverage for VitePlus lifecycle commands, platform
  support, role convergence order, idempotence, safe adoption, and host entry
  points; focused Mago checks for changed PHP.
