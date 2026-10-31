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
import { type CloudRequest, cloudRequest } from "./upcloud_sweep.ts";

const Id = z.number().int().positive();
export const ParkInput = z.object({
  eligible: z.boolean(),
  group_id: Id,
  project_id: Id,
  instance_id: Id,
  guest_node_id: Id,
  task_vm_id: Id,
  node_id: Id,
  name: z.string().regex(/^tvm-[1-9][0-9]*$/),
  provider: z.enum(["incus", "upcloud"]),
  provider_id: z.string().uuid().nullable(),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  deadline_at: z.number().int().positive(),
  vm: z.object({
    project: z.string().min(1),
    name: z.string(),
    cidr: z.string(),
  })
    .strict().nullable(),
}).strict().refine((input) =>
  input.name === `tvm-${input.task_vm_id}` &&
  (input.provider === "incus") === (input.vm !== null) &&
  (input.vm === null || input.vm.name === input.name)
);
export type ParkIntent = z.infer<typeof ParkInput>;
type Gateway = Pick<GatewayOperation, "read" | "withPrivateMaterial">;
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };
type Agent = (
  call: NodeCall,
  signal?: AbortSignal,
) => Promise<Record<string, unknown>>;
type Clock = { now: () => number; wait: () => Promise<void> };
const Success = z.object({
  group_id: Id,
  task_vm_id: Id,
  instance_id: Id,
  status: z.literal("waiting_for_review"),
  power: z.literal("stopped"),
}).strict();
const Refusal = z.enum([
  "task_vm.park_failed",
  "task_vm.vm_missing",
  "task_vm.not_settled",
  "tasks.park_refused",
]);
export class ParkRefused extends Error {
  constructor(readonly code: z.infer<typeof Refusal>) {
    super(code);
  }
}
const Result = z.union([
  Success,
  z.object({ error_code: Refusal }).strict(),
]);
const Args = DirectArgs.extend({ kind: z.literal("tasks.park"), node_id: Id });

async function current(input: ParkIntent, gateway: Gateway, now: number) {
  if (now >= input.deadline_at * 1000) throw new Error("Park deadline reached");
  const value = z.object({
    status: z.enum(["queued", "running"]),
    cancel_requested: z.boolean(),
    input: z.object({ eligible: z.boolean() }),
  }).parse(await gateway.read());
  if (!value.input.eligible) throw new ParkRefused("tasks.park_refused");
  if (value.cancel_requested) {
    throw new DirectOperationCancelled({ effectRunning: false });
  }
}

/** Exact provider work only; callers compose this action in their own native root. */
export async function parkVm(
  input: ParkIntent,
  gateway: Gateway,
  agent: Agent = runNodeAgent,
  request: CloudRequest = cloudRequest(gateway),
  signal?: AbortSignal,
  clock: Clock = {
    now: Date.now,
    wait: () => new Promise((resolve) => setTimeout(resolve, 2000)),
  },
) {
  await current(input, gateway, clock.now());
  if (input.provider === "incus") {
    const call = async (name: string, value: Record<string, unknown>) => {
      const reply = await agent({
        name,
        input: value,
        pin: input.agent_sha256,
        sudo: true,
        mutates: name === "incus.vm",
        deadlineMs: name === "incus.vm" ? 900000 : 60000,
        maxOutputBytes: 16384,
      }, signal);
      if (reply.error_code !== undefined) {
        throw new Error("Park action refused");
      }
      return reply;
    };
    const observe = async () => {
      const reply = z.object({
        observation: z.object({ running: z.boolean(), settled: z.boolean() })
          .nullable(),
      }).parse(await call("incus.vm-state", input.vm!));
      if (reply.observation === null) {
        throw new ParkRefused("task_vm.vm_missing");
      }
      if (!reply.observation.settled) {
        throw new ParkRefused("task_vm.not_settled");
      }
      return reply.observation;
    };
    if ((await observe()).running) {
      await current(input, gateway, clock.now());
      try {
        await call("incus.vm", { vm: input.vm, operation: { kind: "stop" } });
      } catch {
        // A lost stop reply is reconciled by the fresh typed power observation.
      }
      if ((await observe()).running) throw new Error("The VM is still running");
    }
  } else {
    let uuid = input.provider_id;
    if (uuid === null) {
      const listed = z.object({
        servers: z.object({
          server: z.array(
            z.object({ uuid: z.string().uuid(), title: z.string() }),
          ),
        }),
      }).parse(await request("GET", "server?label=orbit-task-vm"));
      const matches = listed.servers.server.filter((server) =>
        server.title === input.name
      );
      if (matches.length !== 1) throw new Error("The exact VM is not unique");
      uuid = matches[0].uuid;
    }
    const observe = async () => {
      const raw = await request("GET", `server/${uuid}`, undefined, true);
      const server = z.object({
        server: z.object({
          uuid: z.literal(uuid),
          title: z.literal(input.name),
          hostname: z.literal(input.name),
          state: z.enum(["started", "stopped", "maintenance", "error"]),
          labels: z.object({
            label: z.array(z.object({ key: z.string(), value: z.string() })),
          }),
        }),
      }).parse(raw).server;
      const labels = server.labels.label.filter((label) =>
        label.key === "orbit-task-vm"
      );
      if (labels.length !== 1 || labels[0].value !== String(input.task_vm_id)) {
        throw new Error("The provider VM is not owned");
      }
      return server.state;
    };
    let state = await observe();
    if (state === "error" || state === "maintenance") {
      throw new ParkRefused("task_vm.not_settled");
    }
    let hard = false;
    let deadline = clock.now() + 150000;
    if (state === "started") {
      await current(input, gateway, clock.now());
      try {
        await request("POST", `server/${uuid}/stop`, {
          stop_server: { stop_type: "soft", timeout: "120" },
        });
      } catch {
        // No success or cancellation is claimed until the provider proves stopped.
      }
    }
    while ((state = await observe()) !== "stopped") {
      if (clock.now() >= input.deadline_at * 1000 || state === "error") {
        throw new Error("The VM stop did not settle");
      }
      if (clock.now() >= deadline) {
        if (hard) throw new Error("The hard stop did not settle");
        await request("POST", `server/${uuid}/stop`, {
          stop_server: { stop_type: "hard" },
        });
        hard = true;
        deadline = clock.now() + 120000;
      }
      await clock.wait();
    }
  }
  await current(input, gateway, clock.now());
  return Success.parse({
    group_id: input.group_id,
    task_vm_id: input.task_vm_id,
    instance_id: input.instance_id,
    status: "waiting_for_review",
    power: "stopped",
  });
}

export function nativePark(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  agent: Agent = runNodeAgent,
) {
  return {
    type: "@nckrtl/task-park",
    version: "2026.10.31.1",
    globalArguments: Globals,
    methods: {
      execute: {
        description:
          "Stop and observe one admitted review VM in the same native Task root",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          return await directOperation(
            args,
            gateway,
            async (raw) => {
              try {
                const input = ParkInput.parse(raw);
                if (input.node_id !== args.node_id) {
                  throw new Error("Wrong park Node");
                }
                return await parkVm(
                  input,
                  gateway,
                  agent,
                  cloudRequest(gateway, ctx.signal),
                  ctx.signal,
                );
              } catch (error) {
                if (error instanceof DirectOperationCancelled) throw error;
                return {
                  error_code: error instanceof ParkRefused
                    ? error.code
                    : "task_vm.park_failed",
                };
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

export const behavior = { ...nativePark(), type: "@nckrtl/task-park" };
