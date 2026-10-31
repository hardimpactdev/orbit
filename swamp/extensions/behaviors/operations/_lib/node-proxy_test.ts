import { assertEquals } from "jsr:@std/assert@1.0.14";

Deno.test("proxy detection works with compiled-style denied filesystem permissions", async () => {
  const url = new URL("./node-proxy.ts", import.meta.url).href;
  const fixture = await Deno.makeTempFile({ suffix: ".ts" });
  await Deno.writeTextFile(
    fixture,
    `import {runtimeNodeProxy} from ${
      JSON.stringify(url)
    }; console.log(JSON.stringify(await runtimeNodeProxy()));`,
  );
  const child = await new Deno.Command(Deno.execPath(), {
    args: [
      "run",
      "--no-prompt",
      "--allow-run=/usr/bin/python3",
      "--deny-read",
      fixture,
    ],
    stdout: "piped",
    stderr: "piped",
  }).output();
  await Deno.remove(fixture);
  assertEquals(child.success, true, new TextDecoder().decode(child.stderr));
  assertEquals(new TextDecoder().decode(child.stdout).trim(), "null");
});

import { assertRejects } from "jsr:@std/assert@1.0.14";
import { commandOutput, proxyMetadataProgram } from "./node-proxy.ts";

Deno.test("metadata subprocess refuses proxy identity and custody failures", async () => {
  for (
    const [node, unsafe, ok] of [[1, false, true], [18, false, true], [
      209,
      false,
      false,
    ], [1, true, false]] as const
  ) {
    const program = `import builtins,io,types,os,json
realopen=builtins.open
config=json.dumps({'node_id':${node},'host':'10.44.0.1','user':'orbit'})
builtins.open=lambda path: io.StringIO('0::/system.slice/orbit-node-proxy-worker-${node}.service\\n' if path=='/proc/self/cgroup' else config)
os.lstat=lambda path: types.SimpleNamespace(st_uid=0,st_mode=(0o120777 if ${
      unsafe ? "True" : "False"
    } else 0o040755 if path in ('/etc','/etc/orbit','/etc/orbit/node-proxy') else 0o100644))
${proxyMetadataProgram}`;
    const output = await new Deno.Command("/usr/bin/python3", {
      args: ["-c", program],
      stdout: "piped",
      stderr: "null",
    }).output();
    assertEquals(output.success, ok);
    if (!ok) assertEquals(output.stdout.length, 0);
  }
});

Deno.test("duplex commands drain output while sending a large private stdin", async () => {
  const result = await commandOutput(
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
  assertEquals(result.success, true);
  assertEquals(new TextDecoder().decode(result.stdout.slice(-6)), "262144");
  await assertRejects(() =>
    commandOutput(
      { command: "/usr/bin/sleep", args: ["5"] },
      undefined,
      4096,
      AbortSignal.timeout(10),
    )
  );
});

import { proxyLaunch, proxyNode } from "./node-proxy.ts";
import { ProxyIdentity } from "./node-proxy-config.ts";
import { assertThrows } from "jsr:@std/assert@1.0.14";
Deno.test("proxy identity is anchored and shares the approved Node address/user contract", () => {
  assertEquals(
    proxyNode("0::/system.slice/orbit-node-proxy-worker-18.service\n"),
    18,
  );
  assertThrows(() =>
    proxyNode("0::/user.slice/orbit-node-proxy-worker-1.service\n")
  );
  assertThrows(() =>
    proxyNode("7:other:/system.slice/orbit-node-proxy-worker-1.service\n")
  );
  assertThrows(() =>
    ProxyIdentity.parse({ node_id: 1, host: "10.44.0.13", user: "orbit" })
  );
  assertThrows(() =>
    ProxyIdentity.parse({ node_id: 18, host: "10.44.0.13", user: "root" })
  );
  const command = proxyLaunch(
    { node_id: 1, host: "10.44.0.1", user: "orbit" },
    ["/usr/bin/id"],
    12000,
  ).args.at(-1)!;
  assertEquals(
    command.includes(
      "'/usr/bin/timeout' '--signal=TERM' '--kill-after=5s' '12s'",
    ),
    true,
  );
  assertEquals(
    command.includes("'/usr/bin/sudo' '-n' '-u' 'orbit' '--' '/usr/bin/id'"),
    true,
  );
});
