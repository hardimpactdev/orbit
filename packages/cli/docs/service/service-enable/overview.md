# service:enable overview

Enables a service so it starts with orbit start.

- Enables service in services.yaml
- Regenerates docker-compose.yaml

Failure and recovery paths

- Fails if service name not found

Inputs and options

- service (required): Service name to enable
- --json: Output as JSON

Key integrations

- ServiceManager for enable and compose generation
