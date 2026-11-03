import { assertEquals, assertFalse, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { bootstrapFixture } from "../../../../tests/_support/bootstrap_fixture.ts";

for (const address of ["10.44.0.12", "10.44.0.120"]) {
  Deno.test(`Mac inspection checks the admitted address against ${address}`, async () => {
    const { repo, name, inputs, calls } = await bootstrapFixture(true);
    await using _ = repo;
    repo.env.MAC_ADDRESS = address;
    await repo.cli(["workflow", "validate", name]);
    const success = address === "10.44.0.12";
    const { run, history } = await repo.runWorkflow(name, inputs, { success });
    assertEquals(run.status, success ? "succeeded" : "failed");
    assertStringIncludes(
      JSON.stringify(history),
      success ? "bootstrap-macos-12" : "The Mac must have its admitted WireGuard address",
    );
    const audit = await Deno.readTextFile(calls);
    for (const command of ["uname", "ifconfig"]) assertStringIncludes(audit, command);
    for (const command of ["orbit-agent", "id -"]) assertFalse(audit.includes(command));
  });
}
