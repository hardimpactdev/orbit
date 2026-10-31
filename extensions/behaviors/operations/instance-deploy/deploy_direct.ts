import { isDeepStrictEqual } from "node:util";
import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import { DirectArgs, GatewayOperation } from "../_lib/direct.ts";
import { runNodeAgent } from "../_lib/node-agent.ts";
import { currentProgram, sudoAppStep } from "./app_step.ts";
import { DeploySourceInput, prepareDeploySource } from "./deploy_source.ts";
import { DeployAppInput, runDeployPhase } from "./deploy_steps.ts";
import { prepareDeployVp } from "./deploy_vp.ts";

const Args = DirectArgs.extend({
  kind: z.literal("instance.deploy"),
  node_id: z.number().int().positive(),
});
const Code = z.string().regex(/^[a-z0-9_.-]{1,100}$/);
const Input = DeploySourceInput.extend({
  deployment: DeploySourceInput.shape.deployment.extend({
    variant: z.literal("development"),
    node_user: z.string().regex(/^[a-z_][a-z0-9_-]{0,63}$/).refine((x) =>
      x !== "root"
    ),
    needs_vp: z.boolean(),
    projection_route_id: z.number().int().positive().nullable(),
    steps: DeployAppInput.shape.steps,
  }),
});
const Checkpoint = z.object({
  operation_id: z.number().int().positive(),
  run_id: z.string().uuid(),
  started_at: z.string(),
  active: z.boolean(),
}).strict();
type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  readResource(name: string): Promise<Record<string, unknown> | null>;
  writeResource(
    spec: string,
    name: string,
    value: Record<string, unknown>,
  ): Promise<unknown>;
};
class Refused extends Error {}
type Ports = {
  http?: (ctx: Context) => Http;
  invoke?: typeof runNodeAgent;
  run?: typeof sudoAppStep;
  now?: () => number;
};

