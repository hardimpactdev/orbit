import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
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
    [{ organizations: [] }, "vault.organization_missing"],
    [{ organizations: [{ id: "one", name: "Orbit" }, { id: "two", name: "Orbit" }] }, "vault.organization_missing"],
    [{ collections: [] }, "vault.collection_missing"],
    [{ readonly: true }, "vault.collection_read_only"],
    [{ fail: "login" }, "vault.login_failed"],
    [{ fail: "unlock" }, "vault.login_failed"],
    [{ kdf: 1 }, "vault.kdf_unsupported"],
    [{ echo_secret: "organizations" }, "vault.result_invalid"],
    [{}, "vault.items_published"],
  ] as const
) {
  Deno.test(`setup refuses ${expected}: ${JSON.stringify(change)}`, async () => {
    await using f = await vaultFixture(true);
    if (expected === "vault.result_invalid") f.inputs.intent.organization = "org";
    if (expected === "vault.items_published") {
      Object.assign(f.inputs.intent, {
        previous: { organization_id: "old-org", collection_id: "old-collection" },
        published: true,
      });
    }
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
    assertEquals(code, expected);
    if (expected === "vault.items_published") assert(!state.commands.some((row: string[]) => row[0] === "create"));
  });
}
