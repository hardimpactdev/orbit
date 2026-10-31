import { assertEquals, assertRejects, assertThrows } from "jsr:@std/assert@1";
import { incusGuestSystem, sshGuestSystem } from "./guest_system.ts";
import type { BootstrapEnvironment } from "../node-bootstrap/bootstrap_ssh.ts";

Deno.test("UpCloud first visit reuses canonical pinned SSH with the admitted guest and private identity", async () => {
  const body =
    "AAAAC3NzaC1lZDI1NTE5AAAAIAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEB";
  let inside = false;
  const run = sshGuestSystem(
    { node_id: 212, name: "tvm-184" },
    async () => {},
    {
      withPrivateMaterial: async (use) => {
        inside = true;
        try {
          await use({
            first_visit: {
              kind: "ssh",
              address: "192.0.2.9",
              publicKey: "ssh-ed25519 " + body,
              identity: "/private/key",
            },
          });
        } finally {
          inside = false;
        }
      },
    },
    {} as BootstrapEnvironment,
    (target, identity, _environment, argv, stdin, seconds) => {
      assertEquals(inside, true);
      assertEquals(target, {
        node_id: 212,
        host: "192.0.2.9",
        port: 22,
        user: "orbit",
        fingerprint: "SHA256:RXm/ruZ0eTzRXKwi1AQEDynB0VgHQ2ac9KPSFdf/YnA",
      });
      assertEquals(identity, "/private/key");
      assertEquals(argv, ["/usr/bin/sudo", "-n", "--", "/usr/bin/id", "-u"]);
      assertEquals(stdin, "private-input");
      assertEquals(seconds, 15);
      return Promise.resolve("0\n");
    },
  );
  assertEquals(
    await run({
      command: "/usr/bin/sudo",
      args: ["-n", "--", "/usr/bin/id", "-u"],
      stdin: "private-input",
      timeoutMs: 15000,
    }),
    { success: true, stdout: "0\n" },
  );
});

Deno.test("malformed first-visit pin cannot reach the SSH transport or expose material", async () => {
  const run = sshGuestSystem(
    { node_id: 212, name: "tvm-184" },
    async () => {},
    {
      withPrivateMaterial: (use) =>
        use({
          first_visit: {
            kind: "ssh",
            address: "192.0.2.9",
            publicKey: "ssh-ed25519 invalid%",
            identity: "/private/key",
          },
        }),
    },
    {} as BootstrapEnvironment,
    () => {
      throw new Error("must not execute");
    },
  );
  await assertRejects(
    () => run({ command: "/usr/bin/true", args: [], timeoutMs: 1000 }),
    Error,
    "task_vm.bootstrap_failed",
  );
});

Deno.test("first visit executes on only the admitted Incus guest without any agent action", async () => {
  let guards = 0;
  const run = incusGuestSystem({
    task_vm_id: 42,
    project: "orbit-tasks",
    name: "tvm-42",
  }, () => {
    guards++;
    return Promise.resolve();
  }, (command) => {
    assertEquals([command.command, ...command.args], [
      "/usr/bin/sudo",
      "-n",
      "--",
      "/usr/bin/incus",
      "--project",
      "orbit-tasks",
      "exec",
      "--mode=non-interactive",
      "--user",
      "1000",
      "--group",
      "1000",
      "--env",
      "SUDO_USER=orbit",
      "--",
      "tvm-42",
      "/usr/bin/sudo",
      "-n",
      "--",
      "/usr/bin/id",
      "-u",
    ]);
    assertEquals(command.stdin, "private");
    return Promise.resolve({
      success: true,
      stdout: "0\n",
    });
  });
  assertEquals(
    (await run({
      command: "/usr/bin/sudo",
      args: ["-n", "--", "/usr/bin/id", "-u"],
      stdin: "private",
      timeoutMs: 1000,
    })).success,
    true,
  );
  assertEquals(guards, 1);
  assertThrows(() =>
    incusGuestSystem(
      { task_vm_id: 42, project: "orbit-tasks", name: "tvm-43" },
      async () => {},
    )
  );
});

Deno.test("a superseded enrollment cannot use the firstvisit guest", async () => {
  const run = incusGuestSystem(
    { task_vm_id: 42, project: "orbit-tasks", name: "tvm-42" },
    () => Promise.reject(new Error("cancelled")),
    () => {
      throw new Error("must not execute");
    },
  );
  await assertRejects(
    () =>
      run({
        command: "/usr/bin/true",
        args: [],
        timeoutMs: 1000,
      }),
    Error,
    "cancelled",
  );
});
