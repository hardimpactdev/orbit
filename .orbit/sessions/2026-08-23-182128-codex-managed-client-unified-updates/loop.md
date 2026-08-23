# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-codex-managed-client-unified-updates
- Worktree: /home/nckrtl/orbit/.worktrees/codex-managed-client-unified-updates
- Branch: codex/managed-client-unified-updates

## Goal

`orbit update:all` carries one immutable desktop/Agent/CLI update identity,
targets roleless `managed=true` clients, skips an unreachable managed macOS
desktop before mutation, and reports actionable Agent-unavailable behavior.

## Scope

- Owned: gateway and CLI update contracts, release-manifest and candidate tooling, product docs, focused tests, and the narrow retained-Incus Valkey fixture-preparation repair required to produce runtime proof; primitive=managed client unified update; transitions=success:reachable managed client verifies the selected artifacts|failure:post-mutation error fails the target|retry:rerun uses the immutable or newly resolved plan|stop-restart:unreachable managed macOS desktop is skipped before mutation|stale:mismatched staged or installed identity is rejected
- Constraints: preserve caller-local de-duplication, existing role-bearing target behavior, gateway authorization, immutable plan identity, owner-user paths, no SSH fallback, and no human-only E2E execution. Producers: node `managed` state, Agent eligibility, release manifest resolver/generator, and immutable update plan. Consumers: target selection, version checks, artifact relay, workload updater, local installer, final verifier, CLI renderers, release candidate tooling, and product docs. Dangerous invariants: unmanaged roleless clients stay excluded; skips occur only before side effects; caller is never duplicated; a started mutation cannot be relabeled skipped; desktop, Agent, and CLI artifacts bind one version/build.
- Out of scope: native `apps/macos` lifecycle/menu implementation, login-item behavior, native app installation, Mini UI proof, live `NMBP P` mutation, version bump, and GitHub release publication.

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Feature/Services/Operations/WorkloadNodeUpdaterTest.php` (33 passed); `ReleaseManifestDesktopArtifactTest`, `AgentUnreachablePlatformMetaTest`, `FleetUpdatePreMutationSkipRegistryTest`, `DeployManagerContainerRoutingTest` Agent-reachability, `ToolLifecycleControllerTest` logs platform; `bin/orbit-cli-pest` desktop stage/unsafe-path and skipped-marker renderer tests; focused Mago analyze/format/lint on changed gateway, CLI, and core PHP
  - broader: passed - `composer quality-check` on clean HEAD `1a83f161ea28bf0c50df88622a8b813d5799089a` (`git.dirty=false`) exited 0 in 133s; artifact `.orbit/quality-gates/quality-check-2026-08-23T160355Z-5f3bd1ec2f36.json`
  - runtime: passed - candidate=1a83f161ea28bf0c50df88622a8b813d5799089a; venue=retained-incus; environment=dev-fixture; target=retained topology dev-669dc2 operator_gateway_agent; expected=managed roleless selection, pre-mutation desktop-offline skip, public CLI remediation, and immutable handoff validation; observed=managed roleless Mac selected while unmanaged peer stayed outside the set, unavailable managed Mac skipped with orbit_desktop_not_running before installed identity changed, JSON/stream/human CLI returned orbit_agent_unavailable with Desktop remediation, owner-only handoff matched one automatic operation/version/build and stale_build_rejected=true; result=passed; evidence=`.orbit/evidence/managed-client-unified-updates-retained-incus.txt`
- Blast radius: complete - evidence=reviewer bounded repository-wide searches for node.agent_unreachable, agent_push_unavailable, desktop artifact construction, staged-path validation, desktop-offline constants, target_version callers, and prepare_prerequisites; result=all affected surfaces resolved with only non-blocking polish observations
- Review: passed - general reviewer confirmed D1-D5 closed and correction delta sound - human-judgment=not-required
- Reviewed feature tip: 1a83f161ea28bf0c50df88622a8b813d5799089a
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 1a83f161ea28bf0c50df88622a8b813d5799089a
- Accepted main tip: aa03ff60ff4d0b70ce7d47c042b875b3087fbd16

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
