# stop overview

Stops all Orbit services in reverse order of start.

- Detects PHP-FPM sockets on the host
- Stops Horizon first
- Stops host Caddy
- Stops all Docker services (dns, reverb, postgres, redis, mailpit)
- Note: Does NOT stop PHP-FPM pools (keeps them for other projects)

Failure and recovery paths

- Individual service failures are tracked
- Returns status for each service

Inputs and options

- --json: Output as JSON

Key integrations

- ServiceManager for Docker services
- CaddyManager for reverse proxy
- HorizonManager for queue processing
- PhpManager for architecture detection
