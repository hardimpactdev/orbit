import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../_lib/direct.ts";
import { DirectArgs, GatewayOperation } from "../_lib/direct.ts";
import {
  Code,
  Outcome as FootprintOutcome,
  Snapshot,
  Stage as FootprintStage,
} from "../node/converge_direct_contract.ts";
import { convergeFootprintStage } from "../node/converge_footprint.ts";
import { CliConvergePlan, convergeCli } from "./cli_converge_local.ts";
import {
  applyRecipe,
  incusRecipePreflight,
  inspectRecipe,
  inspectRecipePhpBuild,
  installRecipePhpBuild,
  type RecipeProgramRunner,
  runRecipeProgram,
  verifyRecipeDelivery,
} from "./recipe_local.ts";

const ObjectInput = z.record(z.string(), z.unknown());
const Text = z.string().min(1).max(256);
const Changes = z.object({
  changed_packages: z.array(Text).max(512),
  changed_tools: z.array(Text).max(512),
  restarted_services: z.array(Text).max(512),
  newer_than_recipe: z.record(
    z.string().regex(/^[a-zA-Z0-9_.+-]{1,128}$/),
    z.object({ installed: Text, version: Text }).strict(),
  ),
}).strict();
export const RecipeInput = z.object({
  node_id: z.number().int().positive(),
  identity: Text,
  snapshot: Text,
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  cli_version: Text,
  automatic: z.boolean(),
  daytime: z.boolean(),
  recipe: ObjectInput,
  php: ObjectInput.nullable(),
  incus_hold: z.union([z.record(z.string(), z.string()), z.tuple([])]),
  footprint: Snapshot,
  cli: CliConvergePlan,
  build_id: z.number().int().positive().nullable().optional(),
}).strict().superRefine((input, ctx) => {
  if (
    input.footprint.node_id !== input.node_id ||
    input.footprint.agent_sha256 !== input.agent_sha256 ||
    input.recipe.user !== input.cli.user ||
    input.recipe.snapshot !== input.snapshot ||
    input.cli.release?.version !== input.cli_version
  ) {
    ctx.addIssue({
      code: "custom",
      message: "Recipe inputs differ from the admitted Node or delivery",
    });
  }
});
export const Args = DirectArgs.extend({
  kind: z.literal("fleet.recipe"),
  node_id: z.number().int().positive(),
});
export const Stage = z.enum([
  "preflight",
  "recipe-apply",
  "php-build",
  ...FootprintStage.options,
  "cli",
  "delivery",
]);
export const Outcome = z.object({
  stage: Stage,
  ok: z.boolean(),
  error_code: Code.optional(),
  footprint: FootprintOutcome.optional(),
  changes: Changes.optional(),
  delivery: z.object({ identity: Text, agent_sha256: Text, cli_version: Text })
    .strict().optional(),
}).strict();
const Admission = z.object({
  ready: z.boolean(),
  started_at: z.string(),
  input: RecipeInput.nullable(),
  status: z.enum(["queued", "running", "succeeded", "failed", "cancelled"]),
}).strict();
const Current = z.object({
  id: z.number().int().positive(),
  kind: z.literal("fleet.recipe"),
  node_id: z.number().int().positive(),
  status: z.enum(["queued", "running", "succeeded", "failed", "cancelled"]),
  input: z.unknown(),
  cancel_requested: z.boolean(),
});
const Cancellation = z.object({
  cancel_requested: z.boolean(),
  window_open: z.boolean(),
}).strict();
const StepArgs = Args.extend({
  stage: Stage,
  admission: Admission,
  cancellation: Cancellation,
  binary_changed: z.boolean().default(false),
});
const ProjectArgs = Args.extend({
  admission: Admission,
  outcomes: z.array(Outcome).max(Stage.options.length),
});
type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  writeResource: (
    spec: string,
    name: string,
    value: unknown,
  ) => Promise<unknown>;
};
export type RecipeAction = (
  stage: z.infer<typeof Stage>,
  input: z.infer<typeof RecipeInput>,
  gateway: GatewayOperation,
  binaryChanged: boolean,
  signal?: AbortSignal,
) => Promise<z.infer<typeof Outcome>>;

