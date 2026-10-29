import { z } from "npm:zod@4.4.3";
import { ExecutorError } from "../../operation-runner/mod.ts";
import type { FamilyEffects } from "./runtime.ts";

export const recipePhases = [
  "apt-source",
  "packages",
  "javascript",
  "rust",
  "agent",
  "pi",
] as const;
export const ImageInput = z.object({
  build_id: z.number().int().positive(),
  attempt: z.number().int().positive(),
  host_node_id: z.number().int().positive(),
  deadline_at: z.number().int().positive(),
  identity: z.string().regex(/^sha256:[a-f0-9]{64}$/),
  generation: z.number().int().positive(),
  project: z.string(),
  name: z.string(),
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
  recipe: z.string().transform((value) =>
    z.record(z.string(), z.unknown()).parse(JSON.parse(value))
  ),
  pi_artifact: z.object({ path: z.string(), sha256: z.string() }).nullable(),
  resume_from: z.string().nullable(),
  effect_key: z.string().optional(),
  reuse_published: z.boolean(),
}).strict();
export type ImageIntent = z.infer<typeof ImageInput>;

export async function installWithRetry(
  key: string,
  install: (key: string) => Promise<unknown>,
) {
  for (let attempt = 0;; attempt++) {
    try {
      await install(attempt === 0 ? key : `${key}-retry-${attempt}`);
      return;
    } catch (error) {
      if (
        !(error instanceof ExecutorError) ||
        error.code !== "agent.supervisor_not_ready"
      ) throw error;
    }
  }
}

