# Deprecated Solo Entry Point

This file is deprecated.

Use `solo-orchestration/README.md` and execute
`solo-orchestration/kickstarter.md` directly from the current coordinator
session.

Do not give this file to a Solo agent, and do not spawn a dedicated
kickstarter agent. The kickstarter is a one-off procedure for the current
coordinator to run. The long-running Solo roles are:

- orchestrator;
- tailer;
- loop improver.

The current loop source of truth lives entirely in `solo-orchestration/`.
