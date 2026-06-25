# `orbit process:edit [name]`

<!-- command-status: reserved -->

[Back to Process commands.](../README.md)

`process:edit` is a backward-compatible alias for
[`process:update`](../2_process-update/process-update.md).

Existing scripts may continue to call `process:edit` during the compatibility
window. New documentation, examples, and automation should use
`process:update`.

The alias accepts the same arguments and options as `process:update`, including
`--name=<new-slug>` for supported process identity rename paths and `--json`
for the machine-readable renderer. Human output may include deprecation copy;
JSON output keeps the same envelope and payload as `process:update` so scripted
callers can migrate command names without changing parsers.

***

**Technical Contract:** [`technical/1_process-edit.md`](technical/1_process-edit.md)
