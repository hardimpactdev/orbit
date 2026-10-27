# Add a private project extension

1. Copy these templates to `extensions/behaviors/projects/<slug>/mod.ts` and
   `manifest.yaml`. Fill every placeholder. Add README and LICENSE files there.
2. Read the project's package manifests, maintenance paths and setup.
   Copy its exact paths, check, branch and policy. Use `[]` for an unused
   ecosystem. Include application paths such as Hauzer's `mobile/` explicitly.
3. Add `{ projectId: <id>, type: '@nckrtl/project-<slug>' }` to the Gateway's
   `projectExtensions`. Remove its old `dependencyProjects` entry in the same
   change. The IDs are routing keys; config lives only in the extension.
4. Run `swamp workflow create project-<slug>-routine --json` and the matching
   `project-<slug>-detect`. Keep Swamp's assigned IDs. Use Project 20's two
   workflows as the body template, with the new Project ID. Name them
   `@nckrtl/project-<slug>/routine` and `@nckrtl/project-<slug>/detect` to meet
   Swamp's collective packaging rule. Stagger their
   `trigger.schedule` values. Swamp schedules use UTC.
5. Add a fixture for the config copied from the project's setup. Test config
   resolution, path/policy validation and identical maintenance inputs. Run only
   the affected tests, with no more than four workers. Validate both workflows
   and run extension fmt/quality before the PR.

The project extension contains config and schedules. All package-manager work,
PR text, worker access and factory state stay in shared extensions. Use the
private repo deployment bundle. Registry publication needs the orchestrator's
approval. Do not edit the project repository during phase 4.

Release-age exception history belongs in the private project's `exceptions`
config and Swamp resource. Preserve any existing dates and durations when
preparing a project. See [exception storage](../../project-maintenance-exceptions.md).
