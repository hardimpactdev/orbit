# `orbit node role:update [node] [role]`

[Back to Nodes commands.](../README.md)

Update the desired settings for one role assignment.

## Usage

You can run this command to change the role-local settings of an active
role assignment.

```bash
orbit node role:update [node] [role] [--tld=] [--json]
```

## Behavior

This command rewrites desired role settings and re-runs convergence for the target node.

- `gateway` cannot be updated through this command.
- Updating role settings triggers convergence again.
- Updating settings on the `agent` role (for example changing the role's
  `tld`) requires the `node:update` permission on the caller's grant to
  the target node. The default agent self-grant does not include
  `node:update`, so an node cannot rewrite its own role settings from
  its own CLI; an operator with the required permission must perform that
  change.
- Human output shows progress while convergence runs.

## Technical Contract

See [technical contract](technical/1_node-role-update.md).
