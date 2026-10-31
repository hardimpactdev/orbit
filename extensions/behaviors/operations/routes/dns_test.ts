import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { dnsLaunch, publishPrivateDns, runPrivateDns } from "./dns.ts";
import { commandLaunch, type NodeCommand } from "../_lib/node-commands.ts";
import { proxyAddress } from "../_lib/node-proxy-config.ts";
const input = {
  node_id: 1,
  records: "",
  catalog: "{}",
  digest: "44136fa355b3678a1146ad16f7e8649e94fb4fc21fe77e8310c060f61caaff8a",
};
Deno.test("DNS rejects a changed catalog or invalid JSON before invoking the fixed program", async () => {
  let calls = 0;
  for (const catalog of ["{", '{"new":true}']) {
    await assertRejects(() =>
      publishPrivateDns({ ...input, catalog }, () => {
        calls++;
        return Promise.resolve({});
      })
    );
  }
  assertEquals(calls, 0);
});
Deno.test("DNS confirms exactly the admitted digest and preserves only bounded outcomes", async () => {
  assertEquals(
    await publishPrivateDns(input, () =>
      Promise.resolve({
        outcome: "published",
        data: { ready: true, digest: input.digest, stdout: "PRIVATE" },
        log: "PRIVATE",
      })),
    {
      outcome: "published",
      data: { ready: true, digest: input.digest },
      ready: true,
    },
  );
  assertEquals(
    await publishPrivateDns(input, () =>
      Promise.resolve({
        outcome: "refused",
        data: { error_code: "private_dns.listener_not_ready", log: "PRIVATE" },
      })),
    { error_code: "private_dns.listener_not_ready" },
  );
  await assertRejects(() =>
    publishPrivateDns(
      input,
      () =>
        Promise.resolve({
          outcome: "published",
          data: { ready: true, digest: "a".repeat(64) },
        }),
    )
  );
  await assertRejects(() =>
    publishPrivateDns(
      input,
      () => Promise.resolve({ outcome: "published", data: { ready: true } }),
    )
  );
});
Deno.test("DNS keeps fixed execution and stdin data on each placed proxy Node", async () => {
  for (const node of [1, 18] as const) {
    const launch = dnsLaunch();
    assertEquals(launch.command, "/usr/bin/timeout");
    assertEquals(launch.args.slice(0, 6), [
      "--signal=TERM",
      "--kill-after=5s",
      "60s",
      "/usr/bin/python3",
      "-I",
      "-c",
    ]);
    const command: NodeCommand = (argv, stdin, asUser, options) => {
      assertEquals(argv, [launch.command, ...launch.args]);
      assertEquals(JSON.parse(new TextDecoder().decode(stdin)), {
        records: input.records,
        catalog: input.catalog,
      });
      assertEquals(asUser, "root");
      assertEquals(options, { deadlineMs: 70000, maxOutputBytes: 4096 });
      const placed = commandLaunch(argv, asUser, {
        node_id: node,
        host: proxyAddress(node),
        user: "orbit",
      });
      assertEquals(placed.args.includes("orbit@" + proxyAddress(node)), true);
      return Promise.resolve({
        success: true,
        code: 0,
        stdout: new TextEncoder().encode(
          JSON.stringify({
            outcome: "published",
            data: { ready: true, digest: input.digest },
          }),
        ),
      });
    };
    const result = await publishPrivateDns(
      input,
      (value) => runPrivateDns(value, command),
    );
    assertEquals("ready" in result && result.ready, true);
  }
});
Deno.test("DNS never decodes failed or oversized command output as a confirmed publication", async () => {
  for (
    const result of [
      {
        success: false,
        code: 1,
        stdout: new TextEncoder().encode("private-output"),
      },
      { success: true, code: 0, stdout: new Uint8Array(4097) },
    ]
  ) {
    const error = await assertRejects(
      () => runPrivateDns(input, () => Promise.resolve(result)),
      Error,
    );
    assertEquals(error.message.includes("private-output"), false);
  }
});
