import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import { DirectArgs, GatewayOperation } from "../_lib/direct.ts";
import { type NodeCall, runNodeAgent } from "../_lib/node-agent.ts";
import { incusGuest } from "./image_guest.ts";
import { recipePhases as phases } from "./images.ts";

const hash = z.string().regex(/^[a-f0-9]{64}$/);
const object = z.record(z.string(), z.unknown());
export { recipePhases as phases } from "./images.ts";
export const stages = [
  "reset",
  "launch",
  "boot",
  ...phases,
  "prepare",
  "clean",
  "publish",
  "smoke",
  "seed",
  "delete-smoke",
  "finish-seed",
  "promote",
  "prune",
  "complete",
] as const;
const StoredImageIntent = z.object({
  build_id: z.number().int().positive(),
  attempt: z.number().int().positive(),
  host_node_id: z.number().int().positive(),
  agent_sha256: hash,
  identity: z.string().regex(/^sha256:[a-f0-9]{64}$/),
  generation: z.number().int().positive(),
  project: z.string().regex(/^[a-z0-9][a-z0-9-]{0,62}$/),
  name: z.string().regex(/^tvm-image-[0-9]+(?:-attempt-[0-9]+)?$/),
  cidr: z.string(),
  pool: z.string(),
  network: z.string(),
  cpus: z.number().int().positive(),
  memory: z.string(),
  disk: z.string(),
  source: z.string(),
  candidate_alias: z.string(),
  base_alias: z.string(),
  gateway_public_key: z.string(),
  certificate: z.string(),
  recipe: object,
  pi_artifact: z.object({
    sha256: hash,
  }).strict().nullable(),
  prune: object,
}).strict();
export const ImageIntent = z.preprocess((value) => {
  if (
    value !== null && typeof value === "object" && "recipe" in value &&
    typeof value.recipe === "string"
  ) {
    try {
      return { ...value, recipe: JSON.parse(value.recipe) };
    } catch {
      return value;
    }
  }
  return value;
}, StoredImageIntent).superRefine((input, ctx) => {
  if (
    input.recipe.identity !== input.identity ||
    input.recipe.agent_sha256 !== input.agent_sha256 ||
    input.recipe.pi_sha256 !== (input.pi_artifact?.sha256 ?? null)
  ) {
    ctx.addIssue({
      code: "custom",
      message: "Image recipe pins differ from admitted artifacts",
    });
  }
});
export type Intent = z.infer<typeof ImageIntent>;
type Host = (call: NodeCall) => Promise<Record<string, unknown>>;
type Guest = ReturnType<typeof incusGuest>;
const fingerprint = (raw: unknown) =>
  z.object({ fingerprint: hash }).parse(raw).fingerprint;

