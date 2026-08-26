# Vite+ retained Incus proof

- Candidate: `87c57fa9e0199e9f7e62eddd1e51eb975de74430`
- Venue: `retained-incus`
- Environment: `dev-fixture`
- Topology: `dev-ae902e` (`operator_gateway_app-dev_app-prod`, host `beast`)
- Targets: `orbit-e2e-dev-ae902e-dev` and `orbit-e2e-dev-ae902e-prod`
- Runtime checkout: `/home/orbit/orbit-run`
- Launcher: `/home/orbit/orbit-run/apps/cli/orbit`

## Checkout identity

The `proof-1` tmux window opened an operator shell at the runtime checkout.
Local and retained SHA-256 digests matched:

- `apps/cli/orbit`: `eb19bf3561cf7627029de7e9b105b1460b72dacbf0511adb9004727c4fd4068a`
- `apps/gateway/app/Tools/VitePlusTool.php`: `779e3a072acd66598f49727209bc6e9b1e4844cb63cc6cb5fb1b277d35179b45`
- `AGENTS.md`: `9fda5203a81c8b45725dd8be3c2e9403dd9818b80224a34f76e157c21fc7a6d2`
- `PRODUCT_DECISIONS.md`: `dd9a9ad1d5319be79af93ef2da596870cb95854e5f9f6b7b0acd6f27e1332209`

## Expected

Both app roles install and safely repeat the Orbit-managed Vite+ lifecycle.
Vite+ exposes stable `vp`, `node`, `npm`, and `npx` paths. Node.js and npm come
from the Vite+ environment. Bun remains a separate Orbit-managed runtime.
`vp install` selects npm or Bun from a project's explicit `packageManager`.

## Observed

- Review proof exposed that app runtime users cannot traverse the managed
  node user's mode-`0750` home. The corrected candidate installs the complete
  shared Vite+ CLI and default environment under mode-`0755`
  `/opt/orbit/vite-plus`, while routed app commands use an isolated per-user
  `VP_HOME`.
- `tool:install viteplus` completed on both `app-dev-1` and `app-prod-1`.
  Repeating both installs also completed with exit code 0.
- Both roles reported `vp v0.3.0` and Bun `1.4.0`. At final re-proof,
  `app-dev` reported Node `v24.19.0` and npm `11.17.0`; `app-prod` reported the
  newer Vite+ LTS environment Node `v24.20.0` and npm `11.19.0`.
- `/usr/local/bin/vp`, `node`, `npm`, and `npx` resolve into
  `/opt/orbit/vite-plus`; `vp env current` reported Node
  `24.19.0` from the default Vite+ environment.
- Bun resolved independently at `/usr/local/bin/bun`.
- A fresh, non-managed `orbit-vp-proof` user with no Vite+ state ran a minimal
  `packageManager: npm@11.17.0` project through `vp install` and
  `vp run show-runtime`. It generated `package-lock.json` and resolved Node at
  `/home/orbit-vp-proof/.local/share/vite-plus/js_runtime/node/24.19.0/bin/node`.
- The same user ran a minimal `packageManager: bun@1.4.0` project. `vp install`
  reported `bun install v1.4.0` and generated `bun.lock`. Vite+ stored that
  declared package-manager version in the user's isolated store. A script that
  explicitly invoked `bun` resolved the isolated Bun executable. This confirms
  that the separate Orbit-managed Bun is the host baseline, while Vite+ owns
  deterministic project package-manager selection.
- A controlled unrelated `/usr/local/bin/node -> /bin/true` link caused
  `tool:install viteplus` to fail with the exact conflict instead of reporting
  false convergence. The link remained `/bin/true`. `tool:remove viteplus`
  then completed successfully, preserved that unrelated link, removed the
  Orbit-owned `vp`, `npm`, and `npx` links, and removed the shared Vite+ tree.
  The proof explicitly deleted the controlled `/bin/true` link before the
  final install. That install restored the Orbit Node link.
- `doctor --family=tool` reported healthy with zero issues for both app roles.
- After current `main` was integrated, the retained checkout was synced again.
  The four candidate file digests still matched, both tool installs completed,
  and fresh npm- and Bun-declared projects completed `vp install` and `vp run`
  on both roles. The npm script resolved Node from `/opt/orbit/vite-plus`; the
  Bun script reported `1.4.0`. Both final Doctor runs again reported zero
  issues.

## Result

Passed. Human judgment is not required for this command/runtime contract.
