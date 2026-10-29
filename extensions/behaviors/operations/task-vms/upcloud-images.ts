import { z } from "npm:zod@4.4.3";
import { ExecutorError } from "../../operation-runner/mod.ts";
import type { FamilyEffects } from "./runtime.ts";
import { installWithRetry, recipePhases } from "./images.ts";

export const UpCloudImageInput = z.object({
  build_id: z.number().int().positive(),
  node_id: z.number().int().positive(),
  identity: z.string(),
  generation: z.number().int().positive(),
  stamp: z.string().regex(/^[0-9]{14}$/),
  name: z.string(),
  source: z.string(),
  deadline_at: z.number().int().positive(),
  recipe: z.string().transform((value) =>
    z.record(z.string(), z.unknown()).parse(JSON.parse(value))
  ),
  recipe_json: z.string(),
  pi_artifact: z.object({ path: z.string(), sha256: z.string() }).nullable(),
}).strict();
export async function buildUpCloudImage(
  input: z.infer<typeof UpCloudImageInput>,
  effects: FamilyEffects,
) {
  const child = (
    key: string,
    verb: string,
    data: Record<string, unknown> = {},
  ) =>
    effects.child(
      key,
      `task_vm.upcloud_image_${verb.replaceAll("-", "_")}`,
      input.node_id,
      {
        build_id: input.build_id,
        ...(verb === "prepare"
          ? {}
          : { token: { $secret: "upcloud.api", id: input.build_id } }),
        ...data,
      },
    );
  const guest = (
    key: string,
    command: string,
    parameters = {},
    slot = "builder",
  ) =>
    effects.child(
      key,
      `task_vm.upcloud_guest_${command.replaceAll(/[.-]/g, "_")}`,
      input.node_id,
      {
        build_id: input.build_id,
        slot,
        parameters_json: JSON.stringify(parameters),
      },
    );
  const stage = <T>(key: string, work: () => Promise<T>) =>
    effects.stage ? effects.stage(key, work) : work();
  const boot = (slot: string) =>
    stage(`${slot}-boot`, async () => {
      // Power/address observations stay in Orbit. Installing the fixed command mode is a separate keyed child.
      for (;;) {
        const state = await effects.read(`upcloud-image/${slot}-power`);
        if (state.expired === true) {
          throw new ExecutorError("task_vm.upcloud_timeout");
        }
        if (state.state === "started" && state.address_ready === true) break;
        if (state.state === "error") {
          throw new ExecutorError("task_vm.upcloud_failed");
        }
        await effects.wait();
      }
      await installWithRetry(`${slot}-agent`, async (key) => {
        if (
          (await effects.read(`upcloud-image/${slot}-boot`)).expired === true
        ) throw new ExecutorError("task_vm.bootstrap_timeout");
        await guest(key, "agent.install-guest", {}, slot);
      });
      for (;;) {
        const state = await effects.read(`upcloud-image/${slot}-boot`);
        if (state.ready === true) break;
        if (state.expired === true) {
          throw new ExecutorError("task_vm.bootstrap_timeout");
        }
        await effects.wait();
      }
      await guest(`${slot}-boot-confirmed`, "guest.cloud-init", {}, slot);
    });
  const launch = (slot: string) =>
    child(`launch-${slot}`, `launch-${slot}`, {
      bootstrap: { $secret: "task_vm.image_bootstrap", id: input.build_id },
    });
  const remove = (slot: string, prefix = "") =>
    stage(`${prefix}remove-${slot}`, async () => {
      await child(`${prefix}hard-stop-${slot}`, `hard-stop-${slot}`);
      for (;;) {
        const power = await effects.read(`upcloud-image/${slot}-power`);
        if (power.state === null || power.state === "stopped") break;
        await effects.wait();
      }
      await child(`${prefix}delete-${slot}`, `delete-${slot}`);
      while (
        (await effects.read(`upcloud-image/${slot}-power`)).state !== null
      ) await effects.wait();
    });
  try {
    await child("prepare", "prepare");
    await launch("builder");
    await boot("builder");
    await guest("owner", "image.owner");
    for (const phase of recipePhases) {
      if (phase === "pi" && input.pi_artifact === null) continue;
      await stage(`phase-${phase}`, async () => {
        let state = await effects.read(`upcloud-image/phase-${phase}`);
        if (state.state === "missing") {
          if (phase === "pi" && input.pi_artifact) {
            await guest("pi-artifact", "pi.upload-guest", {
              artifact: input.pi_artifact.path,
              sha256: input.pi_artifact.sha256,
            });
          }
          await guest(`${phase}-start`, "image.start-phase", {
            key: {
              identity: input.identity,
              profile: "base",
              phase,
              execution: `upcloud-${input.build_id}`,
            },
            boot_id: state.boot_id,
            recipe: input.recipe,
          });
        }
        for (;;) {
          state = await effects.read(`upcloud-image/phase-${phase}`);
          if (state.state === "complete") break;
          if (state.state === "failed") {
            throw new ExecutorError("task_vm.image_build_failed");
          }
          if (state.state !== "running") {
            throw new ExecutorError("task_vm.image_channel_lost");
          }
          await effects.wait();
        }
      });
    }
    await guest("recipe-marker", "image.recipe-marker", {
      identity: input.identity,
      profile: "base",
    });
    await guest("verify", "image.verify", input.recipe);
    await guest("boot-policy", "image.boot", { lean: false });
    await guest("firewall", "image.firewall");
    await guest("clean", "image.clean");
    await stage("stop-builder", async () => {
      const stopped = await child("stop-builder", "stop");
      const softDeadline = typeof stopped.effect_finished_at === "string"
        ? Date.parse(stopped.effect_finished_at) / 1000 + 150
        : input.deadline_at;
      while (
        (await effects.read("upcloud-image/builder-power")).state !== "stopped"
      ) {
        if (Date.now() / 1000 >= softDeadline) {
          await child("hard-stop-builder", "hard-stop-builder");
        }
        await effects.wait();
      }
    });
    await stage("template-online", async () => {
      await child("templatize", "templatize");
      while ((await effects.read("upcloud-image")).template_ready !== true) {
        await effects.wait();
      }
    });
    await remove("builder");
    await launch("smoke");
    await boot("smoke");
    await guest("smoke-verify", "image.verify", input.recipe, "smoke");
    await guest("smoke-check", "image.check", {
      lean_boot: false,
      firewall: true,
    }, "smoke");
    await remove("smoke");
    const result = await child("promote", "promote");
    const state = await effects.read("upcloud-image");
    for (const uuid of z.array(z.string()).parse(state.prune_templates)) {
      await child(`prune-${uuid}`, "prune-template", { template_uuid: uuid });
    }
    return {
      ready: true,
      template_uuid: z.string().parse(result.template_uuid),
    };
  } catch (error) {
    if (
      error instanceof ExecutorError &&
      [
        "operation.waiting",
        "operation.lease_lost",
        "operation.outcome_unknown",
        "operation.execution_busy",
      ].includes(error.code)
    ) throw error;
    // These fixed children remove only this attempt's labelled servers and unverified template.
    for (const slot of ["builder", "smoke"]) {
      try {
        await remove(slot, "cleanup-");
      } catch (cleanupError) {
        if (
          cleanupError instanceof ExecutorError &&
          [
            "operation.waiting",
            "operation.lease_lost",
            "operation.outcome_unknown",
            "operation.execution_busy",
          ].includes(cleanupError.code)
        ) throw cleanupError;
      }
    }
    try {
      await child("cleanup-discard-template", "discard-template");
    } catch (cleanupError) {
      if (
        cleanupError instanceof ExecutorError &&
        [
          "operation.waiting",
          "operation.lease_lost",
          "operation.outcome_unknown",
          "operation.execution_busy",
        ].includes(cleanupError.code)
      ) throw cleanupError;
      // A later sweep retains the same ownership checks.
    }
    throw error;
  }
}
