import {
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.14";
import { commandLaunch, requireLocalWorker, run } from "./node-commands.ts";
import { commandOutput } from "./node-command-process.ts";

Deno.test("the local runner keeps explicit sudo accounts and refuses agent actions or caller shell selection", () => {
  assertEquals(commandLaunch(["/usr/bin/id", "-u"], "node"), {
    command: "/usr/bin/id",
    args: ["-u"],
  });
  assertEquals(commandLaunch(["/usr/bin/id"], "root"), {
    command: "/usr/bin/sudo",
    args: ["-n", "--", "/usr/bin/id"],
  });
  assertEquals(commandLaunch(["/usr/bin/id"], "orbit-app-7"), {
    command: "/usr/bin/sudo",
    args: ["-n", "-u", "orbit-app-7", "--", "/usr/bin/id"],
  });
  for (const account of ["--root", "a;id", "root\n"]) {
    assertThrows(() => commandLaunch(["/usr/bin/id"], account));
  }
  for (
    const cmd of [["id"], [
      "/usr/local/bin/orbit-agent",
      "command",
      "agent.configure",
    ], ["/usr/bin/id\0bad"]]
  ) assertThrows(() => commandLaunch(cmd, "node"));
});
Deno.test("every proxy unit refuses local execution instead of acting on209", () => {
  requireLocalWorker("0::/system.slice/orbit-node-worker.service\n");
  for (const node of [1, 18, 209]) {
    assertThrows(() =>
      requireLocalWorker(
        `0::/system.slice/orbit-node-proxy-worker-${node}.service/child\n`,
      )
    );
  }
});
Deno.test("private stdin roundtrips and nonzero exit status is preserved", async () => {
  const source = new TextEncoder().encode("private-stdin-needle\n");
  const output = await run(
    [
      "/usr/bin/python3",
      "-c",
      "import sys;sys.stdout.buffer.write(sys.stdin.buffer.read());sys.exit(37)",
    ],
    source,
    "node",
  );
  assertEquals(output.stdout, source);
  assertEquals(output.code, 37);
  assertEquals(output.success, false);
});
Deno.test("full duplex drains while writing and timeout/output refusal contains no private bytes", async () => {
  const output = await commandOutput(
    {
      command: "/usr/bin/python3",
      args: [
        "-c",
        "import sys;sys.stdout.buffer.write(b'x'*262144);sys.stdout.flush();data=sys.stdin.buffer.read();sys.stdout.write(str(len(data)))",
      ],
    },
    new Uint8Array(262144),
    300000,
    AbortSignal.timeout(3000),
  );
  assertEquals(output.success, true);
  assertEquals(new TextDecoder().decode(output.stdout.slice(-6)), "262144");
  await assertRejects(() =>
    run(["/usr/bin/sleep", "5"], undefined, "node", { deadlineMs: 10 })
  );
  const error = await assertRejects(
    () =>
      run(["/usr/bin/printf", "private-output-needle"], undefined, "node", {
        maxOutputBytes: 2,
      }),
    Error,
  );
  assertEquals(error.message.includes("private-output-needle"), false);
});
Deno.test("the runner works under compiled-style denied /proc read permissions", async () => {
  const fixture = await Deno.makeTempFile({ suffix: ".ts" });
  const url = new URL("./node-commands.ts", import.meta.url).href;
  try {
    await Deno.writeTextFile(
      fixture,
      `import {run} from ${
        JSON.stringify(url)
      };const r=await run(['/usr/bin/printf','ready'],undefined,'node');console.log(new TextDecoder().decode(r.stdout));`,
    );
    const child = await new Deno.Command(Deno.execPath(), {
      args: [
        "run",
        "--no-prompt",
        "--deny-read",
        "--allow-run=/usr/bin/cat,/usr/bin/printf",
        fixture,
      ],
      stdout: "piped",
      stderr: "piped",
    }).output();
    assertEquals(child.success, true, new TextDecoder().decode(child.stderr));
    assertEquals(new TextDecoder().decode(child.stdout).trim(), "ready");
  } finally {
    await Deno.remove(fixture);
  }
});
