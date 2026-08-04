# Skill Commands

Skill commands install Orbit's repository-owned agent skill into local user
skill directories for supported LLM tools.

## State Ownership

The skill command domain does not own a state family. Skill commands
mutate only caller-local filesystem state and do not write gateway intent,
fleet state, node runtime state, or extension enablement state.

The source skill is the checked-in `.agents/skills/orbit/` directory from the
Orbit install root. The command copies that directory into the selected target.
Orbit does not verify skill files that live under a caller's home directory
through a doctor family. [`doctor --family=tool`](../3_tool/tool-doctor.md) owns managed tool
binary and capability readiness when an LLM tool is tracked as an Orbit tool,
and [`doctor --family=node`](../1_node/node-doctor.md) owns node runtime
readiness. There is no `doctor --family=skill` contract.

## Domain Rules

These rules constrain all skill commands.

- `skill:*` commands are local-only helper commands. They never call the
  gateway API and never install downloadable extensions.
- Provider slugs are fixed by the command contract. The supported slugs in
  this release are `codex`, `claude`, `antigravity`, and `grok`.
- A known provider without an explicit path resolves to that provider's default
  user skill directory.
- A first positional value that is not a known provider is an explicit target
  path, not an unknown provider error.
- Existing targets are protected. The command fails before mutation unless
  `--force` is present.

## Commands

The skill family has one command in this slice.

1. [`orbit skill:install [provider] [path]`](1_skill-install/skill-install.md)

## Related

- The Orbit skill source lives at `.agents/skills/orbit/SKILL.md`.
- [Extension commands](../21_extension/README.md)
- [`doctor --family=tool`](../3_tool/tool-doctor.md)
- [`doctor --family=node`](../1_node/node-doctor.md)
