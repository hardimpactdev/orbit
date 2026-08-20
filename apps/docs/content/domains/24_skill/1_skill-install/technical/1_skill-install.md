# Technical Contract: `orbit skill:install [provider] [path] [--force] [--json]`

[Back to public `skill:install` documentation.](../skill-install.md)

**Owner:** `skill`.

**Effects:** `local-only`, `write`; conditionally `destructive` only when an
existing target is replaced.

**Prerequisites:**

- The Orbit install root contains `.agents/skills/orbit/SKILL.md`.
- `HOME` is available when resolving a provider default target.
- The caller can write the selected target's parent directory.

## Signature

```bash
orbit skill:install [provider] [path] [--force] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `provider` | `argument` | When no explicit target path is supplied. | Never. | `None.` | Supported provider slug, or explicit target path when not a known slug. |
| `path` | `argument` | Optional when `provider` is a known slug. | When the first positional value is not a known provider slug. | `None.` | Explicit target path. |
| `force` | `--force` | Optional. | Never. | `false` | Destructive consent for removing an existing target before copying. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer and non-interactive input mode. |

Installing into an absent target is not destructive. When the resolved target
already exists, interactive mode asks for confirmation unless `--force` is
present; non-interactive mode requires `--force`. `--json` selects a renderer
and non-interactive input mode only; it is not destructive consent.

## Behavior Contract

### Target resolution

- With neither `provider` nor `path`, fail with `validation_failed`,
  `meta.reason=missing_target`, and `meta.fields=["provider","path"]`.
- With a known `provider` and no `path`, resolve the default target:
  - `codex` -> `$HOME/.agents/skills/orbit`
  - `claude` -> `$HOME/.claude/skills/orbit`
  - `antigravity` -> `$HOME/.gemini/config/skills/orbit`
  - `grok` -> `$HOME/.grok/skills/orbit`
- If a provider default is requested and `HOME` is unavailable, fail with
  `validation_failed`, `meta.field=home`, and `meta.reason=missing_home`.
- With a known `provider` and a `path`, install to the explicit `path` and
  include the provider slug in the result.
- With an unknown first positional and no `path`, treat the first positional as
  the explicit target path and install the raw Orbit skill directory there.
- With an unknown first positional and a second positional, fail with
  `validation_failed`, `meta.field=path`, and `meta.reason=unexpected_path`.

### Install planning

- Resolve the provider, target path, source directory, and the initial
  target-exists snapshot once into an immutable install plan.
- After consent, install uses that planned provider, target, and source. It
  does not resolve the target or source path again.
- Immediately before delete or copy, re-check whether the planned target exists
  and re-validate that the planned source directory still contains `SKILL.md`.
- This command has no `--dry-run` flag. Planning is an internal step, not a
  preview mode.

### Copy and overwrite rules

- Validate that the source skill directory exists before mutating the target.
- If the target exists and `--force` is absent, interactive mode asks for
  confirmation after target/source validation and before deletion.
- If the target exists in non-interactive mode and `--force` is absent, fail
  with `validation_failed`, `meta.field=force`, and
  `meta.reason=destructive_consent_required`.
- After interactive confirmation or `--force`, remove the existing target
  before copying. Existing directories, files, and symlinks are replacement
  targets.
- Copy the source directory recursively into the resolved target.
- If copying fails after source and target validation, return
  `skill.install_failed` with `meta.source` and `meta.target`.

## Local-Only Boundary

`skill:install` never calls the gateway API, never enables or disables
extensions, never mutates gateway or fleet state, and never downloads skills.
It is a caller-machine filesystem command.

## Input Mode Contracts

- [Interactive input mode](5.1_skill-install_input-mode_interactive.md)
- [Non-interactive input mode](5.2_skill-install_input-mode_non-interactive.md)

## Renderer Contracts

- [Human renderer](6.1_skill-install_output-render_human.md)
- [JSON renderer](6.2_skill-install_output-render_json.md)

## Failure Semantics

| Condition | Contract |
| --- | --- |
| Missing target input | `error.code=validation_failed`; `error.meta.reason=missing_target` |
| Unknown first positional plus second positional | `error.code=validation_failed`; `error.meta.reason=unexpected_path` |
| Missing `HOME` for provider default | `error.code=validation_failed`; `error.meta.reason=missing_home` |
| Missing or invalid source skill | `error.code=validation_failed`; `error.meta.reason=missing_source` |
| Existing target without destructive consent | `error.code=validation_failed`; `error.meta.field=force`; `error.meta.reason=destructive_consent_required` |
| Copy failure after validation | `error.code=skill.install_failed`; `error.meta.source` and `error.meta.target` |

The shared exit status policy applies: `0` for success, `1` for Orbit-handled
failures, and `2` only for console-runtime usage failures before Orbit applies
this contract.

## Doctor Relationship

`skill:install` has no doctor family. It does not create drift issues and does
not repair Orbit state. Provider tools may need to reload or restart their own
skill discovery after installation; that reload is outside Orbit's doctor
contract.

## Activity Logging

This command does not emit activity. It installs files in the caller-local CLI
environment, and the CLI has no trusted shared activity helper. The gateway
records API work; `skill:install` does not make a gateway API request.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Skill/SkillInstallCommandTest.php` | Command signature, provider/default and explicit-path resolution, absent-target install, missing-source validation, interactive replacement confirmation/decline, `--force`, JSON consent envelope, and local-only no-gateway behavior. |
| `apps/cli/tests/Unit/Services/Skill/SkillInstallActionsTest.php` | Plan built once (`resolve()` called once), install-time target-existence race revalidation, missing-source revalidation after planning, and consent via `withForce()` without rebuilding the request. |

There is no gateway-side coverage for this command contract: `skill:install`
is local-only and has no gateway API surface.
