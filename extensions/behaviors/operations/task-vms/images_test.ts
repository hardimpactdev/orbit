import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  buildImage,
  ImageInput,
  installWithRetry,
  recipePhases,
} from "./images.ts";
import type { FamilyEffects } from "./runtime.ts";

const intent = (extra = {}) =>
  ImageInput.parse({
    build_id: 4,
    attempt: 1,
    host_node_id: 12,
    deadline_at: 4_000_000_000,
    identity: `sha256:${"a".repeat(64)}`,
    generation: 2,
    project: "tasks",
    name: "tvm-image-4",
    cidr: "10.1.0.1/24",
    pool: "default",
    network: "tasks",
    cpus: 4,
    memory: "8GiB",
    disk: "60GiB",
    source: "ubuntu:24.04",
    candidate_alias: "candidate-4",
    base_alias: "base",
    gateway_public_key: "public",
    certificate: "public-ca",
    recipe: JSON.stringify({
      recipe: { identity: `sha256:${"a".repeat(64)}` },
      packages: {},
    }),
    pi_artifact: { path: "/artifacts/pi", sha256: "b".repeat(64) },
    resume_from: null,
    reuse_published: false,
    ...extra,
  });
class Effects implements FamilyEffects {
  stages = new Map<string, unknown>();
  async stage<T>(key: string, work: () => Promise<T>): Promise<T> {
    if (this.stages.has(key)) return this.stages.get(key) as T;
    const result = await work();
    this.stages.set(key, result);
    return result;
  }

  calls: { key: string; kind: string; input: Record<string, unknown> }[] = [];
  receipts = new Map<string, Record<string, unknown>>();
  phases = new Map<string, string>();
  desired = true;
  seedPending = false;
  fingerprint: string | null = null;
  refuse: string | null = null;
  lose: string | null = null;
  async child(
    key: string,
    kind: string,
    _node: number,
    input: Record<string, unknown>,
  ) {
    await Promise.resolve();
    if (!this.receipts.has(key)) {
      this.calls.push({ key, kind, input });
      if (this.refuse === key) throw new Error("refused");
      if (
        kind === "task_vm.guest_image_start_phase" ||
        kind === "task_vm.guest_image_restart_phase"
      ) {
        const parameters = JSON.parse(String(input.parameters_json));
        assertEquals(parameters.boot_id, "boot-1");
        assertEquals(parameters.key.execution, "4");
        this.phases.set(parameters.key.phase, "complete");
      }
      if (key === "publish") this.fingerprint = "c".repeat(64);
      this.receipts.set(key, { ready: true, fingerprint: this.fingerprint });
    }
    if (this.lose === key) {
      this.lose = null;
      throw new Error("lost reply");
    }
    return this.receipts.get(key)!;
  }
  read(view: string): Promise<Record<string, unknown>> {
    if (view.includes("phase-")) {
      return Promise.resolve({
        state: this.phases.get(view.split("phase-")[1]) ?? "missing",
        boot_id: "boot-1",
        invocation_id: "old-invocation",
        attempt: 1,
      });
    }
    if (view.endsWith("boot")) {
      return Promise.resolve({ ready: true, expired: false });
    }
    return Promise.resolve({
      desired: this.desired,
      fingerprint: this.fingerprint,
      seed_ready: false,
      seed_pending: this.seedPending,
    });
  }
  wait() {
    return Promise.resolve();
  }
}
Deno.test("image builds snapshot apt first and proves smoke and topology before promotion", async () => {
  const effects = new Effects();
  assertEquals(await buildImage(intent(), effects), {
    ready: true,
    fingerprint: "c".repeat(64),
  });
  const keys = effects.calls.map((call) => call.key);
  assertEquals(
    keys.filter((key) => key.endsWith("-start")),
    recipePhases.map((phase) => `${phase}-start`),
  );
  for (const key of ["smoke-check", "smoke-verify", "finish-seed"]) {
    assertEquals(keys.indexOf(key) < keys.indexOf("promote"), true);
  }
  assertEquals(effects.calls.find((call) => call.key === "finish-seed"), {
    key: "finish-seed",
    kind: "task_vm.image_finish_seed",
    input: {
      build_id: 4,
      attempt: 1,
      phase: "topology-seed",
      location: { project: "tasks", pool: "default" },
      operation: { kind: "finish_seed", fingerprint: "c".repeat(64) },
    },
  });
  assertEquals(keys.at(-1), "prune");
});
Deno.test("image refusals preserve the existing alias and stop later effects", async () => {
  for (
    const refusal of [
      "clean",
      "publish",
      "smoke-check",
      "smoke-verify",
      "finish-seed",
    ]
  ) {
    const effects = new Effects();
    effects.refuse = refusal;
    await assertRejects(() => buildImage(intent(), effects));
    assertEquals(effects.calls.some((call) => call.key === "promote"), false);
  }
});
Deno.test("image restart after each uncertain boundary reuses child identities", async () => {
  for (
    const lose of [
      "launch",
      "apt-source-start",
      "publish",
      "smoke-verify",
      "promote",
      "prune",
    ]
  ) {
    const effects = new Effects();
    effects.lose = lose;
    await assertRejects(() => buildImage(intent(), effects));
    await buildImage(intent(), effects);
    const keys = effects.calls.map((call) => call.key);
    assertEquals(new Set(keys).size, keys.length);
  }
});
Deno.test("only a later explicit attempt restarts a failed native invocation with its original boot identity", async () => {
  const effects = new Effects();
  effects.phases.set("apt-source", "failed");
  await assertRejects(() => buildImage(intent(), effects));
  assertEquals(
    effects.calls.some((call) => call.key === "apt-source-restart"),
    false,
  );
  await buildImage(intent({ attempt: 2, resume_from: "apt-source" }), effects);
  const restart = effects.calls.find((call) =>
    call.key === "apt-source-restart"
  )!;
  assertEquals(
    JSON.parse(String(restart.input.parameters_json)).invocation_id,
    "old-invocation",
  );
});
Deno.test("unknown native phase state never authorizes a new installer", async () => {
  const effects = new Effects();
  effects.phases.set("apt-source", "unknown");
  await assertRejects(() => buildImage(intent(), effects));
  assertEquals(
    effects.calls.some((call) => call.key === "apt-source-start"),
    false,
  );
});
Deno.test("a partial topology seed gets a fresh smoke VM before resuming", async () => {
  const effects = new Effects();
  effects.seedPending = true;
  effects.fingerprint = "c".repeat(64);
  await buildImage(
    intent({ reuse_published: true, resume_from: "topology-seed" }),
    effects,
  );
  assertEquals(effects.calls.slice(1, 3).map((call) => call.key), [
    "reset-seed-smoke",
    "seed-smoke-launch",
  ]);
});
Deno.test("seed finalization resumes after smoke deletion without probing the deleted guest", async () => {
  const effects = new Effects();
  effects.fingerprint = "c".repeat(64);
  let guestPresent = true;
  const child = effects.child.bind(effects);
  effects.child = async (key, ...args) => {
    const fresh = !effects.receipts.has(key);
    try {
      return await child(key, ...args);
    } finally {
      if (fresh && effects.receipts.has(key)) {
        if (key === "topology-seed") effects.seedPending = true;
        if (key === "reset-seed-smoke" || key === "delete-smoke") {
          guestPresent = false;
        }
        if (key === "seed-smoke-launch") guestPresent = true;
      }
    }
  };
  const read = effects.read.bind(effects);
  effects.read = (view) => {
    if (view.endsWith("boot") && !guestPresent) {
      return Promise.reject(new Error("The smoke guest no longer exists"));
    }
    return read(view);
  };
  const input = intent({
    reuse_published: true,
    resume_from: "topology-seed",
  });
  effects.lose = "topology-seed";
  await assertRejects(() => buildImage(input, effects), Error, "lost reply");
  effects.lose = "delete-smoke";
  await assertRejects(() => buildImage(input, effects), Error, "lost reply");
  assertEquals(guestPresent, false);
  assertEquals(await buildImage(input, effects), {
    ready: true,
    fingerprint: "c".repeat(64),
  });
  const keys = effects.calls.map((call) => call.key);
  assertEquals(keys.filter((key) => key === "seed-smoke-launch").length, 1);
  assertEquals(keys.slice(-3), ["finish-seed", "promote", "prune"]);
});
Deno.test("a retained published image can retry pruning without rebuilding or repromoting", async () => {
  const effects = new Effects();
  effects.fingerprint = "c".repeat(64);
  await buildImage(
    intent({ reuse_published: true, resume_from: "prune" }),
    effects,
  );
  assertEquals(effects.calls.map((call) => call.key), [
    "image-intent",
    "prune",
  ]);
});
Deno.test("superseded recipe refuses before any VM effect", async () => {
  const effects = new Effects();
  effects.desired = false;
  await assertRejects(() => buildImage(intent(), effects));
  assertEquals(effects.calls, []);
});

