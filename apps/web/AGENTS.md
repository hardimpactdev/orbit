# Orbit web

`apps/web` is the static single-page app that the Gateway serves at `https://gateway.orbit`. It reads the Gateway API and follows realtime events. It has no server and keeps no state of its own. Read this directory's [README](README.md) and the [web app reference](../../docs/reference/web-app.md) before you change it.

- Call the Gateway only through `src/api`. Each action sends the same request that the matching CLI command sends.
- Regenerate `src/api/schema.d.ts` with `bun run types` after `docs/openapi.json` changes. It is an ignored build output. The web check and build scripts generate it. Install Gateway Composer dependencies first to build OpenAPI. Never edit or commit it by hand.
- Keep demo fixtures under `fixtures/fleet` in the recorded Gateway fixture format. `bin/api-fixtures --check` validates them.
- Browser tests must never write tracked files. Do not save screenshots from them.
- A UI change follows [web verification](../../docs/reference/web-verification.md): attach phone and desktop screenshots from `bin/web-verify`.

## Checks

From this directory, run:

```bash
bun install --frozen-lockfile
bun run check
bun run test
bun run build
```

The tests need Playwright's Chromium and WebKit. Install them once with `bunx playwright install webkit chromium`. Review changed screens in `tests/browser/expected`, and accept them with `bun run test:browser -- -u`.
