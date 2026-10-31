import { isDeepStrictEqual } from "node:util";
import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import { DirectArgs, GatewayOperation, Globals } from "../_lib/direct.ts";
import { processAction } from "../processes-schedules/process-control.ts";
import { Input as ProcessInput } from "../processes-schedules/process-input.ts";
import {
  type DeploySourceLocal,
  LocalDeployError,
  localDeploySource,
} from "./deploy_local.ts";
import {
  DeploySourceInput,
  productionDeployHome,
  Release,
} from "./deploy_source.ts";
import { DeployPhpInput, refreshDeployPhp } from "./deploy_php.ts";
import { PhpLocalError } from "../php/local.ts";
import {
  assertDeploymentExecutionAvailable,
  readDeploymentExecution,
  sameDeploymentExecution,
  saveDeploymentExecution,
} from "./deployment_execution.ts";

const Args = DirectArgs.extend({
  kind: z.literal("instance.rollback"),
  node_id: z.number().int().positive(),
});
const Restart = ProcessInput.omit({ agent_sha256: true });
const Input = DeploySourceInput.extend({
  release: Release.shape.name,
  deployment: DeploySourceInput.shape.deployment.extend({
    variant: z.literal("rollback"),
  }),
  php_input: DeployPhpInput.shape.php,
  runtime_definitions: z.object({
    processes: z.array(z.never()).max(0),
    schedules: z.array(z.never()).max(0),
    restart: z.array(Restart).max(256),
  }).strict(),
});
const Checkpoint = z.object({
  operation_id: z.number().int().positive(),
  run_id: z.string().uuid(),
  started_at: z.string(),
  active: z.boolean(),
  phase: z.string(),
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
type Ports = {
  http?: (ctx: Context) => Http;
  source?: DeploySourceLocal;
  php?: typeof refreshDeployPhp;
  restart?: ReturnType<typeof processAction>;
  now?: () => number;
};
class Refused extends Error {}

/** One retained-release rollback root. It never fetches, prepares a release or runs application commands. */
export function rollbackDirect(ports: Ports = {}) {
  const now = ports.now ?? Date.now;
  const gatewayFor = (args: z.infer<typeof Args>, ctx: Context) =>
    new GatewayOperation(
      (ports.http ??
        ((ctx: Context) =>
          gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile)))(ctx),
      args,
    );
  const journalFor = async (args: z.infer<typeof Args>, ctx: Context) => {
    const saved = await ctx.readResource(
      `rollback-${args.operation_id}-${args.run_id}`,
    );
    const journal = saved === null ? null : Checkpoint.parse(saved);
    if (
      journal &&
      (journal.operation_id !== args.operation_id ||
        journal.run_id !== args.run_id)
    ) throw new Error("deployment.identity_changed");
    return journal;
  };
  return {
    type: "@nckrtl/instance-rollback-direct",
    version: "2026.10.31.1",
    globalArguments: Globals,
    resources: {
      rollbackExecution: {
        description: "Public rollback effect boundary",
        schema: Checkpoint,
        lifetime: "infinite",
        garbageCollection: 10,
      },
    },
    methods: {
      failure: {
        description:
          "Settle a failed root only when no local effect is unresolved",
        arguments: Args,
        execute: async (raw: z.infer<typeof Args>, ctx: Context) => {
          const args = Args.parse(raw), journal = await journalFor(args, ctx);
          const shared = await readDeploymentExecution(ctx);
          if (
            journal?.active ||
            (shared?.active &&
              (!sameDeploymentExecution(args, shared) || journal === null))
          ) return { status: "held" };
          await saveDeploymentExecution(ctx, args, false);
          const at = new Date(now()).toISOString();
          await gatewayFor(args, ctx).project({
            status: "failed",
            started_at: journal?.started_at ?? at,
            finished_at: at,
            error_code: "deployment.interrupted",
            result: {
              failed_step: journal?.phase ?? "native_execution",
              selection_confirmed: false,
            },
          });
          return { status: "failed" };
        },
      },
      run: {
        description:
          "Select the admitted retained release, refresh PHP and restart captured Processes",
        arguments: Args,
        execute: async (raw: z.infer<typeof Args>, ctx: Context) => {
          const args = Args.parse(raw), gateway = gatewayFor(args, ctx);
          let activated = false;
          const read = async () => {
            const row = z.object({
              id: z.number().int(),
              node_id: z.number().int(),
              kind: z.literal("instance.rollback"),
              status: z.string(),
              cancel_requested: z.boolean(),
              input: z.unknown(),
            }).parse(await gateway.read(activated ? undefined : ctx.signal));
            if (row.id !== args.operation_id || row.node_id !== args.node_id) {
              throw new Refused("deployment.identity_changed");
            }
            return row;
          };
          const initial = await read();
          if (["succeeded", "failed", "cancelled"].includes(initial.status)) {
            return { status: initial.status };
          }
          const shared = await assertDeploymentExecutionAvailable(ctx, args);
          const input = Input.parse(initial.input),
            home = productionDeployHome(input),
            saved = await journalFor(args, ctx);
          if (input.node_id !== args.node_id) {
            throw new Refused("deployment.identity_changed");
          }
          if (saved?.active || (shared?.active && saved === null)) {
            throw new Error("deployment.execution_unconfirmed");
          }
          const started_at = saved?.started_at ?? new Date(now()).toISOString(),
            deadline = now() + 900000;
          let phase = "rollback_selection",
            active = false,
            error_code: string | null = null;
          const result = {
            instance_id: input.instance_id,
            commit: null as string | null,
            release: null as z.infer<typeof Release> | null,
            previous: null as z.infer<typeof Release> | null,
            selected_release: null as z.infer<typeof Release> | null,
            selection_confirmed: false,
            failed_step: null as string | null,
            runtime_definitions: {
              processes: [],
              schedules: [],
              restart: [] as Record<string, unknown>[],
            },
          };
          const mark = async (value: boolean) => {
            await ctx.writeResource(
              "rollbackExecution",
              `rollback-${args.operation_id}-${args.run_id}`,
              {
                operation_id: args.operation_id,
                run_id: args.run_id,
                started_at,
                active: value,
                phase,
              },
            );
            await saveDeploymentExecution(ctx, args, value);
            active = value;
          };
          const current = async () => {
            const row = await read();
            if (
              row.status !== "running" ||
              !isDeepStrictEqual(Input.parse(row.input), input)
            ) throw new Refused("deployment.identity_changed");
            return {
              cancel_requested: activated ? false : row.cancel_requested,
            };
          };
          const check = async () => {
            if (
              (await current()).cancel_requested ||
              (!activated && ctx.signal?.aborted)
            ) throw new Refused("operation.cancelled");
            if (now() >= deadline) {
              throw new Refused("deployment.deadline_exceeded");
            }
          };
          const local = ports.source ?? localDeploySource({ deadline });
          const effect = async <T>(use: () => Promise<T>): Promise<T> => {
            await check();
            await mark(true);
            try {
              const value = await use();
              if (
                typeof value === "object" && value !== null &&
                "error_code" in value &&
                /execution_(unconfirmed|uncertain)/.test(
                  String(value.error_code),
                )
              ) throw new Error("deployment.execution_unconfirmed");
              await mark(false);
              return value;
            } catch (error) {
              if (
                (error instanceof LocalDeployError &&
                  !/execution_(unconfirmed|uncertain)/.test(error.code)) ||
                (error instanceof PhpLocalError && error.restored === true)
              ) await mark(false);
              throw error;
            }
          };
          try {
            if (saved || !["queued", "running"].includes(initial.status)) {
              if (saved !== null) {
                await saveDeploymentExecution(ctx, args, false);
              }
              throw new Refused("deployment.interrupted");
            }
            await mark(false);
            await gateway.project({
              status: "running",
              started_at,
              result: {},
            });
            await check();
            result.previous = Release.nullable().parse(
              (await local.inspectRelease(home)).release,
            );
            result.selected_release = result.previous;
            result.selection_confirmed = true;
            const release = Release.parse(
              (await local.inspectRelease(home, input.release)).release,
            );
            if (release.name !== input.release) {
              throw new Refused("rollback.release_invalid");
            }
            result.release = release;
            result.commit = release.commit;
            phase = "activation";
            const selected = Release.parse(
              await effect(() => local.activateRelease(home, release)),
            );
            if (!isDeepStrictEqual(selected, release)) {
              throw new Refused("deployment.selection_invalid");
            }
            activated = true;
            result.selected_release = selected;
            phase = "php_refresh";
            const php = await (ports.php ?? refreshDeployPhp)(
              {
                project_id: input.project_id,
                php: input.php_input,
              },
              deadline,
              { current, now, mutation: effect },
            );
            if ("error_code" in php) throw new Refused(php.error_code);
            phase = "process_restart";
            for (const member of input.runtime_definitions.restart) {
              const restart = await effect(() =>
                (ports.restart ?? processAction())(
                  "process.restart",
                  ProcessInput.parse(member),
                )
              );
              result.runtime_definitions.restart.push({
                process_id: member.process_id,
                ...restart,
              });
              if ("error_code" in restart) {
                throw new Refused(String(restart.error_code));
              }
            }
          } catch (error) {
            error_code = error instanceof Refused
              ? error.message
              : error instanceof LocalDeployError
              ? error.code
              : "deployment.interrupted";
            result.failed_step = phase;
            if (phase === "activation") {
              result.selection_confirmed = false;
              try {
                result.selected_release = Release.nullable().parse(
                  (await local.inspectRelease(home)).release,
                );
                result.selection_confirmed = true;
              } catch { /* An unknown selection is not replaced by a guess. */ }
            }
          }
          if (active || error_code === "deployment.execution_unconfirmed") {
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
            error_code: status === "failed" ? error_code : null,
            result,
          });
          if (status === "failed") throw new Error(error_code!);
          return { status };
        },
      },
    },
  };
}
