# Signal: Retained Incus `--sync` Leaves Gateway Container Serving Stale Code

Status: guarded
First seen: 2026-06-25
Last seen: 2026-06-25
Last reviewed: 2026-06-25
Source worktree: fleet-doctor-node-progress
Source commit: 58ffe687
Signal type: e2e-failure
Guardrail target: .agents/skills/implementing-features/SKILL.md (Retained Incus Inspection Gate)
Guardrail change: 58ffe687 follow-up — post-`--sync` gateway-container restart step
Related signals: 2026-06-23-e2e-substrate-failure-triage.md
Superseded by: none
Tags: e2e, incus, retained-topology, sync, gateway, substrate

## Signal

During retained-Incus re-verification of a gateway change, `composer e2e:incus
-- --sync --id=<id>` was used to push fresh worktree code into a source-mounted
topology. After the sync, every gateway call returned `gateway_unavailable
(HTTP 500)` — including single-node `orbit doctor`, a path the change never
touched. `php artisan tinker` invoking the same service in a fresh process
worked fine, proving the synced code was correct. The gateway API is served by
a long-running `php -S` process inside the `orbit-gateway-e2e-topology-lease-http`
Docker container, which kept the pre-sync code loaded. `docker restart` of the
gateway lease containers (`...-lease-http` and `...-lease-tls`) cleared the 500
and restored normal behavior.

## Prior Occurrences

Adjacent to 2026-06-23-e2e-substrate-failure-triage.md (treat substrate vs
product before code blame), but that record does not name the concrete
post-`--sync` stale-container reload step. First explicit occurrence of this
specific failure mode.

## Missing Guardrail

The retained-Incus gate documented `--sync` as a one-way file refresh but did
not say the long-running gateway API container must be restarted to serve the
new code. An agent re-verifying after `--sync` could see a 500 on an unrelated
gateway path and misdiagnose it as a regression from their own change.

## Guardrail Change

Added a post-`--sync` note to the Retained Incus Inspection Gate in
`.agents/skills/implementing-features/SKILL.md`: `--sync` refreshes files but not
the running gateway container; restart `orbit-gateway-e2e-topology-lease-http`
and `...-lease-tls` before re-verifying; a 500 on an unrelated gateway path
right after `--sync` is a stale-container signal, not a code defect.

## Verification

Reproduced in this loop: post-`--sync` single-node + fleet `doctor` both 500;
`docker restart` of the two gateway lease containers → doctor returned normal
`drift_detected` and the fleet `--stream-json` per-node progress proof ran
cleanly. The guardrail text is reachable from the exact `--sync` step agents
follow during retained-Incus re-verification.

## Reappearance Check

If a gateway call 500s immediately after `composer e2e:incus --sync`, restart
the gateway lease containers on the gateway VM first, then re-test. Only after a
restart still 500s should the change itself be suspected. Stronger fix
(follow-up): make `--sync` restart the gateway lease container automatically.

## Curation Notes

Separate from 2026-06-23-e2e-substrate-failure-triage.md: that record is the
general "classify substrate before code" stance; this one names the specific
`--sync` stale-container mechanism and the exact restart command. Keep both.
