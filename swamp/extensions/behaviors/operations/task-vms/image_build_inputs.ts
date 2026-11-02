import { z } from "npm:zod@4.4.3";
import { recipePhases } from "./images.ts";

/**
 * Pure shaping for the image build workflow: agent envelopes in, typed agent
 * replies out. No host command, Gateway call, wait or retry runs here; every
 * Node effect is one official SSH step in the workflow.
 */
const hash = z.string().regex(/^[a-f0-9]{64}$/);
const object = z.record(z.string(), z.unknown());
const Code = /^[a-z0-9_]+\.[a-z0-9_.-]{1,80}$/;
export const phases = recipePhases;

const StoredImageIntent = z.object({
  build_id: z.number().int().positive(),
  attempt: z.number().int().positive(),
  host_node_id: z.number().int().positive(),
  agent_sha256: hash,
  agent_url: z.string().url().startsWith("https://"),
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
  ssh_execution: object,
  pi_source: object,
  certificate: z.string(),
  recipe: object,
  pi_artifact: z.object({ path: z.string().startsWith("/"), sha256: hash })
    .strict().nullable(),
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
    input.name !== `tvm-image-${input.build_id}` &&
    input.name !== `tvm-image-${input.build_id}-attempt-${input.attempt}`
  ) {
    ctx.addIssue({
      code: "custom",
      message: "Image name differs from admitted attempt",
    });
  }
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

/** One agent call on stdin: the frozen `{agent_sha256, deadline_ms, input}` line. */
export function envelope(
  pin: string,
  seconds: number,
  input: Record<string, unknown>,
): string {
  return JSON.stringify({
    agent_sha256: hash.parse(pin),
    deadline_ms: seconds * 1000,
    input,
  }) + "\n";
}

const key = (input: Intent, phase: string) => ({
  identity: input.identity,
  profile: "base",
  phase,
  execution: `${input.build_id}-${input.attempt}`,
});

/** The retained guest unit name that `image.start-phase` gives one phase key. */
export async function phaseUnit(input: Intent, phase: string) {
  const k = key(input, phase);
  const bytes = await crypto.subtle.digest(
    "SHA-256",
    new TextEncoder().encode(
      `${k.identity}:${k.profile}:${k.phase}:${k.execution}`,
    ),
  );
  const digest = Array.from(
    new Uint8Array(bytes),
    (byte) => byte.toString(16).padStart(2, "0"),
  ).join("");
  return `orbit-recipe-image-${digest.slice(0, 32)}.service`;
}

const vm = (input: Intent, smoke = false) => ({
  project: input.project,
  name: input.name + (smoke ? "-smoke" : ""),
  cidr: input.cidr,
});
const create = (input: Intent, smoke: boolean, image: string) => ({
  vm: vm(input, smoke),
  operation: {
    kind: "create_image",
    image,
    gateway_public_key: input.gateway_public_key,
    cpus: input.cpus,
    memory: input.memory,
    disk: input.disk,
    network: input.network,
    builder: !smoke,
  },
});

/** Every envelope whose input is fixed by the admitted intent. */
export async function imagePlan(raw: unknown) {
  const input = ImageIntent.parse(raw);
  const pin = input.agent_sha256;
  const host = (seconds: number, payload: Record<string, unknown>) =>
    envelope(pin, seconds, payload);
  const remove = (smoke: boolean) =>
    host(900, { vm: vm(input, smoke), operation: { kind: "remove_instance" } });
  const candidate = `/var/tmp/orbit-agent-candidate-${pin}`;
  return {
    reset_builder: remove(false),
    reset_smoke: remove(true),
    launch: host(900, create(input, false, input.source)),
    inspect_boot: envelope(pin, 30, {
      key: key(input, recipePhases[0]),
      boot_id: null,
    }),
    install: envelope(pin, 180, { candidate, sha256: pin }),
    cloud_init: envelope(pin, 45, {}),
    owner: envelope(pin, 120, {}),
    prepare_metadata: host(900, {
      project: input.project,
      operation: { kind: "prepare_metadata", name: input.name },
    }),
    lean_boot: envelope(pin, 120, { lean: true }),
    marker: envelope(pin, 120, { identity: input.identity, profile: "base" }),
    trust_ca: envelope(pin, 120, { certificate: input.certificate }),
    clean: envelope(pin, 120, {}),
    publish: host(900, {
      project: input.project,
      operation: {
        kind: "publish",
        name: input.name,
        alias: `${input.candidate_alias}-attempt-${input.attempt}`,
        properties: { "orbit.recipe": input.identity, "orbit.profile": "base" },
      },
    }),
    check: envelope(pin, 120, { lean_boot: true, firewall: false }),
    verify: envelope(pin, 120, input.recipe),
    prune: host(900, input.prune),
    candidate,
    units: Object.fromEntries(
      await Promise.all(
        recipePhases.map(async (phase) => [phase, await phaseUnit(input, phase)]),
      ),
    ),
    pi_target: input.pi_artifact === null
      ? ""
      : `/var/cache/orbit/pi/${input.pi_artifact.sha256}.bin`,
  };
}

const Reply = z.discriminatedUnion("ok", [
  z.object({ ok: z.literal(true), data: object }),
  z.object({
    ok: z.literal(false),
    error: z.object({ code: z.string() }).passthrough(),
  }),
]);

/** A failed agent reply keeps its own public code; anything unreadable is a build failure. */
export function agentData(stdout: string): Record<string, unknown> {
  let reply: z.infer<typeof Reply>;
  try {
    reply = Reply.parse(JSON.parse(stdout));
  } catch {
    throw new Error("task_vm.image_build_failed");
  }
  if (!reply.ok) {
    throw new Error(
      Code.test(reply.error.code) ? reply.error.code : "task_vm.image_build_failed",
    );
  }
  return reply.data;
}

export const Expect = z.enum([
  "ready",
  "installed",
  "started",
  "complete",
  "seed-volume",
]);
const Observation = z.object({
  boot_id: z.string().uuid(),
  state: z.enum(["missing", "running", "complete", "failed"]),
}).passthrough();

/** Check one agent reply against what its step needs before the next step may run. */
export function agentReply(args: {
  stdout: string;
  expect: z.infer<typeof Expect>;
  pin?: string;
  boot_id?: string;
  fingerprint?: string;
}): { ok: true } {
  const data = agentData(args.stdout);
  const failed = () => new Error("task_vm.image_build_failed");
  switch (args.expect) {
    case "ready":
      if (data.ready !== true) throw failed();
      break;
    case "installed":
      if (data.sha256 !== args.pin || typeof data.replaced !== "boolean") {
        throw new Error("task_vm.bootstrap_failed");
      }
      break;
    case "started":
    case "complete": {
      const observed = Observation.parse(data);
      if (observed.boot_id !== args.boot_id) {
        throw new Error("task_vm.image_builder_rebooted");
      }
      const accepted = args.expect === "started"
        ? ["running", "complete"]
        : ["complete"];
      if (!accepted.includes(observed.state)) throw failed();
      break;
    }
    case "seed-volume":
      if (
        data.name !==
          `orbit-topology-seed-${hash.parse(args.fingerprint).slice(0, 12)}`
      ) throw failed();
      break;
  }
  return { ok: true };
}

/** The builder's boot identity fixes every phase start and completion read. */
export function imageBoot(raw: unknown, stdout: string) {
  const input = ImageIntent.parse(raw);
  const boot_id = Observation.parse(agentData(stdout)).boot_id;
  const recipe = input.recipe;
  return {
    boot_id,
    start: Object.fromEntries(recipePhases.map((phase) => [
      phase,
      envelope(input.agent_sha256, 120, {
        key: key(input, phase),
        boot_id,
        recipe,
      }),
    ])),
    inspect: Object.fromEntries(recipePhases.map((phase) => [
      phase,
      envelope(input.agent_sha256, 30, { key: key(input, phase), boot_id }),
    ])),
  };
}

/** The published fingerprint fixes every later host call. */
export function imagePublication(raw: unknown, stdout: string) {
  const input = ImageIntent.parse(raw);
  const data = agentData(stdout);
  if (data.ready !== true) throw new Error("task_vm.image_build_failed");
  const fingerprint = hash.parse(data.fingerprint);
  const pin = input.agent_sha256;
  const image = (operation: Record<string, unknown>) =>
    envelope(pin, 900, { project: input.project, operation });
  return {
    fingerprint,
    promote_candidate: image({
      kind: "promote",
      alias: input.candidate_alias,
      fingerprint,
    }),
    launch_smoke: envelope(pin, 900, create(input, true, fingerprint)),
    seed: envelope(pin, 600, {
      project: input.project,
      pool: input.pool,
      vm: vm(input, true).name,
      size: "45GiB",
      fingerprint,
    }),
    finish_seed: envelope(pin, 180, {
      location: { project: input.project, pool: input.pool },
      operation: { kind: "finish_seed", fingerprint },
    }),
    promote: image({ kind: "promote", alias: input.base_alias, fingerprint }),
  };
}

type Handle = { name: string };
type Context = {
  writeResource(
    spec: string,
    name: string,
    data: Record<string, unknown>,
  ): Promise<Handle>;
};
const resource = (schema: z.ZodType) => ({
  description: "Public image build shaping",
  schema,
  lifetime: "1d",
  garbageCollection: 1,
});
const Envelopes = z.record(z.string(), z.string());
const Stdout = z.object({ intent: z.unknown(), stdout: z.string() });
const ReplyArgs = z.object({
  stdout: z.string(),
  expect: Expect,
  pin: z.string().optional(),
  boot_id: z.string().optional(),
  fingerprint: z.string().optional(),
});

export const behavior = {
  type: "@nckrtl/task-vm-image-build",
  version: "2026.11.02.1",
  resources: {
    plan: resource(z.object({
      units: Envelopes,
      candidate: z.string(),
      pi_target: z.string(),
    }).catchall(z.unknown())),
    reply: resource(z.object({ ok: z.literal(true) })),
    boot: resource(z.object({
      boot_id: z.string().uuid(),
      start: Envelopes,
      inspect: Envelopes,
    })),
    publication: resource(z.object({ fingerprint: hash }).catchall(z.string())),
  },
  methods: {
    plan: {
      description: "Shape the fixed agent envelopes of one admitted image attempt",
      arguments: z.object({ intent: z.unknown() }),
      execute: async (args: { intent: unknown }, ctx: Context) => ({
        dataHandles: [
          await ctx.writeResource("plan", "plan", await imagePlan(args.intent)),
        ],
      }),
    },
    reply: {
      description: "Check one agent reply; a refusal fails with its public code",
      arguments: ReplyArgs,
      execute: async (args: z.infer<typeof ReplyArgs>, ctx: Context) => ({
        dataHandles: [
          await ctx.writeResource("reply", "reply", agentReply(args)),
        ],
      }),
    },
    boot: {
      description: "Read the builder boot and shape every phase envelope",
      arguments: Stdout,
      execute: async (args: z.infer<typeof Stdout>, ctx: Context) => ({
        dataHandles: [
          await ctx.writeResource(
            "boot",
            "boot",
            imageBoot(args.intent, args.stdout),
          ),
        ],
      }),
    },
    publication: {
      description: "Read the published fingerprint and shape later host calls",
      arguments: Stdout,
      execute: async (args: z.infer<typeof Stdout>, ctx: Context) => ({
        dataHandles: [
          await ctx.writeResource(
            "publication",
            "publication",
            imagePublication(args.intent, args.stdout),
          ),
        ],
      }),
    },
  },
};
