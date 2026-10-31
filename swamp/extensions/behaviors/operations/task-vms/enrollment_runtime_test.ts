import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  configureTaskVmRuntime,
  type EnrollmentRuntimeIntent,
  type RuntimeProcessInstaller,
} from "./enrollment_runtime.ts";
import type { SystemCommand, SystemTransport } from "../node/system_command.ts";
import { digest } from "./image_guest.ts";
const content =
  "# Orbit Process 7\n[Service]\nExecStart=/home/orbit/.local/bin/pi-server serve\n";
const bytes = new TextEncoder().encode("pinned Pi fixture");
const input: EnrollmentRuntimeIntent = {
  guest_node_id: 212,
  user: "orbit",
  agent_sha256: "a".repeat(64),
  pi_sha256: await digest(bytes),
  origin: "http://10.44.0.3:8317",
  models: ["fixture-model"],
  process: {
    process_id: 7,
    name: "orbit-process-7-pi-server.service",
    runtime: "systemd",
    agent_sha256: "a".repeat(64),
    content_hash: await digest(new TextEncoder().encode(content)),
    desired: "running",
    on_demand: false,
    activation_pending: false,
    required_mount_paths: [],
    retired: null,
  },
};
function fixture(current = false, state = "active") {
  const calls: Array<{ name: string; input: Record<string, unknown> }> = [];
  const commands: SystemCommand[] = [];
  let downloads = 0;
  const material = {
    withPrivateMaterial: async (
      use: (raw: Record<string, unknown>) => Promise<void>,
    ) => {
      await use({
        model_key: "b".repeat(64),
        pi_token: "c".repeat(64),
        installation: {
          runtime: "systemd",
          content,
          environment: "# Orbit Process 7\n",
        },
      });
    },
  };
  const artifact = () => {
    downloads++;
    return Promise.resolve(bytes);
  };
  const transport: SystemTransport = (command) => {
    commands.push(command);
    assertEquals(command.command, "/usr/bin/sudo");
    if (command.args[2] === "/usr/bin/systemctl") {
      calls.push({ name: "systemctl", input: {} });
      assertEquals(command.args, [
        "-n",
        "--",
        "/usr/bin/systemctl",
        "show",
        "--property=ActiveState",
        "--value",
        "--",
        input.process.name,
      ]);
      return Promise.resolve({ success: true, stdout: state + "\n" });
    }
    assertEquals(command.args.slice(0, 4), [
      "-n",
      "--",
      "/usr/bin/python3",
      "-c",
    ]);
    const request = JSON.parse(command.stdin!);
    const name = request.name as string;
    calls.push({ name, input: request.input });
    return Promise.resolve({
      success: true,
      stdout: JSON.stringify(
        name === "pi.inspect-artifact"
          ? { current }
          : name === "pi.receive-artifact"
          ? { received_bytes: bytes.length, verified: true }
          : name === "pi.configure-runtime"
          ? {
            binary: "/home/orbit/.local/bin/pi-server",
            token_file: "/home/orbit/.pi/agent/orbit-token",
          }
          : { changed: true },
      ),
    });
  };
  const process: RuntimeProcessInstaller = async (
    admitted,
    privateMaterial,
    _system,
    signal,
  ) => {
    signal?.throwIfAborted();
    assertEquals(admitted, input.process);
    await privateMaterial.withPrivateMaterial((value) => {
      assertEquals(value.installation, {
        runtime: "systemd",
        content,
        environment: "# Orbit Process 7\n",
      });
      return Promise.resolve();
    });
    calls.push({ name: "shared.installProcess", input: {} });
    return { ready: true, state };
  };
  const identity = (args: { node_id: number; user: string }) => {
    assertEquals(args, { node_id: 212, user: "orbit" });
    return Promise.resolve({
      ...args,
      hostname: "tvm-184",
      effective_user: "orbit",
      sudo_ready: true as const,
    });
  };
  return {
    calls,
    commands,
    material,
    artifact,
    transport,
    process,
    identity,
    downloads: () => downloads,
  };
}
Deno.test("runtime lower uses direct Pi system commands and shared native Process installation in the same root", async () => {
  const f = fixture();
  const result = await configureTaskVmRuntime(
    input,
    async () => {},
    f.material,
    f.artifact,
    f.transport,
    f.process,
    undefined,
    f.identity,
  );
  assertEquals(f.downloads(), 1);
  assertEquals(f.calls.map((call) => call.name), [
    "pi.inspect-artifact",
    "pi.receive-artifact",
    "pi.install-artifact",
    "pi.configure-runtime",
    "shared.installProcess",
    "systemctl",
  ]);
  assertEquals(
    f.calls.find((call) => call.name === "pi.configure-runtime")!.input
      .model_key,
    "b".repeat(64),
  );
  assertEquals(result, { process_id: 7, configured: true });
  assertEquals(JSON.stringify(result).includes("b".repeat(64)), false);
  assertEquals(
    f.commands.some((command) =>
      command.args.join(" ").includes("orbit-agent")
    ),
    false,
  );
  assertEquals(
    f.commands.some((command) =>
      command.args.join(" ").includes("b".repeat(64))
    ),
    false,
  );
});
Deno.test("an already current Pi avoids artifact delivery while still configuring its owned Process", async () => {
  const f = fixture(true);
  await configureTaskVmRuntime(
    input,
    async () => {},
    f.material,
    f.artifact,
    f.transport,
    f.process,
    undefined,
    f.identity,
  );
  assertEquals(f.downloads(), 0);
  assertEquals(f.calls.map((call) => call.name), [
    "pi.inspect-artifact",
    "pi.configure-runtime",
    "shared.installProcess",
    "systemctl",
  ]);
});
Deno.test("a mismatched placed Node refuses before a private read or system effect", async () => {
  const f = fixture();
  let reads = 0;
  await assertRejects(() =>
    configureTaskVmRuntime(
      input,
      async () => {},
      {
        withPrivateMaterial: () => {
          reads++;
          return Promise.resolve();
        },
      },
      f.artifact,
      f.transport,
      f.process,
      undefined,
      () => Promise.reject(new Error("wrong Node")),
    )
  );
  assertEquals(f.calls, []);
  assertEquals(reads, 0);
});
Deno.test("native systemctl must confirm active even when the installer returned ready", async () => {
  const f = fixture(true, "inactive");
  await assertRejects(() =>
    configureTaskVmRuntime(
      input,
      async () => {},
      f.material,
      f.artifact,
      f.transport,
      f.process,
      undefined,
      f.identity,
    )
  );
  assertEquals(f.calls.at(-1)?.name, "systemctl");
});
Deno.test("a wrong artifact digest refuses before upload or Process mutation", async () => {
  const f = fixture();
  await assertRejects(
    () =>
      configureTaskVmRuntime(
        input,
        async () => {},
        f.material,
        () => Promise.resolve(new TextEncoder().encode("wrong digest")),
        f.transport,
        f.process,
        undefined,
        f.identity,
      ),
    Error,
    "task_vm.pi_artifact_failed",
  );
  assertEquals(f.calls.map((call) => call.name), ["pi.inspect-artifact"]);
});
Deno.test("a superseded parent refuses after inspection before the first artifact mutation", async () => {
  const f = fixture();
  let guards = 0;
  await assertRejects(
    () =>
      configureTaskVmRuntime(
        input,
        () =>
          ++guards >= 3
            ? Promise.reject(new Error("superseded"))
            : Promise.resolve(),
        f.material,
        f.artifact,
        f.transport,
        f.process,
        undefined,
        f.identity,
      ),
    Error,
    "task_vm.pi_artifact_failed",
  );
  assertEquals(f.calls.map((call) => call.name), ["pi.inspect-artifact"]);
  assertEquals(f.downloads(), 0);
});
Deno.test("cancellation refuses before shared Process or later native commands", async () => {
  const f = fixture(true);
  const controller = new AbortController();
  const material = {
    withPrivateMaterial: async (
      use: (raw: Record<string, unknown>) => Promise<void>,
    ) => {
      await f.material.withPrivateMaterial(use);
      controller.abort();
    },
  };
  await assertRejects(() =>
    configureTaskVmRuntime(
      input,
      async () => {},
      material,
      f.artifact,
      f.transport,
      f.process,
      controller.signal,
      f.identity,
    )
  );
  assertEquals(f.calls.map((call) => call.name), [
    "pi.inspect-artifact",
    "pi.configure-runtime",
  ]);
});
