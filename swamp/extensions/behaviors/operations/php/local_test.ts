import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { z } from "npm:zod@4.4.3";
import { commandLaunch, type NodeCommand } from "../_lib/node-commands.ts";
import { proxyAddress } from "../_lib/node-proxy-config.ts";
import { nodePhpRunner, PhpLocalError, runPhp } from "./local.ts";

Deno.test("PHP default transport keeps private JSON on stdin on both logical proxies", async () => {
  for (const node of [1, 18] as const) {
    const controller = new AbortController();
    const command: NodeCommand = (argv, stdin, user, options) => {
      assertEquals(argv, ["/usr/bin/python3", "-I", "-c", "fixed program"]);
      assertEquals(user, "root");
      assertEquals(JSON.parse(new TextDecoder().decode(stdin)), {
        input: { value: "private-material" },
      });
      assertEquals(options, {
        signal: controller.signal,
        deadlineMs: 1020000,
        maxOutputBytes: 2097152,
      });
      const launch = commandLaunch(argv, user, {
        node_id: node,
        host: proxyAddress(node),
        user: "orbit",
      }, options?.deadlineMs);
      assert(launch.args.includes("orbit@" + proxyAddress(node)));
      assert(!JSON.stringify(launch).includes("private-material"));
      return Promise.resolve({
        success: true,
        code: 0,
        stdout: new TextEncoder().encode('{"ready":true}'),
      });
    };
    assertEquals(
      await runPhp(
        { value: "private-material" },
        "fixed program",
        z.object({ ready: z.boolean() }).strict(),
        "php.build_execution_failed",
        controller.signal,
        nodePhpRunner(command),
      ),
      { ready: true },
    );
  }
});

Deno.test("PHP transport sanitizes failures and byte bounds without claiming restoration", async () => {
  for (
    const command of [
      (() => {
        throw new Error("private-transport-detail");
      }) as NodeCommand,
      (() =>
        Promise.resolve({
          success: true,
          code: 0,
          stdout: new Uint8Array(2097153),
        })) as NodeCommand,
      (() =>
        Promise.resolve({
          success: false,
          code: 1,
          stdout: new TextEncoder().encode("private-output"),
        })) as NodeCommand,
    ]
  ) {
    const error = await assertRejects(
      () =>
        runPhp(
          {},
          "fixed program",
          z.object({}),
          "php.build_execution_failed",
          undefined,
          nodePhpRunner(command),
        ),
      PhpLocalError,
      "php.build_execution_failed",
    );
    assertEquals(error.restored, undefined);
    assert(!error.message.includes("private"));
  }
});

Deno.test("PHP transport refuses pre-cancelled or noncanonical commands before dispatch", async () => {
  let calls = 0;
  const runner = nodePhpRunner(() => {
    calls++;
    throw new Error("unexpected");
  });
  await assertRejects(() =>
    runner({
      executable: "sudo",
      args: ["-n", "--", "python3", "-I", "-c", "fixed"],
      stdin: "{}",
    }, AbortSignal.abort())
  );
  await assertRejects(() =>
    runner({
      executable: "sudo",
      args: ["-n", "--", "sh", "-c", "private-command"],
      stdin: "{}",
    })
  );
  assertEquals(calls, 0);
});

Deno.test("PHP in-flight cancellation remains an uncertain sanitized failure", async () => {
  const controller = new AbortController();
  let drained = false;
  const command: NodeCommand = (_argv, _stdin, _user, options) => {
    return new Promise((_resolve, reject) => {
      options!.signal!.addEventListener("abort", () => {
        drained = true;
        reject(new Error("private-interrupted-output"));
      }, { once: true });
      controller.abort();
    });
  };
  const error = await assertRejects(
    () =>
      runPhp(
        {},
        "fixed program",
        z.object({}),
        "php.build_execution_failed",
        controller.signal,
        nodePhpRunner(command),
      ),
    PhpLocalError,
    "php.build_execution_failed",
  );
  assertEquals(drained, true);
  assertEquals(error.restored, undefined);
});
