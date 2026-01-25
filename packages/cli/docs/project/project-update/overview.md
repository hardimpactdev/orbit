# project:update overview

Updates an existing project by pulling latest code, installing dependencies, building assets, running migrations, and refreshing Caddy.

- Resolves the project path from an explicit path or --project name, prompting if interactive.
- Validates directory existence and git repository presence.
- Optionally runs git pull unless --no-git is set.
- Installs Composer dependencies when composer.json exists and --no-deps is not set.
- Installs Node dependencies when package.json exists and --no-deps is not set, using detected package manager.
- Runs asset build when a build script exists in package.json.
- Runs migrations when artisan exists and --no-migrate is not set.
- Configures trusted proxies for Laravel 11+ apps (edits bootstrap/app.php).
- Regenerates and reloads Caddy configuration.

Failure and recovery paths

- Git pull failures stop the flow early with an error response.
- Dependency/build/migration failures are captured in step results but do not automatically abort.
- Exceptions return an error exit code and JSON error when requested.

Inputs and options

- path (optional) or --project name
- --no-git, --no-deps, --no-migrate, --json

Key integrations

- git for pulling updates
- composer, bun/pnpm/yarn/npm for dependencies/builds
- php artisan migrate
- Caddy config generation and reload
