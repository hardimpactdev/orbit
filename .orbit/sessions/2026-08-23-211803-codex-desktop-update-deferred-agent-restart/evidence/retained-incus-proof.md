# Retained Incus proof

- Candidate: `9384291231ad867577a7ff9dddb5a7621544b612`
- Topology-proven install-script bytes: `c8235c577ae00551257d777e66292b0e8ec768d5`
- Topology: `dev-61956a` (`operator_gateway_app-dev`, Incus host `beast`)
- Inspected instance: `orbit-e2e-dev-61956a-operator`
- Runtime checkout: `/home/orbit/orbit-run`
- Launcher: `/usr/local/bin/orbit` resolved to `/home/orbit/orbit-run/apps/cli/orbit`
- Proof window: `feat-codex-desktop-update-deferred-agent-restart:proof-1` on Mini

This candidate is a clean rebase of the prior docs-and-CLI tip onto
`848dc136a86cd0a9dd6fe3a8b8b10cccab982a15`. `.orbit/evidence/retained-incus-proof.out`
is the original run at the topology-proven install-script bytes; the proof
script was retargeted to this candidate without re-running. The two
install-script PHP files keep the topology-proven hashes
`929c625263b31612a1effb42f82da37d77fb049aee2ef6efbed2e3a43afccfd0` and
`154024e63b75fee0b703832d1c7fd7ee431dd906e29aa08f090778cd3c307d4c`.

The original proof matched those production hashes before it ran the internal
fleet-update install command through the real CLI launcher.

The Desktop-handoff case used writable isolated install paths and fake service
commands that would record any systemd or launchd access. It installed and
verified the CLI and Agent, staged the Desktop archive, wrote the handoff with
mode `0600`, preserved the Agent/CLI/Desktop hashes and handoff identity, emitted
`defer_agent_restart_to_desktop`, and made no service-manager call.

The standalone case omitted the Desktop handoff. It exercised the same real CLI
command and confirmed the existing systemd probe and restart path still ran.

Raw terminal result:

```text
RETAINED_PROOF candidate=c8235c577ae00551257d777e66292b0e8ec768d5 topology=dev-61956a instance=orbit-e2e-dev-61956a-operator launcher=/home/orbit/orbit-run/apps/cli/orbit desktop_handoff=passed standalone_restart=passed
```

Artifacts:

- `.orbit/evidence/retained-incus-proof.sh`
- `.orbit/evidence/retained-incus-driver.sh`
- `.orbit/evidence/retained-incus-proof.out`
