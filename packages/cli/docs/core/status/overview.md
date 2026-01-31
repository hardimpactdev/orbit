# status overview

Shows Orbit status including all running services and configuration.

- Detects PHP-FPM sockets on the host
- Checks PHP-FPM pools, Caddy, Horizon status
- Gets Docker service statuses via ServiceManager
- Scans sites and counts them
- Shows config path, TLD, default PHP version

Failure and recovery paths

- Always returns SUCCESS (informational command)

Inputs and options

- --json: Output as JSON

Key integrations

- ServiceManager for enabled services
- DockerManager for container statuses
- PhpManager for PHP-FPM status
- CaddyManager for Caddy status
- HorizonManager for queue status
- SiteScanner for site count
- ConfigManager for configuration info
