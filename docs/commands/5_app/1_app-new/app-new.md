# `orbit app:new [name]`

[Back to App commands.](../README.md)

Create a new Orbit-managed app on an app node.

## Usage

```bash
orbit app:new [name]
orbit app:new [name] --node=app-1
orbit app:new [name] --repo=hardimpactdev/orbit --php-version=8.5
orbit app:new [name] --domain=example.com --json
```

## Description

`app:new` creates or clones a new application on a target app node through the
gateway over SSH. After creating the app source, it writes initial gateway configuration
and runs the standard app registration pipeline to converge app runtime artifacts,
proxy routes, and process definitions.

This command is the primary way to start a fresh project or spin up a new
service instance in Orbit.

## Behavior

The steps below describe what the command does during a successful run.

- **Source Creation:** Creates an empty directory or clones a repository onto
  the target node. Cloning runs non-interactively on the target node using the
  credentials that node already has in place; `app:new` does not prompt for or
  forward git credentials. `--repo` accepts full Git URLs for any host the node
  can access. The `owner/repo` shorthand is GitHub-only and expands to
  `git@github.com:owner/repo.git`.
- **Registry Write:** Writes authoritative app configuration to the gateway database.
  App names are identity slugs and must be globally unique in the gateway app
  registry.
- **Registration Pipeline:** Executes the same convergence logic as
  [`app:register`](../2_app-register/app-register.md) to set up PHP-FPM, proxy
  routes, and runtime configuration.
- **Production Activation:** When `--domain` is provided, production configuration is
  recorded. If DNS or TLS prerequisites are not yet met, the command still
  succeeds; the inactive domain is reported as a warning with a retry path
  through [`app:register [name] --domain=<host>`](../2_app-register/app-register.md),
  which is safe to call repeatedly.
- **Retry Safety:** If source creation fails, no app configuration is written; fix the
  node-side source problem and rerun `app:new`. After gateway configuration is written,
  registration failures preserve that configuration. Subsequent runs of
  `app:register` or `doctor --fix --family=app --restore` will attempt to complete
  artifact convergence.

## Requirements

- The CLI caller must be able to reach the Orbit gateway.
- The gateway must be able to reach the target app node over SSH.
- The target node must be an active app node.

## Output

You will receive one of the following output formats depending on the flags you supply.

- **Human:** Progress covering source creation, registry write, setup, proxy route application, and apply verification.
- **JSON:** A machine-readable result containing the new app's registry data, or
  a machine-readable failure with diagnostic metadata.

## Related

- [`orbit app:register [name]`](../2_app-register/app-register.md)
- [`orbit app:list`](../3_app-list/app-list.md)
- [`orbit app:remove [app]`](../6_app-remove/app-remove.md)
- [Technical Contract](technical/1_app-new.md)
