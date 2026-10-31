import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  enrollmentReadinessProgram,
  verifyEnrollmentWorker,
} from "./enrollment_readiness.ts";
import type { SystemTransport } from "./system_command.ts";
const input = {
  node_id: 23,
  user: "orbit",
  agent_sha256: "a".repeat(64),
  configuration: "gateway_url = 'https://gateway.orbit'\n",
};
Deno.test("enrollment readiness checks the placed account and fixed root-owned configuration/pin without agent execution", async () => {
  const commands: string[][] = [];
  const invoke: SystemTransport = (command) => {
    commands.push([command.command, ...command.args]);
    assertEquals(command.timeoutMs <= 30000, true);
    assertEquals(command.maxOutputBytes, 4096);
    if (command.command === "/usr/bin/id") {
      return Promise.resolve({ success: true, stdout: "orbit\n" });
    }
    assertEquals(command.args.slice(0, 4), [
      "-n",
      "--",
      "/usr/bin/python3",
      "-c",
    ]);
    assertEquals(JSON.parse(command.stdin!), {
      agent_sha256: input.agent_sha256,
      configuration: input.configuration,
    });
    return Promise.resolve({ success: true, stdout: '{"ready":true}' });
  };
  assertEquals(await verifyEnrollmentWorker(input, invoke), {
    node_id: 23,
    user: "orbit",
    agent_sha256: input.agent_sha256,
    ready: true,
  });
  assertEquals(commands.length, 2);
  assertEquals(
    commands.some((command) =>
      command.some((part) => part.includes("orbit-agent command"))
    ),
    false,
  );
});
Deno.test("enrollment readiness refuses account/pin/config/sudo uncertainty and bounds transport diagnostics", async () => {
  for (const failure of ["foreign-account", "false", "invalid", "transport"]) {
    let count = 0;
    const invoke: SystemTransport = () => {
      count++;
      if (count === 1) {
        return Promise.resolve({
          success: true,
          stdout: failure === "foreign-account" ? "root" : "orbit",
        });
      }
      if (failure === "transport") throw new Error("private-native-diagnostic");
      return Promise.resolve({
        success: true,
        stdout: failure === "false" ? '{"ready":false}' : "invalid",
      });
    };
    const error = await assertRejects(
      () => verifyEnrollmentWorker(input, invoke),
      Error,
      "node.enrollment_worker_unready",
    );
    assertEquals(String(error).includes("private-native-diagnostic"), false);
    if (failure === "foreign-account") assertEquals(count, 1);
  }
});
Deno.test("enrollment readiness refuses pre-cancel before inspecting the target", async () => {
  let calls = 0;
  await assertRejects(() =>
    verifyEnrollmentWorker(input, () => {
      calls++;
      return Promise.reject();
    }, AbortSignal.abort())
  );
  assertEquals(calls, 0);
});

Deno.test("actual read-only readiness program checks the configured bytes, pin, ownership modes and symlinks", async () => {
  const root = await Deno.makeTempDir({ prefix: "enrollment-readiness-" });
  try {
    const binary = root + "/usr/local/bin/orbit-agent",
      config = root + "/etc/orbit/agent/config.toml";
    await Deno.mkdir(root + "/usr/local/bin", { recursive: true });
    await Deno.mkdir(root + "/etc/orbit/agent", { recursive: true });
    const bytes = new TextEncoder().encode("synthetic observer binary");
    const digest = Array.from(
      new Uint8Array(await crypto.subtle.digest("SHA-256", bytes)),
      (b) => b.toString(16).padStart(2, "0"),
    ).join("");
    const observed = { ...input, agent_sha256: digest };
    await Deno.writeFile(binary, bytes, { mode: 0o755 });
    await Deno.writeTextFile(config, input.configuration, { mode: 0o644 });
    const originalBinary = await Deno.lstat(binary),
      originalConfig = await Deno.lstat(config);
    const invoke: SystemTransport = async (command) => {
      if (command.command === "/usr/bin/id") {
        return { success: true, stdout: "orbit" };
      }
      const child = new Deno.Command("/usr/bin/python3", {
        args: ["-c", enrollmentReadinessProgram(root, Deno.uid()!)],
        stdin: "piped",
        stdout: "piped",
        stderr: "piped",
      }).spawn();
      const writer = child.stdin.getWriter();
      await writer.write(new TextEncoder().encode(command.stdin!));
      await writer.close();
      const output = await child.output();
      assertEquals(new TextDecoder().decode(output.stderr), "");
      return {
        success: output.success,
        stdout: new TextDecoder().decode(output.stdout),
      };
    };
    assertEquals((await verifyEnrollmentWorker(observed, invoke)).ready, true);
    assertEquals((await Deno.lstat(binary)).ino, originalBinary.ino);
    assertEquals((await Deno.lstat(config)).ino, originalConfig.ino);
    for (
      const corrupt of [
        "pin",
        "configuration",
        "binary-mode",
        "config-mode",
        "config-symlink",
      ]
    ) {
      await Deno.chmod(binary, 0o755);
      await Deno.chmod(config, 0o644);
      const candidate = { ...observed };
      if (corrupt === "pin") candidate.agent_sha256 = "c".repeat(64);
      if (corrupt === "configuration") {
        candidate.configuration = "foreign-config";
      }
      if (corrupt === "binary-mode") await Deno.chmod(binary, 0o775);
      if (corrupt === "config-mode") await Deno.chmod(config, 0o666);
      if (corrupt === "config-symlink") {
        await Deno.rename(config, config + ".real");
        await Deno.symlink(config + ".real", config);
      }
      await assertRejects(
        () => verifyEnrollmentWorker(candidate, invoke),
        Error,
        "node.enrollment_worker_unready",
      );
    }
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});
