import { assertEquals } from "jsr:@std/assert@1.0.19";
import { provision } from "../../../../tests/_support/vaultwarden_account.ts";
import { vaultFixture } from "../../../../tests/_support/vault_fixture.ts";
Deno.test({
  name: "real official CLI creates, updates and deletes a Vaultwarden secure note",
  ignore: !Deno.env.get("ORBIT_TEST_VAULTWARDEN_URL") || !Deno.env.get("ORBIT_TEST_VAULTWARDEN_CA"),
  async fn() {
    await using f = await vaultFixture();
    const configuration = await provision(
      Deno.env.get("ORBIT_TEST_VAULTWARDEN_URL")!,
      f.secrets[0],
      Deno.env.get("ORBIT_TEST_VAULTWARDEN_CA")!,
    );
    f.repo.secrets.push(configuration.client_secret);
    await Deno.remove(`${f.repo.dir}/fixture-bin/npx`);
    f.inputs.intent.ca_file = Deno.env.get("ORBIT_TEST_VAULTWARDEN_CA")!;
    Object.assign(f.inputs.intent, {
      url: configuration.url,
      client_id: configuration.client_id,
      organization_id: configuration.organization_id,
      collection_id: configuration.collection_id,
      ledger: [],
    });
    Object.assign(f.privateInput, configuration, { ledger: [] });
    await f.put();
    const first = await f.run();
    const ledger = JSON.parse(first.steps.result.outputs.stdout).data;
    assertEquals(ledger.sources, ["database-user:7"]);
    f.inputs.intent.ledger = [{
      source: ledger.sources[0],
      cipher_id: ledger.cipher_ids[0],
      revision_date: ledger.revisions[0],
      fingerprint: "new",
    }];
    f.inputs.intent.wanted[0].fingerprint = f.privateInput.wanted[0].fingerprint = "updated";
    await f.put();
    const updated = await f.run();
    assertEquals(JSON.parse(updated.steps.result.outputs.stdout).data.cipher_ids, ledger.cipher_ids);
    f.inputs.intent.wanted = [];
    assertEquals(JSON.parse((await f.run()).steps.result.outputs.stdout).data.sources, []);
  },
});