/** Each call is one native workflow step; Swamp retains successful step outputs. */
export async function imageStage(
  stage: typeof stages[number],
  input: Intent,
  published: string | null,
  host: Host,
  guest: (smoke: boolean) => Guest,
  artifact: () => Promise<Uint8Array>,
  expectedBoot: string | null = null,
) {
  const command = async (name: string, payload: Record<string, unknown>) => {
    const result = await host({
      name,
      input: payload,
      pin: input.agent_sha256,
      sudo: true,
      mutates: true,
      deadlineMs: name === "incus.volume"
        ? 180000
        : name === "incus.build-topology-seed"
        ? 600000
        : 900000,
      maxOutputBytes: 16384,
    });
    if ("error_code" in result) throw new Error(String(result.error_code));
    if (name !== "incus.volume") {
      z.object({ ready: z.literal(true) }).parse(result);
    }
    return result;
  };
  const vm = (smoke = false) => ({
    project: input.project,
    name: input.name + (smoke ? "-smoke" : ""),
    cidr: input.cidr,
  });
  const remove = (smoke: boolean) =>
    command("incus.vm", {
      vm: vm(smoke),
      operation: { kind: "remove_instance" },
    });
  const image = (operation: Record<string, unknown>) =>
    command("incus.image", { project: input.project, operation });
  const launch = (smoke: boolean, source: string) =>
    command("incus.vm", {
      vm: vm(smoke),
      operation: {
        kind: "create_image",
        image: source,
        gateway_public_key: input.gateway_public_key,
        cpus: input.cpus,
        memory: input.memory,
        disk: input.disk,
        network: input.network,
        builder: !smoke,
      },
    });
  const fp = () => hash.parse(published);
  const builder = guest(false);
  if ((phases as readonly string[]).includes(stage)) {
    if (stage === "pi" && input.pi_artifact === null) return {};
    const key = {
      identity: input.identity,
      profile: "base",
      phase: stage,
      execution: `${input.build_id}-${input.attempt}`,
    };
    const deadline = Date.now() + 1440000;
    const observe = async (
      boot_id: string | null,
    ): Promise<Record<string, unknown>> => {
      for (;;) {
        if (Date.now() >= deadline) {
          throw new Error("task_vm.image_channel_lost");
        }
        try {
          return await builder.command(
            "image.inspect-phase",
            { key, boot_id },
            30,
          );
        } catch (error) {
          if (
            !(error instanceof Error) ||
            error.message !== "task_vm.image_channel_lost"
          ) throw error;
          // systemd can re-exec during APT. Re-read status; never repeat a mutation.
          await new Promise((resolve) => setTimeout(resolve, 1000));
        }
      }
    };
    let observation = await observe(null);
    const boot_id = z.string().uuid().parse(observation.boot_id);
    if (expectedBoot !== null && boot_id !== expectedBoot) {
      throw new Error("task_vm.image_builder_rebooted");
    }
    if (observation.state === "missing") {
      if (stage === "pi") {
        await builder.artifact(await artifact(), input.pi_artifact!.sha256);
      }
      observation = await builder.command("image.start-phase", {
        key,
        boot_id,
        recipe: input.recipe,
      });
    }
    // Local observation of the guest's existing bounded service, never a Gateway wait or child.
    while (observation.state === "running") {
      if (Date.now() >= deadline) throw new Error("task_vm.image_build_failed");
      await new Promise((resolve) => setTimeout(resolve, 1000));
      observation = await observe(boot_id);
    }
    if (observation.state !== "complete" || observation.boot_id !== boot_id) {
      throw new Error("task_vm.image_build_failed");
    }
    return {};
  }
  switch (stage) {
    case "reset":
      await remove(false);
      await remove(true);
      break;
    case "launch":
      await launch(false, input.source);
      break;
    case "boot": {
      await builder.bootstrap();
      const observed = await builder.command("image.inspect-phase", {
        key: {
          identity: input.identity,
          profile: "base",
          phase: "apt-source",
          execution: `${input.build_id}-${input.attempt}`,
        },
        boot_id: null,
      }, 30);
      return { boot_id: z.string().uuid().parse(observed.boot_id) };
    }
    case "prepare":
      await builder.command("image.owner", {});
      await image({ kind: "prepare_metadata", name: input.name });
      await builder.command("image.boot", { lean: true });
      break;
    case "clean":
      await builder.command("image.recipe-marker", {
        identity: input.identity,
        profile: "base",
      });
      await builder.command("image.trust-ca", {
        certificate: input.certificate,
      });
      await builder.command("image.clean", {});
      break;
    case "publish": {
      const result = await image({
        kind: "publish",
        name: input.name,
        alias: `${input.candidate_alias}-attempt-${input.attempt}`,
        properties: { "orbit.recipe": input.identity, "orbit.profile": "base" },
      });
      const candidate = fingerprint(result);
      await image({
        kind: "promote",
        alias: input.candidate_alias,
        fingerprint: candidate,
      });
      return { fingerprint: candidate };
    }
    case "smoke": {
      await remove(false);
      await launch(true, fp());
      const smoke = guest(true);
      await smoke.bootstrap();
      await smoke.command("image.check", { lean_boot: true, firewall: false });
      await smoke.command("image.verify", input.recipe, 120);
      break;
    }
    case "seed":
      await command("incus.build-topology-seed", {
        project: input.project,
        pool: input.pool,
        vm: vm(true).name,
        size: "45GiB",
        fingerprint: fp(),
      });
      break;
    case "delete-smoke":
      await remove(true);
      break;
    case "finish-seed": {
      const result = await command("incus.volume", {
        location: { project: input.project, pool: input.pool },
        operation: { kind: "finish_seed", fingerprint: fp() },
      });
      z.object({ name: z.literal(`orbit-topology-seed-${fp().slice(0, 12)}`) })
        .parse(result);
      break;
    }
    case "promote":
      await image({
        kind: "promote",
        alias: input.base_alias,
        fingerprint: fp(),
      });
      break;
    case "prune":
      await command("incus.image", input.prune);
      break;
    case "complete":
      return { ready: true, fingerprint: fp() };
  }
  return {};
}

