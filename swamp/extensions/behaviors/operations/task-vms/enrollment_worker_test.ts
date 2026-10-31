import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/toml@1.0.9";
import {
  configureTaskVmWorker,
  type EnrollmentWorkerIntent,
  type NativeAgentConfiguration,
  type NativeConfigureAgent,
} from "./enrollment_worker.ts";
import type { SystemTransport } from "../node/system_command.ts";
const hash = "a".repeat(64);
const pair = {
  enrollment_token: `orbit-node-212.${"b".repeat(32)}`,
  server_token: `orbit-node-212-access.${"c".repeat(32)}`,
};
const input: EnrollmentWorkerIntent = {
  task_vm_id: 184,
  guest_node_id: 212,
  user: "orbit",
  agent_sha256: "d".repeat(64),
  agent: {
    configuration: 'gateway_url = "https://gateway.orbit"\n',
    certificate: "public-ca",
    unit: "admitted-unit",
    checkouts: [],
  },
};
function fixture() {
  const calls: NativeAgentConfiguration[] = [], stored: unknown[] = [];
  const material = {
    secret_hash: hash as string | null,
    worker_credentials: pair as typeof pair | null,
  };
  let inside = false;
  const privateMaterial = {
    withPrivateMaterial: async (
      use: (value: Record<string, unknown>) => Promise<void>,
    ) => {
      inside = true;
      try {
        await use(material);
      } finally {
        inside = false;
      }
    },
    storeSecretOutput: (value: unknown) => {
      assertEquals(inside, true);
      stored.push(value);
      return Promise.resolve();
    },
  };
  const transport: SystemTransport = () =>
    Promise.reject(
      new Error("No system effect outside canonical native configure"),
    );
  const configure: NativeConfigureAgent = (configuration, passedTransport) => {
    assertEquals(inside, true);
    assertEquals(passedTransport, transport);
    calls.push(configuration);
    return Promise.resolve({
      configured: true,
      secret_hash: hash,
      restarted: true,
      unclosed: [],
    });
  };
  return { calls, stored, material, privateMaterial, transport, configure };
}
Deno.test("TaskVM worker hands exact Node credentials to canonical native configure inside private callback", async () => {
  const f = fixture();
  let guards = 0;
  const result = await configureTaskVmWorker(
    input,
    true,
    () => {
      guards++;
      return Promise.resolve();
    },
    f.privateMaterial,
    f.transport,
    f.configure,
  );
  assertEquals(guards, 2);
  assertEquals(f.calls.length, 1);
  assertEquals(f.calls[0].agent_sha256, input.agent_sha256);
  assertEquals(parse(f.calls[0].configuration).swamp_worker, {
    node_id: 212,
    user: "orbit",
  });
  assertEquals(f.calls[0].worker_credentials, pair);
  assertEquals(f.calls[0].allow_secret_rotation, false);
  assertEquals(f.stored, [{ secret_hash: hash }]);
  assertEquals(result, { node_id: 212, user: "orbit", configured: true });
  assertEquals("ready" in result, false);
  assertEquals(JSON.stringify(result).includes(pair.enrollment_token), false);
});
Deno.test("TaskVM native configure refuses credentials for another Node before touching transport", async () => {
  const f = fixture();
  f.material.worker_credentials = {
    ...pair,
    enrollment_token: pair.enrollment_token.replace("212", "209"),
  };
  await assertRejects(() =>
    configureTaskVmWorker(
      input,
      true,
      () => Promise.resolve(),
      f.privateMaterial,
      f.transport,
      f.configure,
    )
  );
  assertEquals(f.calls, []);
  assertEquals(f.stored, []);
});
Deno.test("TaskVM stop removes worker selection and preserves private credential files", async () => {
  const f = fixture();
  await configureTaskVmWorker(
    input,
    false,
    () => Promise.resolve(),
    f.privateMaterial,
    f.transport,
    f.configure,
  );
  assertEquals("swamp_worker" in parse(f.calls[0].configuration), false);
  assertEquals(f.calls[0].worker_credentials, null);
  assertEquals(f.calls[0].secret_hash, hash);
});
Deno.test("superseded parent refuses after private read and before native configuration", async () => {
  const f = fixture();
  let guards = 0;
  await assertRejects(() =>
    configureTaskVmWorker(
      input,
      true,
      () =>
        ++guards === 2
          ? Promise.reject(new Error("superseded"))
          : Promise.resolve(),
      f.privateMaterial,
      f.transport,
      f.configure,
    )
  );
  assertEquals(f.calls, []);
  assertEquals(f.stored, []);
});
Deno.test("TaskVM bootstrap refuses a preselected worker configuration", async () => {
  const f = fixture();
  await assertRejects(() =>
    configureTaskVmWorker(
      {
        ...input,
        agent: {
          ...input.agent,
          configuration: '[swamp_worker]\nnode_id=209\nuser="orbit"\n',
        },
      },
      true,
      () => Promise.resolve(),
      f.privateMaterial,
      f.transport,
      f.configure,
    )
  );
  assertEquals(f.calls, []);
});
Deno.test("first native configuration alone may rotate the absent agent secret", async () => {
  const f = fixture();
  f.material.secret_hash = null;
  await configureTaskVmWorker(
    input,
    true,
    () => Promise.resolve(),
    f.privateMaterial,
    f.transport,
    f.configure,
  );
  assertEquals(f.calls[0].allow_secret_rotation, true);
  assertEquals(f.calls[0].secret_hash, null);
  assertEquals(f.stored, [{ secret_hash: hash }]);
});
Deno.test("native configure refusal cannot become acknowledgement or store a hash", async () => {
  const f = fixture();
  await assertRejects(
    () =>
      configureTaskVmWorker(
        input,
        true,
        () => Promise.resolve(),
        f.privateMaterial,
        f.transport,
        () =>
          Promise.resolve({
            configured: false,
            error_code: "swamp.node_worker_not_ready",
          }),
      ),
    Error,
    "task_vm.worker_bootstrap_refused",
  );
  assertEquals(f.stored, []);
});
Deno.test("native configure cannot replace an already recorded secret hash", async () => {
  const f = fixture();
  await assertRejects(() =>
    configureTaskVmWorker(
      input,
      true,
      () => Promise.resolve(),
      f.privateMaterial,
      f.transport,
      () =>
        Promise.resolve({
          configured: true,
          secret_hash: "e".repeat(64),
          restarted: true,
          unclosed: [],
        }),
    )
  );
  assertEquals(f.stored, []);
});
Deno.test("private native configure errors are redacted and no fallback executes", async () => {
  const f = fixture();
  const error = await assertRejects(() =>
    configureTaskVmWorker(
      input,
      true,
      () => Promise.resolve(),
      f.privateMaterial,
      f.transport,
      () => Promise.reject(new Error(pair.enrollment_token)),
    )
  );
  assertEquals(
    error instanceof Error && error.message,
    "task_vm.worker_bootstrap_refused",
  );
  assertEquals(f.stored, []);
});
Deno.test("native configuration is explicit and unavailable callbacks refuse without agent dispatch", async () => {
  const f = fixture();
  await assertRejects(
    () =>
      configureTaskVmWorker(
        input,
        true,
        () => Promise.resolve(),
        f.privateMaterial,
        f.transport,
        undefined as unknown as NativeConfigureAgent,
      ),
    Error,
    "task_vm.worker_bootstrap_refused",
  );
  assertEquals(f.calls, []);
  assertEquals(f.stored, []);
});
