import { z } from "npm:zod@4";
import { detect, DetectionSchema, gitCommand } from "./_lib/detect.ts";
import { baselinePlan, PlanSchema, taskPlan } from "./_lib/tasks.ts";
import { project } from "./config.ts";
import { repository } from "./_lib/queue.ts";

const ConfigurationSchema = z.object({
  repository: z.string(),
  defaultBranch: z.string(),
  paths: z.array(z.string()),
  ledger: z.string(),
  upstream: z.string(),
  checkCommand: z.string(),
  compatibilityCommand: z.string(),
  mergeApproval: z.literal("nick"),
});
interface Context {
  logger?: {
    info: (message: string, values?: Record<string, unknown>) => void;
  };
  signal?: AbortSignal;
  writeResource: (
    spec: string,
    name: string,
    data: Record<string, unknown>,
  ) => Promise<{ name: string }>;
}
const ArgsSchema = z.object({ cwd: z.string().min(1) });
/** Private Horizon project extension. Detection runs on the supplied group's VM worker. */
export const behavior = {
  type: "@nckrtl/project-horizon-new-dawn",
  version: "2026.10.27.1",
  globalArguments: z.object({}),
  resources: {
    configuration: {
      description:
        "Horizon repository, paths, parity ledger and human merge approval",
      schema: ConfigurationSchema,
      lifetime: "infinite",
      garbageCollection: 10,
    },
    detection: {
      description: "Exact scripts/horizon-sync detect output",
      schema: DetectionSchema,
      lifetime: "30d",
      garbageCollection: 20,
    },
    plan: {
      description:
        "One baseline task or one parity task per changed upstream item",
      schema: PlanSchema,
      lifetime: "30d",
      garbageCollection: 20,
    },
  },
  methods: {
    configuration: {
      description: "Read Horizon project configuration",
      arguments: z.object({}),
      execute: async (_input: Record<string, never>, ctx: Context) => ({
        dataHandles: [
          await ctx.writeResource(
            "configuration",
            "configuration",
            ConfigurationSchema.parse(project),
          ),
        ],
      }),
    },
    detect: {
      description:
        "Read the parity ledger and diff the next upstream tag; never edits the checkout",
      arguments: ArgsSchema,
      execute: async (input: z.infer<typeof ArgsSchema>, ctx: Context) => {
        ctx.logger?.info("Detecting the next Horizon upstream tag");
        const { cwd: suppliedCwd } = ArgsSchema.parse(input);
        const cwd = await Deno.realPath(suppliedCwd);
        const git = gitCommand(ctx.signal);
        const origin = await git([
          "-C",
          cwd,
          "config",
          "--get",
          "remote.origin.url",
        ]);
        if (
          origin.code !== 0 ||
          repository(origin.text.trim()) !== project.repository
        ) {
          throw new Error(
            "Horizon detection checkout must match " + project.repository,
          );
        }
        const head = await git(["-C", cwd, "rev-parse", "HEAD"]);
        const base = await git([
          "-C",
          cwd,
          "rev-parse",
          "origin/" + project.defaultBranch,
        ]);
        if (
          head.code !== 0 || base.code !== 0 ||
          head.text.trim() !== base.text.trim()
        ) {
          throw new Error(
            "Horizon detection requires a checkout of the fetched default branch",
          );
        }
        const status = await git([
          "-C",
          cwd,
          "status",
          "--porcelain",
          "--",
          project.ledger,
        ]);
        if (status.code !== 0 || status.text.trim()) {
          throw new Error("Commit the Horizon parity ledger before detection");
        }
        // Only absence requests the one-time baseline. Malformed or unreadable ledgers fail.
        try {
          await Deno.stat(`${cwd}/${project.ledger}`);
        } catch (error) {
          if (!(error instanceof Deno.errors.NotFound)) throw error;
          ctx.logger?.info(
            "Horizon ledger is absent; planning one baseline task",
          );
          return {
            dataHandles: [
              await ctx.writeResource("plan", "plan", baselinePlan(project)),
            ],
          };
        }
        const result = await detect(
          cwd,
          project.upstream,
          git,
          project.ledger,
        );
        const detection = await ctx.writeResource(
          "detection",
          "detection",
          result,
        );
        const plan = await ctx.writeResource(
          "plan",
          "plan",
          taskPlan(result, project),
        );
        ctx.logger?.info(
          "Detected Horizon tag {tag} with {count} parity tasks",
          { tag: result.tag, count: result.candidates.length },
        );
        return { dataHandles: [detection, plan] };
      },
    },
  },
};
