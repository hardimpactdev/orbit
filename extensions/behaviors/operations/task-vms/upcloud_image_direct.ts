import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import { DirectArgs, GatewayOperation } from "../_lib/direct.ts";
import { imagePhase } from "./image_phase.ts";
import { recipePhases } from "./images.ts";
import {
  type CloudImage,
  imageCloud,
  ImageCloudIntent,
} from "./upcloud_image_api.ts";
import { cloudRequest } from "./upcloud_sweep.ts";
import {
  HostKeys,
  imageHostKeys,
  type SshEnvironment,
  sshImageGuest,
} from "./image_ssh.ts";

export const upcloudImageStages = [
  "prepare",
  "launch-builder",
  "boot-builder",
  ...recipePhases,
  "prepare-image",
  "clean",
  "stop-builder",
  "templatize",
  "delete-builder",
  "launch-smoke",
  "boot-smoke",
  "smoke",
  "delete-smoke",
  "promote",
  "prune",
  "complete",
] as const;
const Args = DirectArgs.extend({
  kind: z.literal("task_vm.upcloud_image_build"),
  node_id: z.number().int().positive(),
});
type Args = z.infer<typeof Args>;
type Context = SshEnvironment & {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  readResource(name: string): Promise<Record<string, unknown> | null>;
  writeResource(
    spec: string,
    name: string,
    data: Record<string, unknown>,
  ): Promise<{ name: string }>;
};
const errorCode = (error: unknown) =>
  error instanceof Error &&
    /^[a-z0-9_]+\.[a-z0-9_.-]{1,80}$/.test(error.message)
    ? error.message
    : "task_vm.image_build_failed";

