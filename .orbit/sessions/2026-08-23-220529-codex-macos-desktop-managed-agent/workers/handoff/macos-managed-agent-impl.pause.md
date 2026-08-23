# Pause: macos-managed-agent-impl

status=blocked
native-tip=62a86811020018af99af37fc065e92416a7d90c5
dirty=false
branch=codex/macos-desktop-managed-agent
worktree=/Users/nckrtl/orbit/.worktrees/codex-macos-desktop-managed-agent

preserved-commits:
- 5d2534ec59335954416bd858aa6efcfb7e81f2d6 Make Orbit Desktop own the macOS Agent lifetime
- 1472ac2bd31c68138c6e60b296b2d3b10115cdea Verify desktop updater signatures before install
- 62a86811020018af99af37fc065e92416a7d90c5 Embed the Tauri build-time updater pubkey

base-dependency=retained-incus CLI `update:all` Agent restart after Desktop migrates canonical `dev.orbit.agent`
defect=After Desktop bootouts the LaunchAgent, `LocalFleetUpdateInstallCliAction` `restart_agent_service_if_present` sees `systemctl=missing` and no launchd unit, fails with `agent_service_missing_bootstrap_required`, and never writes the pending desktop handoff.
do-not-add=apps/cli
failed-gate=.orbit/quality-gates/profiles/2026-08-23T18-08-35Z-62a868110200
failed-log=.orbit/quality-gates/profiles/2026-08-23T18-08-35Z-62a868110200/cli_pest.log
narrow-rerun=`bin/orbit-cli-pest --compact tests/Feature/InternalFleetUpdateInstallCliCommandTest.php` (3 failures, same as the gate)
classification=cross-slice product defect in CLI restart/handoff; not a native 62a source defect
host-macos-leftover=stopped exact debug Desktop pid 99503 and Agent child 99607 via parent SIGKILL then bounded child TERM/KILL; launchd unrestored; dummy pending-desktop-update.json and desktop-cccccccctest.tar.gz removed

request=land a separate retained-topology CLI correction, then rebase this native branch
