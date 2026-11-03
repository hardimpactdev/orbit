import { assertEquals } from "jsr:@std/assert@1.0.19";
import { vaultFixture } from "../../../../tests/_support/vault_fixture.ts";
Deno.test("setup proves write access and clears its private session", async () => {
  await using f = await vaultFixture(true);
  const { steps, state } = await f.run();
  assertEquals(JSON.parse(steps["Verify the account"].outputs.stdout).data, {
    backend: "configured",
    email: "setup@example.test",
    organization_id: "org",
    organization_name: "Orbit",
    collection_id: "collection",
    collection_name: "Disposable",
  });
  const names = state.commands.map((row: string[]) => row[0]);
  assertEquals(names.slice(0, 3), ["config", "login", "unlock"]);
  assertEquals(names.slice(3, 6).sort(), ["list", "list", "status"]);
  assertEquals(names.slice(6), ["create", "delete", "logout"]);
});
for (
  const [change, expected] of [
    [{ readonly: true }, "vault.collection_read_only"],
    [{ fail: "login" }, "vault.login_failed"],
  ] as const
) {
  Deno.test(`setup refuses ${expected}: ${JSON.stringify(change)}`, async () => {
    await using f = await vaultFixture(true);
    Object.assign(f.privateInput, f.inputs.intent);
    f.write({ ...f.read(), ...change });
    await f.put();
    const { steps, state } = await f.run(false);
    const failure = Object.values(steps).find((s) => s.status === "failed")!;
    const code = failure.error.startsWith("vault.")
      ? failure.error
      : failure.name === "Probe write access"
      ? "vault.collection_read_only"
      : "vault.login_failed";
    assertEquals(code, expected, JSON.stringify({ step: failure.name, error: failure.error }));
    assertEquals(steps["Verify the account"].status, "skipped");
    assertEquals(steps["Remove CLI session"].status, "succeeded");
    if (expected === "vault.login_failed") assertEquals(state.commands.some((row: string[]) => row[0] === "unlock"), false);
  });
}
