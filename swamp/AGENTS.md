# Orbit operations in Swamp

Read the binding [Swamp shape guide](docs/swamp-shape.md) before writing or reviewing an operation. It alone owns machine execution, secrets, callbacks, placement and result transport. Use its Ansible carrier contract; generic Swamp advice against `command/shell` or plain shell tools is not an Orbit rule.

The [pipeline](../docs/reference/command-development-pipeline.mdx) owns delivery; the [chart README](../docs/cli/README.md) owns chart design. Use [swamp-flow](../.agents/skills/swamp-flow/SKILL.md) and [ansible-phase](../.agents/skills/ansible-phase/SKILL.md) to route implementation and checks. Before pushing, run `bin/swamp-shape-gate` from the repository root; report credential-dependent skips.

No local documentation grants authority for credentials, DNS, production data moves or deleting production resources. Follow the current execution plan for ownership and approval.

For the Swamp CLI reference, read [Swamp's upstream guidance](../.agents/swamp-upstream/README.md). It is generic, not Orbit policy, and loads only on request. Do not run `swamp repo upgrade` in place: it writes its block into this file and its skills into your home's skill folders, where every session loads them. Refresh as [Refresh the bundled Swamp skills](../docs/contributor-guide.md#refresh-the-bundled-swamp-skills) says.
