# Orbit Monorepo

A hybrid PHP/JavaScript monorepo using Composer path repositories and Bun workspaces.

## Structure

```
orbit/
├── packages/
│   ├── core/          # hardimpactdev/orbit-core - Core library
│   ├── cli/           # hardimpactdev/orbit-cli - CLI tools
│   ├── app/           # @hardimpactdev/orbit-app - App/UI components (npm)
│   ├── web/           # hardimpactdev/orbit-web - Web application
│   └── desktop/       # hardimpactdev/orbit-desktop - Desktop application
├── .changeset/        # Changeset configuration for versioning
├── composer.json      # PHP workspace (path repositories)
├── package.json       # Bun/npm workspace
├── turbo.json         # Turborepo build orchestration
└── README.md
```

## Package Names

| Directory | Composer Package | NPM Package |
|-----------|-----------------|-------------|
| `packages/core` | `hardimpactdev/orbit-core` | `hardimpactdev/orbit-core` |
| `packages/cli` | `hardimpactdev/orbit-cli` | `hardimpactdev/orbit-cli` |
| `packages/app` | `hardimpactdev/orbit-app` | `@hardimpactdev/orbit-app` |
| `packages/web` | `hardimpactdev/orbit-web` | `hardimpactdev/orbit-web` |
| `packages/desktop` | `hardimpactdev/orbit-desktop` | `hardimpactdev/orbit-desktop` |

## Versioning

All packages are versioned together using [Changesets](https://github.com/changesets/changesets) with fixed versioning.

### Creating a changeset

```bash
bun changeset
```

### Versioning packages

```bash
bun version
```

### Publishing

```bash
bun release
```

## Development

### Install dependencies

```bash
# JavaScript dependencies
bun install

# PHP dependencies
composer install
```

### Build all packages

```bash
bun build
```

### Build CLI

```bash
cd packages/cli && php box.phar compile
```

## CLI

The compiled CLI is at `packages/cli/builds/orbit.phar`.

