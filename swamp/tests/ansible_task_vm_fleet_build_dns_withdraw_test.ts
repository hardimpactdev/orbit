/** Exercise the shipped fleet phase against isolated stock and DNS boundaries. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { find, guests, hasAnsible, intent, reads, run } from "./_support/task_vm_fleet_build.ts";
import { existingGatewayDns, freshGatewayDnsCheck, productionGatewayDns, withdrawGatewayDns } from "./_support/task_vm_stock_roles.ts";

Deno.test({
  name:
    "a recorded local listener is disabled and its stale catalog removed after routing to a separate VPN",
  ignore: !hasAnsible,
  async fn() {
    const result = await withdrawGatewayDns();
    assertEquals(result.status, "succeeded", result.output);
    assertEquals(result.retained, []);
    assertEquals(result.running, false);
    const route = result.calls.findIndex((call) =>
      call.name === "orbit-gateway-dns.service" &&
      call.state === "started"
    );
    const stop = result.calls.findIndex((call) =>
      call.name === "orbit-private-dns.socket" && call.state === "stopped"
    );
    assertEquals(stop > route && route >= 0, true);
    assertEquals(
      result.calls.filter((call) => call.state === "stopped").map((
        call,
      ) => [call.name, call.enabled]),
      [
        ["orbit-private-dns.socket", false],
        ["orbit-private-dns.service", false],
      ],
    );
  },
});

Deno.test({
  name:
    "withdrawal retains the listener when resolver policy still names its local address",
  ignore: !hasAnsible,
  async fn() {
    const result = await withdrawGatewayDns(true);
    assertEquals(result.status, "failed", result.output);
    assertEquals(result.running, true);
    assertEquals(result.retained.length, 4);
    assertEquals(
      result.calls.some((call) => call.state === "stopped"),
      false,
    );
  },
});
