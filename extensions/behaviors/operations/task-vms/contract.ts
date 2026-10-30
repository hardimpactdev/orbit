import { z } from "npm:zod@4.4.3";

export const IncusEffect = z.enum([
  "task_vm.incus_create",
  "task_vm.incus_start",
  "task_vm.incus_stop",
  "task_vm.incus_delete",
]);
export const VmLocation = z.object({
  project: z.string().min(1),
  name: z.string().regex(/^tvm-[0-9]+$/),
  cidr: z.string().min(1),
}).strict();
const Power = z.discriminatedUnion("kind", [
  z.object({ kind: z.literal("start") }).strict(),
  z.object({ kind: z.literal("stop") }).strict(),
  z.object({ kind: z.literal("delete"), pool: z.string().min(1) }).strict(),
  z.object({
    kind: z.literal("create"),
    image: z.string().min(1),
    expected_fingerprint: z.string().nullable(),
    base_alias: z.string().min(1),
    gateway_public_key: z.string().min(1),
    cpus: z.number().int().positive(),
    memory: z.string().min(1),
    disk: z.string().min(1),
    network: z.string().min(1),
    pool: z.string().min(1),
    topology: z.boolean(),
  }).strict(),
]);
export const IncusInput = z.object({
  task_vm_id: z.number().int().positive(),
  vm: VmLocation,
  operation: Power,
  effect_key: z.string().optional(),
}).strict().superRefine((input, ctx) => {
  if (input.vm.name !== `tvm-${input.task_vm_id}`) {
    ctx.addIssue({ code: "custom", message: "Task VM identity mismatch" });
  }
});
export const Completion = z.object({
  status: z.enum(["succeeded", "failed", "cancelled"]),
  result: z.object({
    ready: z.boolean().optional(),
    enrolled: z.boolean().optional(),
    changed: z.boolean().optional(),
    process_id: z.number().int().positive().optional(),
    node_id: z.number().int().positive().optional(),
    template_uuid: z.string().nullable().optional(),
    provider_id: z.string().nullable().optional(),
    stopped: z.boolean().optional(),
    archive_ids: z.array(z.string()).max(4096).optional(),
    archive_digests: z.array(z.string()).max(4096).optional(),
    failed_ids: z.array(z.string()).max(4096).optional(),
    failure_reasons: z.array(z.string()).max(4096).optional(),
    skipped: z.array(
      z.object({
        child_id: z.number().int().positive(),
        reason: z.string().min(1),
        key: z.string(),
        kind: z.string(),
      }).strict(),
    ).max(128).optional(),
    fingerprint: z.string().nullable().optional(),
    name: z.string().optional(),
    state: z.string().optional(),
    boot_id: z.string().optional(),
    unit: z.string().optional(),
    invocation_id: z.string().nullable().optional(),
    exit_code: z.number().nullable().optional(),
    reason: z.string().nullable().optional(),
    error_code: z.string().regex(/^[a-z0-9_.-]+$/).nullable().optional(),
  }).strict(),
  error_code: z.string().regex(/^[a-z0-9_.-]+$/).nullable(),
}).strict();

export function incusInvocation(kind: string, input: unknown) {
  IncusEffect.parse(kind);
  const admitted = IncusInput.parse(input);
  const verb = kind.slice("task_vm.incus_".length);
  if (admitted.operation.kind !== verb) {
    throw new Error("Task VM effect does not match admitted kind");
  }
  return {
    step: `incus-${verb}`,
    transport: "agent" as const,
    name: "incus.vm",
    input: { vm: admitted.vm, operation: admitted.operation },
    proof: "ready" as const,
  };
}

const runtimeEffects: Record<
  string,
  {
    name: string;
    transport: string;
    fields: string[];
    action?: string;
    proof: "ready" | "changed" | "stopped" | "receipt";
    command?: string;
    step?: string;
    values?: Record<string, unknown>;
  }
> = {
  "task_vm.model_issue": {
    name: "task_vm.runtime",
    transport: "task_vm_runtime",
    fields: ["task_vm_id", "origin", "key"],
    action: "issue",
    proof: "ready",
  },
  "task_vm.model_revoke": {
    name: "task_vm.runtime",
    transport: "task_vm_runtime",
    fields: ["task_vm_id", "origin", "key"],
    action: "revoke",
    proof: "ready",
  },
  "task_vm.pi_upload": {
    name: "task_vm.runtime",
    transport: "task_vm_runtime",
    fields: ["artifact", "sha256"],
    action: "upload",
    proof: "ready",
  },
  "task_vm.runtime_confirm": {
    name: "task_vm.runtime",
    transport: "task_vm_runtime",
    fields: ["task_vm_id", "sha256"],
    action: "confirm",
    proof: "ready",
  },
  "task_vm.pi_install": {
    name: "pi.install-artifact",
    transport: "agent",
    fields: ["user", "sha256"],
    proof: "changed",
  },
  "task_vm.configure_runtime": {
    name: "pi.configure-runtime",
    transport: "agent",
    fields: ["user", "origin", "models", "model_key", "token"],
    proof: "changed",
  },
};

