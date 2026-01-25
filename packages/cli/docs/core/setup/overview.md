# setup overview

Platform-aware setup command that configures Orbit for Mac or Linux with specified TLD and PHP versions.

- Auto-detects platform (Darwin/Linux)
- Delegates to MacSetup or LinuxSetup classes
- Installs and configures PHP-FPM, Caddy, Docker services
- Sets up TLD resolver and certificates

Failure and recovery paths

- Fails on unsupported platforms
- Platform-specific setup handles own error cases

Inputs and options

- --tld: TLD for local development (default: test)
- --php-versions: Comma-separated PHP versions (default: 8.4,8.5)
- --skip-docker: Skip Docker/OrbStack installation
- --json: Output progress as JSON

Key integrations

- MacSetup/LinuxSetup for platform-specific configuration
- ConfigManager, DockerManager, CaddyManager
- PhpManager, HorizonManager
- PlatformService for OS detection
