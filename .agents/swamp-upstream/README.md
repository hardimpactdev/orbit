# Swamp's upstream guidance

Swamp writes these files for the pinned runtime in `swamp/.swamp.yaml`. They are kept word for word, so that a later refresh can be compared with them, and outside `.agents/skills` and every `AGENTS.md`, so that no client loads them by itself. Orbit's rules in [swamp/AGENTS.md](../../swamp/AGENTS.md) and the [Swamp shape guide](../../swamp/docs/swamp-shape.md) win where they differ.

- [managed-section.md](managed-section.md): the block that `swamp repo upgrade` writes into `swamp/AGENTS.md`.
- [skills/swamp](skills/swamp/SKILL.md): the Swamp CLI reference, with a routing table to its guides.
- [skills/swamp-getting-started](skills/swamp-getting-started/SKILL.md): Swamp's onboarding for new users.
- [swamp-source.json](swamp-source.json): the upstream commit and the SHA-256 of each skill file.

[Refresh the bundled Swamp skills](../../docs/contributor-guide.md#refresh-the-bundled-swamp-skills) updates them.