runtimeEffects["task_vm.cache_initialize"] = {
  name: "package-cache.host",
  transport: "agent",
  fields: [],
  values: { operation: "initialize" },
  proof: "receipt",
};
runtimeEffects["task_vm.cache_mount"] = {
  name: "incus.package-cache",
  transport: "agent",
  fields: ["project", "name"],
  proof: "ready",
};
runtimeEffects["task_vm.cache_seed"] = {
  name: "task_vm.guest",
  transport: "task_vm_guest",
  fields: ["guest", "parameters_json"],
  command: "package-cache.seed",
  proof: "ready",
};

for (const verb of ["create", "start", "stop", "hard_stop", "delete"]) {
  runtimeEffects[`task_vm.upcloud_${verb}`] = {
    name: "task_vm.upcloud",
    transport: "task_vm_upcloud",
    fields: ["task_vm_id", "token"],
    action: verb,
    proof: "ready",
  };
}
for (
  const [verb, action] of Object.entries({
    prepare_enrollment: "prepare-enrollment",
    enroll_node: "enroll",
    confirm_stopped: "stopped",
    mark_destroying: "destroying",
    forget_workspaces: "forget-workspaces",
    destroy_records: "forget",
  })
) {
  runtimeEffects[`task_vm.${verb}`] = {
    name: "task_vm.lifecycle",
    transport: "task_vm_lifecycle",
    fields: ["task_vm_id"],
    action,
    proof: "ready",
  };
}
runtimeEffects["task_vm.stop_worker"] = {
  name: "swamp.stop-worker",
  transport: "agent",
  fields: [],
  proof: "stopped",
};
for (
  const command of [
    "guest.cloud-init",
    "guest.fingerprint",
    "image.owner",
    "image.boot",
    "image.firewall",
    "image.check",
    "image.trust-ca",
    "image.clean",
    "image.recipe-marker",
    "image.inspect-phase",
    "image.start-phase",
    "image.restart-phase",
    "image.verify",
  ]
) {
  runtimeEffects[`task_vm.guest_${command.replaceAll(/[.-]/g, "_")}`] = {
    name: "task_vm.guest",
    transport: "task_vm_guest",
    fields: ["guest", "parameters_json"],
    command,
    step: command,
    proof: command.endsWith("-phase") || command === "guest.fingerprint"
      ? "receipt"
      : "ready",
  };
}
runtimeEffects["task_vm.fill_package_cache_batch"] = {
  name: "task_vm.package-cache",
  transport: "task_vm_package-cache",
  fields: [
    "host_node_id",
    "artifacts",
    "conflicts",
    "max_bytes",
    "github_token",
  ],
  proof: "ready",
};
for (
  const [verb, command, fields] of [
    ["image_remove", "incus.vm", ["vm", "operation"]],
    ["image_launch", "incus.vm", ["vm", "operation"]],
    ["image_metadata", "incus.image", ["project", "operation"]],
    ["image_publish", "incus.image", ["project", "operation"]],
    ["image_seed", "incus.build-topology-seed", [
      "project",
      "pool",
      "vm",
      "size",
      "fingerprint",
    ]],
    ["image_finish_seed", "incus.volume", ["location", "operation"]],
  ] as const
) {
  runtimeEffects[`task_vm.${verb}`] = {
    name: "task_vm.image-host",
    transport: "task_vm_image-host",
    fields: [...fields],
    command,
    proof: "ready",
  };
}
for (const verb of ["image_promote", "image_prune"]) {
  runtimeEffects[`task_vm.${verb}`] = {
    name: "task_vm.image-host",
    transport: "task_vm_image-host",
    fields: ["build_id"],
    proof: "ready",
  };
}
runtimeEffects["task_vm.guest_install"] = {
  name: "task_vm.guest",
  transport: "task_vm_guest",
  command: "agent.install-guest",
  fields: ["guest"],
  values: { parameters_json: "{}" },
  proof: "ready",
};
runtimeEffects["task_vm.guest_pi_upload"] = {
  name: "task_vm.guest",
  transport: "task_vm_guest",
  command: "pi.upload-guest",
  fields: ["guest", "parameters_json"],
  proof: "ready",
};
runtimeEffects["task_vm.image_intent"] = {
  name: "task_vm.image-phase",
  transport: "task_vm_image-phase",
  fields: ["build_id"],
  proof: "ready",
};
runtimeEffects["task_vm.image_phase_intent"] = {
  name: "task_vm.image-phase",
  transport: "task_vm_image-phase",
  fields: ["key"],
  proof: "ready",
};
runtimeEffects["task_vm.image_phase_confirm"] = {
  name: "task_vm.image-phase",
  transport: "task_vm_image-phase",
  fields: ["key", "boot_id"],
  proof: "ready",
};
for (
  const verb of [
    "prepare",
    "launch-builder",
    "launch-smoke",
    "stop",
    "hard-stop-builder",
    "hard-stop-smoke",
    "templatize",
    "delete-builder",
    "delete-smoke",
    "promote",
    "prune-template",
    "discard-template",
  ]
) {
  runtimeEffects[`task_vm.upcloud_image_${verb.replaceAll("-", "_")}`] = {
    name: "task_vm.upcloud-image",
    transport: "task_vm_upcloud-image",
    step: verb,
    fields: [
      "build_id",
      ...(verb === "prepare" ? [] : ["token"]),
      ...(verb.startsWith("launch-") ? ["bootstrap"] : []),
      ...(verb === "prune-template" ? ["template_uuid"] : []),
    ],
    action: verb.startsWith("launch-")
      ? "launch"
      : verb.startsWith("hard-stop-")
      ? "hard-stop"
      : verb,
    values: verb.startsWith("launch-")
      ? { slot: verb === "launch-smoke" ? "smoke" : "builder" }
      : verb.startsWith("hard-stop-")
      ? { slot: verb.slice("hard-stop-".length) }
      : {},
    proof: "ready",
  };
}
for (
  const command of [
    "guest.cloud-init",
    "image.owner",
    "image.boot",
    "image.firewall",
    "image.check",
    "image.trust-ca",
    "image.clean",
    "image.recipe-marker",
    "image.start-phase",
    "image.verify",
    "agent.install-guest",
    "pi.upload-guest",
  ]
) {
  runtimeEffects[`task_vm.upcloud_guest_${command.replaceAll(/[.-]/g, "_")}`] =
    {
      name: "task_vm.upcloud-image",
      transport: "task_vm_upcloud-image",
      step: `upcloud-guest-${command.replaceAll(".", "-")}`,
      fields: ["build_id", "slot", "parameters_json"],
      action: "guest",
      command,
      proof: command === "image.start-phase" ? "receipt" : "ready",
    };
}
for (const verb of ["delete", "delete_upcloud", "stop_upcloud", "observe"]) {
  runtimeEffects[`task_vm.sweep_${verb}`] = {
    name: "task_vm.sweep",
    transport: "task_vm_sweep",
    step: `sweep-${verb.replaceAll("_", "-")}`,
    fields: [
      "node_id",
      "provider",
      "sweep_id",
      ...(verb === "observe" ? [] : ["deletion"]),
      ...(["delete_upcloud", "stop_upcloud"].includes(verb) ? ["token"] : []),
    ],
    action: verb === "observe"
      ? "observe"
      : verb === "stop_upcloud"
      ? "stop"
      : "delete",
    proof: "ready",
  };
}
export const leafKinds = [
  ...IncusEffect.options,
  ...Object.keys(runtimeEffects),
];

export function invocationFor(kind: string, raw: unknown) {
  if (IncusEffect.safeParse(kind).success) return incusInvocation(kind, raw);
  const definition = runtimeEffects[kind];
  if (!definition) throw new Error("Unknown task VM effect");
  const admitted = z.record(z.string(), z.unknown()).parse(raw);
  const input: Record<string, unknown> = {};
  for (const field of definition.fields) {
    if (!(field in admitted)) throw new Error(`Missing admitted ${field}`);
    input[field] = admitted[field];
  }
  Object.assign(input, definition.values);
  if (definition.action) input.action = definition.action;
  if (definition.command) input.command = definition.command;
  return {
    step: definition.step ??
      kind.slice("task_vm.".length).replaceAll("_", "-"),
    name: definition.name,
    transport: definition.transport,
    input,
    proof: definition.proof,
  };
}
