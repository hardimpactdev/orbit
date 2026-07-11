# `orbit app:new [name]`

[Back to App commands.](../README.md)

Create a new Orbit-managed app on a node with an app role.

## Usage

```bash
orbit app:new [name]
orbit app:new [name] --node=app-1
orbit app:new [name] --repo=hardimpactdev/orbit --php-version=8.5
orbit app:new [name] --template-repo=hardimpactdev/laravel-template --new-repo=hardimpactdev/my-app
orbit app:new [name] --repo=hardimpactdev/orbit --domain=example.com --json
orbit app:new [name] --repo=hardimpactdev/orbit --domain=example.com --stream-json
```

## Description

`app:new` creates a new application on a target node from an explicit source
plan. After creating the app source, it writes initial gateway configuration
and runs the standard app registration pipeline to converge app runtime artifacts,
proxy routes, and process definitions.

This command is the primary way to start a fresh project or spin up a new
service instance in Orbit.

## Behavior

The steps below describe what the command does during a successful run.

- **Source Resolution:** Before it starts creating anything, interactive
  `app:new` resolves the target node, app slug, and whether to create a new
  repository from a template or clone an existing repository. There is no
  implicit empty-directory path. Use [`app:register`](../2_app-register/app-register.md)
  to adopt source that already exists on a node.
- **New Repository:** Creates a private GitHub repository from the selected
  GitHub template, then clones the new repository into the app path. The
  template and destination use `owner/repo` identities.
- **Existing Repository:** Clones the selected repository into the app path.
  GitHub repositories, including `owner/repo` shorthand and `github.com` URLs,
  use `gh repo clone`; full Git URLs for other hosts use `git clone`.
- **Credentials:** Repository creation and cloning run non-interactively on the
  target node with credentials already configured there. Repository URLs with
  embedded credentials, query strings, or fragments are rejected. `app:new`
  never asks for, stores, or forwards git credentials, SSH keys, or access
  tokens.
- **Registry Write:** Writes authoritative app configuration to the gateway database.
  App names are identity slugs and must be globally unique in the gateway app
  registry.
- **Registration Pipeline:** Executes the same convergence logic as
  [`app:register`](../2_app-register/app-register.md) to set up runtime container, proxy
  routes, and runtime configuration.
- **Runtime Transport:** Stores the FrankenPHP proxy transport for app-dev as
  `http` by default. Pass `--runtime-proxy-transport=https` to opt the app into
  inner TLS between `orbit-caddy` and the runtime container.
- **Production Activation:** When `--domain` is provided, production configuration is
  recorded. If DNS or TLS prerequisites are not yet met, the command still
  succeeds; the inactive domain is reported as a warning with a retry path
  through [`app:register [name] --domain=<host>`](../2_app-register/app-register.md),
  which is safe to call repeatedly.
- **Retry Safety:** If source creation fails, no app configuration is written; fix the
  node-side source problem and rerun `app:new`. After gateway configuration is written,
  registration failures preserve that configuration. Subsequent runs of
  `app:register` or `doctor --family=app --restore` will attempt to complete
  artifact convergence. If a previous `app:new` run already cloned the requested
  repository but failed before writing app configuration, rerunning `app:new`
  reuses that matching checkout instead of cloning again. Template mode reuses
  a matching checkout or an already-created destination only when GitHub
  reports that the destination repository is private and came from the
  requested template.

## Requirements

- The CLI caller must be able to reach the Orbit gateway.
- The gateway must be able to reach the target node through its selected node
  execution transport.
- The target node must be active with the applicable app role: `app-dev`
  without `--domain`, or `app-prod` when `--domain` is supplied.
- Creating from a template requires authenticated GitHub CLI access on the
  target node for `github.com` and a repository that GitHub exposes as a
  template.

## Output

You will receive one of the following output formats depending on the flags you supply.

- **Human:** Progress covering operation state, source creation, registry write,
  and runtime application.
- **JSON:** A machine-readable result containing the new app's registry data, or
  a machine-readable failure with diagnostic metadata.
- **Stream JSON:** `--stream-json` emits newline-delimited progress JSON and is
  mutually exclusive with `--json`.

## Related

- [`orbit app:register [name]`](../2_app-register/app-register.md)
- [`orbit app:list`](../3_app-list/app-list.md)
- [`orbit app:remove [app]`](../6_app-remove/app-remove.md)
- [Technical Contract](technical/1_app-new.md)
