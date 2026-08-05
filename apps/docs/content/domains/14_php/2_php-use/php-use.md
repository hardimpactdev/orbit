# `orbit php:use [version]`

Change the PHP image version used by an instance or workspace runtime container.

## Usage

```bash
orbit php:use [version] [--instance=<app.instance>] [--workspace=<workspace>] [--node=<node>] [--inherit] [--cli] [--json]
```

## Examples

```bash
orbit php:use 8.5 --instance=docs
orbit php:use 8.4 --instance=docs --workspace=feature-docs
orbit php:use --instance=docs --workspace=feature-docs --inherit
orbit php:use 8.5 --node=app-1 --cli
orbit php:use 8.5 --instance=docs --json
```

## Arguments and options

- `version`: PHP version to select. Required unless `--inherit` is supplied.
- `--instance=<app.instance>`: Select the concrete instance whose parent
  project's shared PHP runtime policy is changed. All instances of that project
  consume the shared policy.
- `--workspace=<workspace>`: Target workspace override.
- `--inherit`: Clear a workspace override so the workspace inherits the parent
  project PHP version.
- `--cli`: Select the node CLI PHP default. Only PHP 8.5 is supported,
  matching the production native Orbit CLI binary artifact's embedded PHP
  version. Source-mounted Docker/Incus development and E2E nodes invoke
  `<source>/apps/cli/orbit`.
- `--node=<node>`: Target node for `--cli`, or an optional serving-node
  assertion for a workspace. It is invalid for an app policy write, which
  selects one concrete instance (dotted selector or unambiguous bare-project
  shorthand) and preflights only that instance's serving node. A mismatched
  workspace node fails with the stable `target_mismatch` reason before any
  gateway configuration is written. See the
  [JSON renderer contract](technical/6.2_php-use_output-render_json.md) for
  the exact failure shape.
- `--json`: Return the selected runtime result in the shared JSON command
  envelope.

## What Happens

Run this command to select the PHP image version for an instance or workspace.

`php:use` resolves exactly one target scope: project runtime policy for one
selected instance, workspace runtime override, workspace inheritance, or node
CLI default. It validates that project and workspace versions are supported by
Orbit. Before an app-policy write it authorizes `php:write` and verifies the
image only on the selected instance's serving node; any denial or missing image
stops before policy mutation. Node CLI selection only accepts PHP 8.5.

For an instance target, the command writes the parent project's shared policy
only after that selected-instance preflight, then reconciles the selected
instance's runtime container and proxy backend. Fan-out reconciliation for
sibling Orbit instances is reported as non-fatal warnings when applicable; the
success payload remains single-instance result facts. External-driver instances
are not reconciled by Orbit. Workspace targets update and reconcile only the
selected workspace placement. Proxy drift remains a `proxy` family concern.

The command does not install PHP, edit project files, read `.php-version`, or
mutate Composer constraints.

## Output

Output shows the resolved target, selected version, and the reconciliation
result for that one instance after the app-policy write.

Human output renders progress and a short result summary. Use `--json` for
machine-readable output.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity has `php:write` on every affected Orbit instance
  serving node for an app-policy write, or on the resolved workspace/CLI node.
  Gateway identity remains implicit.
- The requested project PHP version is available on every affected Orbit instance
  serving node; a workspace version is available on that workspace's serving
  node. Node CLI selection requires PHP 8.5.
- The concrete instance-serving node is Agent-eligible and reachable when
  runtime artifacts must be applied.

## Related Commands

Use these commands to list versions or verify runtime health across families.

- [`orbit php:list`](../1_php-list/php-list.md)
- [`doctor --family=instance`](../../5_app/instance-doctor.md)
- [`doctor --family=workspace`](../../6_workspace/workspace-doctor.md)
- [`doctor --family=proxy`](../../8_proxy/proxy-doctor.md)
- [Technical contract](technical/1_php-use.md)
