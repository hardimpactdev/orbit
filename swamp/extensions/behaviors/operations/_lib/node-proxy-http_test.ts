import {
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.14";
import { proxyGatewayRequest, proxyHttpConfig } from "./node-proxy-http.ts";
import { proxyLaunch } from "./node-proxy.ts";

const proxy = {
  node_id: 1 as const,
  host: "10.44.0.1",
  user: "orbit" as const,
};

Deno.test("the HTTP caller retains pinned effect-Node origin and never falls through locally", async () => {
  const Original = Deno.Command;
  const calls: { command: string | URL; options?: Deno.CommandOptions }[] = [];
  class FixtureCommand extends Original {
    constructor(command: string | URL, options?: Deno.CommandOptions) {
      calls.push({ command, options });
      super("/usr/bin/python3", {
        ...options,
        args: [
          "-c",
          'import sys,json; config=sys.stdin.read(); print(json.dumps({"config":config})); print("200\\tapplication/json",end="")',
        ],
      });
    }
  }
  Object.defineProperty(Deno, "Command", {
    value: FixtureCommand,
    configurable: true,
  });
  try {
    for (
      const [node, host] of [[1, "10.44.0.1"], [18, "10.44.0.13"]] as const
    ) {
      const response = await proxyGatewayRequest(
        { node_id: node, host, user: "orbit" },
        "https://gateway.orbit",
        "/public/custom-ca.crt",
        "GET",
        "/api/v1/nodes/1",
        undefined,
        "public-request",
        undefined,
        600001,
      );
      assertEquals(response.status, 200);
      const config = (response.body as { config: string }).config;
      assertEquals(config.includes('cacert = "/public/custom-ca.crt"'), true);
      assertEquals(config.includes("max-time = 601"), true);
      const call = calls.at(-1)!;
      assertEquals(call.command, "/usr/bin/sudo");
      assertEquals(call.options?.args?.includes("/usr/bin/ssh"), true);
      assertEquals(call.options?.args?.includes(`orbit@${host}`), true);
      assertEquals(
        call.options?.args?.includes("StrictHostKeyChecking=yes"),
        true,
      );
      assertEquals(
        call.options?.args?.at(-1)?.includes("'/usr/bin/curl' '--config' '-'"),
        true,
      );
    }
    const count = calls.length;
    await assertRejects(() =>
      proxyGatewayRequest(
        proxy,
        "https://gateway.orbit",
        "/public/custom-ca.crt",
        "GET",
        "https://foreign.test/api/v1/send",
      )
    );
    assertEquals(calls.length, count);
  } finally {
    Object.defineProperty(Deno, "Command", {
      value: Original,
      configurable: true,
    });
  }
});

Deno.test("proxy Gateway curl uses the configured public CA instead of the agent-private path", () => {
  const ca = "/usr/local/share/ca-certificates/custom-role-root.crt";
  const config = proxyHttpConfig(
    "https://gateway.orbit",
    "GET",
    "/api/v1/direct-operations/6081?run_id=public-run",
    undefined,
    undefined,
    60000,
    ca,
  );
  assertEquals(config.includes(`cacert = ${JSON.stringify(ca)}`), true);
  assertEquals(config.includes("/etc/orbit/agent/ca.pem"), false);
});

Deno.test("Gateway requests originate on the effect Node with private bytes only in curl stdin", () => {
  const config = proxyHttpConfig(
    "https://gateway.orbit",
    "POST",
    "/api/v1/direct-operations/7/secret-output",
    { outputs: { token: 'private-needle\\"\n' }, run_id: "public-id" },
    undefined,
    60000,
    "/usr/local/share/ca-certificates/orbit-root.crt",
  );
  assertEquals(
    config.includes(
      'cacert = "/usr/local/share/ca-certificates/orbit-root.crt"',
    ),
    true,
  );
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
      undefined,
      60000,
      "/usr/local/share/ca-certificates/orbit-root.crt",
    )
  );
  assertThrows(() =>
    proxyHttpConfig(
      "https://gateway.orbit",
      "POST",
      "/api/v1/x",
      {},
      "key\r\nInjected: yes",
      60000,
      "/usr/local/share/ca-certificates/orbit-root.crt",
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
    "/usr/local/share/ca-certificates/orbit-root.crt",
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
        "/usr/local/share/ca-certificates/orbit-root.crt",
      )
    );
  }
});
