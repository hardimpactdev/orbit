import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { commandLaunch, type NodeCommand } from "../_lib/node-commands.ts";
import { proxyAddress } from "../_lib/node-proxy-config.ts";
import { runLocalProgram } from "./local-program.ts";

Deno.test("fixed programs keep private JSON on stdin through either placed proxy", async () => {
  for (const node of [1, 18] as const) {
    const input = { private_pair: "private-test-material", desired: [] };
    const command: NodeCommand = (argv, stdin, user, options) => {
      assertEquals(argv, [
        "/usr/bin/timeout",
        "--signal=TERM",
        "--kill-after=5s",
        "120s",
        "/usr/bin/python3",
        "-I",
        "-c",
        "fixed program",
      ]);
      assertEquals(JSON.parse(new TextDecoder().decode(stdin)), input);
      assertEquals(user, "root");
      assertEquals(options, { deadlineMs: 130000, maxOutputBytes: 65536 });
      const launch = commandLaunch(argv, user, {
        node_id: node,
        host: proxyAddress(node),
        user: "orbit",
      });
      assertEquals(launch.args.includes("orbit@" + proxyAddress(node)), true);
      assertEquals(launch.args.join(" ").includes(input.private_pair), false);
      return Promise.resolve({
        success: true,
        code: 0,
        stdout: new TextEncoder().encode('{"changed":true}'),
      });
    };
    assertEquals(
      await runLocalProgram("fixed program", input, undefined, command),
      { changed: true },
    );
  }
});

Deno.test("fixed programs refuse cancellation before starting a Node command", async () => {
  let called = false;
  const command: NodeCommand = () => {
    called = true;
    throw new Error("unexpected");
  };
  await assertRejects(() =>
    runLocalProgram("fixed program", {}, AbortSignal.abort(), command)
  );
  assertEquals(called, false);
});

Deno.test("fixed programs never expose failed transport output or private errors", async () => {
  const privateText = "must-not-escape";
  for (
    const command of [
      (() =>
        Promise.resolve({
          success: false,
          code: 1,
          stdout: new TextEncoder().encode(privateText),
        })) as NodeCommand,
      (() => {
        throw new Error(privateText);
      }) as NodeCommand,
    ]
  ) {
    const error = await assertRejects(
      () => runLocalProgram("fixed program", {}, undefined, command),
      Error,
      "Local publication outcome is uncertain",
    );
    assertEquals(error.message.includes(privateText), false);
  }
});

Deno.test("fixed programs require bounded typed public JSON", async () => {
  for (
    const [stdout, message] of [
      [new Uint8Array(65537), "bounded outcome"],
      [new TextEncoder().encode("[]"), "typed outcome"],
      [new TextEncoder().encode("private-non-json"), "typed outcome"],
    ] as const
  ) {
    const command: NodeCommand = () =>
      Promise.resolve({ success: true, code: 0, stdout });
    await assertRejects(
      () => runLocalProgram("fixed program", {}, undefined, command),
      Error,
      message,
    );
  }
});
