import { assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { nativeRepo, ROOT } from "./native_harness.ts";
import { exists, stepsOf } from "./native_history.ts";
export async function vaultFixture(setup = false) {
  const repo = await nativeRepo();
  repo.timeoutMs = 180_000;
  await repo.copyBehaviors("operations/vault-sync");
  const name = setup ? "operation-vault-setup" : "operation-vault-sync";
  await repo.workflow(name);
  const state = `${repo.dir}/fixture.json`;
  const read = () => JSON.parse(Deno.readTextFileSync(state));
  const write = (value: unknown) => Deno.writeTextFileSync(state, JSON.stringify(value));
  write(
    setup
      ? {
        commands: [],
        organizations: [{ id: "org", name: "Orbit" }],
        collections: [{ id: "collection", organizationId: "org", name: "Disposable" }],
      }
      : { ciphers: [], writes: [], deletes: [] },
  );
  await Deno.mkdir(`${repo.dir}/fixture-bin`);
  await repo.fakeBinary(
    "fixture-bin/npx",
    await Deno.readTextFile(`${ROOT}/tests/_support/${setup ? "bw_setup_cli" : "bw_cli"}.ts`),
  );
  const secrets = setup
    ? ["setup-private-password-65921", "setup-private-client-49381", "unused", "setup-private-session-74891"]
    : [
      "vault-private-master-93857",
      "vault-private-client-89216",
      "vault-private-item-35791",
      "vault-private-session-72364",
    ];
  repo.secrets = secrets.filter((s) => s !== "unused");
  Object.assign(repo.env, {
    PATH: `${repo.dir}/fixture-bin:${Deno.env.get("PATH")}`,
    ORBIT_BW_FIXTURE_STATE: state,
    FIXTURE_MASTER_PASSWORD: secrets[0],
    FIXTURE_CLIENT_SECRET: secrets[1],
    FIXTURE_ITEM_SECRET: secrets[2],
    FIXTURE_SESSION: secrets[3],
  });
  const wanted = [{ source: "database-user:7", fingerprint: "new" }];
  // deno-lint-ignore no-explicit-any
  const intent: any = setup
    ? {
      url: "https://vault.orbit",
      client_id: "client",
      organization: "Orbit",
      collection: "Disposable",
      published: false,
      ca_file: "/public/ca.pem",
    }
    : {
      url: "https://vault.example.test",
      client_id: "client",
      organization_id: "org",
      collection_id: "collection",
      ca_file: "/public/ca.pem",
      wanted,
      ledger: [{ source: "database-user:7", cipher_id: "published", revision_date: "old", fingerprint: "old" }],
    };
  // Test cases deliberately vary the admitted envelope and its private counterpart independently.
  // deno-lint-ignore no-explicit-any
  const privateInput: any = { ...structuredClone(intent), client_secret: secrets[1], master_password: secrets[0] };
  if (!setup) {
    privateInput.wanted[0].cipher = {
      name: "Database",
      notes: "Owned credential",
      fields: [
        { name: "orbit.source", hidden: false, value: "database-user:7" },
        { name: "password", hidden: true, value: secrets[2] },
      ],
    };
  }
  const inputs = {
    operation_id: 7,
    node_id: 9,
    kind: setup ? "vault.setup" : "vault.sync",
    intent,
    execution_secrets: {
      configuration: {
        vault: "orbit-control-inputs",
        key: setup ? "vault-setup-00000000-0000-4000-8000-000000000007" : "vault-sync-fixture",
      },
    },
  };
  await repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
  const put = () =>
    repo.cli(["vault", "put", "orbit-control-inputs", inputs.execution_secrets.configuration.key, "--force"], {
      input: JSON.stringify(privateInput),
    });
  await put();
  async function run(success = true, workflow = name) {
    const result = await repo.runWorkflow(workflow, inputs, { success });
    const steps = stepsOf(result.history);
    assertEquals(result.run.status, success ? "succeeded" : "failed");
    const workspace = steps.workspace ?? steps["Create CLI session"];
    if (workspace?.outputs?.stdout) assertFalse(exists(workspace.outputs.stdout.trim()));
    if (setup) assertEquals(["succeeded", "skipped"].includes(steps["Clear the session"].status), true);
    if (workflow === "operation-vault-teardown") assertEquals(steps["Remove CLI session"].status, "succeeded");
    if (success && !setup && workflow === "operation-vault-sync") {
      assertEquals(JSON.parse(steps.result.outputs.stdout).outcome, "succeeded");
    }
    return { ...result, steps, state: read() };
  }
  return {
    repo,
    inputs,
    privateInput,
    secrets,
    read,
    write,
    put,
    run,
    async [Symbol.asyncDispose]() {
      await repo[Symbol.asyncDispose]();
    },
  };
}
export const cipher = (id = "published", source: string | null = "database-user:7", extra = {}) => ({
  id,
  source,
  organization_id: "org",
  collection_ids: ["collection"],
  revision: "old",
  deleted: false,
  ...extra,
});
