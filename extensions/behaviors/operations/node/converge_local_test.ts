import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { localAccount, publishDns } from "./converge_local.ts";
import { DnsProgram } from "./converge_dns_program.ts";
import type { SystemTransport } from "./system_command.ts";

Deno.test("DNS publication uses the shared placed command transport with bounded stdin and cancellation", async () => {
  const controller = new AbortController();
  const payload = {
    records: "host-record=node.orbit,10.44.0.2\n",
    catalog: "{}\n",
  };
  let calls = 0;
  const invoke: SystemTransport = (input, signal) => {
    calls++;
    assertEquals(input.command, "/usr/bin/sudo");
    assertEquals(input.args, [
      "-n",
      "--",
      "/usr/bin/python3",
      "-c",
      DnsProgram,
    ]);
    assertEquals(JSON.parse(input.stdin!), payload);
    assertEquals(input.maxOutputBytes, 65536);
    assertEquals(signal, controller.signal);
    return Promise.resolve({ success: true, stdout: '{"ready":true}' });
  };
  assertEquals(await publishDns(payload, controller.signal, invoke), {
    ready: true,
  });
  controller.abort();
  await assertRejects(() => publishDns(payload, controller.signal, invoke));
  assertEquals(calls, 1);
});

Deno.test("account resolution reads passwd and group through the same placed transport", async () => {
  const seen: string[][] = [];
  const invoke: SystemTransport = (input) => {
    seen.push([input.command, ...input.args]);
    return Promise.resolve({
      success: true,
      stdout: input.args[0] === "passwd"
        ? "orbit:x:1000:1001:Node user:/srv/orbit:/bin/bash\n"
        : "managed:x:1001:\n",
    });
  };
  assertEquals(await localAccount("orbit", invoke), {
    home: "/srv/orbit",
    group: "managed",
  });
  assertEquals(seen, [["/usr/bin/getent", "passwd", "orbit"], [
    "/usr/bin/getent",
    "group",
    "1001",
  ]]);
});

Deno.test("DNS and account transport refusals cannot report success", async () => {
  const refused: SystemTransport = () =>
    Promise.resolve({ success: false, stdout: "" });
  await assertRejects(
    () => publishDns({ records: "", catalog: "{}" }, undefined, refused),
    Error,
    "Private DNS publication outcome is uncertain",
  );
  await assertRejects(
    () => localAccount("orbit", refused),
    Error,
    "The admitted Node account cannot be resolved",
  );
});
