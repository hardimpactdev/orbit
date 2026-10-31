import {
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.19";
import {
  ImageIntent,
  imageStage,
  phases,
  stages,
} from "./image_build_direct.ts";
import { agentReply, type NodeCall } from "../_lib/node-agent.ts";
import { guestArgv, incusGuest } from "./image_guest.ts";

export const intent = () =>
  ImageIntent.parse({
    build_id: 7,
    attempt: 1,
    host_node_id: 13,
    agent_sha256: "a".repeat(64),
    identity: "sha256:" + "b".repeat(64),
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
    certificate: "public-root",
    recipe: {
      identity: "sha256:" + "b".repeat(64),
      agent_sha256: "a".repeat(64),
      pi_sha256: null,
    },
    pi_artifact: null,
    prune: {
      project: "orbit-tasks",
      operation: {
        kind: "prune",
        pool: "orbit-tasks",
        obsolete_aliases: [],
        retired_aliases: [],
        protected_fingerprints: [],
      },
    },
  });
const fp = "c".repeat(64);
const boot = "878a384f-8b8a-4066-bd17-dcad8eac3e86";
function fixture(failSmoke = false) {
  const calls: string[] = [];
  const host = (call: NodeCall) => {
    assertEquals(call.pin, "a".repeat(64));
    assertEquals(call.sudo, true);
    assertEquals(
      call.input.project ??
        (call.input.vm as Record<string, unknown> | undefined)?.project ??
        (call.input.location as Record<string, unknown> | undefined)?.project,
      "orbit-tasks",
    );
    calls.push(
      call.name + ":" +
        ((call.input.operation as Record<string, unknown> | undefined)?.kind ??
          "seed"),
    );
    return Promise.resolve(
      call.name === "incus.volume"
        ? { name: `orbit-topology-seed-${fp.slice(0, 12)}` }
        : { ready: true, fingerprint: fp },
    );
  };
  const guest = (smoke: boolean) => ({
    bootstrap() {
      calls.push(smoke ? "smoke-boot" : "builder-boot");
      return Promise.resolve();
    },
    artifact() {
      return Promise.resolve();
    },
    command(name: string, input: Record<string, unknown>) {
      calls.push(name);
      if (name === "image.check" && failSmoke) {
        return Promise.reject(new Error("task_vm.image_check_failed"));
      }
      if (name === "image.inspect-phase") {
        assertEquals(input.key, {
          identity: intent().identity,
          profile: "base",
          phase: phases[
            Math.max(
              0,
              calls.filter((c) => c === name).length - 2,
            )
          ],
          execution: "7-1",
        });
        return Promise.resolve({ state: "complete", boot_id: boot });
      }
      return Promise.resolve({ ready: true });
    },
  });
  return { calls, host, guest };
}

Deno.test("native image stages preserve smoke and seed ordering before promotion without children", async () => {
  const f = fixture();
  let published: string | null = null;
  for (const stage of stages) {
    const result = await imageStage(
      stage,
      intent(),
      published,
      f.host,
      f.guest,
      () => Promise.reject(new Error("No Pi admitted")),
    );
    if (stage === "publish") published = String(result.fingerprint);
    if (stage === "complete") {
      assertEquals(result, { ready: true, fingerprint: fp });
    }
  }
  assertEquals(f.calls.slice(-5), [
    "incus.build-topology-seed:seed",
    "incus.vm:remove_instance",
    "incus.volume:finish_seed",
    "incus.image:promote",
    "incus.image:prune",
  ]);
  assertEquals(
    f.calls.indexOf("image.verify") <
      f.calls.indexOf("incus.volume:finish_seed"),
    true,
  );
});

Deno.test("a failed native smoke never reaches seed or promotion", async () => {
  const f = fixture(true);
  await assertRejects(
    async () => {
      for (
        const stage of ["smoke", "seed", "finish-seed", "promote"] as const
      ) {
        await imageStage(
          stage,
          intent(),
          fp,
          f.host,
          f.guest,
          () => Promise.reject(new Error("No Pi admitted")),
        );
      }
    },
    Error,
    "task_vm.image_check_failed",
  );
  assertEquals(f.calls.some((name) => /seed|promote/.test(name)), false);
});

Deno.test("image publication refuses an absent or malformed fingerprint", async () => {
  const f = fixture();
  await assertRejects(() =>
    imageStage(
      "publish",
      intent(),
      null,
      () => Promise.resolve({ ready: true }),
      f.guest,
      () => Promise.resolve(new Uint8Array()),
    )
  );
  await assertRejects(() =>
    imageStage(
      "finish-seed",
      intent(),
      null,
      f.host,
      f.guest,
      () => Promise.resolve(new Uint8Array()),
    )
  );
});

Deno.test("image intent refuses a recipe with different agent or Pi pins", () => {
  const input = intent();
  assertThrows(() =>
    ImageIntent.parse({
      ...input,
      recipe: { ...input.recipe, agent_sha256: "d".repeat(64) },
    })
  );
  assertThrows(() =>
    ImageIntent.parse({
      ...input,
      pi_artifact: { sha256: fp },
    })
  );
});

Deno.test("guest root argv has one fixed Incus target and no shell or SSH hop", () => {
  assertEquals(
    guestArgv("orbit-tasks", "tvm-image-7", [
      "/usr/local/bin/orbit-agent",
      "command",
      "image.boot",
    ]),
    [
      "-n",
      "--",
      "/usr/bin/incus",
      "--project",
      "orbit-tasks",
      "exec",
      "--mode=non-interactive",
      "--user",
      "0",
      "--group",
      "0",
      "--env",
      "SUDO_USER=orbit",
      "--",
      "tvm-image-7",
      "/usr/local/bin/orbit-agent",
      "command",
      "image.boot",
    ],
  );
  assertThrows(() => guestArgv("../default", "tvm-image-7", []));
  assertThrows(() => guestArgv("orbit-tasks", "production-guest", []));
});

Deno.test("the image owner read runs as orbit while privileged guest commands run as root", async () => {
  for (
    const name of [
      "image.owner",
      "image.boot",
      "guest.cloud-init",
      "image.inspect-phase",
    ]
  ) {
    const expected = name === "image.owner" ? "1000" : "0";
    const guest = incusGuest(
      "orbit-tasks",
      "tvm-image-7",
      "a".repeat(64),
      (args) => {
        const accepted = args[args.indexOf("--user") + 1] === expected &&
          args[args.indexOf("--group") + 1] === expected;
        return Promise.resolve({
          success: accepted,
          bytes: new TextEncoder().encode(JSON.stringify(
            accepted
              ? { ok: true, data: { ready: true } }
              : { ok: false, error: { code: "agent.identity_mismatch" } },
          )),
        });
      },
    );
    assertEquals(await guest.command(name, {}), { ready: true });
  }
});

Deno.test("guest command pins its envelope and returns only typed safe refusals", async () => {
  const guest = incusGuest(
    "orbit-tasks",
    "tvm-image-7",
    "a".repeat(64),
    (args, bytes) => {
      assertEquals(args.slice(-3), [
        "/usr/local/bin/orbit-agent",
        "command",
        "image.owner",
      ]);
      assertEquals(JSON.parse(new TextDecoder().decode(bytes)), {
        agent_sha256: "a".repeat(64),
        deadline_ms: 120000,
        input: {},
      });
      return Promise.resolve({
        success: false,
        bytes: new TextEncoder().encode(JSON.stringify({
          ok: false,
          error: {
            code: "task_vm.image_build_failed",
            message: "private diagnostic",
          },
        })),
      });
    },
  );
  await assertRejects(
    () => guest.command("image.owner", {}),
    Error,
    "task_vm.image_build_failed",
  );
  assertEquals(
    agentReply(
      new TextEncoder().encode(
        '{"ok":false,"error":{"code":"task_vm.image_build_failed","message":"private"}}',
      ),
      false,
    ),
    { error_code: "task_vm.image_build_failed" },
  );
});

Deno.test("a changed builder boot refuses before starting another phase", async () => {
  const f = fixture();
  await assertRejects(
    () =>
      imageStage(
        "apt-source",
        intent(),
        null,
        f.host,
        f.guest,
        () => Promise.resolve(new Uint8Array()),
        "878a384f-8b8a-4066-bd17-dcad8eac3e87",
      ),
    Error,
    "task_vm.image_builder_rebooted",
  );
  assertEquals(f.calls.includes("image.start-phase"), false);
});

Deno.test("an unconfirmed host result cannot advance native stages", async () => {
  const f = fixture();
  await assertRejects(() =>
    imageStage(
      "reset",
      intent(),
      null,
      () => Promise.resolve({ ready: false }),
      f.guest,
      () => Promise.resolve(new Uint8Array()),
    )
  );
});

Deno.test("bootstrap accepts only the installed digest from the typed installer result", async () => {
  const pin = "a".repeat(64);
  const guest = incusGuest(
    "orbit-tasks",
    "tvm-image-7",
    pin,
    () =>
      Promise.resolve({
        success: true,
        bytes: new TextEncoder().encode(
          JSON.stringify({
            ok: true,
            data: {
              replaced: true,
              sha256: pin,
              previous_sha256: null,
              observer: "absent",
            },
          }),
        ),
      }),
  );
  const result = await guest.command("agent.install", {
    candidate: "/var/tmp/candidate",
    sha256: pin,
  }, 180);
  assertEquals(result.sha256, pin);
});

Deno.test("admitted recipe JSON preserves empty native maps through Gateway storage", () => {
  const input = intent();
  const recipe = { ...input.recipe, rust: {}, recipe: { tools: {} } };
  const parsed = ImageIntent.parse({
    ...input,
    recipe: JSON.stringify(recipe),
  });
  assertEquals(parsed.recipe, recipe);
  assertEquals(Array.isArray(parsed.recipe.rust), false);
});
