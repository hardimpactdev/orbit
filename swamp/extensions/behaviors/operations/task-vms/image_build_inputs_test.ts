import { assertEquals, assertRejects, assertThrows } from "jsr:@std/assert@1.0.19";
import {
  agentReply,
  imageBoot,
  imagePlan,
  imagePublication,
  phaseUnit,
} from "./image_build_inputs.ts";
import { recipePhases } from "./images.ts";

const pin = "a".repeat(64);
const identity = "sha256:" + "b".repeat(64);
const boot = "878a384f-8b8a-4066-bd17-dcad8eac3e86";
const fingerprint = "c".repeat(64);
const ssh = {
  identity_file: "/etc/orbit/swamp-role-worker/ssh-identity",
  target: { host: "10.44.0.9", user: "orbit", port: 22, host_keys: [] },
};
export const intent = (pi: string | null = null) => ({
  build_id: 7,
  attempt: 1,
  host_node_id: 13,
  agent_sha256: pin,
  identity,
  generation: 1,
  project: "orbit-tasks",
  name: "tvm-image-7",
  cidr: "10.251.77.0/24",
  pool: "orbit-tasks",
  network: "orbittask0",
  cpus: 2,
  memory: "4GiB",
  disk: "45GiB",
  source: "ubuntu-26.04-vm",
  candidate_alias: "candidate-7",
  base_alias: "orbit-task-base",
  gateway_public_key: "public-key",
  ssh_execution: ssh,
  pi_source: ssh,
  certificate: "public-root",
  recipe: JSON.stringify({ identity, agent_sha256: pin, pi_sha256: pi }),
  pi_artifact: pi === null ? null : { path: "/srv/pi.bin", sha256: pi },
  prune: { project: "orbit-tasks", operation: { kind: "prune" } },
});
const ok = (data: Record<string, unknown>) =>
  JSON.stringify({ data, ok: true });
const refused = (code: string) =>
  JSON.stringify({ error: { code, message: "refused" }, ok: false });
const line = (stdin: string) => {
  assertEquals(stdin.endsWith("\n"), true);
  return JSON.parse(stdin);
};

Deno.test("plan pins every fixed envelope to the admitted agent", async () => {
  const plan = await imagePlan(intent());
  assertEquals(line(plan.reset_builder), {
    agent_sha256: pin,
    deadline_ms: 900000,
    input: {
      vm: { project: "orbit-tasks", name: "tvm-image-7", cidr: "10.251.77.0/24" },
      operation: { kind: "remove_instance" },
    },
  });
  assertEquals(line(plan.reset_smoke).input.vm.name, "tvm-image-7-smoke");
  assertEquals(line(plan.launch).input.operation, {
    kind: "create_image",
    image: "ubuntu-26.04-vm",
    gateway_public_key: "public-key",
    cpus: 2,
    memory: "4GiB",
    disk: "45GiB",
    network: "orbittask0",
    builder: true,
  });
  assertEquals(line(plan.install), {
    agent_sha256: pin,
    deadline_ms: 180000,
    input: { candidate: plan.candidate, sha256: pin },
  });
  assertEquals(line(plan.publish).input.operation.alias, "candidate-7-attempt-1");
  assertEquals(line(plan.verify).input.identity, identity);
  assertEquals(line(plan.prune).input, intent().prune);
  assertEquals(plan.pi_target, "");
  assertEquals(Object.keys(plan.units), [...recipePhases]);
});

Deno.test("phase units match the agent's retained unit name", async () => {
  // apps/agent image_recipe/lifecycle.rs Key::unit(): sha256("identity:profile:phase:execution")[..32].
  const unit = await phaseUnit(
    (await import("./image_build_inputs.ts")).ImageIntent.parse(intent()),
    "apt-source",
  );
  const digest = Array.from(
    new Uint8Array(
      await crypto.subtle.digest(
        "SHA-256",
        new TextEncoder().encode(`${identity}:base:apt-source:7-1`),
      ),
    ),
    (b) => b.toString(16).padStart(2, "0"),
  ).join("");
  assertEquals(unit, `orbit-recipe-image-${digest.slice(0, 32)}.service`);
});

Deno.test("a Pi recipe names its pinned guest cache file", async () => {
  const pi = "d".repeat(64);
  assertEquals(
    (await imagePlan(intent(pi))).pi_target,
    `/var/cache/orbit/pi/${pi}.bin`,
  );
});

