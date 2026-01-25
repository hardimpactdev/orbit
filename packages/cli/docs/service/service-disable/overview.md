# service:disable overview

Disables a service so it does not start with orbit start.

- Disables service in services.yaml
- Regenerates docker-compose.yaml

Failure and recovery paths

- Fails if service name not found

Inputs and options

- service (required): Service name to disable
- --json: Output as JSON

Key integrations

- ServiceManager for disable and compose generation
