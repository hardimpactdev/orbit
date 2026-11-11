/** Exercise the shipped fleet phase against isolated stock and DNS boundaries. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { find, guests, hasAnsible, intent, reads, run } from "./_support/task_vm_fleet_build.ts";
import { existingGatewayDns, freshGatewayDnsCheck, productionGatewayDns, withdrawGatewayDns } from "./_support/task_vm_stock_roles.ts";

Deno.test({
  name:
    "local Gateway DNS check mode skips activation and readiness without retries or mutation",
  ignore: !hasAnsible,
  async fn() {
    const result = await existingGatewayDns(true);
    assertEquals(result.firstStatus, "succeeded", result.first);
    assertEquals(result.secondStatus, "succeeded", result.second);
    assertStringIncludes(result.second, "changed=0");
    assertEquals(result.samePid, true);
    assertEquals(result.second.includes("FAILED - RETRYING"), false);
    assertStringIncludes(
      result.second,
      "TASK [Prove the local Gateway DNS answer before routing]",
    );
    assertStringIncludes(result.second, "skipping: [localhost]");
  },
});

Deno.test({
  name:
    "fresh local Gateway DNS check mode plans resources without requiring an installed release",
  ignore: !hasAnsible,
  async fn() {
    const result = await freshGatewayDnsCheck();
    assertEquals(result.status, "succeeded", result.output);
    assertEquals(result.output.includes("FAILED - RETRYING"), false);
  },
});
