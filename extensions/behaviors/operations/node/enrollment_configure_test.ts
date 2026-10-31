import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  configureEnrolledNode,
  type EnrollmentConfiguration,
} from "./enrollment_configure.ts";
import type { SystemTransport } from "./system_command.ts";
const pin = "a".repeat(64), hash = "b".repeat(64);
const input: EnrollmentConfiguration = {
  node_id: 23,
  agent_sha256: pin,
  binary_changed: true,
  configuration: {
    configuration: '[swamp_worker]\nnode_id = 23\nuser = "orbit"\n',
    certificate: "ca",
    unit: "unit",
    checkouts: [],
  },
  worker: { node_id: 23, user: "orbit" },
};
const material = {
  secret_hash: null,
  worker_credentials: {
    enrollment_token: "orbit-node-23.private-token",
    server_token: "orbit-node-23-access.private-token",
  },
};

Deno.test("same-root enrollment persists observer hash before starting the guest worker", async () => {
  const sequence: string[] = [];
  const invoke: SystemTransport = (command) => {
    sequence.push("configure");
    assertEquals(command.args.slice(0, 3), ["-n", "--", "/usr/bin/python3"]);
    return Promise.resolve({
      success: true,
      stdout: JSON.stringify({
        configured: true,
        secret_hash: hash,
        restarted: true,
        unclosed: [],
      }),
    });
  };
  const result = await configureEnrolledNode(
    input,
    (action) => action(material, invoke),
    (output) => {
      sequence.push("store");
      assertEquals(output, { secret_hash: hash });
      return Promise.resolve();
    },
    undefined,
    (worker, credentials) => {
      sequence.push("worker");
      assertEquals(worker, input.worker);
      assertEquals(credentials, material.worker_credentials);
      return Promise.resolve({ configured: true, changed: true });
    },
  );
  assertEquals(result, { configured: true });
  assertEquals(sequence, ["configure", "store", "worker"]);
  assertEquals(JSON.stringify(result).includes(hash), false);
});
Deno.test("same-root enrollment never starts worker when protected hash storage fails", async () => {
  let installed = 0;
  await assertRejects(() =>
    configureEnrolledNode(
      input,
      (action) =>
        action(material, () =>
          Promise.resolve({
            success: true,
            stdout: JSON.stringify({
              configured: true,
              secret_hash: hash,
              restarted: true,
              unclosed: [],
            }),
          })),
      () => Promise.reject(new Error("store refused")),
      undefined,
      () => {
        installed++;
        return Promise.resolve({ configured: true, changed: true });
      },
    )
  );
  assertEquals(installed, 0);
});
Deno.test("same-root enrollment rejects foreign worker and pre-cancel before private callback", async () => {
  let reads = 0;
  const callback = () => {
    reads++;
    return Promise.resolve();
  };
  await assertRejects(() =>
    configureEnrolledNode(
      { ...input, worker: { node_id: 24, user: "orbit" } },
      callback,
      () => Promise.resolve(),
    )
  );
  await assertRejects(() =>
    configureEnrolledNode(
      input,
      callback,
      () => Promise.resolve(),
      AbortSignal.abort(),
    )
  );
  assertEquals(reads, 0);
});
Deno.test("same-root enrollment preserves known configure refusal without storing or installing", async () => {
  let effects = 0;
  const outcome = await configureEnrolledNode(
    input,
    (action) =>
      action(material, () =>
        Promise.resolve({
          success: true,
          stdout: JSON.stringify({
            configured: false,
            error_code: "agent.version_mismatch",
          }),
        })),
    () => {
      effects++;
      return Promise.resolve();
    },
    undefined,
    () => {
      effects++;
      return Promise.resolve({ configured: true, changed: true });
    },
  );
  assertEquals(outcome, {
    configured: false,
    error_code: "agent.version_mismatch",
  });
  assertEquals(effects, 0);
});
