import {
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.14";
import { commandLaunch, run } from "./node-commands.ts";
import { ProxyIdentity } from "./node-proxy-config.ts";
import { proxyHttpConfig, proxyLaunch, proxyNode } from "./node-proxy.ts";

const proxy = {
  node_id: 1 as const,
  host: "10.44.0.1",
  user: "orbit" as const,
};

Deno.test("only an exact approved systemd proxy cgroup selects the effect Node", () => {
  assertEquals(
    proxyNode("0::/system.slice/orbit-node-proxy-worker-1.service\n"),
    1,
  );
  assertEquals(
    proxyNode("0::/system.slice/orbit-node-proxy-worker-18.service/child\n"),
    18,
  );
  assertEquals(proxyNode("0::/system.slice/orbit-node-worker.service\n"), null);
  assertEquals(proxyNode("0::/user.slice/node-1.service\n"), null);
  assertThrows(() =>
    proxyNode("0::/system.slice/orbit-node-proxy-worker-209.service\n")
  );
});

Deno.test("commands use the existing Node user or explicit sudo locally and over pinned SSH", () => {
  assertEquals(commandLaunch(["/usr/bin/id", "-u"], "node", null), {
    command: "/usr/bin/id",
    args: ["-u"],
  });
  assertEquals(commandLaunch(["/usr/bin/id", "-u"], "root", null), {
    command: "/usr/bin/sudo",
    args: ["-n", "--", "/usr/bin/id", "-u"],
  });
  const remote = commandLaunch(["/usr/bin/id", "-u"], "root", proxy);
  assertEquals(remote.command, "/usr/bin/sudo");
  assertEquals(
    remote.args.at(-1)?.endsWith(
      "'/usr/bin/sudo' '-n' '--' '/usr/bin/id' '-u'",
    ),
    true,
  );
  assertEquals(remote.args.includes("orbit@10.44.0.1"), true);
  for (
    const flag of [
      "ForwardAgent=no",
      "IdentityAgent=none",
      "ClearAllForwardings=yes",
      "StrictHostKeyChecking=yes",
      "ConnectTimeout=8",
      "IdentitiesOnly=yes",
    ]
  ) {
    assertEquals(remote.args.includes(flag), true);
  }
  assertEquals(
    remote.args.includes(
      "UserKnownHostsFile=/etc/orbit/node-proxy/known_hosts",
    ),
    true,
  );
  assertEquals(
    commandLaunch(["/usr/bin/id"], "orbit-app-7", proxy).args.at(-1)?.endsWith(
      "'/usr/bin/sudo' '-n' '-u' 'orbit-app-7' '--' '/usr/bin/id'",
    ),
    true,
  );
});

Deno.test("caller input cannot select an SSH host, shell option or agent action", () => {
  for (const user of ["--root", "root;id", "a\nroot"]) {
    assertThrows(() => commandLaunch(["/usr/bin/id"], user, proxy));
  }
  for (
    const command of [["id"], ["/usr/bin/id\0bad"], [
      "/usr/local/bin/orbit-agent",
      "command",
      "firewall.rule",
    ]]
  ) {
    assertThrows(() => commandLaunch(command, "root", proxy));
  }
  assertThrows(() =>
    proxyLaunch({ ...proxy, host: "10.44.0.1;id" }, ["/usr/bin/id"])
  );
  assertThrows(() =>
    ProxyIdentity.parse({ ...proxy, user: "-oProxyCommand=id" })
  );
  assertEquals(
    proxyLaunch(proxy, ["/usr/bin/printf", "one'two"]).args.at(-1)?.endsWith(
      "'/usr/bin/printf' 'one'\\''two'",
    ),
    true,
  );
});

Deno.test("private command input stays on stdin and nonzero exits remain truthful", async () => {
  const privateInput = new TextEncoder().encode("private-command-needle\n");
  const result = await run(
    [
      "/usr/bin/python3",
      "-c",
      "import sys; sys.stdout.buffer.write(sys.stdin.buffer.read()); sys.exit(37)",
    ],
    privateInput,
    "node",
  );
  assertEquals(result.success, false);
  assertEquals(result.code, 37);
  assertEquals(result.stdout, privateInput);
});

Deno.test("bounded command failures never expose stdout or input in diagnostics", async () => {
  const error = await assertRejects(
    () =>
      run(["/usr/bin/printf", "private-output-needle"], undefined, "node", {
        maxOutputBytes: 2,
      }),
    Error,
  );
  assertEquals(error.message.includes("private-output-needle"), false);
  await assertRejects(() =>
    run(["/usr/bin/sleep", "5"], undefined, "node", { deadlineMs: 10 })
  );
});

Deno.test("Gateway requests originate on the effect Node with private bytes only in curl stdin", () => {
  const config = proxyHttpConfig(
    "https://gateway.orbit",
    "POST",
    "/api/v1/direct-operations/7/secret-output",
    { outputs: { token: 'private-needle\\"\n' }, run_id: "public-id" },
  );
  assertEquals(config.includes('cacert = "/etc/orbit/agent/ca.pem"'), true);
  assertEquals(config.includes("private-needle"), true);
  const launch = proxyLaunch(proxy, ["/usr/bin/curl", "--config", "-"]);
  assertEquals(JSON.stringify(launch).includes("private-needle"), false);
  assertEquals(JSON.stringify(launch).includes("ORBIT_AGENT"), false);
  assertThrows(() =>
    proxyHttpConfig(
      "https://gateway.orbit",
      "POST",
      "https://foreign.test/api/v1/send",
      {},
    )
  );
  assertThrows(() =>
    proxyHttpConfig(
      "https://gateway.orbit",
      "POST",
      "/api/v1/x",
      {},
      "key\r\nInjected: yes",
    )
  );
});

Deno.test("proxy Gateway requests retain the caller's long request budget", () => {
  const config = proxyHttpConfig(
    "https://gateway.orbit",
    "GET",
    "/api/v1/nodes/1",
    undefined,
    undefined,
    600001,
  );
  assertEquals(config.includes("max-time = 601"), true);
  for (const value of [0, -1, NaN, Infinity]) {
    assertThrows(() =>
      proxyHttpConfig(
        "https://gateway.orbit",
        "GET",
        "/api/v1/nodes/1",
        undefined,
        undefined,
        value,
      )
    );
  }
});
