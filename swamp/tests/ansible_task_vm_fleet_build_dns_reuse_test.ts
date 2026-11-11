/** Exercise the shipped fleet phase against isolated stock and DNS boundaries. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { find, guests, hasAnsible, intent, reads, run } from "./_support/task_vm_fleet_build.ts";
import { existingGatewayDns, freshGatewayDnsCheck, productionGatewayDns, withdrawGatewayDns } from "./_support/task_vm_stock_roles.ts";

Deno.test({
  name:
    "an already-running local listener is reused with zero changes and peer-managed Gateway DNS is preserved",
  ignore: !hasAnsible,
  async fn() {
    const result = await existingGatewayDns();
    assertEquals(result.firstStatus, "succeeded", result.first);
    assertEquals(result.secondStatus, "succeeded", result.second);
    assertStringIncludes(result.second, "changed=0");
    assertEquals(result.samePid, true);
    assertEquals(
      result.calls.filter((call) =>
        call.name === "orbit-private-dns.service" &&
        call.state === "restarted"
      ).length,
      1,
    );
    assertEquals(
      result.calls.some((call) =>
        call.name === "orbit-gateway-dns.service" && call.state
      ),
      false,
    );
    assertStringIncludes(
      result.second,
      "TASK [Read Gateway resolver policy]",
    );
    assertStringIncludes(result.second, "skipping: [localhost]");
  },
});

Deno.test({
  name:
    "production Gateway vars from main create no listener or catalog and change no resolver",
  ignore: !hasAnsible,
  async fn() {
    const result = await productionGatewayDns();
    assertEquals(result.status, "succeeded", result.output);
    assertStringIncludes(result.output, "changed=0");
    assertEquals(result.dnsDirectory, false);
    assertEquals(result.listener, false);
    assertEquals(result.socket, false);
    assertEquals(result.calls.some((call) => call.state), false);
  },
});
