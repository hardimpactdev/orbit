# Orbit Command Catalog

Orbit publishes a generated command catalog for agents and tooling that need a
compact command contract without reading every domain document.

The catalog lives at [`generated/command-catalog.json`](generated/command-catalog.json).
Do not hand-edit it. Regenerate it from the docs app:

```bash
cd apps/docs
php artisan orbit:command-catalog
```

Use the check mode as the drift guard:

```bash
cd apps/docs
php artisan orbit:command-catalog --check
```

For a compact single-command lookup, use `--command`. This prints only the
selected command entry as JSON and does not rewrite the committed artifact:

```bash
cd apps/docs
php artisan orbit:command-catalog --command=tool:install
```

Use docs-lint alongside the catalog check when public command documentation
changes:

```bash
composer docs-lint
```

## Sources

The catalog joins existing Orbit sources:

- The live CLI surface from `apps/cli/orbit list --format=json`.
- Command documentation directories under `apps/docs/content/domains`.
- Command docs registries under `apps/docs/config/librarian-command-docs`.
- Technical contract test mappings from command technical files.

The catalog does not parse command signatures from Markdown. Signatures come
from the live CLI surface so the generated artifact cannot drift from the
command implementation silently.

## Schema

The top-level object contains:

- `schema_version`: catalog schema version.
- `generated_from`: source labels used by the generator.
- `commands`: command entries keyed by command name.
- `registries`: shared command-doc registries for error codes, warning codes,
  entity schemas, shared options, and state families.

Each command entry contains:

- `name`, `slug`, and `family`.
- `arguments` and `options` from the live CLI surface.
- `renderers.json` and `renderers.stream_json` booleans inferred from live options.
- `public_options_documented.json` and
  `public_options_documented.stream_json` booleans from direct checks against
  the public command page. These indicate whether the public docs mention the
  live `--json` and `--stream-json` options.
- `destructive_consent`, currently `null` until a later slice exposes this as
  structured metadata.
- `docs.directory`, `docs.public`, and `docs.technical` paths relative to
  `apps/docs/content`.
- `docs.repo_directory`, `docs.repo_public`, and `docs.repo_technical` paths
  relative to the repository root for agents that need direct file reads.
- `linked_test_files` parsed from technical contract test mappings.
- `p4_mapping` deterministic CLI-to-SDK-to-gateway trace metadata for LLM
  consumers. Values are generated from CLI gateway call sites, SDK request
  classes, gateway routes, controller classes, and statically discoverable
  authorization strings. They are not hand-authored per command.

### `p4_mapping`

Each command entry includes a `p4_mapping` object with stable keys:

- `sdk_request`: SDK Saloon request metadata when a matching SDK request class
  exists for the command's primary gateway endpoint. Object fields:
  - `class`: fully qualified SDK request class name.
  - `path`: repository-relative path to the request PHP file.
- `gateway_route`: gateway HTTP route metadata when a matching route exists.
  Object fields:
  - `method`: HTTP verb such as `GET` or `POST`.
  - `uri`: route URI relative to the gateway API prefix, using `{param}`
    placeholders for dynamic segments.
- `gateway_controller`: gateway controller metadata when the route resolves to a
  controller. Object fields:
  - `class`: fully qualified controller class name.
  - `path`: repository-relative path to the controller PHP file.
  - `action`: controller entrypoint method, usually `__invoke`.
- `authorization_permission`: sorted list of permission strings discovered
  statically from `#[RequiresPermission(...)]` attributes and permission-like
  string literals in authorization-related lines of the mapped controller
  action source. Use `[]` when a controller mapping exists but no permission
  string is statically discoverable. Use `null` when no gateway mapping exists
  for the command.
- `response_dto`: SDK response DTO metadata when the matched SDK request
  declares a `createDtoFromResponse()` return type. Object fields:
  - `class`: fully qualified SDK response class name.
  - `path`: repository-relative path to the response PHP file.

Null semantics:

- `null` on an object field means that mapping dimension is not statically
  discoverable for the command.
- Local-only commands with no gateway call site keep every `p4_mapping` field
  `null`.
- Commands with multiple or partially parsed gateway call sites keep every
  `p4_mapping` field `null` instead of guessing a primary endpoint.
- CLI command class mapping is intentionally deferred; `p4_mapping` traces the
  command's primary gateway endpoint to SDK and gateway implementation surfaces.

## Drift Guarantee

The catalog is committed so agents can read it cheaply. Pest coverage compares
the committed JSON to a freshly built catalog. The check fails when a live
command, docs path, linked test mapping, or command-doc registry changes without
regenerating the artifact.