/** Native development deploy; the admitted environment selects its configured Node account. */
export function deployDirect(ports: Ports = {}) {
  const now = ports.now ?? Date.now;
  return {
    type: "@nckrtl/instance-deploy-direct",
    version: "2026.10.31.1",
    globalArguments: Globals,
    resources: {
      execution: {
        description:
          "Public effect checkpoint; no private material or command output",
        schema: Checkpoint,
        lifetime: "infinite",
        garbageCollection: 10,
      },
    },
    methods: {
      run: {
        description:
          "Run the admitted deploy on its exact Node in one native run",
        arguments: Args,
        execute: async (raw: z.infer<typeof Args>, ctx: Context) => {
          const args = Args.parse(raw);
          const http = (ports.http ??
            ((ctx: Context) =>
              gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile)))(
              ctx,
            );
          const gateway = new GatewayOperation(http, args);
          const read = async () => {
            const current = z.object({
              id: z.number().int(),
              kind: z.literal("instance.deploy"),
              node_id: z.number().int(),
              status: z.string(),
              input: z.unknown(),
              cancel_requested: z.boolean(),
            }).parse(await gateway.read(ctx.signal));
            if (
              current.id !== args.operation_id ||
              current.node_id !== args.node_id
            ) {
              throw new Refused("deployment.identity_changed");
            }
            return current;
          };
          const initial = await read();
          if (["succeeded", "failed", "cancelled"].includes(initial.status)) {
            return { status: initial.status };
          }
          const input = Input.parse(initial.input), d = input.deployment;
          const checkpointName =
            `execution-${args.operation_id}-${args.run_id}`;
          const stored = await ctx.readResource(checkpointName);
          const checkpoint = stored === null ? null : Checkpoint.parse(stored);
          if (
            checkpoint &&
            (checkpoint.operation_id !== args.operation_id ||
              checkpoint.run_id !== args.run_id)
          ) throw new Error("deployment.identity_changed");
          if (checkpoint?.active) {
            throw new Error("deployment.execution_unconfirmed");
          }
          const started_at = checkpoint?.started_at ??
            new Date(now()).toISOString();
          let effectActive = false;
          const mark = async (active: boolean) => {
            await ctx.writeResource("execution", checkpointName, {
              operation_id: args.operation_id,
              run_id: args.run_id,
              started_at,
              active,
            });
            effectActive = active;
          };
          const invoke: typeof runNodeAgent = async (call, signal) => {
            if (call.mutates) await mark(true);
            const reply = await (ports.invoke ?? runNodeAgent)(call, signal);
            if (
              call.mutates &&
              !/execution_(unconfirmed|uncertain)/.test(
                String(reply.error_code ?? ""),
              )
            ) await mark(false);
            return reply;
          };
          const run: typeof sudoAppStep = async (...parameters) => {
            await mark(true);
            const reply = await (ports.run ?? sudoAppStep)(...parameters);
            if (
              !("error_code" in reply) ||
              !/execution_(unconfirmed|uncertain)/.test(reply.error_code)
            ) await mark(false);
            return reply;
          };
          const deadline = now() +
            (900 +
                d.steps.reduce(
                  (total, step) => total + step.timeout_seconds,
                  0,
                )) * 1000;
          const result = {
            instance_id: input.instance_id,
            commit: input.commit,
            release: null,
            previous: null,
            selected_release: null,
            selection_confirmed: true,
            failed_step: null as string | null,
            warnings: 0,
            output_tail: null as string | null,
          };
          let phase = "source_preparation", error_code: string | null = null;
          const current = async () => {
            const current = await read();
            if (
              current.status !== "running" ||
              !isDeepStrictEqual(Input.parse(current.input), input)
            ) {
              throw new Refused("deployment.identity_changed");
            }
            return current;
          };
          const check = async () => {
            const row = await current();
            if (row.cancel_requested || ctx.signal?.aborted) {
              throw new Refused("operation.cancelled");
            }
            if (now() >= deadline) {
              throw new Refused("deployment.deadline_exceeded");
            }
            return row;
          };
          const call = async (
            name: string,
            data: Record<string, unknown>,
            sudo: boolean,
          ) => {
            await check();
            const reply = await (ports.invoke ?? runNodeAgent)({
              name,
              input: data,
              pin: input.agent_sha256,
              sudo,
              mutates: false,
              deadlineMs: Math.min(120000, deadline - now()),
            }, ctx.signal);
            if ("error_code" in reply) {
              throw new Refused(Code.parse(reply.error_code));
            }
            return reply;
          };
          try {
            if (initial.status !== "queued") {
              throw new Refused(
                initial.cancel_requested
                  ? "operation.cancelled"
                  : "deployment.interrupted",
              );
            }
            await mark(false);
            await gateway.project({
              status: "running",
              started_at,
              result: {},
            });
            await check();
            // Native automatic policy settles pending publication before admission.
            if (d.projection_route_id !== null) {
              throw new Refused("deployment.projection_pending");
            }
            const source = await prepareDeploySource(input, deadline, {
              invoke,
              current,
              now,
              git: (use) =>
                gateway.withPrivateMaterial(async (material) => {
                  await use({
                    github_token: z.string().nullable().parse(
                      material.git_read,
                    ),
                  });
                }),
            }, ctx.signal);
            if ("error_code" in source) {
              throw new Refused(Code.parse(source.error_code));
            }
            result.commit = source.commit;
            let runtime: z.infer<typeof DeployAppInput>["vp_environment"] =
              null;
            if (d.needs_vp) {
              phase = "vp_environment";
              await check();
              let outcome: Awaited<ReturnType<typeof prepareDeployVp>> = {
                error_code: "deployment.vp_store_failed",
              };
              await gateway.withPrivateMaterial(async (material) => {
                outcome = await prepareDeployVp(
                  {
                    agent_sha256: input.agent_sha256,
                    path: d.home,
                    user: d.node_user,
                    node_user: d.node_user,
                    materialize: z.record(z.string(), z.unknown()).parse(
                      material.vp_materialize,
                    ),
                  },
                  deadline,
                  {
                    invoke,
                    current,
                    now,
                    reference: async (pins, status) => {
                      await check();
                      await gateway.project({
                        status: "running",
                        started_at,
                        result: { vp_environment: { ...pins, status } },
                      });
                    },
                  },
                  ctx.signal,
                );
              });
              const resolved = outcome as Awaited<
                ReturnType<typeof prepareDeployVp>
              >;
              if ("error_code" in resolved) {
                throw new Refused(Code.parse(resolved.error_code));
              }
              runtime = resolved.runtime === null
                ? null
                : { ...resolved.runtime, owner: d.node_user };
            }
            phase = "before_activation";
            const app = DeployAppInput.parse({
              instance_id: input.instance_id,
              project_id: input.project_id,
              target: {
                variant: "checkout",
                user: d.node_user,
                directory: d.home,
                seed_path: "",
                seed_commit: "",
              },
              steps: d.steps,
              vp_environment: runtime,
            });
            const commands = await runDeployPhase(
              app,
              "before_activation",
              deadline,
              {
                read: async () => {
                  const row = await current();
                  return { input: app, cancel_requested: row.cancel_requested };
                },
                program: async (step, use) => {
                  await check();
                  await use(await currentProgram(http, step));
                },
                run,
                now,
              },
              ctx.signal,
            );
            if ("error_code" in commands) {
              result.failed_step = commands.failed_step;
              result.output_tail = commands.output_tail?.replaceAll("\0", "") ||
                null;
              throw new Refused(Code.parse(commands.error_code));
            }
            result.warnings = commands.warnings;
            phase = "verification";
            const account = z.object({
              user: z.literal(d.node_user),
              uid: z.number().int().positive(),
              group: z.string().regex(/^[a-z_][a-z0-9_-]{0,63}$/),
            }).parse(
              await call("account.lookup", { user: d.node_user }, false),
            );
            const root = d.home.slice(0, d.home.lastIndexOf("/")).replace(
              /\/[^/]+$/,
              "",
            );
            const checked = z.object({
              origin: z.literal(d.repository),
              branch: z.literal(d.branch),
              commit: z.literal(source.commit),
            }).parse(
              await call("instance.inspect-development-source", {
                repository: d.repository,
                checkout: d.home,
                root,
                user: account.user,
                group: account.group,
                verify_origin: true,
                require_idle: true,
              }, false),
            );
            result.commit = checked.commit;
          } catch (error) {
            error_code = error instanceof Refused
              ? error.message
              : "deployment.interrupted";
            result.failed_step ??= phase;
          }
          if (
            effectActive || error_code === "deployment.execution_unconfirmed"
          ) {
            throw new Error("deployment.execution_unconfirmed");
          }
          const status = error_code === null
            ? "succeeded"
            : error_code === "operation.cancelled"
            ? "cancelled"
            : "failed";
          await gateway.project({
            status,
            started_at,
            finished_at: new Date(now()).toISOString(),
            result,
            error_code: status === "failed" ? error_code : null,
          });
          if (status === "failed") throw new Error(error_code!);
          return { status };
        },
      },
    },
  };
}

export const behavior = deployDirect();
