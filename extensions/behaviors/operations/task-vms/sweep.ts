import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import {
  DirectArgs,
  directOperation,
  DirectOperationCancelled,
  GatewayOperation,
} from "../_lib/direct.ts";
import { type NodeCall, runNodeAgent } from "../_lib/node-agent.ts";
import { sweepUpCloud } from "./upcloud_sweep.ts";

const Name = z.string().regex(/^[A-Za-z0-9][A-Za-z0-9._-]{0,253}$/);
const Ids = z.array(z.number().int().positive()).max(4096);
export const SweepIntent = z.object({
  node_id: z.number().int().positive(),
  provider: z.enum(["incus", "upcloud"]),
  deadline_at: z.number().int().positive(),
  ci_runner: z.boolean(),
  stray_vm_ids: Ids,
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  host: z.object({
    project: Name,
    pool: Name,
    cidr: z.string(),
    operator_owned: z.array(z.string()),
  }).strict().nullable(),
  retired_build_ids: Ids,
  live_names: z.array(Name).max(4096),
  protected_build_ids: Ids,
  recorded_build_ids: Ids,
  running_stamps: z.array(z.string().regex(/^[0-9]{14}$/)).max(4096),
}).strict().refine((input) =>
  (input.provider === "incus") === (input.host !== null)
);
export type SweepInput = z.infer<typeof SweepIntent>;
export type SweepGateway = Pick<
  GatewayOperation,
  "read" | "withPrivateMaterial"
>;
export type Agent = (
  call: NodeCall,
  signal?: AbortSignal,
) => Promise<Record<string, unknown>>;
export class SweepRefused extends Error {
  constructor() {
    super("task_vm.host_command_failed");
  }
}
const Args = DirectArgs.extend({
  kind: z.literal("task_vm.sweep_host"),
  node_id: z.number().int().positive(),
});
const Result = z.union([
  z.object({ error_code: z.literal("task_vm.host_command_failed") }).strict(),
  z.object({
    ready: z.literal(true),
    observed_names: z.array(Name).max(4096),
    deleted_names: z.array(Name).max(4096),
  }).strict(),
  z.object({
    error_code: z.literal("task_vm.host_command_failed"),
    observed_names: z.array(Name).max(4096),
    deleted_names: z.array(Name).max(4096),
  }).strict(),
]);

/** Fresh Orbit reads contain only DB ownership; execution and inventory stay native. */
export async function currentSweep(
  input: SweepInput,
  gateway: SweepGateway,
  signal?: AbortSignal,
  settling = false,
): Promise<SweepInput> {
  signal?.throwIfAborted();
  if (Date.now() >= input.deadline_at * 1000) throw new SweepRefused();
  const current = z.object({
    cancel_requested: z.boolean(),
    status: z.string(),
    input: SweepIntent,
  }).parse(await gateway.read(signal));
  if (current.cancel_requested && !settling) {
    throw new DirectOperationCancelled({ effectRunning: false });
  }
  if (!["queued", "running"].includes(current.status)) {
    throw new Error("Sweep is no longer running");
  }
  const value = current.input;
  if (
    value.node_id !== input.node_id || value.provider !== input.provider ||
    value.deadline_at !== input.deadline_at ||
    value.agent_sha256 !== input.agent_sha256 ||
    JSON.stringify(value.host) !== JSON.stringify(input.host) ||
    JSON.stringify(value.retired_build_ids) !==
      JSON.stringify(input.retired_build_ids)
  ) {
    throw new Error("Sweep ownership changed");
  }
  return value;
}
function operatorOwns(input: SweepInput, name: string) {
  const host = input.host!;
  return host.operator_owned.includes(`${host.project}/${name}`) ||
    host.operator_owned.includes(`${host.project}/*`);
}
export function orphanInstance(input: SweepInput, name: string): boolean {
  if (operatorOwns(input, name) || input.live_names.includes(name)) {
    return false;
  }
  if (/^tvm-[0-9]+$/.test(name)) return true;
  const build = /^tvm-image-([0-9]+)(?:-attempt-[0-9]+)?(?:-smoke)?$/.exec(
    name,
  );
  return build !== null &&
    (input.retired_build_ids.includes(Number(build[1])) ||
      !input.recorded_build_ids.includes(Number(build[1]))) &&
    !input.protected_build_ids.includes(Number(build[1]));
}
export function orphanVolume(input: SweepInput, name: string): boolean {
  const match = /^(tvm-[0-9]+)-topology-seed$/.exec(name);
  return match !== null && !operatorOwns(input, name) &&
    !input.live_names.includes(match[1]);
}

