import { z } from "npm:zod@4.4.3";
import type { GatewayOperation } from "../_lib/direct.ts";
import { type NodeCall, runNodeAgent } from "../_lib/node-agent.ts";
import type { SystemTransport } from "../node/system_command.ts";
import { incusVmOperation, observeIncusVm } from "./provider_incus.ts";
import type { CloudRequest } from "./upcloud_sweep.ts";

const Id = z.number().int().positive();
const Digest = z.string().regex(/^[a-f0-9]{64}$/);
const PiUrl = z.string().regex(
  /^http:\/\/(?:[0-9]{1,3}\.){3}[0-9]{1,3}:[1-9][0-9]{0,4}$/,
)
  .refine((value) => {
    return URL.canParse(value) && Number(new URL(value).port) > 0;
  });
export const ResumeInput = z.object({
  eligible: z.boolean(),
  task_vm_id: Id,
  node_id: Id,
  guest_node_id: Id,
  name: z.string().regex(/^tvm-[1-9][0-9]*$/),
  provider: z.enum(["incus", "upcloud"]),
  provider_id: z.string().uuid().nullable(),
  agent_sha256: Digest,
  guest_agent_sha256: Digest,
  user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
  pi_sha256: Digest,
  pi_url: PiUrl,
  deadline_at: z.number().int().positive(),
  vm: z.object({
    project: z.string().min(1),
    name: z.string(),
    cidr: z.string(),
  }).strict().nullable(),
}).strict().refine((input) =>
  input.name === `tvm-${input.task_vm_id}` &&
  (input.provider === "incus") === (input.vm !== null) &&
  (input.vm === null || input.vm.name === input.name)
);
export type ResumeIntent = z.infer<typeof ResumeInput>;
type Agent = (
  call: NodeCall,
  signal?: AbortSignal,
) => Promise<Record<string, unknown>>;
type Clock = { now: () => number; wait: () => Promise<void> };
const clock: Clock = {
  now: Date.now,
  wait: () => new Promise((resolve) => setTimeout(resolve, 2000)),
};
function within(input: ResumeIntent, time: Clock, signal?: AbortSignal) {
  signal?.throwIfAborted();
  if (!input.eligible) throw new Error("task_vm.resume_refused");
  if (time.now() >= input.deadline_at * 1000) {
    throw new Error("task_vm.resume_timeout");
  }
}

/** Provider work composes inside the caller's native root; no child admission. */
export async function resumeProvider(
  input: ResumeIntent,
  guard: () => Promise<void>,
  invoke: SystemTransport,
  request: CloudRequest,
  signal?: AbortSignal,
  time: Clock = clock,
): Promise<void> {
  within(input, time, signal);
  let guardFailure: unknown;
  const check = async () => {
    try {
      within(input, time, signal);
      await guard();
      within(input, time, signal);
    } catch (error) {
      guardFailure = error;
      throw error;
    }
  };
  await check();
  if (input.provider === "incus") {
    const observe = async () => {
      within(input, time, signal);
      let observation;
      try {
        observation = await observeIncusVm(input.vm!, check, invoke, signal);
      } catch {
        if (guardFailure !== undefined) throw guardFailure;
        within(input, time, signal);
        throw new Error("task_vm.not_settled");
      }
      if (observation === null) throw new Error("task_vm.vm_missing");
      return observation.running;
    };
    if (await observe()) {
      within(input, time, signal);
      return;
    }
    within(input, time, signal);
    await guard();
    within(input, time, signal);
    try {
      await incusVmOperation(
        { vm: input.vm!, operation: { kind: "start" } },
        check,
        invoke,
        signal,
      );
    } catch {
      if (guardFailure !== undefined) throw guardFailure;
      /* Fresh power observation reconciles a lost start reply. */
    }
    if (!await observe()) throw new Error("task_vm.resume_failed");
    within(input, time, signal);
    return;
  }
  let uuid = input.provider_id;
  if (uuid === null) {
    const listed = z.object({
      servers: z.object({
        server: z.array(
          z.object({ uuid: z.string().uuid(), title: z.string() }),
        ),
      }),
    })
      .parse(await request("GET", "server?label=orbit-task-vm"));
    const matches = listed.servers.server.filter((server) =>
      server.title === input.name
    );
    if (matches.length !== 1) throw new Error("task_vm.vm_missing");
    uuid = matches[0].uuid;
  }
  const observe = async () => {
    within(input, time, signal);
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
    })
      .parse(await request("GET", `server/${uuid}`, undefined, true)).server;
    within(input, time, signal);
    const labels = server.labels.label.filter((label) =>
      label.key === "orbit-task-vm"
    );
    if (labels.length !== 1 || labels[0].value !== String(input.task_vm_id)) {
      throw new Error("task_vm.resume_failed");
    }
    return server.state;
  };
  let state = await observe();
  while (state === "maintenance") {
    within(input, time, signal);
    await guard();
    await time.wait();
    state = await observe();
  }
  if (state === "error") throw new Error("task_vm.resume_failed");
  if (state === "stopped") {
    within(input, time, signal);
    await guard();
    within(input, time, signal);
    try {
      await request("POST", `server/${uuid}/start`, { server: {} });
    } catch {
      /* An uncertain reply never substitutes for observed started power. */
    }
  }
  while ((state = await observe()) !== "started") {
    within(input, time, signal);
    if (state === "error") throw new Error("task_vm.resume_failed");
    await time.wait();
  }
  within(input, time, signal);
}