function recipeRefusal(input: z.infer<typeof RecipeInput>): string | null {
  if (input.footprint.refusal) return input.footprint.refusal;
  if (input.cli.plan_error) return input.cli.plan_error;
  if (input.cli.skip_reason) return "node.recipe_delivery_unavailable";
  return null;
}

/** Fixed local commands and canonical local Node/Fleet functions, inside the parent native run. */
export function recipeActions(
  run: RecipeProgramRunner = runRecipeProgram,
  cli = convergeCli,
): RecipeAction {
  return async (stage, input, gateway, binaryChanged, signal) => {
    const refuse = (code: unknown) => ({
      stage,
      ok: false,
      error_code: Code.parse(code),
    });
    const admittedRefusal = recipeRefusal(input);
    if (admittedRefusal) return refuse(admittedRefusal);
    if (FootprintStage.safeParse(stage).success) {
      const footprint = FootprintOutcome.parse(
        await convergeFootprintStage(
          FootprintStage.parse(stage),
          input.footprint,
          gateway,
          binaryChanged,
          signal,
        ),
      );
      return {
        stage,
        ok: footprint.ok,
        footprint,
        ...(footprint.error_code ? { error_code: footprint.error_code } : {}),
      };
    }
    if (stage === "preflight") {
      if (!input.automatic) {
        const incus = await incusRecipePreflight(input.recipe, { signal, run });
        if ("error_code" in incus) return refuse(incus.error_code);
        if (!("ready" in incus) || incus.ready !== true) {
          return refuse("node.recipe_incus_busy");
        }
      }
      const inspected = await inspectRecipe(input.recipe, { signal, run });
      if (inspected.error_code) return refuse(inspected.error_code);
      if (
        Object.entries(inspected).some(([name, value]) =>
          name !== "_actions" &&
          ObjectInput.parse(value).status === "unsupported_newer"
        )
      ) return refuse("node.recipe_failed");
      if (input.php !== null) {
        const planned = await inspectRecipePhpBuild(input.php, { signal, run });
        if ("error_code" in planned) return refuse(planned.error_code);
        z.object({
          pending: z.array(z.string()),
          repair: z.array(z.string()),
          extras: z.array(z.string()),
          unowned: z.array(z.string()),
        }).parse(planned);
      }
      return { stage, ok: true };
    }
    if (stage === "recipe-apply") {
      const result = await applyRecipe(input.recipe, { signal, run });
      if ("error_code" in result) return refuse(result.error_code);
      if (result.healthy !== true) return refuse("node.recipe_health_failed");
      return {
        stage,
        ok: true,
        changes: Changes.parse({
          changed_packages: result.changed_packages,
          changed_tools: result.changed_tools,
          restarted_services: result.restarted_services,
          newer_than_recipe: result.newer_than_recipe,
        }),
      };
    }
    if (stage === "php-build") {
      if (input.php === null) return { stage, ok: true };
      const result = await installRecipePhpBuild(input.php, { signal, run });
      return "error_code" in result
        ? refuse(result.error_code)
        : ["current", "applied"].includes(String(result.outcome))
        ? { stage, ok: true }
        : refuse("node.recipe_php_failed");
    }
    if (stage === "cli") {
      const result = await cli({
        ...input.cli,
        agent_sha256: input.agent_sha256,
      }, { signal });
      return "error_code" in result
        ? refuse(result.error_code)
        : result.outcome === "skipped"
        ? refuse("node.recipe_delivery_unavailable")
        : { stage, ok: true };
    }
    const result = await verifyRecipeDelivery({
      user: input.recipe.user,
      identity: input.identity,
      agent_sha256: input.agent_sha256,
      cli_version: input.cli_version,
    }, { run, signal });
    if (result.error_code) return refuse(result.error_code);
    if (
      result.identity !== input.identity ||
      result.agent_sha256 !== input.agent_sha256 ||
      result.cli_version !== input.cli_version
    ) return refuse("node.recipe_delivery_failed");
    return {
      stage,
      ok: true,
      delivery: {
        identity: input.identity,
        agent_sha256: input.agent_sha256,
        cli_version: input.cli_version,
      },
    };
  };
}

