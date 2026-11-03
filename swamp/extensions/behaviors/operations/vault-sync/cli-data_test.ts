import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { cliDataMethods } from "./cli-data.ts";

const env = { ORBIT_CLIENT_SECRET: "private-client", ORBIT_MASTER_PASSWORD: "private-password" };
for (const [organizations, organization, code] of [
    [[], "Orbit", "vault.organization_missing"],
    [[{ id: "one", name: "Orbit" }, { id: "two", name: "Orbit" }], "Orbit", "vault.organization_missing"],
    [[{ id: "org", name: env.ORBIT_CLIENT_SECRET }], "org", "vault.result_invalid"],
] as const) {
    Deno.test(`organization refuses ${code}: ${JSON.stringify(organizations)}`, async () => {
        const directory = await Deno.makeTempDir();
        try {
            await Deno.writeTextFile(`${directory}/organizations`, JSON.stringify(organizations));
            const { context, getWrittenResources } = createModelTestContext();
            await assertRejects(() => cliDataMethods.organization.execute({ directory, intent: { organization }, env }, { ...context, modelId: "00000000-0000-4000-8000-000000000001" }), Error, code);
            assertEquals(getWrittenResources(), []);
        } finally { await Deno.remove(directory, { recursive: true }); }
    });
}
for (const [collections, published, code] of [
    [[], false, "vault.collection_missing"],
    [[{ id: "collection", name: "Disposable", organizationId: "org" }], true, "vault.items_published"],
] as const) {
    Deno.test(`collection refuses ${code}`, async () => {
        const directory = await Deno.makeTempDir();
        try {
            await Deno.writeTextFile(`${directory}/collections`, JSON.stringify(collections));
            const { context, getWrittenResources } = createModelTestContext();
            await assertRejects(() => cliDataMethods.collection.execute({
                directory, organization: "org", env,
                intent: { collection: "Disposable", published, previous: { organization_id: "old-org", collection_id: "old-collection" } },
            }, { ...context, modelId: "00000000-0000-4000-8000-000000000001" }), Error, code);
            assertEquals(getWrittenResources(), []);
            await assertRejects(() => Deno.stat(`${directory}/probe-input`), Deno.errors.NotFound);
        } finally { await Deno.remove(directory, { recursive: true }); }
    });
}
Deno.test("account refuses unsupported KDF before publishing metadata", async () => {
    const directory = await Deno.makeTempDir();
    try {
        await Deno.writeTextFile(`${directory}/status`, JSON.stringify({ userId: "account", userEmail: "setup@example.test" }));
        await Deno.writeTextFile(`${directory}/data.json`, JSON.stringify({ user_account_kdfConfig_kdfConfig: { kdfType: 1, iterations: 600000 } }));
        const { context, getWrittenResources } = createModelTestContext();
        await assertRejects(() => cliDataMethods.account.execute({ directory, env }, { ...context, modelId: "00000000-0000-4000-8000-000000000001" }), Error, "vault.kdf_unsupported");
        assertEquals(getWrittenResources(), []);
    } finally { await Deno.remove(directory, { recursive: true }); }
});
Deno.test("private intent mismatch refuses before publishing CLI credentials", async () => {
    const { context, getWrittenResources } = createModelTestContext();
    await assertRejects(() => cliDataMethods.prepare.execute({
        intent: { url: "https://vault.example.test" },
        env: { ORBIT_VAULT_CONFIGURATION: JSON.stringify({ url: "https://different.example.test" }) },
    }, { ...context, modelId: "00000000-0000-4000-8000-000000000001" }), Error, "vault.intent_changed");
    assertEquals(getWrittenResources(), []);
});
