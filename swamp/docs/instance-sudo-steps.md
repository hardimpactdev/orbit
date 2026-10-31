# Instance app steps

Follow the binding [Swamp shape guide](swamp-shape.md). Each app action is a
placed `command/shell` step. The Gateway supplies full non-secret intent; the
account comes from that admitted intent, never caller-selected input.

Production actions run as `sudo -n -u orbit-app-<ProjectID>` in the admitted
release. Development actions run as the configured Node account that owns the
checkout. Preserve the admitted path, ownership and release identity checks.

Application script text is visible in the workflow or behavior definition, or
delivered as a file through Swamp's supported dispatch path. It is not embedded
in TypeScript or fetched through a private callback. Scripts are linted with
shellcheck or ruff.

Resolve secrets with `vault.get` into the command environment. Pass them to
tools through stdin or a config file, never arguments. Public step metadata
belongs in workflow inputs; it must not be hidden on a private-material
endpoint.

Ordering, guards, tolerated failures, timeout and recovery belong to Swamp. The
pilot settles exact script delivery and environment syntax; this page does not
introduce another runner API.

[Source removal policy](../extensions/behaviors/operations/instance-lifecycle/destroy-system.md)
preserves unpushed work and the identity checks that force cannot skip.