export function recipeDirect(
  action: RecipeAction = recipeActions(),
  makeHttp: (ctx: Context) => Http = (ctx) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  now: () => Date = () => new Date(),
) {
  const gateway = (args: z.infer<typeof Args>, ctx: Context) =>
    new GatewayOperation(makeHttp(ctx), Args.parse(args));
  const checkpointPolicy = (
    input: z.infer<typeof RecipeInput>,
    current: z.infer<typeof Current>,
    signal?: AbortSignal,
  ) => {
    const hour = Number(new Intl.DateTimeFormat("en-GB", {
      timeZone: "Europe/Amsterdam",
      hour: "2-digit",
      hourCycle: "h23",
    }).format(now()));
    return {
      window_open: !input.automatic || input.daytime || (hour >= 3 && hour < 6),
      cancel_requested: current.cancel_requested ||
        current.status === "cancelled" || signal?.aborted === true,
    };
  };
  return {
    type: "@nckrtl/fleet-recipe-direct",
    version: "2026.10.31.1",
    globalArguments: Globals,
    resources: {
      admission: {
        description: "Safe immutable recipe input",
        schema: Admission,
        lifetime: "1d",
        garbageCollection: 10,
      },
      outcome: {
        description: "Bounded native recipe checkpoint",
        schema: Outcome,
        lifetime: "1d",
        garbageCollection: 20,
      },
      cancellation: {
        description: "Cancellation at the next native checkpoint",
        schema: Cancellation,
        lifetime: "1d",
        garbageCollection: 20,
      },
    },
    methods: {
      admit: {
        description: "Read the one parent intent without host work",
        arguments: Args,
        execute: async (raw: z.infer<typeof Args>, ctx: Context) => {
          const args = Args.parse(raw);
          const current = Current.parse(
            await gateway(args, ctx).read(ctx.signal),
          );
          if (
            current.id !== args.operation_id || current.node_id !== args.node_id
          ) throw new Error("Recipe root differs from its admitted Node");
          const terminal = ["succeeded", "failed", "cancelled"].includes(
            current.status,
          );
          const cancelled = current.cancel_requested ||
            ctx.signal?.aborted === true;
          const admission = Admission.parse({
            ready: !terminal && !cancelled,
            started_at: new Date().toISOString(),
            input: terminal || cancelled
              ? null
              : RecipeInput.parse(current.input),
            status: cancelled && !terminal ? "cancelled" : current.status,
          });
          if (
            admission.input?.node_id !== args.node_id && admission.ready
          ) throw new Error("Recipe input belongs to another Node");
          if (cancelled && !terminal) {
            await gateway(args, ctx).project({
              status: "cancelled",
              started_at: admission.started_at,
              finished_at: admission.started_at,
              result: {},
              error_code: null,
            });
          }
          await ctx.writeResource("admission", "admission", admission);
          return { ready: admission.ready };
        },
      },
      observe: {
        description:
          "Observe cancellation before dispatching a native checkpoint",
        arguments: Args.extend({ stage: Stage }),
        execute: async (
          raw: z.infer<typeof Args> & { stage: z.infer<typeof Stage> },
          ctx: Context,
        ) => {
          const args = Args.parse(raw);
          const current = Current.parse(
            await gateway(args, ctx).read(ctx.signal),
          );
          if (
            current.id !== args.operation_id ||
            current.node_id !== args.node_id ||
            ["succeeded", "failed"].includes(current.status)
          ) throw new Error("Recipe cancellation has no open intent");
          const input = RecipeInput.parse(current.input);
          const cancellation = checkpointPolicy(input, current, ctx.signal);
          await ctx.writeResource(
            "cancellation",
            raw.stage + "-cancellation",
            cancellation,
          );
          return cancellation;
        },
      },
      stage: {
        description: "Run one fixed local checkpoint of the parent recipe",
        arguments: StepArgs,
        execute: async (raw: z.infer<typeof StepArgs>, ctx: Context) => {
          const args = StepArgs.parse(raw);
          const input = RecipeInput.parse(args.admission.input);
          if (
            !args.admission.ready || input.node_id !== args.node_id
          ) throw new Error("Recipe stage has no admitted Node");
          // Native resume reuses prior step inputs. Read the current root before any effect.
          const current = Current.parse(
            await gateway(args, ctx).read(ctx.signal),
          );
          if (
            current.id !== args.operation_id ||
            current.node_id !== args.node_id ||
            ["succeeded", "failed", "cancelled"].includes(current.status)
          ) {
            throw new Error(
              "Recipe root is terminal or belongs to another Node",
            );
          }
          const cancellation = checkpointPolicy(input, current, ctx.signal);
          let outcome: z.infer<typeof Outcome>;
          if (cancellation.cancel_requested) {
            outcome = {
              stage: args.stage,
              ok: false,
              error_code: "operation.cancelled",
            };
          } else if (
            !cancellation.window_open
          ) {
            outcome = {
              stage: args.stage,
              ok: false,
              error_code: "fleet.window_closed",
            };
          } else if (recipeRefusal(input)) {
            outcome = {
              stage: args.stage,
              ok: false,
              error_code: Code.parse(recipeRefusal(input)),
            };
          } else {
            if (args.stage === "agent-install") {
              await gateway(args, ctx).project({
                status: "running",
                started_at: args.admission.started_at,
              });
            }
            outcome = Outcome.parse(
              await action(
                args.stage,
                input,
                gateway(args, ctx),
                args.binary_changed,
                ctx.signal,
              ),
            );
          }
          if (outcome.stage !== args.stage) {
            throw new Error("Recipe checkpoint returned another stage");
          }
          await ctx.writeResource("outcome", args.stage, outcome);
          return { ok: outcome.ok };
        },
      },
      project: {
        description: "Record only proven safe native recipe results",
        arguments: ProjectArgs,
        execute: async (raw: z.infer<typeof ProjectArgs>, ctx: Context) => {
          const args = ProjectArgs.parse(raw);
          if (!args.admission.ready) {
            if (args.admission.status === "failed") {
              throw new Error("node.recipe_failed");
            }
            return { status: args.admission.status };
          }
          const input = RecipeInput.parse(args.admission.input);
          if (input.node_id !== args.node_id) {
            throw new Error("Recipe projection belongs to another Node");
          }
          const failure = args.outcomes.find((o) => !o.ok);
          const deferred = args.outcomes.find((o) =>
            o.footprint?.deferred_error
          )?.footprint?.deferred_error;
          if (
            new Set(args.outcomes.map((o) => o.stage)).size !==
              args.outcomes.length ||
            !failure && args.outcomes.length !== Stage.options.length
          ) throw new Error("A recipe checkpoint is uncertain");
          const code = failure?.error_code ?? deferred;
          const status = code === "operation.cancelled"
            ? "cancelled"
            : code
            ? "failed"
            : "succeeded";
          const footprints = args.outcomes.flatMap((o) =>
            o.footprint ? [o.footprint] : []
          );
          const footprintFailure = footprints.find((o) => !o.ok);
          const complete = footprints.length === FootprintStage.options.length;
          const footprint = complete || footprintFailure
            ? {
              artifacts: footprints.flatMap((o) => o.entry ? [o.entry] : []),
              footprint_proven: complete && !footprintFailure && !deferred,
              ...(footprintFailure?.error_code || deferred
                ? { error_code: footprintFailure?.error_code ?? deferred }
                : {}),
              ...(footprintFailure?.failed_artifact
                ? { failed_artifact: footprintFailure.failed_artifact }
                : {}),
            }
            : null;
          const delivered = args.outcomes.find((o) => o.stage === "delivery")
            ?.delivery;
          const changes = args.outcomes.find((o) => o.stage === "recipe-apply")
            ?.changes;
          if (
            status === "succeeded" && (!delivered || !changes || !footprint)
          ) throw new Error("Recipe completion is unproven");
          const result = status === "cancelled" ? {} : {
            ...(delivered ?? {}),
            ...(changes ? { changes } : {}),
            ...(footprint ? { footprint } : {}),
            ...(code ? { error_code: code } : {}),
          };
          await gateway(args, ctx).project({
            status,
            started_at: args.admission.started_at,
            finished_at: new Date().toISOString(),
            result,
            error_code: status === "cancelled" ? null : code ?? null,
          });
          if (status === "failed") throw new Error(code);
          return { status };
        },
      },
    },
  };
}
export const behavior = recipeDirect();
