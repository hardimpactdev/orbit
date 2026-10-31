import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import { DirectArgs, GatewayOperation, Globals } from "../_lib/direct.ts";
import {
  Code,
  NodeRelease,
  publishCli,
  publishObserver,
} from "./release_local.ts";

const Kind = z.enum(["fleet.rollout", "fleet.cli_converge"]);
const Args = DirectArgs.extend({
  kind: Kind,
  node_id: z.number().int().positive(),
});
const Input = z.object({
  nodes: z.array(NodeRelease).max(1000),
  remaining: z.number().int().nonnegative().default(0),
  rollout_id: z.number().int().positive().optional(),
  commit: z.string().optional(),
}).strict();
const Current = z.object({
  id: z.number(),
  kind: Kind,
  node_id: z.number(),
  input: Input,
  status: z.enum(["queued", "running", "succeeded", "failed", "cancelled"]),
  cancel_requested: z.boolean(),
});
const Admission = z.object({
  ready: z.boolean(),
  started_at: z.string(),
  nodes: z.array(NodeRelease),
  status: z.enum(["running", "succeeded", "failed", "cancelled"]),
});
export const NodeResult = z.object({
  node_id: z.number().int().positive(),
  outcome: z.enum([
    "converged",
    "unchanged",
    "deferred",
    "skipped",
    "failed",
    "cancelled",
    "pending",
  ]),
  error_code: Code.nullable(),
  cli_version: z.string().nullable(),
  agent_version: z.string().nullable(),
  step: z.enum(["admission", "cli", "agent", "verify"]),
  ok: z.boolean(),
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

const terminal = (status: string) =>
  ["succeeded", "failed", "cancelled"].includes(status);
export function windowOpen(daytime: boolean, at: Date): boolean {
  const hour = Number(new Intl.DateTimeFormat("en-GB", {
    timeZone: "Europe/Amsterdam",
    hour: "2-digit",
    hourCycle: "h23",
  }).format(at));
  return daytime || hour >= 3 && hour < 6;
}

/** Native dependencies, placed Node steps and durable outputs own the release lifecycle. */
export function fleetRelease(
  cliInstall: typeof publishCli = publishCli,
  observerInstall: typeof publishObserver = publishObserver,
  makeHttp: (ctx: Context) => Http = (ctx) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  now = () => new Date(),
) {
  async function read(args: z.infer<typeof Args>, ctx: Context) {
    const gateway = new GatewayOperation(makeHttp(ctx), args);
    const current = Current.parse(await gateway.read(ctx.signal));
    if (
      current.id !== args.operation_id || current.kind !== args.kind ||
      current.node_id !== args.node_id
    ) {
      throw new Error("Fleet release intent differs from this native root");
    }
    return { gateway, current };
  }
  return {
    type: "@nckrtl/fleet-release",
    version: "2026.10.31.1",
    globalArguments: Globals,
    resources: {
      admission: {
        description: "Immutable fleet release participants",
        schema: Admission,
        lifetime: "1d",
        garbageCollection: 10,
      },
      node: {
        description: "Bounded publication outcome on one Node",
        schema: NodeResult,
        lifetime: "1d",
        garbageCollection: 10,
      },
    },
    methods: {
      prepare: {
        description:
          "Read admitted participants and start their single native release run",
        arguments: Args,
        execute: async (raw: z.infer<typeof Args>, ctx: Context) => {
          const args = Args.parse(raw);
          const { gateway, current } = await read(args, ctx);
          const started_at = now().toISOString();
          let status: z.infer<typeof Admission>["status"] = "running";
          if (terminal(current.status)) {
            status = current.status as typeof status;
          } else if (current.cancel_requested || ctx.signal?.aborted) {
            status = "cancelled";
            await gateway.project({
              status,
              started_at,
              finished_at: started_at,
              result: {},
            });
          } else await gateway.project({ status, started_at });
          const result = Admission.parse({
            ready: status === "running",
            started_at,
            status,
            nodes: status === "running" ? current.input.nodes : [],
          });
          await ctx.writeResource("admission", "admission", result);
          return result;
        },
      },
      node: {
        description:
          "Publish pinned CLI and observer binaries on this placed Node",
        arguments: Args.extend({
          target_node_id: z.number().int().positive(),
          previous: z.record(z.string(), z.unknown()),
        }),
        execute: async (
          raw: z.infer<typeof Args> & {
            target_node_id: number;
            previous: Record<string, unknown>;
          },
          ctx: Context,
        ) => {
          const args = Args.parse(raw);
          const { current } = await read(args, ctx);
          const node = current.input.nodes.find((v) =>
            v.node_id === raw.target_node_id
          );
          if (!node) {
            throw new Error("Node has no open fleet release intent");
          }
          let result: z.infer<typeof NodeResult> = {
            node_id: node.node_id,
            outcome: "unchanged",
            error_code: null,
            cli_version: null,
            agent_version: null,
            step: "admission",
            ok: true,
          };
          // The native forEach concurrency cap is one. Earlier outputs must exist
          // before a later Node can act; an uncertain or refused effect stops the fleet.
          const index = current.input.nodes.indexOf(node);
          for (let prior = 0; prior < index; prior++) {
            const step = z.object({ outputs: NodeResult.optional() }).safeParse(
              raw.previous[`node-${prior}`],
            );
            if (!step.success || !step.data.outputs?.ok) {
              const stopped = {
                ...result,
                outcome: "pending" as const,
                ok: false,
              };
              await ctx.writeResource("node", `node-${node.node_id}`, stopped);
              return stopped;
            }
          }
          const refusal = (
            code: string,
            step: z.infer<typeof NodeResult>["step"],
          ) => {
            const deferred = [
              "fleet.window_closed",
              "node.update_busy",
              "cli.update_busy",
            ]
              .includes(code);
            result = {
              ...result,
              step,
              error_code: Code.parse(code),
              outcome: deferred
                ? "deferred"
                : code === "cli.foreign_binary"
                ? "skipped"
                : code === "operation.cancelled"
                ? "cancelled"
                : "failed",
              ok: deferred || code === "cli.foreign_binary",
            };
          };
          const cancellation = async () =>
            (await read(args, ctx)).current.cancel_requested ||
            ctx.signal?.aborted === true;
          if (
            terminal(current.status) || current.cancel_requested ||
            ctx.signal?.aborted
          ) {
            refusal("operation.cancelled", "admission");
          } else if (node.skip_reason !== null) result.outcome = "skipped";
          else if (node.plan_error !== null) {
            refusal(node.plan_error, "admission");
          } else if (!windowOpen(node.daytime, now())) {
            refusal("fleet.window_closed", "admission");
          } else {
            const cli = await cliInstall(node, undefined, ctx.signal);
            if ("error_code" in cli) refusal(cli.error_code, "cli");
            else if (!("installed" in cli)) {
              throw new Error("CLI publication returned no CLI result");
            } else {
              result.cli_version = cli.version;
              result.outcome = cli.installed !== null || cli.configured
                ? "converged"
                : "unchanged";
              result.step = "cli";
              if (args.kind === "fleet.rollout") {
                if (await cancellation()) {
                  refusal("operation.cancelled", "agent");
                } else if (!windowOpen(node.daytime, now())) {
                  refusal("fleet.window_closed", "agent");
                } else {
                  if (!node.agent) {
                    throw new Error("Rollout has no pinned agent delivery");
                  }
                  const agent = await observerInstall(
                    node,
                    undefined,
                    ctx.signal,
                  );
                  if ("error_code" in agent) refusal(agent.error_code, "agent");
                  else if (!("changed" in agent)) {
                    throw new Error(
                      "Agent publication returned no observer result",
                    );
                  } else {
                    result.agent_version = agent.version;
                    result.step = "verify";
                    if (agent.changed) result.outcome = "converged";
                  }
                }
              }
            }
          }
          const safe = NodeResult.parse(result);
          await ctx.writeResource("node", `node-${node.node_id}`, safe);
          return safe;
        },
      },
      finish: {
        description:
          "Record the native fleet outcome; uncertain effects have no invented completion",
        arguments: Args.extend({
          admission: Admission,
          node_results: z.record(z.string(), z.unknown()),
        }),
        execute: async (
          raw: z.infer<typeof Args> & {
            admission: z.infer<typeof Admission>;
            node_results: Record<string, unknown>;
          },
          ctx: Context,
        ) => {
          const args = Args.parse(raw);
          if (!raw.admission.ready) return { status: raw.admission.status };
          const { gateway, current } = await read(args, ctx);
          const nodes: z.infer<typeof NodeResult>[] = [];
          for (const [index, node] of current.input.nodes.entries()) {
            const step = z.object({ outputs: NodeResult.optional() }).safeParse(
              raw.node_results[`node-${index}`],
            );
            const output = step.success ? step.data.outputs : undefined;
            if (output && output.node_id !== node.node_id) {
              throw new Error("Native Node output differs from admitted order");
            }
            nodes.push(
              output ?? {
                node_id: node.node_id,
                outcome: "failed",
                ok: false,
                error_code: "fleet.release_uncertain",
                cli_version: null,
                agent_version: null,
                step: "admission",
              },
            );
          }
          const failure = nodes.find((node) =>
            !node.ok && node.outcome !== "pending"
          );
          if (!failure && nodes.some((node) => node.outcome === "pending")) {
            throw new Error("Native fleet has no bounded predecessor result");
          }
          const cancelled = failure?.outcome === "cancelled";
          const status = cancelled
            ? "cancelled"
            : failure
            ? "failed"
            : "succeeded";
          const running = current.input.remaining > 0 ||
            nodes.some((node) => node.outcome === "deferred");
          const result = {
            nodes,
            ...(failure ? { error_code: failure.error_code } : {}),
            ...(args.kind === "fleet.rollout"
              ? {
                rollout_status: failure
                  ? "halted"
                  : running
                  ? "running"
                  : "completed",
                halted_node_id: failure?.node_id ?? null,
              }
              : {}),
          };
          await gateway.project({
            status,
            started_at: raw.admission.started_at,
            finished_at: now().toISOString(),
            result,
            error_code: cancelled ? null : failure?.error_code ?? null,
          });
          if (failure && !cancelled) throw new Error(failure.error_code!);
          return { status };
        },
      },
    },
  };
}
export const behavior = fleetRelease();