/** Runs on the admitted guest worker; takes no token and does not bootstrap trust. */
export async function verifyResumeGuest(
  input: ResumeIntent,
  agent: Agent = runNodeAgent,
  signal?: AbortSignal,
  time: Clock = clock,
): Promise<void> {
  for (;;) {
    within(input, time, signal);
    const call = (name: string, value: Record<string, unknown>) => {
      within(input, time, signal);
      return agent({
        name,
        input: value,
        pin: input.guest_agent_sha256,
        sudo: false,
        mutates: false,
        deadlineMs: Math.min(10000, input.deadline_at * 1000 - time.now()),
        maxOutputBytes: 16384,
      }, signal);
    };
    const status = await call("agent.status", {});
    if (status.observer === "active") {
      const pi = await call("pi.inspect-artifact", {
        user: input.user,
        sha256: input.pi_sha256,
      });
      if (pi.current === true) {
        within(input, time, signal);
        return;
      }
    }
    await time.wait();
  }
}

type PiProbe = (
  url: string,
  token: string,
  signal?: AbortSignal,
) => Promise<boolean>;
async function probePi(
  url: string,
  token: string,
  signal?: AbortSignal,
): Promise<boolean> {
  try {
    const response = await fetch(url + "/capabilities", {
      headers: { Authorization: `Bearer ${token}` },
      redirect: "error",
      signal: signal
        ? AbortSignal.any([signal, AbortSignal.timeout(5000)])
        : AbortSignal.timeout(5000),
    });
    await response.body?.cancel();
    return response.ok;
  } catch {
    return false;
  }
}

/** The owning host checks Pi using private bytes kept inside the callback. */
export async function waitResumePi(
  input: ResumeIntent,
  gateway: Pick<GatewayOperation, "withPrivateMaterial">,
  guard: () => Promise<void>,
  probe: PiProbe = probePi,
  signal?: AbortSignal,
  time: Clock = clock,
): Promise<{ ready: true }> {
  for (;;) {
    within(input, time, signal);
    await guard();
    let ready = false;
    await gateway.withPrivateMaterial(async (material) => {
      const token = z.string().min(1).parse(material.pi_token);
      try {
        ready = await probe(input.pi_url, token, signal);
      } catch { /* Private error text never escapes. */ }
    });
    if (ready) {
      within(input, time, signal);
      return { ready: true };
    }
    await time.wait();
  }
}
