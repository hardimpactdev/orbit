# upgrade overview

Upgrades Orbit CLI to the latest version from GitHub releases and runs all necessary post-upgrade tasks automatically.

- Fetches latest release info from GitHub API
- Compares versions to check if update available
- Downloads new PHAR binary using Laravel Zero's phar-updater
- Validates and replaces current binary
- Launches new binary with post-upgrade tasks

Post-upgrade tasks (run automatically)

- Database migrations (`db:migrate`)
- Web dashboard update (`web:install --force`)
- Service configuration regeneration (docker-compose.yml)
- Service restart

Failure and recovery paths

- Only works when running as compiled PHAR
- Creates backup before replacement
- Restores backup if replacement fails
- Uses `pcntl_exec` to launch new binary for post-upgrade tasks

Inputs and options

- --check: Only check for updates without installing
- --post-upgrade: Run post-upgrade tasks only (internal use)
- --json: Output as JSON

Key integrations

- GitHub API for release info
- Laravel Zero phar-updater for binary replacement
- ServiceManager for docker-compose regeneration
- web:install for dashboard updates
