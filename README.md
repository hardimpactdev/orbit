# Orbit Monorepo

Local PHP development environment — like Laravel Herd/Valet, but designed for both local and remote environments.

## Structure

```
orbit/
├── packages/
│   ├── core/          # Core library (shared logic)
│   ├── cli/           # Command-line interface
│   ├── app/           # UI components (shared React/Vue components)
│   ├── web/           # Web dashboard (bundled into CLI)
│   └── desktop/       # Desktop app (NativePHP wrapper)
├── .changeset/        # Changeset configuration for versioning
├── .github/           # CI/CD workflows
├── docs/              # Documentation
├── package.json       # npm/bun workspace root
└── turbo.json         # Turborepo build orchestration
```

## Packages

| Package | Description |
|---------|-------------|
| `packages/core` | Core functionality shared by all packages |
| `packages/cli` | Laravel Zero CLI — the main entry point |
| `packages/app` | Shared UI components |
| `packages/web` | Web dashboard (Laravel app, bundled into CLI) |
| `packages/desktop` | Desktop application (NativePHP) |

## Development

### Prerequisites

- PHP 8.4+
- Node.js 20+ / Bun
- Composer

### Install dependencies

```bash
# JavaScript dependencies (from root)
bun install

# PHP dependencies (per package)
cd packages/core && composer install
cd packages/cli && composer install
# etc.
```

### Build all packages

```bash
bun build
```

### Run tests

```bash
bun test
```

### Compound Engineering

We use the Plan -> Work -> Review -> Compound loop. See:

- `docs/solutions/patterns/compound-engineering-monorepo.md`

## Versioning

All packages are versioned together using [Changesets](https://github.com/changesets/changesets).

```bash
# Create a changeset
bun changeset

# Version packages
bun version

# Publish
bun release
```

## CLI Distribution

The CLI is distributed as a phar:

```bash
cd packages/cli
php box.phar compile
# Output: builds/orbit.phar
```
