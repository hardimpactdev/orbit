import { ImpactContextSchema } from "../_lib/maintenance/impact.ts";
import { ProjectTypeSchema } from "../_lib/maintenance/project_type.ts";
import {
  BatchPlanSchema,
  type Candidate,
  TargetsSchema,
} from "../_lib/maintenance/batch.ts";
import { batch, plan } from "./_lib/batch.ts";
import { ExceptionsSchema } from "../_lib/maintenance/exceptions.ts";
import {
  snapshotMethod,
  SnapshotSchema,
} from "../_lib/maintenance/snapshot.ts";
/** JavaScript maintenance in a supplied checkout; no Composer or orchestrator is needed. */
import { z } from "npm:zod@4";
import {
  type Context,
  failureReceipts,
  failureResource,
  parse,
  write,
} from "../_lib/maintenance/context.ts";
import {
  AdvisorySchema,
  CommonSchema,
  MaintenanceFailure,
  places,
} from "../_lib/maintenance/io.ts";
import { ChangeSchema } from "../_lib/maintenance/summary.ts";
import { allowSecurity, staleExceptions, StaleSchema } from "./_lib/policy.ts";
import { audit, majors, published } from "./_lib/reports.ts";
import { inventory, summarize } from "./_lib/summary.ts";
import {
  cli,
  coupled,
  executable,
  prepare,
  run,
  sections,
  workspace,
} from "./_lib/workspace.ts";
/** A maintenance error with the command-compatible exit code. */
export { MaintenanceFailure } from "../_lib/maintenance/io.ts";
const CandidateSchema = z.strictObject({
  path: z.string(),
  manager: z.literal("js"),
  package: z.string(),
  reasons: z.array(z.enum(["major", "security"])),
  advisories: z.array(
    AdvisorySchema.extend({ severity: z.string().nullable().optional() }),
  ),
  current: z.string().optional(),
  latest: z.string().optional(),
  align: z.literal("expo-install-fix").optional(),
});
const DetectionSchema = z.strictObject({
  schema: z.literal(1),
  candidates: z.array(CandidateSchema),
  stale_exceptions: z.array(StaleSchema).optional(),
});
const RoutineSchema = z.strictObject({
  schema: z.literal(1),
  paths: z.array(z.string()),
});
const PackageSchema = CommonSchema.extend({
  path: z.string().default("."),
  package: z.string().regex(
    /^(?:@[a-z0-9][a-z0-9_.-]*\/)?[a-z0-9][a-z0-9_.-]*$/,
  ),
  version: z.string().regex(/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/),
  purpose: z.enum(["major", "security"]),
  advisoryIds: z.array(z.string().regex(/^[A-Za-z0-9][A-Za-z0-9_.:/-]*$/))
    .default([]),
}).refine((a) => (a.purpose === "security") === (a.advisoryIds.length > 0), {
  message: "Security needs advisory ids; major takes none",
});
const PackageResultSchema = z.strictObject({
  schema: z.literal(1),
  path: z.string(),
  manager: z.literal("js"),
  package: z.string(),
  version: z.string(),
  purpose: z.enum(["major", "security"]),
  advisoryIds: z.array(z.string()),
});
const InventoryArgsSchema = CommonSchema.extend({
  base: z.string().optional(),
});
const InventorySchema = z.strictObject({
  schema: z.literal(1),
  changes: z.array(ChangeSchema),
});
const SummaryArgsSchema = CommonSchema.extend({
  base: z.string().optional(),
  projectType: ProjectTypeSchema.default("laravel-app"),
  impact: ImpactContextSchema.nullish(),
});
const SummarySchema = z.strictObject({
  schema: z.literal(1),
  title: z.string(),
  body: z.string(),
  text: z.string(),
  changes: z.array(ChangeSchema),
});
async function project(args: z.infer<typeof CommonSchema>) {
  const result = await places(args, "package.json");
  const workspaces = [];
  // Parse every manager and manifest before any command can mutate a path.
  for (const place of result.places) workspaces.push(await workspace(place));
  return { ...result, workspaces };
}
/** Local commands with versioned schema 1 resources, matching Composer maintenance. */
export const behavior = {
  type: "@nckrtl/js-maintenance",
  version: "2026.10.30.1",
  globalArguments: z.strictObject({}),
  resources: {
    snapshot: {
      description: "HEAD and whole working-tree identity for Gateway handoff",
      schema: SnapshotSchema,
      lifetime: "30d",
      garbageCollection: 20,
    },
    failure: failureResource,
    exceptions: {
      description: "Release-age exception receipts",
      schema: ExceptionsSchema,
      lifetime: "infinite",
      garbageCollection: 20,
    },
    routine: {
      description: "Completed JavaScript routine paths",
      schema: RoutineSchema,
      lifetime: "30d",
      garbageCollection: 20,
    },
    detection: {
      description: "Locked majors, advisories and due exceptions",
      schema: DetectionSchema,
      lifetime: "30d",
      garbageCollection: 20,
    },
    batch: {
      description: "Completed project batch targets",
      schema: z.strictObject({ schema: z.literal(1), targets: TargetsSchema }),
      lifetime: "30d",
      garbageCollection: 20,
    },
    plan: {
      description: "Eligible batch targets and blocked findings",
      schema: BatchPlanSchema,
      lifetime: "infinite",
      garbageCollection: 10,
    },
    package: {
      description: "One completed dependency update",
      schema: PackageResultSchema,
      lifetime: "30d",
      garbageCollection: 20,
    },
    inventory: {
      description: "Exact change rows for the dependency repair receipt",
      schema: InventorySchema,
      lifetime: "30d",
      garbageCollection: 20,
    },
    summary: {
      description: "Dependency PR title, body and direct lock changes",
      schema: SummarySchema,
      lifetime: "30d",
      garbageCollection: 20,
    },
  },
  methods: failureReceipts({
    snapshot: snapshotMethod,
    routine: {
      description:
        "Update configured JS paths within declared ranges; stop on failure",
      arguments: CommonSchema,
      execute: async (input: z.input<typeof CommonSchema>, ctx: Context) => {
        const args = parse(CommonSchema, input), p = await project(args);
        for (const js of p.workspaces) await prepare(js, ctx.signal);
        for (const js of p.workspaces) {
          await run(js, cli(js), ["update"], ctx.signal);
        }
        return await write(ctx, "routine", RoutineSchema, {
          schema: 1,
          paths: args.paths,
        });
      },
    },
    detect: {
      description:
        "Detect direct majors and real security advisories from npm or Bun",
      arguments: CommonSchema,
      execute: async (input: z.input<typeof CommonSchema>, ctx: Context) => {
        const args = parse(CommonSchema, input), p = await project(args);
        const candidates = new Map<string, z.infer<typeof CandidateSchema>>();
        for (const js of p.workspaces) {
          for (
            const row of await majors({ ...js, vitePlus: false }, ctx.signal)
          ) {
            if (js.expo && coupled(row.name) && row.name !== "expo") continue;
            candidates.set(`${js.path}:js:${row.name}`, {
              path: js.path,
              manager: "js",
              package: row.name,
              reasons: ["major"],
              advisories: [],
              current: row.current,
              latest: row.latest,
              ...(js.expo && row.name === "expo"
                ? { align: "expo-install-fix" as const }
                : {}),
            });
          }
        }
        for (const js of p.workspaces) {
          for (
            const [name, advisories] of Object.entries(
              published(
                await audit({ ...js, vitePlus: false }, ctx.signal, true),
              ),
            )
          ) {
            if (!advisories.length) continue;
            const key = `${js.path}:js:${name}`;
            const row = candidates.get(key) ??
              {
                path: js.path,
                manager: "js" as const,
                package: name,
                reasons: [],
                advisories: [],
              };
            row.reasons.push("security");
            row.advisories = advisories;
            candidates.set(key, row);
          }
        }
        const stale = await staleExceptions(args.exceptions, p.places);
        return await write(ctx, "detection", DetectionSchema, {
          schema: 1,
          candidates: [...candidates.keys()].sort().map((k) =>
            candidates.get(k)
          ),
          ...(stale.length ? { stale_exceptions: stale } : {}),
        });
      },
    },
    plan: {
      description:
        "Resolve all eligible targets in a disposable checkout without changing the source",
      arguments: CommonSchema.extend({ candidates: z.array(CandidateSchema) }),
      execute: async (
        input: z.input<typeof CommonSchema> & { candidates: Candidate[] },
        ctx: Context,
      ) => {
        const { candidates, ...common } = input;
        return await write(
          ctx,
          "plan",
          BatchPlanSchema,
          await plan(parse(CommonSchema, common), candidates, ctx.signal),
        );
      },
    },
    batch: {
      description:
        "Apply every selected project target together; preserve release-age policy",
      arguments: CommonSchema.extend({ targets: TargetsSchema }),
      execute: async (
        input: z.input<typeof CommonSchema> & {
          targets: z.infer<typeof TargetsSchema>;
        },
        ctx: Context,
      ) => {
        const { targets, ...common } = input;
        TargetsSchema.parse(targets);
        await batch(parse(CommonSchema, common), targets, ctx.signal);
        return await write(
          ctx,
          "batch",
          z.strictObject({ schema: z.literal(1), targets: TargetsSchema }),
          { schema: 1, targets },
        );
      },
    },
    package: {
      description:
        "Update one direct JS dependency; align an Expo SDK through expo install",
      arguments: PackageSchema,
      execute: async (input: z.input<typeof PackageSchema>, ctx: Context) => {
        const args = parse(PackageSchema, input),
          p = await project(args),
          js = p.workspaces.find((p) => p.path === args.path);
        if (!js) throw new MaintenanceFailure(`Unknown path ${args.path}`, 64);
        if (
          args.purpose === "major" && js.expo && args.package !== "expo" &&
          coupled(args.package)
        ) {
          throw new MaintenanceFailure(
            "Expo SDK and React Native are coupled. Bump expo; expo install --fix aligns this package.",
            64,
          );
        }
        const found = sections.filter((s) =>
          args.package in ((js.manifest[s] ?? {}) as Record<string, unknown>)
        );
        const range = found.length === 1
          ? (js.manifest[found[0]] as Record<string, unknown>)[args.package]
          : null;
        if (
          typeof range !== "string" ||
          /(?:npm:|workspace:|git|https?:|file:|link:)/.test(range)
        ) {
          throw new MaintenanceFailure(
            "Target must be one existing direct registry dependency in exactly one section",
            64,
          );
        }
        await prepare(js, ctx.signal);
        if (js.expo && args.package === "expo") await executable("npx");
        if (args.purpose === "security") {
          const before = (await audit(js, ctx.signal))[args.package] ?? [];
          if (
            args.advisoryIds.some((id) => !before.some((a) => a.id === id)) &&
            !(before.length === 0 && range === args.version)
          ) {
            throw new MaintenanceFailure(
              "Requested advisories are not current findings for this package",
              64,
            );
          }
          if (before.length && js.manager === "bun") {
            await allowSecurity(
              js,
              args.package,
              args.policy,
              args.exceptions,
              ctx,
            );
          }
        }
        const target = args.purpose === "major"
          ? `^${args.version}`
          : args.version;
        if (js.expo && args.package === "expo") {
          const env: Record<string, string> =
            args.purpose === "security" && js.manager === "npm"
              ? { npm_config_min_release_age: "0" }
              : {};
          await run(
            js,
            "npx",
            ["expo", "install", `expo@${args.version}`],
            ctx.signal,
            env,
          );
          await run(js, "npx", ["expo", "install", "--fix"], ctx.signal, env);
        } else {
          const flag = found[0] === "devDependencies"
            ? "--save-dev"
            : found[0] === "optionalDependencies"
            ? "--save-optional"
            : "--save-prod";
          let commandArgs: string[];
          if (js.vitePlus || js.manager === "npm") {
            commandArgs = [
              js.vitePlus ? "add" : "install",
              `${args.package}@${target}`,
              flag,
            ];
            if (args.purpose === "security") {
              commandArgs.push("--save-exact");
              if (js.manager === "npm") {
                commandArgs.push(
                  ...(js.vitePlus
                    ? ["--", "--min-release-age=0"]
                    : ["--min-release-age=0"]),
                );
              }
            }
          } else {
            commandArgs = ["add", `${args.package}@${target}`];
            if (found[0] === "devDependencies") commandArgs.push("-d");
            if (found[0] === "optionalDependencies") {
              commandArgs.push("--optional");
            }
            if (args.purpose === "security") commandArgs.push("--exact");
          }
          await run(js, cli(js), commandArgs, ctx.signal);
        }
        if (
          args.purpose === "security" &&
          ((await audit(js, ctx.signal))[args.package] ?? []).length
        ) {
          throw new MaintenanceFailure(
            "The selected package still has security advisories",
            1,
          );
        }
        return await write(ctx, "package", PackageResultSchema, {
          schema: 1,
          path: args.path,
          manager: "js",
          package: args.package,
          version: args.version,
          purpose: args.purpose,
          advisoryIds: args.advisoryIds,
        });
      },
    },
    inventory: {
      description:
        "List the same JS and Composer update rows that publication expects",
      arguments: InventoryArgsSchema,
      execute: async (
        input: z.input<typeof InventoryArgsSchema>,
        ctx: Context,
      ) => {
        const args = parse(InventoryArgsSchema, input);
        const p = await places(args, null);
        const rows = await inventory(p.root, p.places, args.base, ctx.signal);
        return await write(ctx, "inventory", InventorySchema, {
          schema: 1,
          changes: rows.changes,
        });
      },
    },
    summary: {
      description:
        "Write a dependency PR description covering JS and Composer paths",
      arguments: SummaryArgsSchema,
      execute: async (
        input: z.input<typeof SummaryArgsSchema>,
        ctx: Context,
      ) => {
        const args = parse(SummaryArgsSchema, input);
        // Summary may include Composer-only paths in a mixed repository.
        const p = await places(args, null);
        return await write(
          ctx,
          "summary",
          SummarySchema,
          await summarize(
            p.root,
            p.places,
            args.base,
            ctx.signal,
            args.projectType,
            args.impact,
          ),
        );
      },
    },
  }),
};