Deno.test("plan refuses pins that differ from the recipe", async () => {
  const changed = { ...intent(), recipe: JSON.stringify({ identity, agent_sha256: "e".repeat(64), pi_sha256: null }) };
  await assertRejects(() => imagePlan(changed));
  await assertRejects(() => imagePlan({ ...intent(), name: "tvm-image-8" }));
});

Deno.test("boot and publication shape the dependent envelopes", () => {
  const booted = imageBoot(
    intent(),
    ok({ boot_id: boot, state: "missing", unit: "u" }),
  );
  assertEquals(booted.boot_id, boot);
  for (const phase of recipePhases) {
    const start = line(booted.start[phase]);
    assertEquals(start.input.key, {
      identity,
      profile: "base",
      phase,
      execution: "7-1",
    });
    assertEquals(start.input.boot_id, boot);
    assertEquals(line(booted.inspect[phase]).input.boot_id, boot);
  }
  const published = imagePublication(intent(), ok({ ready: true, fingerprint }));
  assertEquals(published.fingerprint, fingerprint);
  assertEquals(line(published.launch_smoke).input.operation.image, fingerprint);
  assertEquals(line(published.launch_smoke).input.operation.builder, false);
  assertEquals(line(published.seed).input, {
    project: "orbit-tasks",
    pool: "orbit-tasks",
    vm: "tvm-image-7-smoke",
    size: "45GiB",
    fingerprint,
  });
  assertEquals(line(published.promote).input.operation, {
    kind: "promote",
    alias: "orbit-task-base",
    fingerprint,
  });
  assertThrows(
    () => imagePublication(intent(), ok({ ready: true, fingerprint: "x" })),
  );
});

Deno.test("replies keep the agent's public refusal code", () => {
  assertThrows(
    () => agentReply({ stdout: refused("agent.version_mismatch"), expect: "ready" }),
    Error,
    "agent.version_mismatch",
  );
  assertThrows(
    () => agentReply({ stdout: "not json", expect: "ready" }),
    Error,
    "task_vm.image_build_failed",
  );
  assertThrows(
    () => agentReply({ stdout: refused("Bad Code"), expect: "ready" }),
    Error,
    "task_vm.image_build_failed",
  );
  assertThrows(
    () => agentReply({ stdout: ok({ ready: false }), expect: "ready" }),
    Error,
    "task_vm.image_build_failed",
  );
  assertEquals(agentReply({ stdout: ok({ ready: true }), expect: "ready" }), { ok: true });
});

Deno.test("phase replies need the same boot and the expected state", () => {
  const phase = (state: string, boot_id = boot) =>
    ok({ boot_id, state, unit: "u" });
  agentReply({ stdout: phase("running"), expect: "started", boot_id: boot });
  agentReply({ stdout: phase("complete"), expect: "started", boot_id: boot });
  agentReply({ stdout: phase("complete"), expect: "complete", boot_id: boot });
  assertThrows(
    () => agentReply({ stdout: phase("running"), expect: "complete", boot_id: boot }),
    Error,
    "task_vm.image_build_failed",
  );
  assertThrows(
    () => agentReply({ stdout: phase("failed"), expect: "started", boot_id: boot }),
    Error,
    "task_vm.image_build_failed",
  );
  assertThrows(
    () =>
      agentReply({
        stdout: phase("complete", "00000000-0000-4000-8000-000000000000"),
        expect: "complete",
        boot_id: boot,
      }),
    Error,
    "task_vm.image_builder_rebooted",
  );
});

Deno.test("install and seed replies check their pins", () => {
  agentReply({ stdout: ok({ sha256: pin, replaced: true }), expect: "installed", pin });
  assertThrows(
    () => agentReply({ stdout: ok({ sha256: "f".repeat(64), replaced: true }), expect: "installed", pin }),
    Error,
    "task_vm.bootstrap_failed",
  );
  agentReply({
    stdout: ok({ name: "orbit-topology-seed-cccccccccccc" }),
    expect: "seed-volume",
    fingerprint,
  });
  assertThrows(
    () => agentReply({ stdout: ok({ name: "other" }), expect: "seed-volume", fingerprint }),
    Error,
    "task_vm.image_build_failed",
  );
});
