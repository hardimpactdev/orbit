import { isDeepStrictEqual } from "node:util";
import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../_lib/direct.ts";
import { DirectArgs, GatewayOperation } from "../_lib/direct.ts";
import {
  type DeploySourceLocal,
  LocalDeployError,
  localDeploySource,
} from "./deploy_local.ts";
import { inspectDeployRunner } from "./runner_local.ts";
import {
  lookupScheduleAccount,
  scheduleSystem,
} from "../processes-schedules/schedule-system.ts";
import { localProcessAction } from "../processes-schedules/native-action.ts";
import {
  installSchedule,
  ScheduleInstallation,
} from "../processes-schedules/installation.ts";
import { processAction } from "../processes-schedules/process-control.ts";
import { Input as ProcessControl } from "../processes-schedules/process-input.ts";
import {
  AppStep,
  AppVpRuntime,
  currentProgram,
  sha256,
  stepRunner,
  sudoAppStep,
} from "./app_step.ts";
import { synchronizeDeployEnvironment } from "./deploy_environment.ts";
import { localEnvironment } from "./environment_local.ts";
import { DeployPhpInput, refreshDeployPhp } from "./deploy_php.ts";
import { PhpLocalError } from "../php/local.ts";
import {
  assertDeploymentExecutionAvailable,
  saveDeploymentExecution,
} from "./deployment_execution.ts";
import {
  activateDeployRelease,
  pruneDeployReleases,
} from "./deploy_release.ts";
import {
  DeploySourceInput,
  prepareDeploySource,
  productionDeployHome,
  Release,
} from "./deploy_source.ts";
import { DeployAppInput, runDeployPhase } from "./deploy_steps.ts";
import { prepareDeployVp } from "./deploy_vp.ts";

