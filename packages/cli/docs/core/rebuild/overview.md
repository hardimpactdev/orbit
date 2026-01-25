# rebuild overview

Rebuilds PHP Docker images with latest extensions.

- Checks Docker is running
- Copies Dockerfiles from stubs if missing
- Stops PHP containers
- Rebuilds images with extensions (redis, pcntl, intl, exif, gd, zip, bcmath)
- Starts PHP containers

Failure and recovery paths

- Fails if Docker is not running
- Build failures reported with error details

Inputs and options

- --json: Output as JSON

Key integrations

- DockerManager for container and image operations
- ConfigManager for config path
