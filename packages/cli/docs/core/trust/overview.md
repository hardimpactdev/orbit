# trust overview

Installs Caddy root CA certificate to trust local HTTPS certificates on macOS.

- Uses root CA from host Caddy data directory
- Adds to macOS System Keychain (requires sudo)
- Sites will show as secure after browser restart

Failure and recovery paths

- Fails if Caddy has not generated a root CA yet
- Requires password for Keychain access

Inputs and options

- None

Key integrations

- macOS security command for Keychain