export async function sweepIncus(
  input: SweepInput,
  gateway: SweepGateway,
  agent: Agent,
  signal?: AbortSignal,
  report: (message: string, facts: Record<string, unknown>) => void = () => {},
) {
  const host = input.host!;
  const call = async (
    name: string,
    data: Record<string, unknown>,
    mutates = false,
  ) => {
    const result = await agent({
      name,
      pin: input.agent_sha256,
      input: data,
      sudo: true,
      mutates,
      deadlineMs: 900000,
      maxOutputBytes: 4 * 1024 * 1024,
    }, signal);
    if (typeof result.error_code === "string") throw new SweepRefused();
    return result;
  };
  const names = async () =>
    z.object({ names: z.array(Name).max(4096) }).parse(
      await call("incus.instances", { project: host.project }),
    ).names;
  // Project existence is proved by incus.instances; a missing project is not an empty inventory.
  const initial = await names();
  const volumes = z.object({ names: z.array(Name).max(4096) }).parse(
    await call("incus.volumes", { project: host.project, pool: host.pool }),
  ).names;
  const inventory = z.object({
    instances: z.array(z.object({ project: Name, name: Name })).max(4096),
    volumes: z.array(z.object({ project: Name, pool: Name, name: Name })).max(
      4096,
    ),
  }).parse(await call("incus.inventory", {}));
  const images = z.object({ images: z.record(z.string(), z.array(z.string())) })
    .parse(await call("incus.images", { project: host.project }));
  let ownership = await currentSweep(input, gateway, signal); // Inventory BEFORE live rows, including clone volumes.
  const excluded = (project: string, name: string) =>
    input.ci_runner && project === "orbit-ci" ||
    host.operator_owned.includes(`${project}/${name}`) ||
    host.operator_owned.includes(`${project}/*`);
  for (const row of inventory.instances) {
    const match = /^tvm-image-([0-9]+)(?:-attempt-[0-9]+)?(?:-smoke)?$/.exec(
      row.name,
    );
    const managed = row.project === host.project &&
      (/^tvm-[0-9]+$/.test(row.name) ||
        match !== null &&
          ownership.protected_build_ids.includes(Number(match[1])));
    if (!managed && !excluded(row.project, row.name)) {
      report(
        "A task VM host has an Incus instance that Orbit does not own.",
        row,
      );
    }
  }
  for (const row of inventory.volumes) {
    const seed = /^orbit-topology-seed-([a-f0-9]{12})(?:-new)?$/.exec(row.name);
    const managed = row.project === host.project && row.pool === host.pool &&
      (/^tvm-[0-9]+-topology-seed$/.test(row.name) ||
        seed !== null &&
          Object.keys(images.images).some((fingerprint) =>
            fingerprint.startsWith(seed[1])
          ));
    if (!managed && !excluded(row.project, row.name)) {
      report(
        "A task VM host has an Incus custom volume that Orbit does not own.",
        row,
      );
    }
  }
  for (const id of ownership.stray_vm_ids) {
    report("A task VM is ready or parked without an open group.", {
      task_vm_id: id,
    });
  }
  const deleted: string[] = [];
  let failed = false;
  for (const name of initial) {
    if (!orphanInstance(ownership, name)) continue;
    ownership = await currentSweep(input, gateway, signal);
    if (!orphanInstance(ownership, name)) continue;
    try {
      const result = await call("incus.vm", {
        vm: { project: host.project, name, cidr: host.cidr },
        operation: { kind: "remove_instance" },
      }, true);
      if (result.ready !== true) throw new SweepRefused();
      deleted.push(name);
    } catch (error) {
      if (!(error instanceof SweepRefused)) throw error; // Unknown transport outcome resumes the SAME native run.
      failed = true;
    }
  }
  for (const name of volumes) {
    ownership = await currentSweep(input, gateway, signal);
    if (!orphanVolume(ownership, name)) continue;
    try {
      const result = await call("incus.volume", {
        location: { project: host.project, pool: host.pool },
        operation: { kind: "remove", name },
      }, true);
      if (result.name !== name) throw new SweepRefused();
      deleted.push(name);
    } catch (error) {
      if (!(error instanceof SweepRefused)) throw error;
      failed = true;
    }
  }
  const observed = await names();
  return {
    ...(failed
      ? { error_code: "task_vm.host_command_failed" as const }
      : { ready: true as const }),
    observed_names: [...new Set(observed)],
    deleted_names: [...new Set(deleted)],
  };
}

type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  logger?: { warn: (message: string, facts: Record<string, unknown>) => void };
};
export function nativeSweep(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  agent: Agent = runNodeAgent,
  cloud = sweepUpCloud,
) {
  return {
    type: "@nckrtl/task-vm-sweep",
    version: "2026.10.31.1",
    globalArguments: Globals,
    methods: {
      execute: {
        description:
          "Sweep one exact host in one native run, including its final observation",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          return await directOperation(
            args,
            gateway,
            async (raw) => {
              const input = SweepIntent.parse(raw);
              if (input.node_id !== args.node_id) {
                throw new Error(
                  "Foreign sweep Node",
                );
              }
              try {
                return input.provider === "incus"
                  ? await sweepIncus(
                    input,
                    gateway,
                    agent,
                    ctx.signal,
                    (message, facts) => ctx.logger?.warn(message, facts),
                  )
                  : await cloud(input, gateway, ctx.signal);
              } catch (error) {
                if (!(error instanceof SweepRefused)) throw error;
                return { error_code: "task_vm.host_command_failed" };
              }
            },
            (result) => Result.parse(result),
            ctx.signal,
          );
        },
      },
    },
  };
}
export const behavior = { ...nativeSweep(), type: "@nckrtl/task-vm-sweep" };
