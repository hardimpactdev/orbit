import { assertEquals } from "jsr:@std/assert@1";
import { Reference } from "./inspection.ts";
import { processInspectionFixture } from "../../../../tests/fixtures/process-inspection.ts";

for (
    const [action, output] of [["status", ""], [
        "logs",
        "private journal token",
    ]]
) {
    Deno.test(`container Process ${action}: emitted vault keys match the reference and Gateway grant`, async () => {
        const fixture = await processInspectionFixture(output);
        const policy = JSON.parse(
            await Deno.readTextFile(
                new URL(
                    "../../../../config/gateway-workflow-grants.json",
                    import.meta.url,
                ),
            ),
        );
        assertEquals(fixture.vaulted.length, 1);
        for (const emitted of fixture.vaulted) {
            assertEquals(
                policy.vaults.some((
                    grant: {
                        vault: string;
                        key_pattern: string;
                        actions: string[];
                    },
                ) => grant.vault === emitted.vault &&
                    grant.actions.includes("write") &&
                    grant.actions.includes("read") &&
                    new RegExp(grant.key_pattern).test(emitted.key)
                ),
                true,
                `No Gateway write/read grant for emitted key ${emitted.key}`,
            );
            assertEquals(Reference.parse(fixture.reference).inspection, {
                vault: emitted.vault,
                key: emitted.key,
            });
            assertEquals(emitted.value, fixture.value);
        }
        assertEquals(
            JSON.stringify(fixture.reference).includes("private journal token"),
            false,
        );
    });
}
