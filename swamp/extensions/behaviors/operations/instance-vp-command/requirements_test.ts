import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior } from "./requirements.ts";
import { vpPreparationJobs } from "./steps.ts";
const runtime = {
    store: "/home/app/.vite-plus",
    vp_version: "0.2.1",
    node_version: "24.2.0",
    package_manager: "pnpm" as const,
    package_manager_version: "10.0.0",
    package_manager_hash: null,
};
const ctx = { writeResource: () => Promise.resolve(undefined) };
Deno.test("existing incompatible CLI refuses before project install", async () => {
    await assertRejects(
        () =>
            behavior.methods.cli.execute({
                runtime,
                observation: { stdout: "vp 0.1.0" },
            }, ctx),
        Error,
        "deployment.vp_store_failed",
    );
    await behavior.methods.cli.execute({
        runtime,
        observation: { stdout: "vp 1.4.0" },
    }, ctx);
});
Deno.test("read-only inventory requires both exact installed versions", async () => {
    const inventory = {
        stdout: JSON.stringify({
            node: [{ version: "24.2.0" }],
            package_managers: { pnpm: [{ version: "10.0.0" }] },
        }),
    };
    assertEquals(
        (await behavior.methods.verify.execute({ runtime, inventory }, ctx))
            .ready,
        true,
    );
    await assertRejects(() =>
        behavior.methods.verify.execute({
            runtime,
            inventory: {
                stdout: JSON.stringify({
                    node: [{ version: "24.2.0" }],
                    package_managers: { pnpm: [] },
                }),
            },
        }, ctx)
    );
});
Deno.test("VP graph gates machine installer to absent manager and uses read-only proof", () => {
    const jobs = vpPreparationJobs("vp", "inputs.intent", "before");
    const steps = jobs[1].steps as Record<string, any>[];
    const manager = steps.find((row) => row.name === "vp-manager")!;
    assertEquals(manager.forEach.in.includes(".manager_required"), true);
    const install = steps.find((row) => row.name === "vp-install")!;
    assertEquals(install.dependsOn[0].step, "vp-cli-ready");
    const inventory = steps.find((row) => row.name === "vp-inventory")!;
    assertEquals(inventory.task.inputs.argv.slice(-3), [
        "env",
        "list",
        "--json",
    ]);
    assertEquals(steps.every((row) => row.target === "orbit-node-209"), true);
    for (const step of steps.filter((row) => row.forEach)) {
        assertEquals(
            step.task.behaviorName.includes("string(self._index)"),
            true,
        );
    }
});
