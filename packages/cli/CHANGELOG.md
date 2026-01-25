# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Changed
- **Unified Web Dashboard**: Replaced the legacy bundled web app with a new unified dashboard powered by `orbit-web`.
- **Bundle-based Installation**: The web app is now installed from a pre-built bundle (`stubs/orbit-web-bundle.tar.gz`) instead of being included as source in the PHAR.

### Removed
- Legacy `web/` source directory from `orbit-cli` repository.
- Direct execution of web app from CLI source.

### Fixed
- Improved Reverb WebSocket authorization and channel persistence.
- Unified database sharing between CLI and Web interfaces.
