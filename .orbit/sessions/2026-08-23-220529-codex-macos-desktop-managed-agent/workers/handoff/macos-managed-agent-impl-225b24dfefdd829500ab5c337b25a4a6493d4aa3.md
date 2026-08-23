# Impl handoff: macos-managed-agent-impl

candidate=225b24dfefdd829500ab5c337b25a4a6493d4aa3
dirty=false
base=732e61be405b88c36be05aec3379f4bf6abfa1a2
branch=codex/macos-desktop-managed-agent
venue=host-macos
quality-check=.orbit/quality-gates/profiles/2026-08-23T19-23-50Z-225b24dfefdd
proof-receipt=ok

preserved-rebase:
- 93f758e1f Make Orbit Desktop own the macOS Agent lifetime
- e312e6712 Verify desktop updater signatures before install
- 8013f8268 Embed the Tauri build-time updater pubkey
- 225b24dfe Align Agent restart docs with Desktop handoff deferral

node-concepts conflict: kept native Desktop lifecycle ownership plus CLI Desktop-handoff Agent restart deferral.

runtime: Desktop 95094 owned Agent 95189; canonical launchd migrated; hide left the child running; parent stop left no listener; autostart Orbit Desktop.plist RunAtLoad true; invalid updater signatures rejected; Mini restored with Desktop 95343 / Agent 95382.

evidence=`.orbit/evidence/macos-desktop-managed-agent/lifetime-proof.txt`

Retained topology proof: passed - host topology kind=host-macos; host=mini; os=Darwin 27.0.0 arm64 / macOS 27.0 26A5416b; command=`cd apps/macos && cargo run --bin orbit-macos`; evidence=`.orbit/evidence/macos-desktop-managed-agent/lifetime-proof.txt`