export function upcloudImageDirect(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile, ctx.signal),
  makeCloud = (input: CloudImage, api: GatewayOperation, ctx: Context) =>
    imageCloud(input, cloudRequest(api, ctx.signal)),
  makeGuest = sshImageGuest,
  makeKeys = imageHostKeys,
) {
  const gateway = (args: Args, ctx: Context) =>
    new GatewayOperation(makeHttp(ctx), args);
  const read = async (args: Args, ctx: Context) => {
    const current = z.object({
      id: z.literal(args.operation_id),
      kind: z.literal(args.kind),
      node_id: z.literal(args.node_id),
      status: z.string(),
      cancel_requested: z.boolean(),
      input: ImageCloudIntent,
    }).parse(await gateway(args, ctx).read(ctx.signal));
    if (current.input.node_id !== args.node_id) {
      throw new Error("operation.execution_forbidden");
    }
    return current;
  };
  return {
    type: "@nckrtl/task-vm-upcloud-image-build",
    version: "2026.10.31.2",
    globalArguments: Globals,
    resources: {
      boot: {
        description: "The image guest boot identity",
        schema: z.object({ boot_id: z.string().uuid() }),
        lifetime: "1d",
        garbageCollection: 1,
      },
      failure: {
        description: "Sanitized native failure before owned cleanup",
        schema: z.object({ error_code: z.string() }),
        lifetime: "1d",
        garbageCollection: 1,
      },
    },
    methods: {
      admit: {
        description: "Read the exact Gateway-owned image intent",
        arguments: Args,
        async execute(args: Args, ctx: Context) {
          const current = await read(args, ctx);
          if (!current.input.current) {
            throw new Error(
              "task_vm.image_build_superseded",
            );
          }
          return {};
        },
      },
      step: {
        description:
          "Execute one native API or guest stage on the Gateway worker",
        arguments: Args.extend({ stage: z.enum(upcloudImageStages) }),
        async execute(
          args: Args & { stage: typeof upcloudImageStages[number] },
          ctx: Context,
        ) {
          const api = gateway(args, ctx);
          const current = await read(args, ctx);
          if (current.status === "succeeded") return {};
          const input = current.input;
          const started_at = new Date().toISOString();
          try {
            ctx.signal?.throwIfAborted();
            if (current.cancel_requested) {
              throw new Error("operation.cancelled");
            }
            if (!input.current) {
              throw new Error("task_vm.image_build_superseded");
            }
            await api.project({
              status: "running",
              started_at,
              result: { phase: args.stage },
            });
            const cloud = makeCloud(input, api, ctx);
            const withKeys = (
              use: (keys: z.infer<typeof HostKeys>) => Promise<void>,
            ) =>
              api.withPrivateMaterial(async (material) => {
                await use(HostKeys.parse(material.bootstrap));
              });
            const guest = async (slot: "builder" | "smoke") => {
              const address = await cloud.boot(slot);
              let publicKey = "";
              await withKeys((keys) => {
                publicKey = keys[slot].public;
                return Promise.resolve();
              });
              return makeGuest(
                {
                  name: input.name + (slot === "smoke" ? "-smoke" : ""),
                  address,
                  publicKey,
                  identity: input.ssh_identity,
                },
                input.agent_sha256,
                ctx,
              );
            };
            let progress: Record<string, unknown> = {};
            if ((recipePhases as readonly string[]).includes(args.stage)) {
              if (args.stage !== "pi" || input.pi_artifact !== null) {
                const boot = await ctx.readResource("builder-boot");
                await imagePhase(
                  {
                    identity: input.identity,
                    profile: "base",
                    phase: args.stage,
                    execution: `upcloud-${input.build_id}`,
                  },
                  input.recipe,
                  await guest("builder"),
                  () =>
                    api.artifact("pi_artifact_file", input.pi_artifact!.sha256),
                  args.stage === "pi" ? input.pi_artifact!.sha256 : null,
                  z.string().uuid().parse(boot?.boot_id),
                );
              }
            } else {switch (args.stage) {
                case "prepare": {
                  await api.withPrivateMaterial(async (material) => {
                    if (material.bootstrap == null) {
                      await api.storeSecretOutput({
                        bootstrap: await makeKeys(input.name),
                      });
                    } else HostKeys.parse(material.bootstrap);
                  });
                  break;
                }
                case "launch-builder":
                case "launch-smoke": {
                  const slot = args.stage === "launch-builder"
                    ? "builder"
                    : "smoke";
                  let uuid = "";
                  await withKeys(async (keys) => {
                    uuid = await cloud.launch(slot, keys);
                  });
                  progress = { resources: { [slot]: { uuid } } };
                  break;
                }
                case "boot-builder":
                case "boot-smoke": {
                  const slot = args.stage === "boot-builder"
                    ? "builder"
                    : "smoke";
                  const image = await guest(slot);
                  await image.bootstrap();
                  await image.command("image.owner", {});
                  const observed = await image.command("image.inspect-phase", {
                    key: {
                      identity: input.identity,
                      profile: "base",
                      phase: "apt-source",
                      execution: `upcloud-${input.build_id}`,
                    },
                    boot_id: null,
                  }, 30);
                  await ctx.writeResource("boot", slot + "-boot", {
                    boot_id: z.string().uuid().parse(observed.boot_id),
                  });
                  break;
                }
                case "prepare-image": {
                  const image = await guest("builder");
                  await image.command("image.recipe-marker", {
                    identity: input.identity,
                    profile: "base",
                  });
                  await image.command("image.verify", input.recipe);
                  await image.command("image.boot", { lean: false });
                  await image.command("image.firewall", {});
                  break;
                }
                case "clean":
                  await (await guest("builder")).command("image.clean", {});
                  break;
                case "stop-builder":
                  await cloud.stop("builder", true);
                  break;
                case "templatize": {
                  const uuid = await cloud.templatize();
                  await api.project({
                    status: "running",
                    started_at,
                    result: { phase: args.stage, template_uuid: uuid },
                  });
                  await cloud.online(uuid);
                  break;
                }
                case "delete-builder":
                  await cloud.remove("builder");
                  break;
                case "smoke": {
                  const image = await guest("smoke");
                  await image.command("image.verify", input.recipe);
                  await image.command("image.check", {
                    lean_boot: false,
                    firewall: true,
                  });
                  break;
                }
                case "delete-smoke":
                  await cloud.remove("smoke");
                  break;
                case "promote":
                  progress = {
                    template_uuid: await cloud.promote(),
                    promoted: true,
                  };
                  break;
                case "prune":
                  await cloud.prune(input.pinned_templates);
                  break;
                case "complete":
                  await api.project({
                    status: "succeeded",
                    started_at,
                    finished_at: new Date().toISOString(),
                    result: {
                      ready: true,
                      template_uuid: z.string().uuid().parse(
                        input.progress.template_uuid,
                      ),
                    },
                  });
                  return {};
              }}
            await api.project({
              status: "running",
              started_at,
              result: { phase: args.stage, ...progress },
            });
            return {};
          } catch (error) {
            const error_code = errorCode(error);
            await ctx.writeResource("failure", "failure", { error_code });
            throw new Error(error_code);
          }
        },
      },
      cleanup: {
        description:
          "Remove only this attempt's labelled servers and unverified template, then project failure",
        arguments: Args,
        async execute(args: Args, ctx: Context) {
          const api = gateway(args, ctx);
          const current = await read(args, ctx);
          if (
            ["succeeded", "failed", "cancelled"].includes(current.status)
          ) return {};
          const started_at = new Date().toISOString();
          await api.project({
            status: "running",
            started_at,
            result: { phase: "cleanup" },
          });
          const cloud = makeCloud(current.input, api, ctx);
          let cleanupFailed = false;
          for (const slot of ["builder", "smoke"] as const) {
            try {
              await cloud.remove(slot);
            } catch {
              cleanupFailed = true;
            }
          }
          try {
            await cloud.discard();
          } catch {
            cleanupFailed = true;
          }
          const saved = await ctx.readResource("failure");
          const error_code = cleanupFailed
            ? "task_vm.image_cleanup_failed"
            : errorCode(new Error(String(saved?.error_code)));
          const cancelled = current.cancel_requested && !cleanupFailed;
          await api.project({
            status: cancelled ? "cancelled" : "failed",
            started_at,
            finished_at: new Date().toISOString(),
            result: cancelled ? {} : { error_code },
            ...(cancelled ? {} : { error_code }),
          });
          return {};
        },
      },
    },
  };
}
export const behavior = {
  ...upcloudImageDirect(),
  type: "@nckrtl/task-vm-upcloud-image-build",
};
