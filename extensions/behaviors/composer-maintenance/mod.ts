/** Composer maintenance in a supplied checkout, with no orchestration dependency. */
import {
  type Context,
  failureReceipts,
  failureResource,
  parse,
  write,
} from "../_lib/maintenance/context.ts";
import { z } from "npm:zod@4";
import {
  AdvisorySchema,
  audit,
  command,
  CommonSchema,
  MaintenanceFailure,
  object,
  places,
  readJson,
  reportJson,
  run,
} from "./_lib/io.ts";
import { allowSecurity, staleExceptions, StaleSchema } from "./_lib/policy.ts";
import { ChangeSchema, summarize } from "./_lib/summary.ts";

export { MaintenanceFailure } from "./_lib/io.ts";

const CandidateSchema = z.strictObject({
  path: z.string(),
  manager: z.literal("composer"),
  package: z.string(),
  reasons: z.array(z.enum(["major", "security"])),
  advisories: z.array(AdvisorySchema),
  current: z.string().optional(),
  latest: z.string().optional(),
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
  package: z.string().regex(/^[a-z0-9][a-z0-9_.-]*\/[a-z0-9][a-z0-9_.-]*$/),
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
  manager: z.literal("composer"),
  package: z.string(),
  version: z.string(),
  purpose: z.enum(["major", "security"]),
  advisoryIds: z.array(z.string()),
});
const SummaryArgsSchema = CommonSchema.extend({ base: z.string().optional() });
const SummarySchema = z.strictObject({
  schema: z.literal(1),
  title: z.string(),
  body: z.string(),
  text: z.string(),
  changes: z.array(ChangeSchema),
});

/** Swamp behavior: local commands and versioned schema 1 resources. */
export const behavior = {
  type: "@nckrtl/composer-maintenance",
  version: "2026.10.27.1",
  globalArguments: z.strictObject({}),
  resources: {
    failure: failureResource,
    routine: {
      description: "Completed Composer routine paths",
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
    package: {
      description: "One completed dependency update",
      schema: PackageResultSchema,
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
    routine: {
      description:
        "Update each configured Composer path within declared ranges; stop on failure",
      arguments: CommonSchema,
      execute: async (input: z.input<typeof CommonSchema>, ctx: Context) => {
        const args = parse(CommonSchema, input);
        const project = await places(args);
        for (const place of project.places) {
          await run(place.dir, ["update", "--no-interaction"], ctx.signal);
        }
        return await write(ctx, "routine", RoutineSchema, {
          schema: 1,
          paths: args.paths,
        });
      },
    },
    detect: {
      description:
        "Detect Composer locked majors and advisories without vendor",
      arguments: CommonSchema,
      execute: async (input: z.input<typeof CommonSchema>, ctx: Context) => {
        const args = parse(CommonSchema, input);
        const project = await places(args);
        const candidates = new Map<string, z.infer<typeof CandidateSchema>>();
        for (const place of project.places) {
          const commandArgs = [
            "outdated",
            "--locked",
            "--major-only",
            "--format=json",
          ];
          const data = reportJson(
            await command(place.dir, "composer", commandArgs, ctx.signal),
            commandArgs,
          );
          if (!Array.isArray(data.locked)) {
            throw new MaintenanceFailure(
              "Composer outdated has no locked field",
            );
          }
          for (const entry of data.locked) {
            const item = object(entry, "Composer outdated entry");
            if (
              typeof item.name !== "string" ||
              typeof item.version !== "string" ||
              typeof item.latest !== "string"
            ) {
              throw new MaintenanceFailure(
                "Composer outdated entry is incomplete",
              );
            }
            candidates.set(`${place.path}:composer:${item.name}`, {
              path: place.path,
              manager: "composer",
              package: item.name,
              reasons: ["major"],
              advisories: [],
              current: item.version,
              latest: item.latest,
            });
          }
        }
        for (const place of project.places) {
          for (
            const [name, advisories] of Object.entries(
              await audit(place.dir, ctx.signal),
            )
          ) {
            if (!advisories.length) continue;
            const key = `${place.path}:composer:${name}`;
            const row = candidates.get(key) ?? {
              path: place.path,
              manager: "composer" as const,
              package: name,
              reasons: [],
              advisories: [],
            };
            row.reasons.push("security");
            row.advisories = advisories;
            candidates.set(key, row);
          }
        }
        const stale = await staleExceptions(project.root, project.places);
        return await write(ctx, "detection", DetectionSchema, {
          schema: 1,
          candidates: [...candidates.keys()].sort().map((key) =>
            candidates.get(key)
          ),
          ...(stale.length ? { stale_exceptions: stale } : {}),
        });
      },
    },
    package: {
      description:
        "Update one direct Composer dependency using a major range or exact security version",
      arguments: PackageSchema,
      execute: async (input: z.input<typeof PackageSchema>, ctx: Context) => {
        const args = parse(PackageSchema, input);
        const project = await places(args);
        const place = project.places.find((p) => p.path === args.path);
        if (!place) {
          throw new MaintenanceFailure(`Unknown path ${args.path}`, 64);
        }
        const manifest = await readJson(`${place.dir}/composer.json`);
        const sections = ["require", "require-dev"].filter((section) => {
          const value = manifest[section];
          return value !== null && typeof value === "object" &&
            args.package in value;
        });
        const range = sections.length === 1
          ? (manifest[sections[0]] as Record<string, unknown>)[args.package]
          : null;
        if (
          typeof range !== "string" ||
          /(?:npm:|workspace:|git|https?:|file:|link:|\bas\b)/.test(range)
        ) {
          throw new MaintenanceFailure(
            "Target must be one existing direct registry dependency in exactly one section",
            64,
          );
        }
        if (args.purpose === "security") {
          const before = (await audit(place.dir, ctx.signal))[args.package] ??
            [];
          if (
            args.advisoryIds.some((id) => !before.some((a) => a.id === id)) &&
            !(before.length === 0 && range === args.version)
          ) {
            throw new MaintenanceFailure(
              "Requested advisories are not current findings for this package",
              64,
            );
          }
          if (before.length) {
            await allowSecurity(project.root, place, args.package, args.policy);
          }
        }
        const target = args.purpose === "major"
          ? `^${args.version}`
          : args.version;
        const requireArgs = [
          "require",
          `${args.package}:${target}`,
          "--no-update",
          "--no-interaction",
        ];
        if (sections[0] === "require-dev") requireArgs.push("--dev");
        await run(place.dir, requireArgs, ctx.signal);
        await run(place.dir, [
          "update",
          args.package,
          "--with-dependencies",
          "--no-interaction",
        ], ctx.signal);
        if (
          args.purpose === "security" &&
          ((await audit(place.dir, ctx.signal))[args.package] ?? []).length
        ) {
          throw new MaintenanceFailure(
            "The selected package still has security advisories",
            1,
          );
        }
        return await write(ctx, "package", PackageResultSchema, {
          schema: 1,
          path: args.path,
          manager: "composer",
          package: args.package,
          version: args.version,
          purpose: args.purpose,
          advisoryIds: args.advisoryIds,
        });
      },
    },
    summary: {
      description:
        "Write the dependency PR title and description from base and current locked versions",
      arguments: SummaryArgsSchema,
      execute: async (
        input: z.input<typeof SummaryArgsSchema>,
        ctx: Context,
      ) => {
        const args = parse(SummaryArgsSchema, input);
        const project = await places(args);
        return await write(
          ctx,
          "summary",
          SummarySchema,
          await summarize(project.root, project.places, args.base, ctx.signal),
        );
      },
    },
  }),
};
