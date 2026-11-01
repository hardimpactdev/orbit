import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior } from "./destroy_plan.ts";
import { behavior as assets } from "./assets.ts";

const context = {
    extensionFile: (name: string) => "/native/" + name,
    writeResource: () => Promise.resolve(),
};
const intent = {
    driver: "mysql",
    container: "orbit-process-42-proof",
    host: "10.44.0.9",
    port: 3306,
    default_open: false,
    databases: ["proof"],
    users: ["owner", "default", "owner"],
    test_prefixes: ["proof_testing"],
    owners: { proof: "owner", proof_testing: "owner" },
};
Deno.test("removal inventory preserves literal prefix ownership and refuses foreign rows", async () => {
    const plan = await behavior.methods.read.execute({
        intent,
        inventory: '{"databases":["proof_testing_1","proof"]}',
    }, context);
    assertEquals(plan.databases, ["proof", "proof_testing_1"]);
    assertEquals(plan.users, ["owner"]);
    assertEquals(JSON.parse(plan.json).owners, {
        proof: "owner",
        proof_testing_1: "owner",
    });
    for (const name of ["foreign", "proofXtesting_1", "Proof_testing_1"]) {
        await assertRejects(
            () =>
                behavior.methods.read.execute({
                    intent,
                    inventory: JSON.stringify({ databases: [name] }),
                }, context),
            Error,
            "database.ownership_unproven",
        );
    }
});
Deno.test("absent result refuses a missing restoration outcome even after completed drops", async () => {
    assertEquals(
        await assets.methods.result.execute({
            completed: true,
            backend: "absent",
            failure_code: "database.destroy_outcome_unknown",
            steps: { restore: { status: "failed" } },
        }, context),
        {
            stdout: '{"error_code":"database.destroy_outcome_unknown"}\n',
            exitCode: 1,
        },
    );
});
