import { z } from "npm:zod@4.4.3";

export const SecretReference = z.object({
  $secret: z.string().min(1),
  id: z.number().int().positive(),
}).strict();
export const RuntimeInput = z.object({
  task_vm_id: z.number().int().positive(),
  node_id: z.number().int().positive(),
  user: z.string().min(1),
  origin: z.string().url(),
  sha256: z.string().regex(/^[a-f0-9]{64}$/),
  models: z.array(z.string().min(1)).nonempty(),
  artifact: z.string().min(1),
  deadline_at: z.number().int().positive(),
  model_key: SecretReference,
  token: SecretReference,
  effect_key: z.string().optional(),
}).strict().superRefine((input, ctx) => {
  if (
    input.model_key.$secret !== "task_vm.model_key" ||
    input.token.$secret !== "task_vm.pi_token" ||
    input.model_key.id !== input.task_vm_id ||
    input.token.id !== input.task_vm_id
  ) {
    ctx.addIssue({
      code: "custom",
      message: "Runtime credential owner mismatch",
    });
  }
});
export type RuntimeIntent = z.infer<typeof RuntimeInput>;

export interface FamilyEffects {
  stage?<T>(key: string, action: () => Promise<T>): Promise<T>;
  child(
    key: string,
    kind: string,
    node: number,
    input: Record<string, unknown>,
  ): Promise<Record<string, unknown>>;
  read(view: string): Promise<Record<string, unknown>>;
  wait(): Promise<void>;
}

/** The Gateway keeps keys and reads; Swamp owns this order and every retry boundary. */
export async function prepareRuntime(
  input: RuntimeIntent,
  effects: FamilyEffects,
) {
  await effects.child("model-key", "task_vm.model_issue", input.node_id, {
    task_vm_id: input.task_vm_id,
    origin: input.origin,
    key: input.model_key,
  });
  const installed = await effects.read("runtime");
  if (typeof installed.pi_current !== "boolean") {
    throw new Error("task_vm.runtime_observation_unknown");
  }
  if (installed.pi_current !== true) {
    await effects.child("pi-upload", "task_vm.pi_upload", input.node_id, {
      artifact: input.artifact,
      sha256: input.sha256,
    });
    await effects.child("pi-install", "task_vm.pi_install", input.node_id, {
      user: input.user,
      sha256: input.sha256,
    });
  }
  await effects.child(
    "pi-configure",
    "task_vm.configure_runtime",
    input.node_id,
    {
      user: input.user,
      origin: input.origin,
      models: input.models,
      model_key: input.model_key,
      token: input.token,
    },
  );
  await effects.child("pi-process", "task_vm.pi_process", input.node_id, {
    task_vm_id: input.task_vm_id,
  });
  for (;;) {
    const runtime = await effects.read("runtime");
    if (runtime.responds === true) break;
    await effects.wait();
  }
  await effects.child(
    "runtime-confirmed",
    "task_vm.runtime_confirm",
    input.node_id,
    {
      task_vm_id: input.task_vm_id,
      sha256: input.sha256,
    },
  );
  return { ready: true };
}
