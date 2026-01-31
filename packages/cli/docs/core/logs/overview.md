# logs overview

Tails container logs for debugging.

- Shows logs for specified container
- Follows log output by default (like tail -f)
- Press Ctrl+C to stop following

Failure and recovery paths

- Container must exist and have logs

Inputs and options

- container (required): Container name (e.g., orbit-reverb, orbit-redis, orbit-postgres)
- --no-follow: Do not follow log output

Note: Caddy runs on the host via systemd, not in Docker. Use `sudo journalctl -u caddy -f` for Caddy logs.

Key integrations

- DockerManager for log streaming
