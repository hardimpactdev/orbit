import { z } from "npm:zod@4.4.3";
import {
  legacyScheduleUnits,
  PublicInstall,
  renderScheduleUnits,
} from "../processes-schedules/contract.ts";
const LifecycleArgs = z.object({
  schedule: PublicInstall,
  cascade: z.boolean(),
}).strict();
const Observation = z.object({
  ok: z.boolean(),
  error_code: z.string().nullable(),
  present: z.boolean(),
  needs_install: z.boolean(),
  obsolete: z.boolean(),
  has_service: z.boolean(),
  has_timer: z.boolean(),
  has_script: z.boolean(),
  has_callback: z.boolean(),
  timer_enabled: z.boolean(),
  timer_active: z.boolean(),
}).strict();
type Context = {
  extensionFile(name: string): string;
  writeResource(
    schema: string,
    name: string,
    value: unknown,
  ): Promise<unknown>;
};

/** Public native file metadata only; no intent reads or execution. */
export const behavior = {
  type: "@nckrtl/schedule-run-assets",
  version: "2026.10.31.2",
  resources: {
    lifecycle: {
      schema: z.object({
        scripts_directory: z.string(),
        lifecycle_json: z.string(),
      }).strict(),
      lifetime: "infinite",
      garbageCollection: 10,
    },
    observation: {
      schema: Observation.extend({ observation_json: z.string() }),
      lifetime: "infinite",
      garbageCollection: 10,
    },
    assets: {
      schema: z.object({ scripts_directory: z.string() }).strict(),
      lifetime: "infinite",
      garbageCollection: 10,
    },
  },
  methods: {
    lifecycle: {
      description:
        "Render canonical public Schedule units and shipped one-action script metadata",
      arguments: LifecycleArgs,
      execute: async (
        args: z.infer<typeof LifecycleArgs>,
        ctx: Context,
      ) => {
        const result = {
          scripts_directory: ctx.extensionFile("scripts/account.py")
            .replace(/\/[^/]+$/, ""),
          lifecycle_json: JSON.stringify({
            ...args,
            units: renderScheduleUnits(args.schedule),
            legacy: legacyScheduleUnits(args.schedule),
          }),
        };
        await ctx.writeResource("lifecycle", "lifecycle", result);
        return result;
      },
    },
    observation: {
      description: "Decode the public preflight snapshot for native guards",
      arguments: z.object({ stdout: z.string().max(4096) }).strict(),
      execute: async (args: { stdout: string }, ctx: Context) => {
        const observation = Observation.parse(JSON.parse(args.stdout));
        const result = {
          ...observation,
          observation_json: JSON.stringify(observation),
        };
        await ctx.writeResource("observation", "observation", result);
        return result;
      },
    },
    read: {
      description: "Resolve the shipped Schedule run validation scripts",
      arguments: z.object({}).strict(),
      execute: async (_args: Record<string, never>, ctx: {
        extensionFile(name: string): string;
        writeResource(
          schema: string,
          name: string,
          value: unknown,
        ): Promise<unknown>;
      }) => {
        const assets = {
          scripts_directory: ctx.extensionFile("scripts/account.py")
            .replace(/\/[^/]+$/, ""),
        };
        await ctx.writeResource("assets", "assets", assets);
        return assets;
      },
    },
  },
};
