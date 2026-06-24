# In-memory tests

Use in-memory Pest tests for ordinary development:

```bash
composer test
```

This lane covers deterministic command behavior, service behavior, database
state, renderers, DTOs, authorization branches, JSON envelopes, and command
contracts. It must not require real SSH, Incus mutation, Docker host pools, or
standing infrastructure.

Fake process, gateway, provider, and transport boundaries in Pest when the
behavior is a command or contract concern. Move to E2E only when the assertion
would be false confidence without a prepared topology, a real VM, or real
host-level mutation.

## Focused commands

Use focused files and filters before escalating to the full default suite.

```bash
bin/orbit-gateway-pest --compact tests/Feature/Commands/Nodes/NodeListCommandTest.php
bin/orbit-gateway-pest --compact --filter='lists nodes'
bin/orbit-gateway-vendor-bin pint --dirty --format agent
```

The default Composer test lane excludes `e2e` and `slow` groups from the
gateway suite. It excludes `slow` from the CLI suite. It uses Pest parallel
mode for the gateway and compact output across the gateway, CLI, docs app, and
packages.

CLI tests belong in the `slow` group when they allocate a real
pseudo-terminal, fork a progress ticker, or wait on real time. Run them through
`composer test:slow` or an explicit focused CLI Pest command.
