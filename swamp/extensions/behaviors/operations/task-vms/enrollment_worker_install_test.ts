import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  installTaskVmWorker,
  taskVmWorkerCommand,
} from "./enrollment_worker_install.ts";
import type { SystemCommand, SystemTransport } from "../node/system_command.ts";
const pair = {
  enrollment_token: "orbit-node-212." + "a".repeat(32),
  server_token: "orbit-node-212-access." + "b".repeat(32),
};
Deno.test("worker installer reads recorded hash before starting canonical worker and never returns credentials", async () => {
  let inside = false;
  let effects = 0;
  const material = {
    withPrivateMaterial: async (
      use: (raw: Record<string, unknown>) => Promise<void>,
    ) => {
      inside = true;
      try {
        await use({ secret_hash: "c".repeat(64), worker_credentials: pair });
      } finally {
        inside = false;
      }
    },
  };
  const system: SystemTransport = (command) => {
    assertEquals(inside, true);
    assertEquals(command.command, "/usr/bin/sudo");
    effects++;
    return Promise.resolve({ success: true, stdout: "active\n" });
  };
  const result = await installTaskVmWorker(
    212,
    async () => {},
    material,
    system,
    undefined,
    async (config, credentials, invoke) => {
      assertEquals(inside, true);
      assertEquals(config, { node_id: 212, user: "orbit" });
      assertEquals(credentials, pair);
      const response = await invoke!(
        [
          "/usr/bin/systemctl",
          "start",
          "--no-block",
          "orbit-node-worker.service",
        ],
        undefined,
        "root",
      );
      assertEquals(response.success, true);
      return { configured: true, changed: true };
    },
  );
  assertEquals(effects, 1);
  assertEquals(result, { configured: true });
  assertEquals("ready" in result, false);
});
Deno.test("absent observer hash or foreign credentials refuse before worker installation", async () => {
  let installs = 0;
  for (
    const value of [{ secret_hash: null, worker_credentials: pair }, {
      secret_hash: "a".repeat(64),
      worker_credentials: {
        ...pair,
        enrollment_token: "orbit-node-209." + "a".repeat(32),
      },
    }]
  ) {
    await assertRejects(
      () =>
        installTaskVmWorker(
          212,
          async () => {},
          { withPrivateMaterial: async (use) => await use(value) },
          () => Promise.reject(new Error("must not run")),
          undefined,
          () => {
            installs++;
            return Promise.resolve({ configured: true, changed: true });
          },
        ),
      Error,
      "task_vm.worker_bootstrap_refused",
    );
  }
  assertEquals(installs, 0);
});
Deno.test("canonical installer command transport preserves private stdin, user, timeout and cancellation", async () => {
  const commands: SystemCommand[] = [];
  let guards = 0;
  const invoke = taskVmWorkerCommand((command) => {
    commands.push(command);
    return Promise.resolve({ success: true, stdout: "ok" });
  }, () => {
    guards++;
    return Promise.resolve();
  });
  const result = await invoke(
    ["/usr/bin/tee", "--", "/private/credential"],
    new TextEncoder().encode("private-token"),
    "root",
    { deadlineMs: 9000 },
  );
  assertEquals(commands, [{
    command: "/usr/bin/sudo",
    args: ["-n", "--", "/usr/bin/tee", "--", "/private/credential"],
    stdin: "private-token",
    timeoutMs: 9000,
  }]);
  assertEquals(new TextDecoder().decode(result.stdout), "ok");
  assertEquals(guards, 1);
  await assertRejects(() =>
    invoke(["/usr/bin/true"], undefined, "root", {
      signal: AbortSignal.abort(),
    })
  );
  assertEquals(commands.length, 1);
});
