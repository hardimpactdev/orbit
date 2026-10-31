import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  resetCommandExecutor,
  setCommandExecutor,
} from "https://raw.githubusercontent.com/swamp-club/swamp-extensions/d6f7afdcd6d04a63fdbc6ed56c403832770f67a6/ssh/extensions/models/_lib/runner.ts";
import { imageSsh, sshImageGuest } from "./image_ssh.ts";

const environment = {
  createCelEnvironment: () => ({
    parse: () => () => true,
    registerFunction: () => {},
  }),
};
const target = {
  name: "tvm-image-20261031050000",
  address: "203.0.113.50",
  publicKey: "unused",
  identity: "/unused/key",
};
Deno.test("image SSH runs account reads as orbit and root mutations with sudo", async () => {
  const calls: string[][] = [];
  const guest = sshImageGuest(
    target,
    "a".repeat(64),
    environment,
    (_target, _environment, argv, stdin) => {
      calls.push(argv);
      assertEquals(JSON.parse(stdin!).agent_sha256, "a".repeat(64));
      return Promise.resolve(
        JSON.stringify({ ok: true, data: { ready: true } }),
      );
    },
  );
  await guest.command("image.owner", {});
  await guest.command("image.boot", { lean: false });
  assertEquals(calls, [
    ["/usr/local/bin/orbit-agent", "command", "image.owner"],
    ["sudo", "-n", "/usr/local/bin/orbit-agent", "command", "image.boot"],
  ]);
});

Deno.test("image SSH never retries a refused pinned host key during bootstrap", async () => {
  let calls = 0;
  const guest = sshImageGuest(target, "a".repeat(64), environment, () => {
    calls++;
    return Promise.reject(new Error("task_vm.host_key_failed"));
  });
  await assertRejects(
    () => guest.bootstrap(),
    Error,
    "task_vm.host_key_failed",
  );
  assertEquals(calls, 1);
});

Deno.test("the official SSH runner uses the admitted key and consumes bounded output without persisting a second run", async () => {
  const identity = await Deno.makeTempFile();
  await Deno.chmod(identity, 0o600);
  await Deno.writeTextFile(identity, "not-a-live-key");
  const algorithm = new TextEncoder().encode("ssh-ed25519");
  const blob = new Uint8Array([
    0,
    0,
    0,
    algorithm.length,
    ...algorithm,
    0,
    0,
    0,
    32,
    ...new Uint8Array(32).fill(1),
  ]);
  const key = "ssh-ed25519 " + btoa(String.fromCharCode(...blob));
  let calls = 0;
  setCommandExecutor((request) => {
    calls++;
    assertEquals(
      request.args.some((arg) => arg.includes("StrictHostKeyChecking=yes")),
      true,
    );
    assertEquals(
      request.args.some((arg) =>
        arg.includes("GlobalKnownHostsFile=/dev/null")
      ),
      true,
    );
    assertEquals(request.stdin, "public-envelope\n");
    return Promise.resolve({
      code: 0,
      signal: null,
      stdout: '{"ok":true}',
      stderr: "",
    });
  });
  try {
    assertEquals(
      await imageSsh(
        { ...target, identity, publicKey: key },
        environment,
        ["/usr/bin/true"],
        "public-envelope\n",
        10,
      ),
      '{"ok":true}',
    );
    assertEquals(calls, 1);
    setCommandExecutor(() =>
      Promise.resolve({
        code: 255,
        signal: null,
        stdout: "",
        stderr: "Host key verification failed: private-diagnostic",
      })
    );
    const error = await assertRejects(
      () =>
        imageSsh(
          { ...target, identity, publicKey: key },
          environment,
          ["/usr/bin/true"],
          undefined,
          10,
        ),
      Error,
      "task_vm.host_key_failed",
    );
    assertEquals(error.message.includes("private-diagnostic"), false);
  } finally {
    resetCommandExecutor();
    await Deno.remove(identity);
  }
});
