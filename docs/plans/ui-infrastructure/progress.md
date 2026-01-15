# UI Infrastructure - Progress Log

## Codebase Patterns

### State Management (Pinia)
- Use Pinia for global application state.
- Use `pinia-plugin-persistedstate` for state that needs to survive page reloads (e.g., user preferences, selected environment).
- Pinia is configured in `resources/js/app.ts`.

## Iteration Log

### 2026-01-15 - Install Pinia and persistence plugin (launchpad-desktop-3w4)
**Status:** Complete
**Files changed:**
- package.json
- resources/js/app.ts

**Learnings:**
- Pinia and `pinia-plugin-persistedstate` work well with Inertia apps.
- Need to ensure `pinia` is used in the `createApp` chain in `setup` function of `createInertiaApp`.

**Verification results:**
- package.json includes pinia and pinia-plugin-persistedstate ✓
- App setup file imports and configures pinia with persistence ✓
- npm run typecheck -> exits 0 ✓

### 2026-01-15 - Create Echo composable for Reverb (launchpad-desktop-ghw)
**Status:** Complete
**Files changed:**
- resources/js/composables/useEcho.ts

**Learnings:**
- `laravel-echo` requires a generic type argument (e.g., `Echo<'reverb'>`) in this TypeScript environment.
- Reverb connections use the `launchpad.{tld}` host pattern on port 8080.

**Verification results:**
- resources/js/composables/useEcho.ts created ✓
- Exports useEcho with connect, disconnect, getEcho, isConnected ✓
- Configures Echo for Reverb with correct host pattern ✓
- npm run typecheck (via bun) -> exits 0 ✓