const Args = DirectArgs.extend({
  kind: z.literal("task_vm.image_build"),
  node_id: z.number().int().positive(),
});
type Handle = { name: string };
type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  readResource(name: string): Promise<Record<string, unknown> | null>;
  writeResource(
    spec: string,
    name: string,
    data: Record<string, unknown>,
  ): Promise<Handle>;
};
export function imageBuildDirect(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  host: Host = runNodeAgent,
  guests = incusGuest,
) {
  const gateway = (args: z.infer<typeof Args>, ctx: Context) =>
    new GatewayOperation(makeHttp(ctx), args);
  const read = async (args: z.infer<typeof Args>, ctx: Context) => {
    const current = z.object({
      id: z.number(),
      kind: z.literal("task_vm.image_build"),
      node_id: z.number(),
      input: ImageIntent,
      cancel_requested: z.boolean(),
      status: z.string(),
    })
      .parse(await gateway(args, ctx).read(ctx.signal));
    if (
      current.id !== args.operation_id || current.node_id !== args.node_id ||
      current.input.host_node_id !== args.node_id
    ) throw new Error("Image host differs from admission");
    if (current.cancel_requested || ctx.signal?.aborted) {
      throw new Error("operation.cancelled");
    }
    if (["failed", "cancelled"].includes(current.status)) {
      throw new Error("Image attempt is terminal");
    }
    return current;
  };
  return {
    type: "@nckrtl/task-vm-image-build",
    version: "2026.10.31.4",
    globalArguments: Globals,
    resources: {
      boot: {
        description: "Native builder boot identity",
        schema: z.object({ boot_id: z.string().uuid() }),
        lifetime: "1d",
        garbageCollection: 1,
      },
      publication: {
        description: "Native published image",
        schema: z.object({ fingerprint: hash }),
        lifetime: "1d",
        garbageCollection: 1,
      },
    },
    methods: {
      failure: {
        description: "Project a failed native admission or artifact step",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const api = gateway(args, ctx);
          const timestamp = new Date().toISOString();
          await api.project({
            status: "failed",
            started_at: timestamp,
            finished_at: timestamp,
            result: { error_code: "task_vm.image_build_failed" },
            error_code: "task_vm.image_build_failed",
          });
          return {};
        },
      },
      admit: {
        description: "Read the exact admitted image attempt",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          await read(args, ctx);
          return {};
        },
      },
      step: {
        description:
          "Execute one native image stage on its admitted build host",
        arguments: Args.extend({ stage: z.enum(stages) }),
        execute: async (
          args: z.infer<typeof Args> & { stage: typeof stages[number] },
          ctx: Context,
        ) => {
          const current = await read(args, ctx);
          if (current.status === "succeeded") return {};
          const input = current.input;
          const api = gateway(args, ctx);
          const started_at = new Date().toISOString();
          await api.project({
            status: "running",
            started_at,
            result: { phase: args.stage },
          });
          const published = await ctx.readResource("published");
          const boot = await ctx.readResource("boot");
          let executing = true;
          try {
            const result = await imageStage(
              args.stage,
              input,
              published ? fingerprint(published) : null,
              host,
              (smoke) =>
                guests(
                  input.project,
                  input.name + (smoke ? "-smoke" : ""),
                  input.agent_sha256,
                ),
              () =>
                api.artifact(
                  "pi_artifact_file",
                  hash.parse(input.pi_artifact?.sha256),
                ),
              boot ? z.string().uuid().parse(boot.boot_id) : null,
            );
            executing = false;
            if (args.stage === "boot") {
              const output = z.object({ boot_id: z.string().uuid() }).parse(
                result,
              );
              return {
                dataHandles: [await ctx.writeResource("boot", "boot", output)],
              };
            }
            if (args.stage === "publish") {
              const output = z.object({ fingerprint: hash }).parse(result);
              await api.project({
                status: "running",
                started_at,
                result: { phase: args.stage, ...output },
              });
              return {
                dataHandles: [
                  await ctx.writeResource("publication", "published", output),
                ],
              };
            }
            await api.project({
              status: args.stage === "complete" ? "succeeded" : "running",
              started_at,
              ...(args.stage === "complete"
                ? { finished_at: new Date().toISOString() }
                : {}),
              result: args.stage === "complete"
                ? result
                : { phase: args.stage, completed: true },
            });
            return {};
          } catch (error) {
            if (!executing) throw error;
            const error_code = error instanceof Error &&
                /^[a-z0-9_]+\.[a-z0-9_.-]{1,80}$/.test(error.message)
              ? error.message
              : "task_vm.image_build_failed";
            await api.project({
              status: "failed",
              started_at,
              finished_at: new Date().toISOString(),
              result: { error_code },
              error_code,
            });
            throw new Error(error_code);
          }
        },
      },
    },
  };
}
export const behavior = {
  ...imageBuildDirect(),
  type: "@nckrtl/task-vm-image-build",
};
