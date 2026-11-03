import { assertEquals } from "jsr:@std/assert@1.0.19";
import { cipher, vaultFixture } from "../../../../tests/_support/vault_fixture.ts";
async function fixture() {
  const f = await vaultFixture();
  await f.repo.workflow("operation-vault-teardown");
  f.inputs.kind = "vault.teardown";
  f.inputs.intent.wanted = [];
  delete f.privateInput.wanted;
  f.inputs.execution_secrets.configuration.key = "vault-teardown-00000000-0000-4000-8000-000000000001";
  f.repo.env.FIXTURE_ORGANIZATION = f.inputs.intent.organization_id;
  await f.put();
  return f;
}
Deno.test("teardown deletes only exact ledger matches", async () => {
  await using f = await fixture();
  f.write({
    ...f.read(),
    ciphers: [
      cipher(),
      cipher("copy"),
      cipher("foreign", null),
      cipher("changed", "database-user:8"),
      cipher("other-org", undefined, { organization_id: "other" }),
      cipher("other-collection", undefined, { collection_ids: ["other"] }),
    ],
  });
  const { steps, state } = await f.run(true, "operation-vault-teardown");
  assertEquals(state.deletes, ["published"]);
  assertEquals(steps["Confirm teardown"].status, "succeeded");
  assertEquals(steps["Remove CLI session"].status, "succeeded");
});
Deno.test("teardown retains completed deletes and clears session on failure", async () => {
  await using f = await fixture();
  f.inputs.intent.ledger.push({ ...f.inputs.intent.ledger[0], source: "database-user:8", cipher_id: "second" });
  f.write({ ...f.read(), ciphers: [cipher(), cipher("second", "database-user:8")], fail_delete: "second" });
  const { steps, state } = await f.run(false, "operation-vault-teardown");
  assertEquals(state.deletes, ["published"]);
  assertEquals([steps["Delete the items-0"].status, steps["Delete the items-1"].status], ["succeeded", "failed"]);
  assertEquals(steps["Remove CLI session"].status, "succeeded");
});
