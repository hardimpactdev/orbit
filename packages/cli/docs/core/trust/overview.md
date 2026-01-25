# trust overview

Installs Caddy root CA certificate to trust local HTTPS certificates on macOS.

- Extracts root CA from Caddy container
- Adds to macOS System Keychain (requires sudo)
- Sites will show as secure after browser restart

Failure and recovery paths

- Fails if Caddy container is not running
- Fails if certificate extraction fails
- Requires password for Keychain access

Inputs and options

- None

Key integrations

- DockerManager for container status
- macOS security command for Keychain
