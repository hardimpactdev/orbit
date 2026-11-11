/** Exercise the shipped fleet phase against isolated stock and DNS boundaries. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { find, guests, hasAnsible, intent, reads, run } from "./_support/task_vm_fleet_build.ts";
import { existingGatewayDns, freshGatewayDnsCheck, productionGatewayDns, withdrawGatewayDns } from "./_support/task_vm_stock_roles.ts";

for (const installation of ["later-failure", "not-started"]) {
  Deno.test({
    name: `conservative DNS ownership can withdraw after ${installation}`,
    ignore: !hasAnsible,
    async fn() {
      const result = await withdrawGatewayDns(false, installation);
      if (installation === "later-failure") {
        assertEquals(result.firstStatus, "failed", result.first);
        assertStringIncludes(result.first, "fixture.php_fpm_invalid");
        assertStringIncludes(
          result.first,
          "Prove the local Gateway DNS answer before routing",
        );
        assertEquals(result.installed, true);
      } else {
        assertEquals(result.installed, false);
      }
      assertEquals(result.status, "succeeded", result.output);
      assertEquals(result.retained, []);
      assertEquals(result.running, false);
    },
  });
}
