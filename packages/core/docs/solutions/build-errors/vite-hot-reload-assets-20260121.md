---
date: 2026-01-21
problem_type: build_error
component: orbit-core/orbit-web integration
severity: moderate
symptoms:
  - "UI changes not appearing after build"
  - "Old styles showing despite successful build"
  - "Changes visible with dev server but not in production"
root_cause: Built assets not published from orbit-core to orbit-web
tags: [vite, build, assets, publishing]
---

# Built Assets Not Published to orbit-web After orbit-core Changes

## Symptom
After making UI changes in orbit-core and running `bun run build`, the changes were not visible when viewing orbit-web.ccc. The build succeeded but the browser showed old styling.

## Investigation
1. Attempted: Checking if Vite dev server was running
   Result: Dev server was running but that's not the issue for production builds

2. Attempted: Rebuilding multiple times
   Result: Build succeeded but changes still not visible

## Root Cause
orbit-core is a Laravel package that orbit-web requires. After building assets in orbit-core, they must be explicitly published to orbit-web's public directory.

## Solution
After building in orbit-core, publish the assets to orbit-web:

```bash
# In orbit-core
cd ~/projects/orbit-core
bun run build

# In orbit-web
cd ~/projects/orbit-web
php artisan vendor:publish --tag=orbit-assets --force
```

This copies the built files from `orbit-core/public/build` to `orbit-web/public/vendor/orbit/build`.

## Prevention
- Always run the publish command after building orbit-core
- Add this to AGENTS.md development workflow
- Consider a build script that combines both steps
- When dev server is running, kill it before testing production build:
  ```bash
  pkill -f "vite" && rm -f /path/to/orbit-core/public/hot
  ```

## Related
- orbit-core architecture follows Laravel Horizon pattern
- Development happens in orbit-core, orbit-web is just a shell
- `/home/nckrtl/workspaces/orbit/AGENTS.md` - Post-Update Workflow section