export async function buildImage(input: ImageIntent, effects: FamilyEffects) {
  const guest = (smoke = false) => ({
    project: input.project,
    name: input.name + (smoke ? "-smoke" : ""),
  });
  const vm = (smoke = false) => ({ ...guest(smoke), cidr: input.cidr });
  const child = (
    key: string,
    kind: string,
    payload: Record<string, unknown>,
    phase = key,
  ) =>
    effects.child(key, kind, input.host_node_id, {
      ...payload,
      build_id: input.build_id,
      attempt: input.attempt,
      phase,
    });
  const command = (
    key: string,
    name: string,
    parameters: Record<string, unknown>,
    smoke = false,
    phase = key,
  ) =>
    child(key, `task_vm.guest_${name.replaceAll(/[.-]/g, "_")}`, {
      guest: guest(smoke),
      parameters_json: JSON.stringify(parameters),
    }, phase);
  const desired = async () => {
    const state = await effects.read("image");
    if (state.desired !== true) {
      throw new ExecutorError("task_vm.image_recipe_superseded");
    }
    return state;
  };
  await desired();
  await child("image-intent", "task_vm.image_intent", {
    build_id: input.build_id,
  }, "intent");
  let enabled = input.resume_from === null;
  const run = async (phase: string, action: () => Promise<unknown>) => {
    if (!enabled && phase === input.resume_from) enabled = true;
    if (!enabled) return;
    if (
      input.reuse_published &&
      ["launch", "boot", ...recipePhases, "prepare", "clean", "publish"]
        .includes(phase)
    ) return;
    const work = async () => {
      await desired();
      await action();
    };
    if (effects.stage) await effects.stage(phase, work);
    else await work();
  };
  const bootstrap = async (smoke: boolean, seed = false) => {
    const prefix = seed ? "seed-smoke" : smoke ? "smoke" : "builder";
    const phase = seed ? "topology-seed" : smoke ? "smoke" : "boot";
    await installWithRetry(`${prefix}-agent`, async (key) => {
      const state = await effects.read(
        seed ? "image/seed-boot" : smoke ? "image/smoke-boot" : "image/boot",
      );
      if (state.expired === true) {
        throw new ExecutorError("task_vm.bootstrap_timeout");
      }
      await child(key, "task_vm.guest_install", { guest: guest(smoke) }, phase);
    });
    for (;;) {
      const state = await effects.read(
        seed ? "image/seed-boot" : smoke ? "image/smoke-boot" : "image/boot",
      );
      if (state.ready === true) break;
      if (state.expired === true) {
        throw new ExecutorError("task_vm.bootstrap_timeout");
      }
      await effects.wait();
    }
    await command(
      seed
        ? "seed-smoke-boot-confirmed"
        : smoke
        ? "smoke-boot-confirmed"
        : "boot-confirmed",
      "guest.cloud-init",
      {},
      smoke,
      phase,
    );
  };
  await run("reset", async () => {
    await child("reset-builder", "task_vm.image_remove", {
      vm: vm(),
      operation: { kind: "remove_instance" },
    }, "reset");
    await child("reset-smoke", "task_vm.image_remove", {
      vm: vm(true),
      operation: { kind: "remove_instance" },
    }, "reset");
  });
  await run(
    "launch",
    () =>
      child("launch", "task_vm.image_launch", {
        vm: vm(),
        operation: {
          kind: "create_image",
          image: input.source,
          gateway_public_key: input.gateway_public_key,
          cpus: input.cpus,
          memory: input.memory,
          disk: input.disk,
          network: input.network,
          builder: true,
        },
      }),
  );
  await run("boot", () => bootstrap(false));
  for (const phase of recipePhases) {
    await run(phase, async () => {
      if (phase === "pi" && input.pi_artifact === null) return;
      const key = {
        identity: input.identity,
        profile: "base",
        phase,
        execution: String(input.build_id),
      };
      await child(
        `${phase}-intent`,
        "task_vm.image_phase_intent",
        { key },
        phase,
      );
      let state = await effects.read(`image/phase-${phase}`);

      if (
        state.state === "failed" && input.attempt > Number(state.attempt ?? 1)
      ) {
        await command(
          `${phase}-restart`,
          "image.restart-phase",
          { key, boot_id: state.boot_id, invocation_id: state.invocation_id },
          false,
          phase,
        );
        state = await effects.read(`image/phase-${phase}`);
      }
      if (state.state === "missing") {
        if (phase === "pi" && input.pi_artifact) {
          await child("pi-artifact", "task_vm.guest_pi_upload", {
            guest: guest(),
            parameters_json: JSON.stringify({
              artifact: input.pi_artifact.path,
              sha256: input.pi_artifact.sha256,
            }),
          }, phase);
        }
        await command(
          `${phase}-start`,
          "image.start-phase",
          { key, boot_id: state.boot_id, recipe: input.recipe },
          false,
          phase,
        );
      }
      for (;;) {
        state = await effects.read(`image/phase-${phase}`);
        if (state.state === "complete") break;
        if (state.state === "failed") {
          throw new ExecutorError("task_vm.image_build_failed");
        }
        if (state.state !== "running") {
          throw new ExecutorError("task_vm.image_channel_lost");
        }
        await effects.wait();
      }
      await child(`${phase}-confirmed`, "task_vm.image_phase_confirm", {
        key,
        boot_id: state.boot_id,
      }, phase);
    });
  }
  await run("prepare", async () => {
    await command("owner", "image.owner", {}, false, "prepare");
    await child("metadata", "task_vm.image_metadata", {
      project: input.project,
      operation: { kind: "prepare_metadata", name: input.name },
    }, "prepare");
    await command(
      "boot-policy",
      "image.boot",
      { lean: true },
      false,
      "prepare",
    );
  });
  await run("clean", async () => {
    await command(
      "recipe-marker",
      "image.recipe-marker",
      { identity: input.identity, profile: "base" },
      false,
      "clean",
    );
    await command(
      "root-ca",
      "image.trust-ca",
      { certificate: input.certificate },
      false,
      "clean",
    );
    await command("clean", "image.clean", {}, false, "clean");
  });
  let state = await desired();
  let fingerprint = typeof state.fingerprint === "string"
    ? state.fingerprint
    : null;
  await run("publish", async () => {
    const published = await child("publish", "task_vm.image_publish", {
      project: input.project,
      operation: {
        kind: "publish",
        name: input.name,
        alias: input.candidate_alias,
        properties: { "orbit.recipe": input.identity, "orbit.profile": "base" },
      },
    });
    fingerprint = z.string().regex(/^[a-f0-9]{64}$/).parse(
      published.fingerprint,
    );
    await child("delete-builder", "task_vm.image_remove", {
      vm: vm(),
      operation: { kind: "remove_instance" },
    }, "publish");
  });
  state = await desired();
  fingerprint ??= typeof state.fingerprint === "string"
    ? state.fingerprint
    : null;
  if (fingerprint === null) {
    throw new ExecutorError("task_vm.image_build_failed");
  }
  const launchSmoke = () =>
    child("launch-smoke", "task_vm.image_launch", {
      vm: vm(true),
      operation: {
        kind: "create_image",
        image: fingerprint,
        gateway_public_key: input.gateway_public_key,
        cpus: input.cpus,
        memory: input.memory,
        disk: input.disk,
        network: input.network,
        builder: false,
      },
    }, "smoke");
  await run("smoke", async () => {
    await launchSmoke();
    await bootstrap(true);
    await command(
      "smoke-check",
      "image.check",
      { lean_boot: true, firewall: false },
      true,
      "smoke",
    );
    await command("smoke-verify", "image.verify", input.recipe, true, "smoke");
  });
  await run("topology-seed", async () => {
    state = await desired();
    if (state.seed_ready !== true) {
      if (state.seed_pending === true) {
        await child("reset-seed-smoke", "task_vm.image_remove", {
          vm: vm(true),
          operation: { kind: "remove_instance" },
        }, "topology-seed");
        await child("seed-smoke-launch", "task_vm.image_launch", {
          vm: vm(true),
          operation: {
            kind: "create_image",
            image: fingerprint,
            gateway_public_key: input.gateway_public_key,
            cpus: input.cpus,
            memory: input.memory,
            disk: input.disk,
            network: input.network,
            builder: false,
          },
        }, "topology-seed");
        await bootstrap(true, true);
      }
      await child("topology-seed", "task_vm.image_seed", {
        project: input.project,
        pool: input.pool,
        vm: guest(true).name,
        size: "45GiB",
        fingerprint,
      });
    }
    await child("delete-smoke", "task_vm.image_remove", {
      vm: vm(true),
      operation: { kind: "remove_instance" },
    }, "topology-seed");
    await child("finish-seed", "task_vm.image_finish_seed", {
      location: { project: input.project, pool: input.pool },
      operation: { kind: "finish_seed", fingerprint },
    }, "topology-seed");
  });
  await run(
    "promote",
    () =>
      child(
        "promote",
        "task_vm.image_promote",
        { build_id: input.build_id },
        "promote",
      ),
  );
  await run("prune", async () => {
    state = await desired();
    await child(
      "prune",
      "task_vm.image_prune",
      { build_id: input.build_id },
      "prune",
    );
  });
  if (!enabled) throw new ExecutorError("task_vm.image_build_failed");
  return { ready: true, fingerprint };
}