Deno.test("bootstrap retries only a proven supervisor-not-ready refusal with a new child key", async () => {
  const { ExecutorError } = await import("../../operation-runner/mod.ts");
  const keys: string[] = [];
  await installWithRetry("builder-agent", (key) => {
    keys.push(key);
    return key === "builder-agent"
      ? Promise.reject(new ExecutorError("agent.supervisor_not_ready"))
      : Promise.resolve({ ready: true });
  });
  assertEquals(keys, ["builder-agent", "builder-agent-retry-1"]);
  await assertRejects(
    () =>
      installWithRetry(
        "builder-agent",
        () => Promise.reject(new ExecutorError("operation.outcome_unknown")),
      ),
    ExecutorError,
    "operation.outcome_unknown",
  );
});
Deno.test("an expired image boot never authorizes another guest installation", async () => {
  const effects = new Effects();
  const read = effects.read.bind(effects);
  effects.read = (view: string) =>
    view.endsWith("boot")
      ? Promise.resolve({ ready: false, expired: true })
      : read(view);
  await assertRejects(() => buildImage(intent(), effects));
  assertEquals(
    effects.calls.some((call) => call.kind === "task_vm.guest_install"),
    false,
  );
});

Deno.test("a retained published candidate is smoke-tested again without republishing a deleted builder", async () => {
  const effects = new Effects();
  effects.fingerprint = "c".repeat(64);
  await buildImage(intent({ reuse_published: true }), effects);
  assertEquals(
    effects.calls.some((call) =>
      ["launch", "publish", "apt-source-start"].includes(call.key)
    ),
    false,
  );
  assertEquals(effects.calls.some((call) => call.key === "smoke-verify"), true);
});