const Code = z.string().regex(/^[a-z0-9_.-]{1,100}$/);
const SafeObject = z.record(z.string(), z.unknown());
const PrivateMap = z.preprocess(
  (value) => Array.isArray(value) && value.length === 0 ? {} : value,
  SafeObject,
);
const Args = DirectArgs.extend({
  kind: z.literal("instance.deploy"),
  node_id: z.number().int().positive(),
});
const Input = DeploySourceInput.extend({
  release_name: Release.shape.name,
  deployment: DeploySourceInput.shape.deployment.extend({
    variant: z.literal("production"),
    needs_vp: z.boolean(),
    steps: DeployAppInput.shape.steps,
  }),
  environment: z.object({
    base: z.string(),
    user: z.string(),
    environment: z.literal("production"),
    domain: z.string().nullable(),
  }).strict(),
  php_input: SafeObject.nullable(),
  runtime_definitions: z.object({
    processes: z.array(SafeObject).max(4096),
    schedules: z.array(
      ScheduleInstallation.omit({ group: true }),
    ).max(4096),
    restart: z.array(ProcessControl.omit({ agent_sha256: true })).max(4096),
  }).strict(),
});
const Member = z.object({
  process_id: z.number().int().positive(),
  ready: z.boolean(),
  state: z.string().optional(),
  error_code: Code.optional(),
}).strict();
const ScheduleMember = z.object({
  id: z.string().uuid(),
  ready: z.boolean(),
  error_code: Code.optional(),
}).strict();
const Result = z.object({
  instance_id: z.number().int().positive(),
  commit: Release.shape.commit.nullable(),
  release: Release.nullable(),
  previous: Release.nullable(),
  selected_release: Release.nullable(),
  selection_confirmed: z.boolean(),
  failed_step: z.string().nullable(),
  warnings: z.number().int().min(0),
  output_tail: z.string().nullable(),
  step_outcome: z.literal("unknown").optional(),
  environment: z.object({ keys: z.array(z.string()).max(1024) }).strict()
    .optional(),
  runtime_definitions: z.object({
    processes: z.array(Member),
    schedules: z.array(ScheduleMember),
    restart: z.array(Member),
  }).strict(),
}).strict();
export const ProductionCheckpoint = z.object({
  operation_id: z.number().int().positive(),
  run_id: z.string().uuid(),
  input_hash: z.string(),
  started_at: z.string(),
  stage: z.number().int().min(0).max(11),
  active: z.boolean(),
  step: AppStep.pick({ name: true, phase: true }).nullable(),
  runtime: AppVpRuntime.nullable(),
  result: Result,
  outcome: z.object({
    status: z.enum(["succeeded", "failed", "cancelled"]),
    error_code: Code.nullable(),
  }).nullable(),
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
  inspectRunner?: typeof inspectDeployRunner;
  environment?: Pick<
    Parameters<typeof synchronizeDeployEnvironment>[2],
    "access"
  >;
  vp?: Pick<Parameters<typeof prepareDeployVp>[2], "local" | "materialize">;
  php?: Pick<
    Parameters<typeof refreshDeployPhp>[2],
    "install" | "profile" | "runtime" | "refresh"
  >;
  processControl?: Parameters<typeof processAction>[0];
  schedule?: typeof scheduleSystem;
  account?: typeof lookupScheduleAccount;
  run?: typeof sudoAppStep;
  now?: () => number;
};
class Refused extends Error {}
const failed = (value: unknown) => {
  if (value !== null && typeof value === "object" && "error_code" in value) {
    throw new Refused(Code.parse(value.error_code));
  }
};

/** Production branch of the existing placed native parent. Checkpoints contain public facts only. */
export async function runProductionDeploy(
  raw: z.infer<typeof Args>,
  ctx: Context,
  ports: Ports = {},
  segment: "prepare" | "finish" = "prepare",
) {
  const args = Args.parse(raw),
    now = ports.now ?? Date.now,
    http = (ports.http ??
      ((ctx: Context) =>
        gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile)))(ctx),
    gateway = new GatewayOperation(http, args);
  const read = async () => {
    const row = z.object({
      id: z.literal(args.operation_id),
      kind: z.literal("instance.deploy"),
      node_id: z.literal(args.node_id),
      status: z.string(),
      cancel_requested: z.boolean(),
      input: z.unknown(),
    }).parse(await gateway.read(ctx.signal));
    return row;
  };
  const initial = await read();
  if (["succeeded", "failed", "cancelled"].includes(initial.status)) {
    return { status: initial.status };
  }
  const activeOwner = await assertDeploymentExecutionAvailable(ctx, args);
  const input = Input.parse(initial.input),
    d = input.deployment,
    home = productionDeployHome(input);
  DeployAppInput.parse({
    instance_id: input.instance_id,
    project_id: input.project_id,
    target: { variant: "release", release: input.release_name },
    steps: d.steps,
  });
  DeployPhpInput.parse({ project_id: input.project_id, php: input.php_input });

  if (
    input.environment.base !== home.home || input.environment.user !== home.user
  ) throw new Error("deployment.identity_changed");
  const name = `production-${args.operation_id}-${args.run_id}`,
    stored = await ctx.readResource(name),
    input_hash = await sha256(JSON.stringify(input));
  if (activeOwner?.active && stored === null) {
    throw new Error("deployment.execution_unconfirmed");
  }
  const state = stored === null
    ? ProductionCheckpoint.parse({
      operation_id: args.operation_id,
      run_id: args.run_id,
      input_hash,
      started_at: new Date(now()).toISOString(),
      stage: 0,
      active: false,
      step: null,
      runtime: null,
      outcome: null,
      result: {
        instance_id: input.instance_id,
        commit: input.commit,
        release: null,
        previous: null,
        selected_release: null,
        selection_confirmed: false,
        failed_step: null,
        warnings: 0,
        output_tail: null,
        runtime_definitions: { processes: [], schedules: [], restart: [] },
      },
    })
    : ProductionCheckpoint.parse(stored);
  if (
    state.operation_id !== args.operation_id || state.run_id !== args.run_id ||
    state.input_hash !== input_hash
  ) throw new Error("deployment.identity_changed");
  const save = async () => {
    await ctx.writeResource("productionExecution", name, state);
    await saveDeploymentExecution(ctx, args, state.active);
  };
  const project = async () => {
    const outcome = state.outcome!;
    await gateway.project({
      ...outcome,
      started_at: state.started_at,
      finished_at: new Date(now()).toISOString(),
      result: state.result,
    });
    if (outcome.status === "failed") throw new Error(outcome.error_code!);
    return { status: outcome.status };
  };
  if (state.outcome !== null) return await project();
  const deadline = Date.parse(state.started_at) +
    (900 + d.steps.reduce((n, s) => n + s.timeout_seconds, 0)) * 1000;
  const activated = () =>
    state.stage >= 5 && state.result.selection_confirmed &&
    state.result.selected_release !== null;
  const current = async () => {
    const row = await read();
    if (
      row.status !== "running" ||
      !isDeepStrictEqual(Input.parse(row.input), input)
    ) throw new Refused("deployment.identity_changed");
    return {
      ...row,
      cancel_requested: activated() ? false : row.cancel_requested,
    };
  };
  const check = async () => {
    if (ctx.signal?.aborted) {
      throw new Error("deployment.execution_unconfirmed");
    }
    const row = await current();
    if (row.cancel_requested) throw new Refused("operation.cancelled");
    if (now() >= deadline) {
      throw new Refused("deployment.deadline_exceeded");
    }
  };
  let uncertain = false, phase = "operation";
  if (state.active) {
    if (!initial.cancel_requested || !state.step) {
      throw new Error("deployment.execution_unconfirmed");
    }
    const step = state.step;
    if (!d.steps.some((s) => s.name === step.name && s.phase === step.phase)) {
      throw new Error("deployment.identity_changed");
    }
    const runner = stepRunner(args, step);
    const reply = await (ports.inspectRunner ?? inspectDeployRunner)(
      runner,
      ctx.signal,
    );
    if (
      !z.object({ runner: z.literal(runner), gone: z.literal(true) }).strict()
        .safeParse(reply).success
    ) throw new Error("deployment.execution_unconfirmed");
    const row = await read();
    if (
      !row.cancel_requested || row.status !== "running" ||
      !isDeepStrictEqual(Input.parse(row.input), input)
    ) throw new Error("deployment.identity_changed");
    state.result.failed_step = `${step.phase}.${step.name}`;
    state.result.step_outcome = "unknown";
    state.outcome = { status: "failed", error_code: "deployment.interrupted" };
    state.active = false;
    state.step = null;
    await save();
    return await project();
  }
  const mutation = async <T>(action: () => Promise<T>): Promise<T> => {
    await check();
    state.active = true;
    state.step = null;
    await save();
    uncertain = true;
    try {
      const reply = await action();
      const error =
        reply !== null && typeof reply === "object" && "error_code" in reply
          ? String(reply.error_code)
          : "";
      if (!/unconfirmed|uncertain/.test(error)) {
        uncertain = false;
        state.active = false;
        await save();
      }
      return reply;
    } catch (error) {
      if (
        (error instanceof LocalDeployError &&
          !/unconfirmed|uncertain/.test(error.code)) ||
        (error instanceof PhpLocalError && error.restored === true)
      ) {
        uncertain = false;
        state.active = false;
        await save();
      }
      throw error;
    }
  };
  const underlying = ports.source ??
    localDeploySource({ deadline, signal: ctx.signal });
  const source: DeploySourceLocal = {
    inspectDevelopment: (...args) => underlying.inspectDevelopment(...args),
    resolveDevelopment: (...args) =>
      mutation(() => underlying.resolveDevelopment(...args)),
    checkoutDevelopment: (...args) =>
      mutation(() => underlying.checkoutDevelopment(...args)),
    prepareRelease: (...args) =>
      mutation(() => underlying.prepareRelease(...args)),
    inspectRelease: async (...args) => {
      await check();
      const result = await underlying.inspectRelease(...args);
      if (phase === "source_preparation") {
        state.result.previous = Release.nullable().parse(result.release);
        state.result.selected_release = state.result.previous;
        state.result.selection_confirmed = true;
        await save();
      }
      return result;
    },
    activateRelease: (...args) =>
      mutation(async () => {
        state.result.selection_confirmed = false;
        await save();
        return await underlying.activateRelease(...args);
      }),
    pruneReleases: (...args) =>
      mutation(() => underlying.pruneReleases(...args)),
  };
  const run: typeof sudoAppStep = async (step, script, signal) => {
    await check();
    state.active = true;
    state.step = { name: step.name, phase: step.phase };
    await save();
    uncertain = true;
    const reply = await (ports.run ?? sudoAppStep)(step, script, signal, args);
    if (
      !("error_code" in reply) ||
      !/execution_(unconfirmed|uncertain)/.test(reply.error_code)
    ) {
      uncertain = false;
      state.active = false;
      state.step = null;
      await save();
    }
    return reply;
  };
  const controlLocal = ports.processControl ?? localProcessAction;
  const memberControl: NonNullable<Parameters<typeof processAction>[0]> = (
    name,
    data,
    digest,
    signal,
  ) =>
    ["process.check-release", "process.status"].includes(name)
      ? controlLocal(name, data, digest, signal)
      : mutation(() => controlLocal(name, data, digest, signal));
  const materialFor = (id: string) => ({
    withPrivateMaterial: (
      use: (value: Record<string, unknown>) => Promise<void>,
    ) =>
      gateway.withPrivateMaterial(async (material) => {
        const aggregate = z.object({
          schedules: PrivateMap,
        }).parse(material.definitions_material);
        await use(SafeObject.parse(aggregate.schedules[id]));
      }),
  });
  const app = () =>
    DeployAppInput.parse({
      instance_id: input.instance_id,
      project_id: input.project_id,
      target: { variant: "release", release: state.result.release!.name },
      steps: d.steps,
      vp_environment: state.runtime,
    });
  const runPhase = async (p: "before_activation" | "after_activation") => {
    const admitted = app();
    const outcome = await runDeployPhase(admitted, p, deadline, {
      read: async () => ({
        input: admitted,
        cancel_requested: (await current()).cancel_requested,
      }),
      program: async (step, use) => {
        await check();
        await use(await currentProgram(http, step));
      },
      run,
      now,
    }, ctx.signal);
    if ("error_code" in outcome) {
      state.result.failed_step = outcome.failed_step;
      state.result.output_tail = outcome.output_tail?.replaceAll("\0", "") ||
        null;
      failed(outcome);
    } else state.result.warnings += outcome.warnings;
  };
  const stages: [string, () => Promise<void>][] = [
    ["source_preparation", async () => {
      const prepared = await prepareDeploySource(input, deadline, {
        local: source,
        current,
        now,
        git: (use) =>
          gateway.withPrivateMaterial(async (material) => {
            await use({
              github_token: z.string().nullable().parse(material.git_read),
            });
          }),
      }, ctx.signal);
      failed(prepared);
      if (!("error_code" in prepared)) {
        state.result.commit = prepared.commit;
        state.result.release = prepared.release;
        state.result.previous = prepared.previous;
        state.result.selected_release = prepared.previous;
        state.result.selection_confirmed = true;
      }
    }],
    ["environment_sync", async () => {
      const outcome = await synchronizeDeployEnvironment(
        { environment: input.environment },
        deadline,
        {
          access: (call, signal) =>
            call.mode === "inspect"
              ? (ports.environment?.access ?? localEnvironment)(call, signal)
              : mutation(() =>
                (ports.environment?.access ?? localEnvironment)(call, signal)
              ),
          current,
          now,
          material: (use) =>
            gateway.withPrivateMaterial(async (material) => {
              await use(material.environment_material);
            }),
        },
        ctx.signal,
      );
      failed(outcome);
      if (!("error_code" in outcome)) {
        state.result.environment = { keys: outcome.synchronized_keys };
      }
    }],
    ["vp_environment", async () => {
      if (!d.needs_vp) return;
      let outcome: Awaited<ReturnType<typeof prepareDeployVp>> = {
        error_code: "deployment.vp_store_failed",
      };
      await gateway.withPrivateMaterial(async (material) => {
        outcome = await prepareDeployVp(
          {
            node_id: args.node_id,
            platform: z.literal("linux").parse(input.platform),
            architecture: z.enum(["x86_64", "arm64"]).parse(input.architecture),
            path: `${home.home}/releases/${state.result.release!.name}`,
            user: home.user,
            node_user: d.node_user,
            materialize: SafeObject.parse(material.vp_materialize),
          },
          deadline,
          {
            ...ports.vp,
            mutation,
            current,
            now,
            reference: async (runtime, status) => {
              state.runtime = { ...runtime, owner: d.node_user };
              await save();
              await gateway.project({
                status: "running",
                started_at: state.started_at,
                result: {
                  release: state.result.release,
                  vp_environment: { ...runtime, status },
                },
              });
            },
          },
          ctx.signal,
        );
      });
      const resolved = outcome as Awaited<ReturnType<typeof prepareDeployVp>>;
      failed(resolved);
      if (!("error_code" in resolved)) {
        state.runtime = resolved.runtime === null
          ? null
          : { ...resolved.runtime, owner: d.node_user };
      }
    }],
    ["before_activation", () => runPhase("before_activation")],
    ["activation", async () => {
      const outcome = await activateDeployRelease(
        input,
        state.result.release,
        deadline,
        { local: source, current, now },
        ctx.signal,
      );
      failed(outcome);
      if (!("error_code" in outcome)) {
        state.result.selected_release = outcome.selected_release;
        state.result.selection_confirmed = outcome.selection_confirmed;
      }
    }],
    ["php_refresh", async () => {
      failed(
        await refreshDeployPhp(
          {
            project_id: input.project_id,
            php: input.php_input as Parameters<
              typeof refreshDeployPhp
            >[0]["php"],
          },
          deadline,
          { ...ports.php, mutation, current, now },
          ctx.signal,
        ),
      );
    }],
    ["runtime_definitions", async () => {
      for (const member of input.runtime_definitions.schedules) {
        if (
          member.user !== home.user || member.home !== home.home ||
          member.shell !== "/bin/bash" || member.login_shell !== false
        ) throw new Refused("deployment.identity_changed");
        const account = await (ports.account ?? lookupScheduleAccount)(
          home.user,
          ctx.signal,
        );
        if (
          account.user !== home.user || account.home !== home.home ||
          account.uid === 0
        ) {
          throw new Refused("deployment.identity_changed");
        }
        const outcome = await installSchedule(
          { ...member, group: account.group },
          materialFor(member.id),
          ctx.signal,
          (name, data, signal) =>
            mutation(() =>
              (ports.schedule ?? scheduleSystem)(name, data, signal)
            ),
        );
        state.result.runtime_definitions.schedules.push({
          id: member.id,
          ready: !("error_code" in outcome),
          ...outcome,
        });
        failed(outcome);
      }
    }],
    ["process_restart", async () => {
      for (const member of input.runtime_definitions.restart) {
        if (member.vite !== null) {
          throw new Refused("deployment.identity_changed");
        }
        const outcome = await processAction(memberControl)(
          "process.restart",
          ProcessControl.parse(member),
          ctx.signal,
        );
        state.result.runtime_definitions.restart.push(Member.parse({
          process_id: member.process_id,
          ready: !("error_code" in outcome),
          ...outcome,
        }));
        failed(outcome);
      }
    }],
    ["after_activation", () => runPhase("after_activation")],
    ["cleanup", async () => {
      const outcome = await pruneDeployReleases(
        input,
        state.result.selected_release,
        state.result.previous,
        3,
        deadline,
        { local: source, current, now },
        ctx.signal,
      );
      if ("error_code" in outcome) {
        if (/execution_(unconfirmed|uncertain)/.test(outcome.error_code)) {
          failed(outcome);
        }
        state.result.warnings++;
      }
    }],
    ["verification", async () => {
      const selected = z.object({ release: Release.nullable() }).parse(
        await source.inspectRelease(home),
      );
      if (!isDeepStrictEqual(selected.release, state.result.release)) {
        throw new Refused("deployment.selection_changed");
      }
      state.result.selected_release = selected.release;
      state.result.selection_confirmed = true;
    }],
  ];
  let error_code: string | null = null;
  try {
    if (
      (segment === "prepare" && stored !== null) ||
      (segment === "finish" && (stored === null || state.stage !== 6)) ||
      !["queued", "running"].includes(initial.status)
    ) {
      throw new Refused(
        initial.cancel_requested
          ? "operation.cancelled"
          : "deployment.interrupted",
      );
    }
    await save();
    if (initial.status === "queued") {
      await gateway.project({
        status: "running",
        started_at: state.started_at,
        result: {},
      });
    }
    if (segment === "finish") {
      state.result.runtime_definitions.processes = input
        .runtime_definitions.processes.map((member) => ({
          process_id: z.number().int().positive().parse(
            member.process_id,
          ),
          ready: true,
          state: member.desired === "running" ? "active" : "inactive",
        }));
    }
    while (state.stage < (segment === "prepare" ? 6 : stages.length)) {
      [phase] = stages[state.stage];
      await check();
      await stages[state.stage][1]();
      state.stage++;
      state.active = false;
      state.step = null;
      await save();
    }
  } catch (error) {
    if (
      phase === "activation" && !state.result.selection_confirmed
    ) {
      state.active = true;
      await save();
      throw new Error("deployment.execution_unconfirmed");
    }
    if (
      uncertain || ctx.signal?.aborted ||
      (state.active && !(error instanceof Refused))
    ) {
      throw new Error("deployment.execution_unconfirmed");
    }
    error_code = error instanceof Refused
      ? error.message
      : "deployment.interrupted";
    state.result.failed_step ??= phase;
  }
  if (
    uncertain ||
    (state.active && phase === "activation" &&
      error_code === "deployment.interrupted") ||
    (state.active && /unconfirmed|uncertain/.test(error_code ?? "")) ||
    error_code === "deployment.execution_unconfirmed"
  ) {
    throw new Error("deployment.execution_unconfirmed");
  }
  if (segment === "prepare" && error_code === null) {
    await saveDeploymentExecution(ctx, args, true);
    return { ready: true };
  }
  state.outcome = {
    status: error_code === null
      ? "succeeded"
      : error_code === "operation.cancelled"
      ? "cancelled"
      : "failed",
    error_code: error_code === "operation.cancelled" ? null : error_code,
  };
  state.active = false;
  state.step = null;
  await save();
  return await project();
}
