import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { incusAgent, localIncus } from "./incus_local.ts";
import { agentLaunch, agentReply, type NodeCall } from "../_lib/node-agent.ts";

Deno.test("Incus selects fixed sudo argv and the admitted digest with its existing deadline", async () => {
  const calls: NodeCall[] = [];
  const input = {
    vm: { project: "orbit-tasks", name: "tvm-5", cidr: "10.251.77.0/24" },
    operation: { kind: "stop" },
  };
  const action = incusAgent((call) => {
    calls.push(call);
    return Promise.resolve({ ready: true });
  });
  assertEquals(await action("a".repeat(64), input), { ready: true });
  assertEquals(calls.length, 1);
  assertEquals(calls[0].mutates, true);
  assertEquals(calls[0].maxOutputBytes, 16384);
  assertEquals(agentLaunch(calls[0]), {
    command: "/usr/bin/sudo",
    args: ["-n", "--", "/usr/local/bin/orbit-agent", "command", "incus.vm"],
    envelope: { agent_sha256: "a".repeat(64), deadline_ms: 900000, input },
  });
});

Deno.test("Incus retains only a typed refusal code from Node output", async () => {
  const action = incusAgent(() =>
    Promise.resolve(agentReply(
      new TextEncoder().encode(JSON.stringify({
        ok: false,
        error: {
          code: "incus.vm_not_running",
          details: { token: "private" },
          message: "private",
        },
      })),
      false,
    ))
  );
  assertEquals(await action("a".repeat(64), {}), {
    error_code: "incus.vm_not_running",
  });
});

Deno.test("the Incus step refuses mismatched digests and unsafe agent paths before sudo", async () => {
  const saved = { lstat: Deno.lstat, readFile: Deno.readFile };
  let unsafe: string | null = null;
  Deno.lstat = ((path: string | URL) =>
    Promise.resolve({
      isSymlink: path === unsafe,
      isFile: String(path).endsWith("orbit-agent"),
      isDirectory: !String(path).endsWith("orbit-agent"),
      uid: 0,
      mode: 0o755,
    } as Deno.FileInfo)) as typeof Deno.lstat;
  Deno.readFile = (() =>
    Promise.resolve(
      new TextEncoder().encode("fixture binary"),
    )) as typeof Deno.readFile;
  try {
    assertEquals(await localIncus("0".repeat(64), {}), {
      error_code: "agent.version_mismatch",
    });
    for (
      const path of [
        "/usr/local",
        "/usr/local/bin",
        "/usr/local/bin/orbit-agent",
      ]
    ) {
      unsafe = path;
      await assertRejects(
        () => localIncus("0".repeat(64), {}),
        Error,
        "The local agent path is not root owned",
      );
    }
  } finally {
    Object.assign(Deno, saved);
  }
});
