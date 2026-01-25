# ensure overview

Ensures all required Orbit services are running, starting any that are stopped.

- Checks Docker is running
- Verifies required containers (dns, caddy, redis, reverb)
- Calls start if any containers are not running
- Verifies Horizon container separately

Failure and recovery paths

- Skips if Docker is not running
- Attempts to start stopped services automatically

Inputs and options

- --json: Output as JSON

Key integrations

- DockerManager for container status checks
- start command for starting services
