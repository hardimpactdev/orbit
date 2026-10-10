# Orbit desktop

`apps/desktop` is a Tauri 2 window around the web app in `apps/web`. Read the [desktop app reference](../../docs/reference/desktop-app.md) before you change it, and update that page in the same change.

- Keep fleet screens and actions in `apps/web`. The shell adds only what a browser page cannot do.
- The release build loads `https://gateway.orbit`. Do not bundle the web app or proxy the API in the shell.
- Add a Tauri command or permission only with the native feature that needs it, and document it.
- Commit `bun.lock` and `src-tauri/Cargo.lock` changes with dependency updates. Never commit `node_modules` or `src-tauri/target`.

## Checks

The app has no tests and no CI job. Before you submit a change, confirm that `bun run dev` opens the window against the web app's dev server.
