/** Exercise the shipped fleet phase against isolated stock and DNS boundaries. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { find, guests, hasAnsible, intent, reads, run } from "./_support/task_vm_fleet_build.ts";
import { existingGatewayDns, freshGatewayDnsCheck, productionGatewayDns, withdrawGatewayDns } from "./_support/task_vm_stock_roles.ts";

Deno.test({
  name:
    "a from-stock refresh with no Gateway release metadata reaches all nested roles after private DNS lets the websocket dependency listen",
  ignore: !hasAnsible,
  fn() {
    const result = run([], false, false, true);
    assertEquals(result.baseState.vm.generation, "stock");
    assertEquals(result.baseState.seed, true);
    assertEquals(result.baseState.calls[0], [
      "--project",
      "orbit-tasks",
      "delete",
      "--force",
      "host-base",
    ]);
    assertStringIncludes(
      JSON.stringify(intent.fleet.bootstrap),
      '"wireguard_ip":"10.45.0.1"',
    );
    assertEquals("APP_VERSION" in intent.fleet.bootstrap, false);
    assertEquals("REVISION" in intent.fleet.bootstrap, false);
    assertEquals(
      result.roleStatuses,
      ["succeeded", "succeeded", "succeeded", "succeeded", "succeeded"],
      Object.entries(result.roleOutputs).map(([id, output]) =>
        `Nested Operation ${id}:\n${output}`
      )
        .join("\n"),
    );
    assertStringIncludes(
      result.roleOutputs[1],
      "Require the recorded CLI pin only for a CLI caller",
    );
    assertStringIncludes(result.roleOutputs[3], "Wait for Reverb");
    assertEquals(result.passed, false, result.output);
    assertStringIncludes(result.output, "TASK [Restart the nested hub]");
    assertStringIncludes(
      result.output,
      "Fixture stops before real nested WireGuard/systemd activation",
    );
    assertEquals(result.operatorKey.trim().length, 44);
    assertStringIncludes(
      result.log.join("\n"),
      " -- systemctl restart wg-quick@orbit",
    );
    assertEquals(find(result.log, "snapshot create"), -1);
    assertEquals(
      find(result.log, "php artisan orbit:role-add gateway websocket") <
        find(result.log, "php artisan orbit:node-add app-dev"),
      true,
    );
    assertEquals(
      find(result.log, "php artisan orbit:node-add app-dev") <
        find(result.log, "php artisan orbit:node-add app-prod"),
      true,
    );
  },
});
