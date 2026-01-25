# init overview

First-time setup command that creates config directory, pulls Docker images, sets up DNS, and initializes all services.

- Checks and optionally installs prerequisites (PHP, Docker, Composer, dig)
- Creates directory structure under ~/.config/orbit
- Copies stub configuration files
- Installs companion web app with SQLite database
- Generates Caddyfile and dnsmasq configuration
- Initializes services.yaml and generates docker-compose.yaml
- Creates Docker network, configures /etc/hosts and DNS
- Builds DNS and PHP containers, pulls service images
- Starts all services
- Installs composer-link plugin globally

Failure and recovery paths

- Fails if Docker is not running
- Prerequisite installation can be skipped or retried
- DNS configuration failures are warnings (continues anyway)

Inputs and options

- --yes: Skip all confirmation prompts
- --skip-prerequisites: Skip prerequisite checks

Key integrations

- PlatformService for OS detection and prerequisites
- DockerManager for containers and images
- ConfigManager for config files
- CaddyfileGenerator for reverse proxy config
- ServiceManager for docker-compose generation
